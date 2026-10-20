<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// 拠点ごとの在庫の合計数（輸送中の箱は除外。在庫が0の拠点も表示する[LEFT JOIN, WHEREではなくONを使う]）
$sql = "
    SELECT l.id, l.name, COALESCE(SUM(s.qty), 0) AS total_qty
    FROM locations l
    LEFT JOIN boxes b  ON b.location_id = l.id AND b.status <> 'in_transit'
    LEFT JOIN stocks s ON s.box_id = b.id
    WHERE l.status = 'active'
    GROUP BY l.id, l.name
    ORDER BY l.id
";
$locations = getDb()->query($sql)->fetchAll();

$pageTitle = 'ダッシュボード';
require __DIR__ . '/../includes/layout/header.php';
?>

<h2>拠点ごとの在庫数</h2>
<div class="table-wrap">
    <table>
        <tr>
            <th>拠点</th>
            <th>在庫の合計数</th>
        </tr>
        <?php foreach ($locations as $location): ?>
            <tr>
                <td><?= h($location['name']) ?></td>
                <td><?= h((string)$location['total_qty']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>