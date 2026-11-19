<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo   = getDb();
$user  = current_user();
$myLoc = $user['location_id'];

// ---------------------------------------------------------------
// 1. 検索条件
//    URL に条件がない（メニューから開いた）ときだけ、利用者ごとの初期値を使う
// ---------------------------------------------------------------
$relations = [
    ''         => 'すべて',
    'mine'     => '自拠点に関係する',
    'incoming' => '自拠点が送り先',
    'outgoing' => '自拠点が送り元',
];

if (!isset($_GET['status']) && !isset($_GET['relation'])) {
    // 初期値：管理者はすべて、一般は自拠点に関係する輸送中
    $status   = $user['role'] === 'admin' ? '' : 'in_transit';
    $relation = $user['role'] === 'admin' ? '' : 'mine';
} else {
    $status   = $_GET['status'] ?? '';
    $relation = $_GET['relation'] ?? '';
}
if (!array_key_exists($status, labels('transfer'))) {
    $status = '';
}
if (!array_key_exists($relation, $relations)) {
    $relation = '';
}

// ---------------------------------------------------------------
// 2. 一覧を取り出す（今は商品移動だけ。箱ごと移動は Issue 12 で追加）
// ---------------------------------------------------------------
$where  = ["o.type IN ('item_transfer', 'box_transfer')"];
$params = [];

if ($status !== '') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($relation === 'mine') {
    $where[]  = '(o.from_location_id = ? OR o.to_location_id = ?)';
    $params[] = $myLoc;
    $params[] = $myLoc;
} elseif ($relation === 'incoming') {
    $where[]  = 'o.to_location_id = ?';
    $params[] = $myLoc;
} elseif ($relation === 'outgoing') {
    $where[]  = 'o.from_location_id = ?';
    $params[] = $myLoc;
}

$stmt = $pdo->prepare(
    'SELECT o.id, o.type, o.created_at, o.status, o.from_location_id, o.to_location_id,
            fl.name AS from_name, tl.name AS to_name, u.name AS user_name
     FROM operations o
     JOIN locations fl ON fl.id = o.from_location_id
     JOIN locations tl ON tl.id = o.to_location_id
     JOIN users u      ON u.id  = o.user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY o.id DESC
     LIMIT 200'
);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

// 各移動の内容（「訪問着 ×3 ほか1件」「BOX-0004 ほか1箱」）
$summaries = transfer_summaries($pdo, array_column($transfers, 'id'));

// 所属拠点の受取待ちの件数
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM operations
     WHERE type IN ('item_transfer', 'box_transfer') AND status = 'in_transit' AND to_location_id = ?"
);
$stmt->execute([$myLoc]);
$waitingCount = (int)$stmt->fetchColumn();

$pageTitle = '移動一覧';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($waitingCount > 0): ?>
    <p class="warning-box">
        受取待ちが <strong><?= h((string)$waitingCount) ?> 件</strong> あります。
        <a href="/transfers/?status=in_transit&relation=incoming">受取待ちを表示する</a>
    </p>
<?php endif; ?>

<form method="get" class="search-form">
    <div class="form-row">
        <label for="status">状態</label>
        <select id="status" name="status">
            <option value="">すべて</option>
            <?php foreach (labels('transfer') as $value => $text): ?>
                <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="relation">自拠点との関係</label>
        <select id="relation" name="relation">
            <?php foreach ($relations as $value => $text): ?>
                <option value="<?= h($value) ?>" <?= $relation === $value ? 'selected' : '' ?>><?= h($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit">検索</button>
    <a href="/transfers/?status=&relation=" class="button button-secondary">すべて表示</a>
    <a href="/operations/item_transfer.php" class="button">商品を発送する</a>
    <a href="/operations/box_transfer.php" class="button">箱を発送する</a>
</form>

<p><?= count($transfers) ?> 件<?= count($transfers) === 200 ? '（新しい200件まで表示）' : '' ?></p>

<?php if (empty($transfers)): ?>
    <p>該当する移動はありません。</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>移動</th>
                <th>種類</th>
                <th>発送日時</th>
                <th>送り元 → 送り先</th>
                <th>内容</th>
                <th>状態</th>
                <th>発送者</th>
            </tr>
            <?php foreach ($transfers as $t): ?>
                <?php
                $isIncoming = $t['status'] === 'in_transit' && (int)$t['to_location_id'] === $myLoc;
                ?>
                <tr class="<?= $t['status'] === 'cancelled' ? 'is-inactive' : '' ?>">
                    <td><a href="/transfers/show.php?id=<?= h((string)$t['id']) ?>">#<?= h((string)$t['id']) ?></a></td>
                    <td><?= $t['type'] === 'box_transfer' ? '箱ごと' : '商品' ?></td>
                    <td><?= h(date('Y/m/d H:i', strtotime($t['created_at']))) ?></td>
                    <td><?= h($t['from_name']) ?> → <?= h($t['to_name']) ?></td>
                    <td>
                        <a href="/transfers/show.php?id=<?= h((string)$t['id']) ?>"><?= h($summaries[(int)$t['id']] ?? '') ?></a>
                    </td>
                    <td>
                        <?= h(label('transfer', $t['status'])) ?>
                        <?php if ($isIncoming): ?><br><strong class="emphasis-note">受取待ち</strong><?php endif; ?>
                    </td>
                    <td><?= h($t['user_name']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>