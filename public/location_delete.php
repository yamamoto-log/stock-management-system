<?php

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';


// POSTリクエストの場合のみ処理を実行（安全対策）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id > 0) {
        // 削除処理 (DELETE)
        $sql = 'DELETE FROM locations WHERE id = :id';
        $stmt = $dbh->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}

// 削除完了後に一覧へリダイレクト
header('Location: location.php');
exit;
