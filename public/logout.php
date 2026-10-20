<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// ログアウトはフォームの送信（POST）でのみ受け付ける（悪意のあるサイトに強制ログアウトさせられるのを防ぐため。CSRF対策の考え方。）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}

verify_csrf();

$_SESSION = [];
session_destroy();

redirect('/login.php');
