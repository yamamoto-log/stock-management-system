<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <title>ダッシュボード</title>
</head>

<body>
    <h1>ダッシュボード</h1>
    <p><?= h($user['name']) ?> さん（<?= h(label('role', $user['role'])) ?>）でログインしています。</p>

    <form method="post" action="/logout.php">
        <?= csrf_field() ?>
        <button type="submit">ログアウト</button>
    </form>
</body>

</html>