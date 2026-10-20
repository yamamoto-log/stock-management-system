<?php
// ログイン状態の確認

/**
 * ログイン中のユーザー情報を返す（ログインしていなければ null）
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * ログインしていなければ、ログイン画面へ移動する
 * 使い方：ログインが必要な画面の先頭で require_login();
 */
function require_login(): void
{
    if (current_user() === null) {
        redirect('/login.php');
    }
}

/**
 * 管理者でなければ、処理を止める
 * 使い方：管理者専用の画面の先頭で require_admin();
 */
function require_admin(): void
{
    require_login();
    if (current_user()['role'] !== 'admin') {
        http_response_code(403);
        exit('この操作は管理者のみ行えます。');
    }
}
