<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();

// 商品移動（item_transfer.php）とほぼ同じ形です。違うところに ★ を付けています。

// ---------------------------------------------------------------
// 1. 送り元の拠点を決める
//    URL の location_id → なければ、URL の箱がある拠点 → なければ所属拠点
// ---------------------------------------------------------------
$locations     = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();
$locationNames = array_column($locations, 'name', 'id');

$startBoxId = (int)($_GET['box_id'] ?? 0);

if (isset($_GET['location_id'])) {
    $locationId = (int)$_GET['location_id'];
} elseif ($startBoxId > 0) {
    $stmt = $pdo->prepare('SELECT location_id FROM boxes WHERE id = ?');
    $stmt->execute([$startBoxId]);
    $locationId = (int)$stmt->fetchColumn();
} else {
    $locationId = $user['location_id'];
}
if (!isset($locationNames[$locationId])) {
    $locationId = $user['location_id'];
}

$destinations = $locationNames;
unset($destinations[$locationId]);

$formKey = 'box_transfer_' . $locationId;

// ---------------------------------------------------------------
// ★ 2. 送れる箱の一覧（使用中・普通の箱・中身あり）
//    [箱ID => ['note' => メモ, 'items' => [[商品名, 数量], …], 'total' => 点数]]
// ---------------------------------------------------------------
function box_transfer_options(PDO $pdo, int $locationId): array
{
    $stmt = $pdo->prepare(
        "SELECT b.id, b.note, s.product_id, s.qty
         FROM boxes b
         JOIN stocks s ON s.box_id = b.id
         WHERE b.location_id = ? AND b.status = 'active' AND b.loose_location_id IS NULL
         ORDER BY b.id, s.product_id"
    );
    $stmt->execute([$locationId]);

    $products = product_options($pdo);
    $options  = [];
    foreach ($stmt->fetchAll() as $row) {
        $boxId = (int)$row['id'];
        if (!isset($options[$boxId])) {
            $options[$boxId] = ['note' => $row['note'], 'items' => [], 'total' => 0];
        }
        $productId = (int)$row['product_id'];
        $options[$boxId]['items'][] = [$products[$productId] ?? product_code($productId), (int)$row['qty']];
        $options[$boxId]['total']  += (int)$row['qty'];
    }
    return $options;
}

$boxOptions = box_transfer_options($pdo, $locationId);

// ---------------------------------------------------------------
// 3. 入力値のチェック
// ---------------------------------------------------------------
function validate_box_transfer(array $input, array $boxOptions, array $destinations): array
{
    $errors = [];

    if (empty($input['box_ids'])) {
        $errors['box_ids'] = '送る箱を1つ以上選択してください。';
    } else {
        foreach ($input['box_ids'] as $boxId) {
            if (!isset($boxOptions[(int)$boxId])) {
                $errors['box_ids'] = '送る箱の選択が正しくありません。';
                break;
            }
        }
    }
    if (!isset($destinations[(int)$input['to_location_id']])) {
        $errors['to_location_id'] = '送り先の拠点を選択してください。';
    }
    if (mb_strlen($input['note']) > 500) {
        $errors['note'] = '備考は500文字以内で入力してください。';
    }
    return $errors;
}

// ---------------------------------------------------------------
// 4. step で処理を切り替える
// ---------------------------------------------------------------
$step        = $_POST['step'] ?? '';
$errors      = [];
$commitError = '';
$showConfirm = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    form_clear($formKey);
    $input = [
        'box_ids'        => isset($boxOptions[$startBoxId]) ? [(string)$startBoxId] : [],
        'to_location_id' => '',
        'note'           => '',
    ];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        // ★ チェックボックスは、チェックされたものだけが送られてくる
        $boxIds = array_map('strval', (array)($_POST['box_ids'] ?? []));
        $input  = [
            'box_ids'        => array_values(array_unique($boxIds)),
            'to_location_id' => (string)($_POST['to_location_id'] ?? ''),
            'note'           => trim($_POST['note'] ?? ''),
        ];
        $errors = validate_box_transfer($input, $boxOptions, $destinations);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? ['box_ids' => [], 'to_location_id' => '', 'note' => ''];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            set_flash('error', '確認中の内容が見つかりませんでした。別の画面で同じ操作をしたか、時間がたって無効になった可能性があります。もう一度入力してください。');
            redirect($_SERVER['REQUEST_URI']);
        }

        $operationId = 0;
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id = ? AND status = 'active'");
            $stmt->execute([(int)$input['to_location_id']]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new StockException('送り先の拠点が利用停止になっています。送り先を選び直してください。');
            }

            $operationId = create_operation($pdo, [
                'type'             => 'box_transfer',
                'user_id'          => $user['id'],
                'from_location_id' => $locationId,
                'to_location_id'   => (int)$input['to_location_id'],
                'status'           => 'in_transit',
                'note'             => null_if_empty($input['note']),
            ]);

            // ★ デッドロックを防ぐため、ID の小さい順にロックする
            $boxIds = array_map('intval', $input['box_ids']);
            sort($boxIds);

            foreach ($boxIds as $boxId) {
                // 送り元の拠点の、使用中の箱か（ロックもかける）
                lock_box($pdo, $boxId, $locationId);

                // ★ 箱なしでないか、空になっていないかを確かめ直す
                $stmt = $pdo->prepare(
                    'SELECT b.loose_location_id, (SELECT COUNT(*) FROM stocks s WHERE s.box_id = b.id) AS line_count
                     FROM boxes b WHERE b.id = ?'
                );
                $stmt->execute([$boxId]);
                $box = $stmt->fetch();
                if ($box['loose_location_id'] !== null) {
                    throw new StockException('箱なしは箱ごと移動できません。');
                }
                if ((int)$box['line_count'] === 0) {
                    throw new StockException(box_code($boxId) . ' は空になっています。送る箱を選び直してください。');
                }

                // ★ 箱を輸送中にする。中身（stocks）は動かさない
                $pdo->prepare("UPDATE boxes SET status = 'in_transit' WHERE id = ?")->execute([$boxId]);
                add_operation_box($pdo, $operationId, $boxId);
            }

            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            $commitError = $e->getMessage();
            $showConfirm = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($commitError === '') {
            form_clear($formKey);
            set_flash('success', '発送しました。送り先で検品が終わったら、受取確認をしてください。');
            redirect('/transfers/show.php?id=' . $operationId);
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

$isOtherLocation = $locationId !== $user['location_id'];

$pageTitle = '箱ごと移動（発送）';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($showConfirm): ?>
    <!-- ========== 確認画面 ========== -->
    <?php if ($commitError !== ''): ?>
        <p class="flash flash-error"><?= h($commitError) ?></p>
    <?php endif; ?>

    <?php if ($isOtherLocation): ?>
        <p class="warning-box">
            所属拠点（<?= h($locationNames[$user['location_id']] ?? '') ?>）以外の拠点
            （<?= h($locationNames[$locationId]) ?>）から発送します。拠点が正しいか確認してください。
        </p>
    <?php endif; ?>

    <p>次の箱を、中身ごと発送します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>送り元</th>
                <td><?= h($locationNames[$locationId]) ?></td>
            </tr>
            <tr class="emphasis-row">
                <th>送り先</th>
                <td><?= h($destinations[(int)$input['to_location_id']] ?? '') ?>　<span class="emphasis-note">← 送り先が正しいか確認してください</span></td>
            </tr>
            <tr>
                <th>備考</th>
                <td><?= nl2br(h($input['note'])) ?></td>
            </tr>
        </table>
    </div>

    <div class="table-wrap">
        <table>
            <tr>
                <th>箱</th>
                <th>中身</th>
                <th>点数</th>
            </tr>
            <?php foreach ($input['box_ids'] as $boxId): ?>
                <?php $option = $boxOptions[(int)$boxId] ?? null; ?>
                <tr>
                    <td><?= h(box_code((int)$boxId)) ?></td>
                    <td>
                        <?php foreach ($option['items'] ?? [] as [$name, $qty]): ?>
                            <?= h($name) ?> ×<?= h((string)$qty) ?><br>
                        <?php endforeach; ?>
                    </td>
                    <td class="num"><?= h((string)($option['total'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <p class="note">※ 発送すると、箱は送り先で受取確認をするまで「輸送中」になり、送り元の在庫から外れます。</p>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit">発送する</button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->
    <form method="get" class="search-form">
        <div class="form-row">
            <label for="location_id">送り元の拠点</label>
            <select id="location_id" name="location_id">
                <?php foreach ($locations as $location): ?>
                    <option value="<?= h((string)$location['id']) ?>" <?= $locationId === (int)$location['id'] ? 'selected' : '' ?>>
                        <?= h($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button-secondary">切り替える</button>
        <span class="note">※ 切り替えると、選んだ箱は消えます。</span>
    </form>

    <?php if (empty($boxOptions)): ?>
        <p>この拠点には、送れる箱がありません（中身のある使用中の箱だけを送れます）。</p>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <p class="flash flash-error">入力内容に誤りがあります。</p>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <h2>送る箱</h2>
            <?= field_error($errors, 'box_ids') ?>

            <div class="table-wrap">
                <table>
                    <tr>
                        <th>送る</th>
                        <th>箱</th>
                        <th>メモ</th>
                        <th>中身</th>
                        <th>点数</th>
                    </tr>
                    <?php foreach ($boxOptions as $boxId => $option): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="box_ids[]" id="box_<?= h((string)$boxId) ?>" value="<?= h((string)$boxId) ?>"
                                    <?= in_array((string)$boxId, $input['box_ids'], true) ? 'checked' : '' ?>>
                            </td>
                            <td><label for="box_<?= h((string)$boxId) ?>"><?= h(box_code($boxId)) ?></label></td>
                            <td><?= h($option['note'] ?? '') ?></td>
                            <td>
                                <?php foreach ($option['items'] as [$name, $qty]): ?>
                                    <?= h($name) ?> ×<?= h((string)$qty) ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td class="num"><?= h((string)$option['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="form-row">
                <label for="to_location_id">送り先の拠点（必須）</label>
                <select id="to_location_id" name="to_location_id">
                    <option value="">選択してください</option>
                    <?php foreach ($destinations as $id => $name): ?>
                        <option value="<?= h((string)$id) ?>" <?= (string)$id === $input['to_location_id'] ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'to_location_id') ?>
            </div>

            <div class="form-row">
                <label for="note">備考</label>
                <textarea id="note" name="note" rows="3"><?= h($input['note']) ?></textarea>
                <?= field_error($errors, 'note') ?>
            </div>

            <p class="button-row">
                <button type="submit" name="step" value="confirm">確認する</button>
            </p>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>