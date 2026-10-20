<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'ダッシュボード';
require __DIR__ . '/../includes/layout/header.php';
?>

<p>ログインしました。ここに受取待ちの一覧などを表示します。</p>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>