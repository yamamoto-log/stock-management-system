<?php
// 共通メニュー
$menu = [
    '/products/'   => '商品一覧',
    '/boxes/'      => '箱一覧',
    '/transfers/'  => '移動一覧',
    '/histories/'  => '履歴',
    '/operations/' => '在庫操作',
];
if ($user['role'] === 'admin') {
    $menu['/locations/'] = '拠点管理';
}

// 今開いている画面のメニューを目立たせるため、URLの先頭を比べる
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<nav class="site-nav">
    <ul>
        <?php foreach ($menu as $path => $name): ?>
            <li>
                <a href="<?= h($path) ?>"
                    class="<?= str_starts_with($currentPath, $path) ? 'is-current' : '' ?>">
                    <?= h($name) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>