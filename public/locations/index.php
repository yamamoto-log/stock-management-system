<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin(); // 管理者専用

$pdo = getDb();

// ---------------------------------------------------------------
// 停止・再開（POST で受け付ける）
// ---------------------------------------------------------------
$confirmAction     = null;   // 確認の表示をする操作（stop / restart）
$confirmLocationId = 0;      // 確認の表示をする拠点

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action     = $_POST['action'] ?? '';
    $step       = $_POST['step'] ?? '';
    $locationId = (int)($_POST['location_id'] ?? 0);

    if (!in_array($action, ['stop', 'restart'], true) || $locationId <= 0) {
        redirect('/locations/');
    }

    if ($step === 'confirm') {
        $confirmAction     = $action;
        $confirmLocationId = $locationId;
    } elseif ($step === 'commit' && $action === 'stop') {
        // 停止の条件を1つずつ確かめる
        $stmt = $pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM operations
                 WHERE status = 'in_transit'
                   AND (from_location_id = ? OR to_location_id = ?)) AS transit_count,
               (SELECT COUNT(*) FROM stocks s
                  JOIN boxes b ON b.id = s.box_id
                 WHERE b.location_id = ?) AS stock_rows,
               (SELECT COUNT(*) FROM boxes
                 WHERE location_id = ? AND loose_location_id IS NULL
                   AND status IN ('active', 'in_transit')) AS box_count,
               (SELECT COUNT(*) FROM users
                 WHERE location_id = ? AND status = 'active') AS user_count"
        );
        $stmt->execute([$locationId, $locationId, $locationId, $locationId, $locationId]);
        $check = $stmt->fetch();

        if ((int)$check['transit_count'] > 0) {
            set_flash('error', '輸送中の移動があるため停止できません。');
        } elseif ((int)$check['stock_rows'] > 0) {
            set_flash('error', '在庫が残っているため停止できません。');
        } elseif ((int)$check['box_count'] > 0) {
            set_flash('error', '箱が残っているため停止できません（先に箱を廃止するか、別の拠点へ移動してください）。');
        } elseif ((int)$check['user_count'] > 0) {
            set_flash('error', '所属しているユーザーがいるため停止できません。');
        } else {
            $pdo->prepare("UPDATE locations SET status = 'inactive' WHERE id = ?")->execute([$locationId]);
            set_flash('success', '拠点を停止しました。');
        }
        redirect('/locations/');
    } elseif ($step === 'commit' && $action === 'restart') {
        $pdo->prepare("UPDATE locations SET status = 'active' WHERE id = ?")->execute([$locationId]);
        set_flash('success', '拠点を再開しました。');
        redirect('/locations/');
    } else {
        redirect('/locations/');
    }
}

// ---------------------------------------------------------------
// 拠点の一覧（在庫数・箱の数・所属ユーザーの数つき）
// ---------------------------------------------------------------
$locations = $pdo->query(
    "SELECT l.id, l.name, l.note, l.status,
       COALESCE((SELECT SUM(s.qty) FROM stocks s
                   JOIN boxes b ON b.id = s.box_id
                  WHERE b.location_id = l.id AND b.status <> 'in_transit'), 0) AS stock_qty,
       (SELECT COUNT(*) FROM boxes b
         WHERE b.location_id = l.id AND b.loose_location_id IS NULL
           AND b.status = 'active') AS box_count,
       (SELECT COUNT(*) FROM users u
         WHERE u.location_id = l.id AND u.status = 'active') AS user_count
     FROM locations l
     ORDER BY l.id"
)->fetchAll();

// 確認の表示に使う拠点名
$confirmLocationName = '';
foreach ($locations as $location) {
    if ((int)$location['id'] === $confirmLocationId) {
        $confirmLocationName = $location['name'];
    }
}

$pageTitle = '拠点管理';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($confirmAction !== null): ?>
    <div class="confirm-box">
        <p>
            「<?= h($confirmLocationName) ?>」を
            <?= $confirmAction === 'stop'
                ? '停止しますか？　停止すると、入庫先や移動先の選択肢に表示されなくなります。'
                : '再開しますか？' ?>
        </p>
        <form method="post" class="button-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= h($confirmAction) ?>">
            <input type="hidden" name="location_id" value="<?= h((string)$confirmLocationId) ?>">
            <button type="submit" name="step" value="commit">
                <?= $confirmAction === 'stop' ? '停止する' : '再開する' ?>
            </button>
            <a href="/locations/" class="button button-secondary">キャンセル</a>
        </form>
    </div>
<?php endif; ?>

<p class="button-row">
    <a href="/locations/edit.php" class="button">拠点を登録する</a>
</p>

<div class="table-wrap">
    <table>
        <tr>
            <th>拠点名</th>
            <th>状態</th>
            <th>在庫数</th>
            <th>箱の数</th>
            <th>所属<br>ユーザー</th>
            <th>備考</th>
            <th>操作</th>
        </tr>
        <?php foreach ($locations as $location): ?>
            <?php $isActive = $location['status'] === 'active'; ?>
            <tr class="<?= $isActive ? '' : 'is-inactive' ?>">
                <td><?= h($location['name']) ?></td>
                <td><?= h(label('status', $location['status'])) ?></td>
                <td class="num"><?= h((string)$location['stock_qty']) ?></td>
                <td class="num"><?= h((string)$location['box_count']) ?></td>
                <td class="num"><?= h((string)$location['user_count']) ?></td>
                <td><?= nl2br(h($location['note'])) ?></td>
                <td>
                    <div class="button-row">
                        <a href="/locations/edit.php?id=<?= h((string)$location['id']) ?>" class="button button-secondary">編集する</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $isActive ? 'stop' : 'restart' ?>">
                            <input type="hidden" name="location_id" value="<?= h((string)$location['id']) ?>">
                            <button type="submit" name="step" value="confirm" class="button-secondary">
                                <?= $isActive ? '停止する' : '再開する' ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<p class="note">※ 在庫数・箱の数・所属ユーザーがすべて0の拠点だけ停止できます。在庫数に、輸送中の商品は含みません。</p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>