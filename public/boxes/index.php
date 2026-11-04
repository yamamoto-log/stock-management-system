<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 検索条件を受け取る（商品一覧と同じ条件＋箱一覧の条件）
// ---------------------------------------------------------------
$name        = trim($_GET['name'] ?? '');
$categoryId  = (int)($_GET['category_id'] ?? 0);
$target      = $_GET['target'] ?? '';
$season      = $_GET['season'] ?? '';
$locationId  = isset($_GET['location_id']) ? (int)$_GET['location_id'] : $user['location_id'];
$onlyEmpty   = isset($_GET['only_empty']);
$showRetired = isset($_GET['show_retired']);

if (!array_key_exists($target, labels('target'))) {
    $target = '';
}
if (!array_key_exists($season, labels('season'))) {
    $season = '';
}

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY id')->fetchAll();
$locations  = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();

// ---------------------------------------------------------------
// 2. 商品の条件をまとめる
//    {p} の部分は、使う場所に合わせて、商品のテーブルの別名に置き換える
// ---------------------------------------------------------------
$productConds  = [];
$productParams = [];

if ($name !== '') {
    $productConds[]  = '{p}.name LIKE ?';
    $productParams[] = '%' . addcslashes($name, '%_\\') . '%';
}
if ($categoryId > 0) {
    $productConds[]  = '{p}.category_id = ?';
    $productParams[] = $categoryId;
}
if ($target !== '') {
    $productConds[]  = '{p}.target = ?';
    $productParams[] = $target;
}
if ($season !== '') {
    $productConds[]  = '{p}.season = ?';
    $productParams[] = $season;
}

$hasProductFilter = !empty($productConds);

/**
 * 商品の条件を、指定した別名で組み立てる　例：product_conds($productConds, 'p2')
 */
function product_conds(array $conds, string $alias): string
{
    return str_replace('{p}', $alias, implode(' AND ', $conds));
}

// ---------------------------------------------------------------
// 3. SQL を組み立てる
//    ? の順番どおりに $params へ値を入れるため、SELECT → WHERE の順に足していく
// ---------------------------------------------------------------
$params = [];

// 商品の条件があるときだけ、該当する商品の数を集計する
$matchColumn = '';
if ($hasProductFilter) {
    $matchColumn = ',
        COALESCE(SUM(CASE WHEN ' . product_conds($productConds, 'p') . ' THEN s.qty END), 0) AS match_qty';
    $params = array_merge($params, $productParams);
}

$where = ["l.status = 'active'"];

if ($locationId > 0) {
    $where[]  = 'b.location_id = ?';
    $params[] = $locationId;
}
if (!$showRetired) {
    $where[] = "b.status <> 'retired'";
}
if ($onlyEmpty) {
    $where[] = 'b.loose_location_id IS NULL';
}
// 条件をすべて満たす商品が、1つでも入っている箱
if ($hasProductFilter) {
    $where[] = 'EXISTS (SELECT 1 FROM stocks s2
                          JOIN products p2 ON p2.id = s2.product_id
                         WHERE s2.box_id = b.id AND ' . product_conds($productConds, 'p2') . ')';
    $params = array_merge($params, $productParams);
}

$sql = "
    SELECT b.id, b.loose_location_id, b.status, b.note,
           l.name AS location_name,
           COALESCE(SUM(s.qty), 0) AS total_qty,
           GROUP_CONCAT(DISTINCT p.season
                        ORDER BY FIELD(p.season, 'summer', 'winter', 'all_season')) AS seasons,
           GROUP_CONCAT(DISTINCT c.name ORDER BY c.id SEPARATOR '・') AS category_names
           $matchColumn
    FROM boxes b
    JOIN locations l       ON l.id = b.location_id
    LEFT JOIN stocks s     ON s.box_id = b.id
    LEFT JOIN products p   ON p.id = s.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY b.id
";
if ($onlyEmpty) {
    $sql .= ' HAVING total_qty = 0';
}
$sql .= ' ORDER BY b.location_id, (b.loose_location_id IS NULL), b.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$boxes = $stmt->fetchAll();

function season_labels(?string $seasons): string
{
    if ($seasons === null || $seasons === '') {
        return '';
    }
    $names = array_map(fn($value) => label('season', $value), explode(',', $seasons));
    return implode('・', $names);
}

$pageTitle = '箱一覧';
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
        <label><input type="checkbox" name="only_empty" value="1" <?= $onlyEmpty ? 'checked' : '' ?>> 空の箱だけ表示する</label>
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="show_retired" value="1" <?= $showRetired ? 'checked' : '' ?>> 廃止した箱も表示する</label>
    </div>
    <button type="submit">検索</button>
    <a href="/boxes/" class="button button-secondary">条件をクリア</a>
</form>

<p><?= count($boxes) ?> 件</p>

<div class="table-wrap">
    <table>
        <tr>
            <th>箱ID</th>
            <th>拠点</th>
            <th>状態</th>
            <th>季節</th>
            <th>カテゴリ</th>
            <th>合計数</th>
            <?php if ($hasProductFilter): ?><th>該当数</th><?php endif; ?>
            <th>メモ</th>
        </tr>
        <?php foreach ($boxes as $box): ?>
            <?php $isLoose = $box['loose_location_id'] !== null; ?>
            <tr class="<?= $isLoose ? 'loose-row' : '' ?>">
                <td>
                    <a href="/boxes/show.php?id=<?= h((string)$box['id']) ?>">
                        <?= $isLoose ? '箱なし' : h(box_code((int)$box['id'])) ?>
                    </a>
                </td>
                <td><?= h($box['location_name']) ?></td>
                <td><?= $isLoose ? '' : h(label('box', $box['status'])) ?></td>
                <?php if ($isLoose): ?>
                    <td>ー</td>
                    <td>ー</td>
                <?php else: ?>
                    <td><?= h(season_labels($box['seasons'])) ?></td>
                    <td><?= $box['category_names'] === null ? '（空）' : h($box['category_names']) ?></td>
                <?php endif; ?>
                <td class="num"><?= h((string)$box['total_qty']) ?></td>
                <?php if ($hasProductFilter): ?>
                    <td class="num"><?= h((string)$box['match_qty']) ?></td>
                <?php endif; ?>
                <td><?= $isLoose ? '店頭など、箱に入っていない商品' : nl2br(h($box['note'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>