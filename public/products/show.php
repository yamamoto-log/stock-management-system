<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 商品を取り出す
// ---------------------------------------------------------------
$productId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE p.id = ?'
);
$stmt->execute([$productId]);
$product = $stmt->fetch();

if ($product === false) {
    http_response_code(404);
    $pageTitle = '商品が見つかりません';
    require __DIR__ . '/../../includes/layout/header.php';
    echo '<p>指定された商品は存在しません。</p>';
    echo '<p><a href="/products/">商品一覧に戻る</a></p>';
    require __DIR__ . '/../../includes/layout/footer.php';
    exit;
}

// ---------------------------------------------------------------
// 2. 拠点別・箱別の在庫（輸送中の箱は除く）
//    拠点の順に並べ、同じ拠点の中では箱なしを先にする
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT l.id AS location_id, l.name AS location_name,
            b.id AS box_id, b.loose_location_id, s.qty
     FROM stocks s
     JOIN boxes b     ON b.id = s.box_id
     JOIN locations l ON l.id = b.location_id
     WHERE s.product_id = ? AND b.status <> 'in_transit'
     ORDER BY l.id, (b.loose_location_id IS NULL), b.id"
);
$stmt->execute([$productId]);
$stockRows = $stmt->fetchAll();

// 拠点ごとのグループに分ける
$groups = [];
foreach ($stockRows as $row) {
    $groups[$row['location_name']][] = $row;
}

// ---------------------------------------------------------------
// 3. 輸送中（商品移動の明細 ＋ 輸送中の箱の中身）
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT o.id AS operation_id, fl.name AS from_name, tl.name AS to_name,
            o.created_at, SUM(oi.qty) AS qty
     FROM operation_items oi
     JOIN operations o ON o.id = oi.operation_id
     JOIN locations fl ON fl.id = o.from_location_id
     JOIN locations tl ON tl.id = o.to_location_id
     WHERE o.type = 'item_transfer' AND o.status = 'in_transit' AND oi.product_id = ?
     GROUP BY o.id, fl.name, tl.name, o.created_at

     UNION ALL

     SELECT o.id AS operation_id, fl.name AS from_name, tl.name AS to_name,
            o.created_at, SUM(s.qty) AS qty
     FROM stocks s
     JOIN boxes b            ON b.id = s.box_id AND b.status = 'in_transit'
     JOIN operation_boxes ob ON ob.box_id = b.id
     JOIN operations o       ON o.id = ob.operation_id AND o.status = 'in_transit'
     JOIN locations fl       ON fl.id = o.from_location_id
     JOIN locations tl       ON tl.id = o.to_location_id
     WHERE s.product_id = ?
     GROUP BY o.id, fl.name, tl.name, o.created_at

     ORDER BY created_at"
);
$stmt->execute([$productId, $productId]);
$transitRows = $stmt->fetchAll();

// ---------------------------------------------------------------
// 4. 合計
// ---------------------------------------------------------------
$locationTotal = array_sum(array_column($stockRows, 'qty'));
$transitTotal  = array_sum(array_column($transitRows, 'qty'));
$grandTotal    = $locationTotal + $transitTotal;

$isActive = $product['status'] === 'active';

$pageTitle = '商品詳細';
require __DIR__ . '/../../includes/layout/header.php';
?>

<h2><?= h(product_code((int)$product['id'])) ?>　<?= h($product['name']) ?></h2>

<div class="table-wrap">
    <table class="detail-table">
        <tr>
            <th>商品コード</th>
            <td><?= h(product_code((int)$product['id'])) ?></td>
        </tr>
        <tr>
            <th>商品名</th>
            <td><?= h($product['name']) ?></td>
        </tr>
        <tr>
            <th>サイズ</th>
            <td><?= h($product['size']) ?></td>
        </tr>
        <tr>
            <th>カテゴリ</th>
            <td><?= h($product['category_name']) ?></td>
        </tr>
        <tr>
            <th>対象</th>
            <td><?= h(label('target', $product['target'])) ?></td>
        </tr>
        <tr>
            <th>季節</th>
            <td><?= h(label('season', $product['season'])) ?></td>
        </tr>
        <tr>
            <th>色</th>
            <td><?= h($product['color']) ?></td>
        </tr>
        <tr>
            <th>柄</th>
            <td><?= h($product['pattern']) ?></td>
        </tr>
        <tr>
            <th>備考</th>
            <td><?= nl2br(h($product['note'])) ?></td>
        </tr>
        <tr>
            <th>状態</th>
            <td><?= h(label('product_status', $product['status'])) ?></td>
        </tr>
    </table>
</div>

<p class="button-row">
    <?php if ($isActive): ?>
        <a href="/operations/stock_in.php?product_id=<?= h((string)$product['id']) ?>" class="button">入庫する</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?>
        <a href="/products/edit.php?id=<?= h((string)$product['id']) ?>" class="button button-secondary">編集する</a>
    <?php endif; ?>
</p>

<h2>在庫</h2>

<?php if (empty($groups) && empty($transitRows)): ?>
    <p>在庫はありません。</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>拠点</th>
                <th>保管場所・移動</th>
                <th>数量</th>
                <th>操作</th>
            </tr>

            <?php foreach ($groups as $locationName => $rows): ?>
                <?php $subtotal = 0; ?>
                <?php foreach ($rows as $i => $row): ?>
                    <?php
                    $subtotal += (int)$row['qty'];
                    $isLoose  = $row['loose_location_id'] !== null;
                    $query    = 'product_id=' . $product['id'] . '&box_id=' . $row['box_id'];
                    ?>
                    <tr>
                        <td><?= $i === 0 ? h($locationName) : '' ?></td>
                        <td>
                            <?php if ($isLoose): ?>
                                箱なし
                            <?php else: ?>
                                <a href="/boxes/show.php?id=<?= h((string)$row['box_id']) ?>">
                                    <?= h(box_code((int)$row['box_id'])) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= h((string)$row['qty']) ?></td>
                        <td>
                            <?php if ($isActive): ?>
                                <a href="/operations/stock_out.php?<?= h($query) ?>">出庫</a> ／
                                <a href="/operations/relocation.php?<?= h($query) ?>">保管場所変更</a> ／
                                <a href="/operations/item_transfer.php?<?= h($query) ?>">移動</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row">
                    <td></td>
                    <td>小計</td>
                    <td class="num"><?= h((string)$subtotal) ?></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!empty($transitRows)): ?>
                <?php foreach ($transitRows as $i => $row): ?>
                    <tr>
                        <td><?= $i === 0 ? '輸送中' : '' ?></td>
                        <td>
                            <?= h($row['from_name']) ?> → <?= h($row['to_name']) ?>
                            （<?= h(date('n/j', strtotime($row['created_at']))) ?> 発送）
                        </td>
                        <td class="num"><?= h((string)$row['qty']) ?></td>
                        <td><a href="/transfers/show.php?id=<?= h((string)$row['operation_id']) ?>">移動詳細を見る</a></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row">
                    <td></td>
                    <td>小計</td>
                    <td class="num"><?= h((string)$transitTotal) ?></td>
                    <td></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <p>
        拠点の在庫 <?= h((string)$locationTotal) ?> ／
        輸送中 <?= h((string)$transitTotal) ?> ／
        <strong>総数 <?= h((string)$grandTotal) ?></strong>
    </p>
<?php endif; ?>

<p><a href="/products/">商品一覧に戻る</a></p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>