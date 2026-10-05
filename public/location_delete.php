<?php

require_once __DIR__ . '/db.php';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    // 削除処理 (DELETE)
    $stmt = $pdo->prepare("DELETE FROM locations WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}

// 削除完了後に一覧へリダイレクト
header('Location: location.php');
exit;
