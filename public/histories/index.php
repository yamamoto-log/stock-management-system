<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo     = getDb();
$user    = current_user();
$perPage = 50;

// ---------------------------------------------------------------
// 1. 検索条件
//    URL に location_id がない（メニューから開いた）ときは所属拠点。空なら「すべて」
// ---------------------------------------------------------------
// 停止中の拠点も選択肢に出す（過去の操作を調べられるように）
$locationNames = array_column(
    $pdo->query('SELECT id, name FROM locations ORDER BY id')->fetchAll(),
    'name',
    'id'
);

if (!isset($_GET['location_id'])) {
    // 管理者はすべて、一般は所属拠点
    $locationId = $user['role'] === 'admin' ? null : $user['location_id'];
} elseif ($_GET['location_id'] === '') {
    $locationId = null;   // すべて
} else {
    $locationId = (int)$_GET['location_id'];
    if (!isset($locationNames[$locationId])) {
        $locationId = null;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));

// ---------------------------------------------------------------
// 2. 出来事の一覧を作る SQL
//    操作（発送を含む）・受取・取消を、それぞれ取り出して UNION ALL でつなげる
// ---------------------------------------------------------------
$locationWhere = $locationId !== null ? ' AND (o.from_location_id = ? OR o.to_location_id = ?)' : '';

$eventsSql = "
    SELECT o.id AS operation_id, o.type, 'done' AS event, 0 AS event_order,
           o.created_at AS event_at, o.user_id AS actor_id
      FROM operations o
     WHERE 1 = 1 $locationWhere
    UNION ALL
    SELECT o.id, o.type, 'received', 1, o.received_at, o.received_by
      FROM operations o
     WHERE o.received_at IS NOT NULL $locationWhere
    UNION ALL
    SELECT o.id, o.type, 'cancelled', 1, o.cancelled_at, o.cancelled_by
      FROM operations o
     WHERE o.cancelled_at IS NOT NULL $locationWhere
";
// ? は3か所 × 2個。すべてのときは0個
$params = $locationId !== null ? array_fill(0, 6, $locationId) : [];

// 件数とページ数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ($eventsSql) e");
$stmt->execute($params);
$total      = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// このページの出来事（拠点名・操作者・理由をつなげる）
$stmt = $pdo->prepare(
    "SELECT e.*, u.name AS actor_name,
            o.from_location_id, o.to_location_id, o.reason, o.note,
            fl.name AS from_name, tl.name AS to_name
       FROM ($eventsSql) e
       JOIN operations o      ON o.id  = e.operation_id
       JOIN users u           ON u.id  = e.actor_id
       LEFT JOIN locations fl ON fl.id = o.from_location_id
       LEFT JOIN locations tl ON tl.id = o.to_location_id
      ORDER BY e.event_at DESC, e.operation_id DESC, e.event_order DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$events = $stmt->fetchAll();

// 内容の要約（商品の明細・箱の明細のどちらでも使える）
$summaries = transfer_summaries($pdo, array_column($events, 'operation_id'));

// ---------------------------------------------------------------
// 3. 表示用の小さな関数
// ---------------------------------------------------------------
function event_name(string $type, string $event): string
{
    $kind = $type === 'box_transfer' ? '（箱ごと）' : '（商品）';
    if ($event === 'received') {
        return '受取' . $kind;
    }
    if ($event === 'cancelled') {
        return '取消' . $kind;
    }
    return [
        'stock_in'      => '入庫',
        'stock_out'     => '出庫',
        'adjustment'    => '在庫調整',
        'relocation'    => '保管場所変更',
        'item_transfer' => '発送（商品）',
        'box_transfer'  => '発送（箱ごと）',
    ][$type] ?? $type;
}

function is_transfer(string $type): bool
{
    return in_array($type, ['item_transfer', 'box_transfer'], true);
}

/** ページのリンク（拠点の条件を引き継ぐ） */
function page_url(?int $locationId, int $page): string
{
    return '/histories/?' . http_build_query([
        'location_id' => $locationId ?? '',
        'page'        => $page,
    ]);
}

$pageTitle = '履歴';
require __DIR__ . '/../../includes/layout/header.php';
?>

<form method="get" class="search-form">
    <div class="form-row">
        <label for="location_id">拠点</label>
        <select id="location_id" name="location_id">
            <option value="">すべて</option>
            <?php foreach ($locationNames as $id => $name): ?>
                <option value="<?= h((string)$id) ?>" <?= $locationId === $id ? 'selected' : '' ?>><?= h($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit">検索</button>
</form>

<p>
    <?= h((string)$total) ?> 件
    <?php if ($total > 0): ?>
        （<?= h((string)($offset + 1)) ?>〜<?= h((string)min($offset + $perPage, $total)) ?> 件目を表示）
    <?php endif; ?>
</p>

<?php if (empty($events)): ?>
    <p>該当する履歴はありません。</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>日時</th>
                <th>操作</th>
                <th>操作者</th>
                <th>拠点</th>
                <th>内容</th>
                <th>理由・備考</th>
            </tr>
            <?php foreach ($events as $e): ?>
                <?php
                $opId = (int)$e['operation_id'];
                $url  = is_transfer($e['type']) ? '/transfers/show.php?id=' . $opId : '/histories/show.php?id=' . $opId;

                // 移動は「送り元 → 送り先」、それ以外は拠点名
                $place = is_transfer($e['type'])
                    ? $e['from_name'] . ' → ' . $e['to_name']
                    : ($e['from_name'] ?? $e['to_name'] ?? '');

                // 理由・備考は、発送などの元の操作の行にだけ出す（受取・取消の行では繰り返さない）
                $memo = '';
                if ($e['event'] === 'done') {
                    $parts = [];
                    if ($e['reason'] !== null) {
                        $parts[] = label('reason', $e['reason']);
                    }
                    if ($e['note'] !== null && $e['note'] !== '') {
                        $parts[] = mb_strimwidth($e['note'], 0, 40, '…');
                    }
                    $memo = implode('：', $parts);
                }
                ?>
                <tr class="<?= $e['event'] === 'cancelled' ? 'is-inactive' : '' ?>">
                    <td><?= h(date('Y/m/d H:i', strtotime($e['event_at']))) ?></td>
                    <td><a href="<?= h($url) ?>"><?= h(event_name($e['type'], $e['event'])) ?> #<?= h((string)$opId) ?></a></td>
                    <td><?= h($e['actor_name']) ?></td>
                    <td><?= h($place) ?></td>
                    <td><a href="<?= h($url) ?>"><?= h($summaries[$opId] ?? '') ?></a></td>
                    <td><?= h($memo) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <p class="button-row">
            <?php if ($page > 1): ?>
                <a href="<?= h(page_url($locationId, $page - 1)) ?>" class="button button-secondary">← 前へ</a>
            <?php endif; ?>
            <span><?= h((string)$page) ?> / <?= h((string)$totalPages) ?> ページ</span>
            <?php if ($page < $totalPages): ?>
                <a href="<?= h(page_url($locationId, $page + 1)) ?>" class="button button-secondary">次へ →</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>