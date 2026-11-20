<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stock.php';
require_login();

$pdo   = getDb();
$user  = current_user();
$myLoc = $user['location_id'];

// ---------------------------------------------------------------
// 1. 拠点ごとの在庫数（輸送中の箱は除外。在庫が0の拠点も表示する）
// ---------------------------------------------------------------
$locations = $pdo->query(
    "SELECT l.id, l.name, COALESCE(SUM(s.qty), 0) AS total_qty
     FROM locations l
     LEFT JOIN boxes b  ON b.location_id = l.id AND b.status <> 'in_transit'
     LEFT JOIN stocks s ON s.box_id = b.id
     WHERE l.status = 'active'
     GROUP BY l.id, l.name
     ORDER BY l.id"
)->fetchAll();

// ★ 輸送中の数＝輸送中の商品移動の明細＋輸送中の箱の中身
$inTransitQty = (int)$pdo->query(
    "SELECT
       (SELECT COALESCE(SUM(oi.qty), 0)
          FROM operation_items oi
          JOIN operations o ON o.id = oi.operation_id
         WHERE o.type = 'item_transfer' AND o.status = 'in_transit')
     + (SELECT COALESCE(SUM(s.qty), 0)
          FROM stocks s
          JOIN boxes b ON b.id = s.box_id
         WHERE b.status = 'in_transit')"
)->fetchColumn();

$grandTotal = array_sum(array_column($locations, 'total_qty')) + $inTransitQty;

// ---------------------------------------------------------------
// 2. 所属拠点の受取待ち・未受取
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT o.id, o.type, o.created_at, o.from_location_id, o.to_location_id,
            fl.name AS from_name, tl.name AS to_name
     FROM operations o
     JOIN locations fl ON fl.id = o.from_location_id
     JOIN locations tl ON tl.id = o.to_location_id
     WHERE o.type IN ('item_transfer', 'box_transfer') AND o.status = 'in_transit'
       AND (o.from_location_id = ? OR o.to_location_id = ?)
     ORDER BY o.id"
);
$stmt->execute([$myLoc, $myLoc]);
$transfers = $stmt->fetchAll();

$incoming = array_filter($transfers, fn($t) => (int)$t['to_location_id'] === $myLoc);
$outgoing = array_filter($transfers, fn($t) => (int)$t['from_location_id'] === $myLoc);
$summaries = transfer_summaries($pdo, array_column($transfers, 'id'));

$myLocationName = $pdo->prepare('SELECT name FROM locations WHERE id = ?');
$myLocationName->execute([$myLoc]);
$myLocationName = (string)$myLocationName->fetchColumn();

$pageTitle = 'ダッシュボード';
require __DIR__ . '/../includes/layout/header.php';

/**
 * 受取待ち・未受取の表（同じ形なので関数にする）
 */
function render_transfer_table(array $rows, array $summaries, string $partnerKey, string $partnerLabel): void
{
    if (empty($rows)) {
        echo '<p>ありません。</p>';
        return;
    }
?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>移動</th>
                <th>種類</th>
                <th>発送日時</th>
                <th><?= h($partnerLabel) ?></th>
                <th>内容</th>
            </tr>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><a href="/transfers/show.php?id=<?= h((string)$t['id']) ?>">#<?= h((string)$t['id']) ?></a></td>
                    <td><?= $t['type'] === 'box_transfer' ? '箱ごと' : '商品' ?></td>
                    <td><?= h(date('Y/m/d H:i', strtotime($t['created_at']))) ?></td>
                    <td><?= h($t[$partnerKey]) ?></td>
                    <td><a href="/transfers/show.php?id=<?= h((string)$t['id']) ?>"><?= h($summaries[(int)$t['id']] ?? '') ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php
}
?>

<h2>受取待ち（<?= h($myLocationName) ?>に届く移動）</h2>
<?php render_transfer_table($incoming, $summaries, 'from_name', '送り元'); ?>
<?php if (!empty($incoming)): ?>
    <p class="note">※ 検品が終わったら、移動詳細で受取確認をしてください。</p>
<?php endif; ?>

<h2>未受取（<?= h($myLocationName) ?>から送った移動）</h2>
<?php render_transfer_table($outgoing, $summaries, 'to_name', '送り先'); ?>

<h2>拠点ごとの在庫数</h2>
<div class="table-wrap">
    <table>
        <tr>
            <th>拠点</th>
            <th>在庫数</th>
        </tr>
        <?php foreach ($locations as $location): ?>
            <tr>
                <td><?= h($location['name']) ?></td>
                <td class="num"><?= h((string)$location['total_qty']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td>輸送中</td>
            <td class="num"><?= h((string)$inTransitQty) ?></td>
        </tr>
        <tr class="subtotal-row">
            <th>合計</th>
            <td class="num"><strong><?= h((string)$grandTotal) ?></strong></td>
        </tr>
    </table>
</div>
<p class="note">※ 拠点ごとの在庫数は、輸送中を含みません。拠点の合計と輸送中を足すと、全体の在庫数になります。</p>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>