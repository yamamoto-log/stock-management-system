<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';


$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $location_id = (int)$_POST['location_id'];
    $product_id = (int)$_POST['product_id'];
    $type = $_POST['type']; // in（入庫） または out（出庫）
    $quantity = (int)$_POST['quantity'];

    if ($quantity <= 0) {
        $error = '数量は1以上を指定してください。';
    } else {
        // 既存の在庫データを検索
        $sql = 'SELECT * FROM location_inventories WHERE location_id = :location_id AND product_id = :product_id';
        $stmt = $dbh->prepare($sql);
        $stmt->bindValue(':location_id', $location_id, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

        $success = false;

        if ($type === 'in') {
            // 【入庫処理】
            if ($inventory) {
                $sql = 'UPDATE location_inventories SET quantity = quantity + :quantity WHERE id = :id';
                $stmt = $dbh->prepare($sql);
                $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stmt->bindValue(':id', $inventory['id'], PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $sql = 'INSERT INTO location_inventories (location_id, product_id, quantity) VALUES (:location_id, :product_id, :quantity)';
                $stmt = $dbh->prepare($sql);
                $stmt->bindValue(':location_id', $location_id, PDO::PARAM_INT);
                $stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
                $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stmt->execute();
            }
            $message = '入庫が完了しました！';
            $success = true;

        } elseif ($type === 'out') {
            // 【出庫処理】
            if (!$inventory || $inventory['quantity'] < $quantity) {
                $current_qty = $inventory ? $inventory['quantity'] : 0;
                $error = "在庫が不足しています。（現在の在庫数: {$current_qty}点）";
            } else {
                $sql = 'UPDATE location_inventories SET quantity = quantity - :quantity WHERE id = :id';
                $stmt = $dbh->prepare($sql);
                $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stmt->bindValue(':id', $inventory['id'], PDO::PARAM_INT);
                $stmt->execute();
                $message = '出庫が完了しました！';
                $success = true;
            }
        }

        // 成功した場合のみ履歴（ログ）を登録
        if ($success) {
            $sql = 'INSERT INTO inventory_logs (location_id, product_id, type, quantity) VALUES (:location_id, :product_id, :type, :quantity)';
            $log_stmt = $dbh->prepare($sql);
            $log_stmt->bindValue(':location_id', $location_id, PDO::PARAM_INT);
            $log_stmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
            $log_stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $log_stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
            $log_stmt->execute();
        }
    }
}

// 拠点一覧と商品一覧を取得
$locations = $dbh->query("SELECT * FROM locations")->fetchAll(PDO::FETCH_ASSOC);
$products = $dbh->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC);

// 現在の在庫一覧を取得
$sql_inv = "SELECT li.id, l.name AS location_name, p.name AS product_name, li.quantity 
            FROM location_inventories li
            JOIN locations l ON li.location_id = l.id
            JOIN products p ON li.product_id = p.id
            ORDER BY li.id DESC";
$inventories = $dbh->query($sql_inv)->fetchAll(PDO::FETCH_ASSOC);

// 入出庫ログ一覧を取得（最新10件）
$sql_logs = "SELECT lg.id, lg.type, lg.quantity, lg.created_at, l.name AS location_name, p.name AS product_name
             FROM inventory_logs lg
             JOIN locations l ON lg.location_id = l.id
             JOIN products p ON lg.product_id = p.id
             ORDER BY lg.id DESC LIMIT 10";
$logs = $dbh->query($sql_logs)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>在庫管理（入出庫・ログ）</title>
</head>
<body>
    <h1>在庫管理・入出庫処理</h1>

    <?php if ($message): ?>
        <p style="color: green; font-weight: bold;"><?php echo $message; ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color: red; font-weight: bold;"><?php echo $error; ?></p>
    <?php endif; ?>

    <form action="" method="POST">
        <label>種別：</label>
        <select name="type">
            <option value="in">入庫 (＋)</option>
            <option value="out">出庫 (－)</option>
        </select>

        <label>拠点：</label>
        <select name="location_id" required>
            <?php foreach ($locations as $loc): ?>
                <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>

        <label>商品：</label>
        <select name="product_id" required>
            <?php foreach ($products as $prod): ?>
                <option value="<?php echo $prod['id']; ?>"><?php echo str2html($prod['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label>数量：</label>
        <input type="number" name="quantity" value="1" min="1" required>

        <button type="submit">実行する</button>
    </form>

    <hr>
    <h2>現在の拠点別在庫一覧</h2>
    <table border="1" cellpadding="5">
        <tr>
            <th>拠点名</th>
            <th>商品名</th>
            <th>在庫数</th>
        </tr>
        <?php foreach ($inventories as $inv): ?>
            <tr>
                <td><?php echo str2html($inv['location_name']); ?></td>
                <td><?php echo str2html($inv['product_name']); ?></td>
                <td><?php echo $inv['quantity']; ?> 点</td>
            </tr>
        <?php endforeach; ?>
    </table>

    <hr>
    <h2>入出庫履歴（最新10件）</h2>
    <table border="1" cellpadding="5">
        <tr>
            <th>日時</th>
            <th>種別</th>
            <th>拠点名</th>
            <th>商品名</th>
            <th>数量</th>
        </tr>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo $log['created_at']; ?></td>
                <td>
                    <?php if ($log['type'] === 'in'): ?>
                        <span style="color: blue; font-weight: bold;">入庫</span>
                    <?php else: ?>
                        <span style="color: red; font-weight: bold;">出庫</span>
                    <?php endif; ?>
                </td>
                <td><?php echo str2html($log['location_name']); ?></td>
                <td><?php echo str2html($log['product_name']); ?></td>
                <td><?php echo $log['quantity']; ?> 点</td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><a href="product.php">商品登録へ</a> | <a href="location.php">拠点登録へ</a></p>
</body>
</html>
