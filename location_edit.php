<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'mariadb'; // ご自身のパスワード
$db_name = 'stock_db';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8", $db_user, $db_pass);
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// 更新処理 (UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['location_name'])) {
    $name = $_POST['location_name'];
    $stmt = $pdo->prepare("UPDATE locations SET name = :name WHERE id = :id");
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    // 更新後に一覧へ戻る
    header('Location: location.php');
    exit;
}

// 該当データの取得
$stmt = $pdo->prepare("SELECT * FROM locations WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$location = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$location) {
    exit('対象の拠点が見つかりません。');
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>拠点編集</title>
</head>
<body>
    <h1>拠点名の編集</h1>
    <form action="" method="POST">
        <label for="location_name">拠点名：</label>
        <input type="text" id="location_name" name="location_name" value="<?php echo htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        <button type="submit">更新する</button>
    </form>
    <p><a href="location.php">戻る</a></p>
</body>
</html>
