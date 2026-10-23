<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 検索条件を受け取る（GET）。選択肢にない値は「指定なし」として扱う
// ---------------------------------------------------------------
$name         = trim($_GET['name'] ?? '');
$categoryId   = (int)($_GET['category_id'] ?? 0);
$target       = $_GET['target'] ?? '';
$season       = $_GET['season'] ?? '';
$locationId   = (int)($_GET['location_id'] ?? 0);
$showInactive = isset($_GET['show_inactive']);

if (!array_key_exists($target, labels('target'))) {
    $target = '';
}
if (!array_key_exists($season, labels('season'))) {
    $season = '';
}

// 選択肢に使うカテゴリと拠点
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY id')->fetchAll();
$locations  = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();

// 選んだ拠点の名前（見出しに表示する）
$selectedLocationName = '';
foreach ($locations as $location) {
    if ((int)$location['id'] === $locationId) {
        $selectedLocationName = $location['name'];
    }
}

// ---------------------------------------------------------------
// 2. 検索条件に合わせて、SQL を組み立てる
// ---------------------------------------------------------------
$where  = [];   // WHERE に並べる条件
$params = [];   // 条件に渡す値（? の順番と同じ順に入れる）

// 拠点を選んだときだけ、その拠点の在庫数の列を追加する
$locationColumn = '';
if ($locationId > 0) {
    $locationColumn = ",
        COALESCE(SUM(CASE WHEN b.status <> 'in_transit' AND b.location_id = ? THEN s.qty END), 0) AS location_qty";
    $params[] = $locationId;
}

if ($name !== '') {
    $where[]  = 'p.name LIKE ?';
    $params[] = '%' . addcslashes($name, '%_\\') . '%';
}
if ($categoryId > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($target !== '') {
    $where[]  = 'p.target = ?';
    $params[] = $target;
}
if ($season !== '') {
    $where[]  = 'p.season = ?';
    $params[] = $season;
}
if (!$showInactive) {
    $where[] = "p.status = 'active'";
}

$sql = "
    SELECT p.id, p.name, p.size, p.target, p.season, p.color, p.pattern, p.status,
           c.name AS category_name,
           COALESCE(SUM(CASE WHEN b.status <> 'in_transit' THEN s.qty END), 0) AS total_qty,
           COALESCE(SUM(CASE WHEN b.status = 'in_transit' THEN s.qty END), 0)
           + (
               SELECT COALESCE(SUM(oi.qty), 0)
               FROM operation_items oi
               JOIN operations o ON o.id = oi.operation_id
               WHERE o.type = 'item_transfer'
                 AND o.status = 'in_transit'
                 AND oi.product_id = p.id
             ) AS transit_qty
           $locationColumn
    FROM products p
    JOIN categories c  ON c.id = p.category_id
    LEFT JOIN stocks s ON s.product_id = p.id
    LEFT JOIN boxes b  ON b.id = s.box_id
";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY p.id';
if ($locationId > 0) {
    $sql .= ' HAVING location_qty > 0';
}
$sql .= ' ORDER BY p.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = '商品一覧';
require __DIR__ . '/../../includes/layout/header.php';
?>

<form method="get" class="search-form">
    <div class="form-row">
        <label for="name">商品名</label>
        <input type="text" id="name" name="name" value="<?= h($name) ?>">
    </div>
    <div class="form-row">
        <label for="category_id">カテゴリ</label>
        <select id="category_id" name="category_id">
            <option value="">すべて</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= h((string)$category['id']) ?>"
                    <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>>
                    <?= h($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="target">対象</label>
        <select id="target" name="target">
            <option value="">すべて</option>
            <?php foreach (labels('target') as $value => $text): ?>
                <option value="<?= h($value) ?>" <?= $target === $value ? 'selected' : '' ?>><?= h($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="season">季節</label>
        <select id="season" name="season">
            <option value="">すべて</option>
            <?php foreach (labels('season') as $value => $text): ?>
                <option value="<?= h($value) ?>" <?= $season === $value ? 'selected' : '' ?>><?= h($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="location_id">拠点</label>
        <select id="location_id" name="location_id">
            <option value="">すべて</option>
            <?php foreach ($locations as $location): ?>
                <option value="<?= h((string)$location['id']) ?>"
                    <?= $locationId === (int)$location['id'] ? 'selected' : '' ?>>
                    <?= h($location['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label>
            <input type="checkbox" name="show_inactive" value="1" <?= $showInactive ? 'checked' : '' ?>>
            廃番も表示する
        </label>
    </div>
    <button type="submit">検索</button>
    <a href="/products/" class="button button-secondary">条件をクリア</a>
    <?php if ($user['role'] === 'admin'): ?>
        <a href="/products/edit.php" class="button">商品を登録する</a>
    <?php endif; ?>
</form>

<p><?= count($products) ?> 件</p>

<div class="table-wrap">
    <table>
        <tr>
            <th>商品コード</th>
            <th>商品名</th>
            <th>サイズ</th>
            <th>カテゴリ</th>
            <th>対象</th>
            <th>季節</th>
            <th>色</th>
            <th>柄</th>
            <?php if ($locationId > 0): ?>
                <th>在庫数<br>（<?= h($selectedLocationName) ?>）</th>
            <?php endif; ?>
            <th>在庫数<br>（全拠点）</th>
            <th>輸送中</th>
            <th>状態</th>
        </tr>
        <?php foreach ($products as $product): ?>
            <tr>
                <td>
                    <a href="/products/show.php?id=<?= h((string)$product['id']) ?>">
                        <?= h(product_code((int)$product['id'])) ?>
                    </a>
                </td>
                <td><?= h($product['name']) ?></td>
                <td><?= h($product['size']) ?></td>
                <td><?= h($product['category_name']) ?></td>
                <td><?= h(label('target', $product['target'])) ?></td>
                <td><?= h(label('season', $product['season'])) ?></td>
                <td><?= h($product['color']) ?></td>
                <td><?= h($product['pattern']) ?></td>
                <?php if ($locationId > 0): ?>
                    <td class="num"><?= h((string)$product['location_qty']) ?></td>
                <?php endif; ?>
                <td class="num"><?= h((string)$product['total_qty']) ?></td>
                <td class="num"><?= (int)$product['transit_qty'] > 0 ? h((string)$product['transit_qty']) : '' ?></td>
                <td><?= $product['status'] === 'inactive' ? '廃番' : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<p class="note">※ 在庫数は、各拠点にある数です。輸送中の商品は「輸送中」の列に表示します。</p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>