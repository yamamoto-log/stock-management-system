<?php
// CSRF対策：フォームに秘密の値（トークン）を埋め込み、送信時に一致するか確認する

/**
 * トークンを取得する（なければ作る）
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * フォームの中に書く hidden の入力欄
 * 使い方：<form method="post"> の直後に <?= csrf_field() ?>
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * 送信されたトークンを確認する。一致しなければ処理を止める
 * 使い方：POST を受け取る処理の最初に verify_csrf();
 */
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? ''; // 教科書の$post_token にあたる
    if (!hash_equals(csrf_token(), $sent)) { // csrf_token() が教科書の $session_token にあたる 
        http_response_code(400);
        exit('不正なリクエストです。画面を再読み込みしてから、もう一度操作してください。');
    }
}
