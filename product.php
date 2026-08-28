<?php

require_once 'db.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_name'])) {
    $name = $_POST['product_name'];
    $stmt = $pdo->prepare("INSERT INTO products (name) VALUES (:name)");
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->execute();
    $message = '商品「' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '」を登録しました！';
}

$stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>商品登録</title>
</head>
<body>
    <h1>商品登録（Step 2-1）</h1>
    <?php if ($message): ?>
        <p style="color: green;"><?php echo $message; ?></p>
    <?php endif; ?>

    <form action="" method="POST">
        <label for="product_name">商品名：</label>
        <input type="text" id="product_name" name="product_name" placeholder="例：振袖A" required>
        <button type="submit">登録する</button>
    </form>

    <hr>
    <h2>登録済み商品一覧</h2>
    <ul>
        <?php foreach ($products as $product): ?>
            <li>ID: <?php echo $product['id']; ?> - <?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="location.php">拠点登録へ</a> | <a href="inventory.php">在庫管理へ</a></p>
</body>
</html>
