<?php

require_once __DIR__ . '/db.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['location_name'])) {
    $name = $_POST['location_name'];
    $stmt = $pdo->prepare("INSERT INTO locations (name) VALUES (:name)");
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $message = '「' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '」を登録しました！';
}

$stmt = $pdo->query("SELECT * FROM locations ORDER BY id DESC");
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>拠点登録</title>
</head>
<body>
    <h1>拠点登録（Step 1）</h1>
    <?php if ($message): ?>
        <p style="color: green;"><?php echo $message; ?></p>
    <?php endif; ?>

    <form action="" method="POST">
        <label for="location_name">拠点名：</label>
        <input type="text" id="location_name" name="location_name" placeholder="例：奈良店" required>
        <button type="submit">登録する</button>
    </form>

    <hr>
    <h2>登録済み拠点一覧</h2>
    <ul>
        <?php foreach ($locations as $loc): ?>
            <li>
                ID: <?php echo $loc['id']; ?> - <?php echo htmlspecialchars($loc['name'], ENT_QUOTES, 'UTF-8'); ?>
                | <a href="location_edit.php?id=<?php echo $loc['id']; ?>">編集</a>
                | <a href="location_delete.php?id=<?php echo $loc['id']; ?>" onclick="return confirm('本当に削除しますか？');">削除</a>
            </li>
        <?php endforeach; ?>
    </ul>
    <p><a href="product.php">商品登録へ</a> | <a href="inventory.php">在庫管理へ</a></p>
</body>
</html>
