<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// すでにログインしていれば、ダッシュボードへ
if (current_user() !== null) {
    redirect('/dashboard.php');
}

$error   = '';
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $loginId  = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    // 利用中のユーザーだけを探す（停止中のユーザーはログインできない）
    $stmt = getDb()->prepare(
        'SELECT id, password_hash, name, role, location_id
         FROM users
         WHERE login_id = ? AND status = ?'
    );
    $stmt->execute([$loginId, 'active']); // 退職者はログインできない（ログアウトするまで使えるがMVPでは許容する）
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // ログイン成功：セッションIDを作り直してから、ユーザー情報を保存（セッション固定攻撃対策）
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'role'        => $user['role'],
            'location_id' => (int)$user['location_id'],
        ];
        redirect('/dashboard.php');
    }

    $error = 'ログインIDまたはパスワードが正しくありません。';
}
?>
<!DOCTYPE html>
<?php
$pageTitle = 'ログイン';
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($error !== ''): ?>
    <p class="flash flash-error"><?= h($error) ?></p>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
        <label for="login_id">ログインID</label>
        <input type="text" id="login_id" name="login_id" value="<?= h($loginId) ?>" required>
    </div>
    <div class="form-row">
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" required>
    </div>
    <button type="submit">ログイン</button>
</form>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>