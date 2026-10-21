<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$operations = [
    'stock_in.php'      => ['入庫',         '新しく仕入れた商品・初期在庫を登録する'],
    'stock_out.php'     => ['出庫',         '廃棄・売却・紛失などで在庫を減らす'],
    'adjustment.php'    => ['在庫調整',     '記録と実物の差を修正する'],
    'relocation.php'    => ['保管場所変更', '同じ拠点の中で、箱・箱なしの間を移す'],
    'item_transfer.php' => ['商品移動',     '商品を別の拠点へ送る'],
    'box_transfer.php'  => ['箱ごと移動',   '箱を中身ごと別の拠点へ送る'],
];

$pageTitle = '在庫操作';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="table-wrap">
    <table>
        <tr>
            <th>操作</th>
            <th>内容</th>
        </tr>
        <?php foreach ($operations as $file => [$name, $description]): ?>
            <tr>
                <td><a href="/operations/<?= h($file) ?>"><?= h($name) ?></a></td>
                <td><?= h($description) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>