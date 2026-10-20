<?php
// 画面の上部（共通）
// 使い方：画面のファイルで $pageTitle を決めてから require する
$user = current_user();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle ?? '') ?>｜<?= h($appConfig['app_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>

<body>
    <header class="site-header">
        <a class="site-title" href="/dashboard.php"><?= h($appConfig['app_name']) ?></a>
        <?php if ($user !== null): ?>
            <div class="user-info">
                <span><?= h($user['name']) ?>（<?= h(label('role', $user['role'])) ?>）</span>
                <form method="post" action="/logout.php">
                    <?= csrf_field() ?>
                    <button type="submit" class="button-link">ログアウト</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($user !== null): ?>
        <?php require __DIR__ . '/nav.php'; ?>
    <?php endif; ?>

    <main class="container">
        <h1><?= h($pageTitle ?? '') ?></h1>

        <?php foreach (get_flash() as $flash): ?>
            <p class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></p>
        <?php endforeach; ?>