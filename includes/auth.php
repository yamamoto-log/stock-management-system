<?php

/**
 * ログイン中のユーザー情報を返す（ログインしていなければ null）
 * セッションの情報をそのまま信じず、毎回 DB で「まだ利用中か」を確かめる
 */
function current_user(): ?array
{
    // 同じ画面の中で何度呼ばれても、DB を見るのは1回だけにする
    static $checked = false;
    static $user    = null;
    if ($checked) {
        return $user;
    }
    $checked = true;

    $sessionUser = $_SESSION['user'] ?? null;
    if ($sessionUser === null) {
        return null;
    }

    $stmt = getDb()->prepare(
        "SELECT id, name, role, location_id FROM users WHERE id = ? AND status = 'active'"
    );
    $stmt->execute([$sessionUser['id']]);
    $row = $stmt->fetch();

    if ($row === false) {
        // 停止された（または削除された）ユーザー：ログアウトさせる
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    // 権限・名前・所属拠点の変更も、すぐに反映する
    $user = [
        'id'          => (int)$row['id'],
        'name'        => $row['name'],
        'role'        => $row['role'],
        'location_id' => (int)$row['location_id'],
    ];
    $_SESSION['user'] = $user;
    return $user;
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
