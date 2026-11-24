<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();

$operationId = (int)($_GET['id'] ?? 0);
$backTo      = '/transfers/show.php?id=' . $operationId;

// ---------------------------------------------------------------
// 1. 移動を取り出す（★ 商品移動と箱ごと移動の両方）
// ---------------------------------------------------------------
function load_transfer(PDO $pdo, int $operationId)
{
    $stmt = $pdo->prepare(
        "SELECT o.*,
                fl.name AS from_name, tl.name AS to_name,
                u.name  AS user_name, ru.name AS received_name, cu.name AS cancelled_name
         FROM operations o
         JOIN locations fl  ON fl.id = o.from_location_id
         JOIN locations tl  ON tl.id = o.to_location_id
         JOIN users u       ON u.id  = o.user_id
         LEFT JOIN users ru ON ru.id = o.received_by
         LEFT JOIN users cu ON cu.id = o.cancelled_by
         WHERE o.id = ? AND o.type IN ('item_transfer', 'box_transfer')"
    );
    $stmt->execute([$operationId]);
    return $stmt->fetch();
}

$transfer = load_transfer($pdo, $operationId);

if ($transfer === false) {
    http_response_code(404);
    $pageTitle = '移動が見つかりません';
    require __DIR__ . '/../../includes/layout/header.php';
    echo '<p>指定された移動は存在しません。</p>';
    echo '<p><a href="/transfers/">移動一覧に戻る</a></p>';
    require __DIR__ . '/../../includes/layout/footer.php';
    exit;
}

$isBoxTransfer = $transfer['type'] === 'box_transfer';   // ★

// ---------------------------------------------------------------
// 2. 受取確認・取消（POST で受け付ける）
// ---------------------------------------------------------------
$confirmAction = null;   // receive / cancel

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';
    $step   = $_POST['step'] ?? '';

    if (!in_array($action, ['receive', 'cancel'], true)) {
        redirect($backTo);
    }

    if ($step === 'confirm') {
        $confirmAction = $action;
    } elseif ($step === 'commit') {
        try {
            $pdo->beginTransaction();

            // ① 状態を「輸送中なら」という条件つきで変える（二重処理を防ぐ要）
            if ($action === 'receive') {
                $stmt = $pdo->prepare(
                    "UPDATE operations
                        SET status = 'received', received_by = ?, received_at = NOW()
                      WHERE id = ? AND type IN ('item_transfer', 'box_transfer') AND status = 'in_transit'"
                );
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE operations
                        SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW()
                      WHERE id = ? AND type IN ('item_transfer', 'box_transfer') AND status = 'in_transit'"
                );
            }
            $stmt->execute([$user['id'], $operationId]);

            if ($stmt->rowCount() === 0) {
                throw new StockException('この移動はすでに受取済か取消済です。画面の状態を確認してください。');
            }

            if ($isBoxTransfer) {
                // ★ ②-箱ごと移動：箱の状態と所属拠点を変えるだけ。中身は動かさない
                $stmt = $pdo->prepare('SELECT box_id FROM operation_boxes WHERE operation_id = ? ORDER BY box_id');
                $stmt->execute([$operationId]);
                $boxIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

                foreach ($boxIds as $boxId) {
                    if ($action === 'receive') {
                        $stmt = $pdo->prepare(
                            "UPDATE boxes SET location_id = ?, status = 'active'
                              WHERE id = ? AND status = 'in_transit'"
                        );
                        $stmt->execute([(int)$transfer['to_location_id'], $boxId]);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE boxes SET status = 'active'
                              WHERE id = ? AND status = 'in_transit'"
                        );
                        $stmt->execute([$boxId]);
                    }
                    if ($stmt->rowCount() === 0) {
                        throw new StockException(box_code($boxId) . ' が輸送中になっていません。管理者に連絡してください。');
                    }
                }
                $message = $action === 'receive'
                    ? '受取確認をしました。箱は中身ごと' . $transfer['to_name'] . 'の所属になりました。'
                    : '発送を取り消しました。箱は' . $transfer['from_name'] . 'で使用中に戻りました。';
            } else {
                // ②-商品移動：明細を取り出す
                $stmt = $pdo->prepare('SELECT id, product_id, qty, from_box_id FROM operation_items WHERE operation_id = ?');
                $stmt->execute([$operationId]);
                $items = $stmt->fetchAll();

                if ($action === 'receive') {
                    // ③-受取：送り先の箱なしへ加え、明細に送り先の保管場所を書き込む
                    $looseBoxId = loose_box_id($pdo, (int)$transfer['to_location_id']);
                    lock_box($pdo, $looseBoxId, (int)$transfer['to_location_id']);

                    foreach ($items as $item) {
                        add_stock($pdo, $looseBoxId, (int)$item['product_id'], (int)$item['qty']);
                        $pdo->prepare('UPDATE operation_items SET to_box_id = ? WHERE id = ?')
                            ->execute([$looseBoxId, $item['id']]);
                    }
                    $message = '受取確認をしました。商品は' . $transfer['to_name'] . 'の箱なしに入りました。';
                } else {
                    // ③-取消：送り元の保管場所へ戻す。使えない箱なら、送り元の箱なしへ戻す
                    $fromLocationId = (int)$transfer['from_location_id'];
                    $looseBoxId     = loose_box_id($pdo, $fromLocationId);

                    foreach ($items as $item) {
                        $boxId = (int)$item['from_box_id'];
                        if (!box_is_usable($pdo, $boxId, $fromLocationId)) {
                            $boxId = $looseBoxId;
                        }
                        lock_box($pdo, $boxId, $fromLocationId);
                        add_stock($pdo, $boxId, (int)$item['product_id'], (int)$item['qty']);
                        // ★ 戻した保管場所を記録する（記録と在庫を一致させるため）
                        $pdo->prepare('UPDATE operation_items SET returned_box_id = ? WHERE id = ?')
                            ->execute([$boxId, $item['id']]);
                    }
                    $message = '発送を取り消しました。商品は' . $transfer['from_name'] . 'に戻りました。';
                }
            }

            $pdo->commit();
            set_flash('success', $message);
        } catch (StockException $e) {
            $pdo->rollBack();
            set_flash('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        redirect($backTo);
    } else {
        redirect($backTo);
    }
}

// ---------------------------------------------------------------
// 3. 明細
// ---------------------------------------------------------------
$products = product_options($pdo);

if ($isBoxTransfer) {
    // ★ 箱ごとに、今の中身を取り出す
    $stmt = $pdo->prepare(
        'SELECT ob.box_id, s.product_id, s.qty
         FROM operation_boxes ob
         LEFT JOIN stocks s ON s.box_id = ob.box_id
         WHERE ob.operation_id = ?
         ORDER BY ob.box_id, s.product_id'
    );
    $stmt->execute([$operationId]);
    $boxLines = [];
    foreach ($stmt->fetchAll() as $row) {
        $boxId = (int)$row['box_id'];
        $boxLines[$boxId] ??= ['items' => [], 'total' => 0];
        if ($row['product_id'] !== null) {
            $productId = (int)$row['product_id'];
            $boxLines[$boxId]['items'][] = [$products[$productId] ?? product_code($productId), (int)$row['qty']];
            $boxLines[$boxId]['total']  += (int)$row['qty'];
        }
    }
} else {
    $stmt = $pdo->prepare(
        'SELECT oi.product_id, oi.qty, oi.from_box_id, oi.to_box_id, oi.returned_box_id,
                fb.loose_location_id AS from_loose, tb.loose_location_id AS to_loose,
                rb.loose_location_id AS returned_loose
         FROM operation_items oi
         LEFT JOIN boxes fb ON fb.id = oi.from_box_id
         LEFT JOIN boxes tb ON tb.id = oi.to_box_id
         LEFT JOIN boxes rb ON rb.id = oi.returned_box_id
         WHERE oi.operation_id = ?
         ORDER BY oi.id'
    );
    $stmt->execute([$operationId]);
    $lines = $stmt->fetchAll();
}

function storage_name(?int $boxId, $looseLocationId): string
{
    if ($boxId === null) {
        return '';
    }
    return $looseLocationId !== null ? '箱なし' : box_code($boxId);
}

$isInTransit = $transfer['status'] === 'in_transit';
$isReceived  = $transfer['status'] === 'received';
$isCancelled = $transfer['status'] === 'cancelled';
$typeName    = $isBoxTransfer ? '箱ごと移動' : '商品移動';   // ★

$pageTitle = '移動詳細';
require __DIR__ . '/../../includes/layout/header.php';
?>

<h2><?= h($typeName) ?> #<?= h((string)$operationId) ?>：<?= h($transfer['from_name']) ?> → <?= h($transfer['to_name']) ?></h2>

<?php if ($confirmAction !== null): ?>
    <div class="confirm-box">
        <?php if ($confirmAction === 'receive' && $isBoxTransfer): ?>
            <p>検品は終わりましたか？　受取確認をすると、箱は中身ごと<?= h($transfer['to_name']) ?>の所属になります。</p>
        <?php elseif ($confirmAction === 'receive'): ?>
            <p>検品は終わりましたか？　受取確認をすると、<?= h($transfer['to_name']) ?>の箱なしに商品が加わります。</p>
        <?php elseif ($isBoxTransfer): ?>
            <p>この発送を取り消しますか？　箱は<?= h($transfer['from_name']) ?>で使用中に戻ります。</p>
        <?php else: ?>
            <p>この発送を取り消しますか？　商品は<?= h($transfer['from_name']) ?>の元の保管場所に戻ります。</p>
        <?php endif; ?>
        <form method="post" class="button-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= h($confirmAction) ?>">
            <button type="submit" name="step" value="commit">
                <?= $confirmAction === 'receive' ? '受取確認する' : '取り消す' ?>
            </button>
            <a href="<?= h($backTo) ?>" class="button button-secondary">キャンセル</a>
        </form>
    </div>
<?php endif; ?>

<div class="table-wrap">
    <table class="detail-table">
        <tr>
            <th>種類</th>
            <td><?= h($typeName) ?></td>
        </tr>
        <tr>
            <th>状態</th>
            <td><strong><?= h(label('transfer', $transfer['status'])) ?></strong></td>
        </tr>
        <tr>
            <th>送り元</th>
            <td><?= h($transfer['from_name']) ?></td>
        </tr>
        <tr>
            <th>送り先</th>
            <td><?= h($transfer['to_name']) ?></td>
        </tr>
        <tr>
            <th>発送</th>
            <td><?= h($transfer['created_at']) ?>（<?= h($transfer['user_name']) ?>）</td>
        </tr>
        <?php if ($transfer['received_at'] !== null): ?>
            <tr>
                <th>受取</th>
                <td><?= h($transfer['received_at']) ?>（<?= h($transfer['received_name']) ?>）</td>
            </tr>
        <?php endif; ?>
        <?php if ($transfer['cancelled_at'] !== null): ?>
            <tr>
                <th>取消</th>
                <td><?= h($transfer['cancelled_at']) ?>（<?= h($transfer['cancelled_name']) ?>）</td>
            </tr>
        <?php endif; ?>
        <tr>
            <th>備考</th>
            <td><?= nl2br(h($transfer['note'])) ?></td>
        </tr>
    </table>
</div>

<?php if ($isInTransit): ?>
    <div class="button-row">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="receive">
            <button type="submit" name="step" value="confirm">受取確認</button>
        </form>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" name="step" value="confirm" class="button-secondary">発送を取り消す</button>
        </form>
    </div>
    <p class="note">※ 受取確認は、送り先で検品が終わってから行ってください。</p>
<?php endif; ?>

<h2>明細</h2>

<?php if ($isBoxTransfer): ?>
    <!-- ★ 箱ごと移動の明細 -->
    <div class="table-wrap">
        <table>
            <tr>
                <th>箱</th>
                <th>中身（現在）</th>
                <th>点数</th>
            </tr>
            <?php foreach ($boxLines as $boxId => $boxLine): ?>
                <tr>
                    <td><a href="/boxes/show.php?id=<?= h((string)$boxId) ?>"><?= h(box_code($boxId)) ?></a></td>
                    <td>
                        <?php if (empty($boxLine['items'])): ?>
                            （空）
                        <?php else: ?>
                            <?php foreach ($boxLine['items'] as [$name, $qty]): ?>
                                <?= h($name) ?> ×<?= h((string)$qty) ?><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= h((string)$boxLine['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php if (!$isInTransit): ?>
        <p class="note">※ 中身は、今の箱の中身です。受取後や取消後に入れ替えた場合は、発送したときの中身と異なります。</p>
    <?php endif; ?>

<?php else: ?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>商品</th>
                <th>数量</th>
                <th>送り元の<br>保管場所</th>
                <th><?= $isCancelled ? '戻した<br>保管場所' : '送り先の<br>保管場所' ?></th>
                <?php if ($isReceived): ?><th>操作</th><?php endif; ?>
            </tr>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td>
                        <a href="/products/show.php?id=<?= h((string)$line['product_id']) ?>">
                            <?= h($products[(int)$line['product_id']] ?? product_code((int)$line['product_id'])) ?>
                        </a>
                    </td>
                    <td class="num"><?= h((string)$line['qty']) ?></td>
                    <td><?= h(storage_name($line['from_box_id'] !== null ? (int)$line['from_box_id'] : null, $line['from_loose'])) ?></td>
                    <?php if ($isCancelled): ?>
                        <td><?= h(storage_name($line['returned_box_id'] !== null ? (int)$line['returned_box_id'] : null, $line['returned_loose'])) ?></td>
                    <?php else: ?>
                        <td><?= h(storage_name($line['to_box_id'] !== null ? (int)$line['to_box_id'] : null, $line['to_loose'])) ?></td>
                    <?php endif; ?>
                    <?php if ($isReceived): ?>
                        <td>
                            <a href="/operations/relocation.php?box_id=<?= h((string)$line['to_box_id']) ?>&product_id=<?= h((string)$line['product_id']) ?>&qty=<?= h((string)$line['qty']) ?>">
                                保管場所変更
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<?php if ($isReceived): ?>
    <p class="note">
        検品で数が合わなかったときは、この移動に関連づけて在庫調整をしてください：
        <a href="/operations/adjustment.php?location_id=<?= h((string)$transfer['to_location_id']) ?>&related_operation_id=<?= h((string)$operationId) ?>">
            <?= h($transfer['to_name']) ?>の在庫を調整する
        </a> ／
        <a href="/operations/adjustment.php?location_id=<?= h((string)$transfer['from_location_id']) ?>&related_operation_id=<?= h((string)$operationId) ?>">
            <?= h($transfer['from_name']) ?>の在庫を調整する
        </a>
    </p>
<?php endif; ?>

<p><a href="/transfers/">移動一覧に戻る</a></p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>