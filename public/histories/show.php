<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo         = getDb();
$operationId = (int)($_GET['id'] ?? 0);

// ---------------------------------------------------------------
// 1. 操作を取り出す
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT o.*, u.name AS user_name, fl.name AS from_name, tl.name AS to_name
     FROM operations o
     JOIN users u           ON u.id  = o.user_id
     LEFT JOIN locations fl ON fl.id = o.from_location_id
     LEFT JOIN locations tl ON tl.id = o.to_location_id
     WHERE o.id = ?'
);
$stmt->execute([$operationId]);
$operation = $stmt->fetch();

if ($operation === false) {
    http_response_code(404);
    $pageTitle = '履歴が見つかりません';
    require __DIR__ . '/../../includes/layout/header.php';
    echo '<p>指定された履歴は存在しません。</p>';
    echo '<p><a href="/histories/">履歴に戻る</a></p>';
    require __DIR__ . '/../../includes/layout/footer.php';
    exit;
}

// 移動は、受取・取消の情報もそろっている移動詳細で見る
if (in_array($operation['type'], ['item_transfer', 'box_transfer'], true)) {
    redirect('/transfers/show.php?id=' . $operationId);
}

// ---------------------------------------------------------------
// 2. 明細（移動元・移動先の保管場所つき）
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT oi.product_id, oi.qty, oi.from_box_id, oi.to_box_id,
            fb.loose_location_id AS from_loose, tb.loose_location_id AS to_loose
     FROM operation_items oi
     LEFT JOIN boxes fb ON fb.id = oi.from_box_id
     LEFT JOIN boxes tb ON tb.id = oi.to_box_id
     WHERE oi.operation_id = ?
     ORDER BY oi.id'
);
$stmt->execute([$operationId]);
$lines    = $stmt->fetchAll();
$products = product_options($pdo);

$typeNames = [
    'stock_in'   => '入庫',
    'stock_out'  => '出庫',
    'adjustment' => '在庫調整',
    'relocation' => '保管場所変更',
];
$typeName = $typeNames[$operation['type']] ?? $operation['type'];

// 在庫調整は、明細の向きで「増やす」「減らす」が分かる
if ($operation['type'] === 'adjustment' && !empty($lines)) {
    $typeName .= $lines[0]['to_box_id'] !== null ? '（増やす）' : '（減らす）';
}

// 関連する移動（在庫調整のみ）
$related = null;
if ($operation['related_operation_id'] !== null) {
    $stmt = $pdo->prepare(
        'SELECT o.id, fl.name AS from_name, tl.name AS to_name
         FROM operations o
         JOIN locations fl ON fl.id = o.from_location_id
         JOIN locations tl ON tl.id = o.to_location_id
         WHERE o.id = ?'
    );
    $stmt->execute([$operation['related_operation_id']]);
    $related = $stmt->fetch();
}

/**
 * 保管場所の表示（箱なしは文字だけ、箱は箱詳細へのリンク）
 */
function storage_link(?string $boxId, ?string $looseLocationId): string
{
    if ($boxId === null) {
        return '';
    }
    if ($looseLocationId !== null) {
        return '箱なし';
    }
    $id = (int)$boxId;
    return '<a href="/boxes/show.php?id=' . h((string)$id) . '">' . h(box_code($id)) . '</a>';
}

$pageTitle = '履歴詳細';
require __DIR__ . '/../../includes/layout/header.php';
?>

<h2><?= h($typeName) ?> #<?= h((string)$operationId) ?></h2>

<div class="table-wrap">
    <table class="detail-table">
        <tr>
            <th>操作</th>
            <td><?= h($typeName) ?></td>
        </tr>
        <tr>
            <th>日時</th>
            <td><?= h($operation['created_at']) ?></td>
        </tr>
        <tr>
            <th>操作者</th>
            <td><?= h($operation['user_name']) ?></td>
        </tr>
        <tr>
            <th>拠点</th>
            <td><?= h($operation['from_name'] ?? $operation['to_name'] ?? '') ?></td>
        </tr>
        <?php if ($operation['reason'] !== null): ?>
            <tr>
                <th>理由</th>
                <td><?= h(label('reason', $operation['reason'])) ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <th><?= $operation['type'] === 'adjustment' ? '理由' : '備考' ?></th>
            <td><?= nl2br(h($operation['note'])) ?></td>
        </tr>
        <?php if ($related !== false && $related !== null): ?>
            <tr>
                <th>関連する移動</th>
                <td>
                    <a href="/transfers/show.php?id=<?= h((string)$related['id']) ?>">
                        #<?= h((string)$related['id']) ?>　<?= h($related['from_name']) ?> → <?= h($related['to_name']) ?>
                    </a>
                </td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<h2>明細</h2>
<div class="table-wrap">
    <table>
        <tr>
            <th>商品</th>
            <th>数量</th>
            <th>移動元の<br>保管場所</th>
            <th>移動先の<br>保管場所</th>
        </tr>
        <?php foreach ($lines as $line): ?>
            <?php
            // 増えた（移動先だけ）は +、減った（移動元だけ）は −、移した（両方）は符号なし
            $sign = '';
            if ($line['from_box_id'] === null) {
                $sign = '+';
            } elseif ($line['to_box_id'] === null) {
                $sign = '−';
            }
            ?>
            <tr>
                <td>
                    <a href="/products/show.php?id=<?= h((string)$line['product_id']) ?>">
                        <?= h($products[(int)$line['product_id']] ?? product_code((int)$line['product_id'])) ?>
                    </a>
                </td>
                <td class="num"><?= h($sign . $line['qty']) ?></td>
                <td><?= storage_link($line['from_box_id'], $line['from_loose']) ?></td>
                <td><?= storage_link($line['to_box_id'], $line['to_loose']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<p><a href="/histories/">履歴に戻る</a></p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>