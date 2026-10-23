<?php
// 共通メニュー
// 読み込んだ画面と変数名がぶつからないよう、nav で始まる名前にしている
$navItems = [
    '/products/'   => '商品一覧',
    '/boxes/'      => '箱一覧',
    '/transfers/'  => '移動一覧',
    '/histories/'  => '履歴',
    '/operations/' => '在庫操作',
];
if ($user['role'] === 'admin') {
    $navItems['/locations/'] = '拠点管理';
}

$navCurrentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<nav class="site-nav">
    <ul>
        <?php foreach ($navItems as $navPath => $navLabel): ?>
            <li>
                <a href="<?= h($navPath) ?>"
                    class="<?= str_starts_with($navCurrentPath, $navPath) ? 'is-current' : '' ?>">
                    <?= h($navLabel) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>