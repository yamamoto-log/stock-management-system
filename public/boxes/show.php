<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$pdo = getDb();

// ---------------------------------------------------------------
// 1. 箱を取り出す
// ---------------------------------------------------------------
$boxId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT b.*, l.name AS location_name
     FROM boxes b
     JOIN locations l ON l.id = b.location_id
     WHERE b.id = ?'
);
$stmt->execute([$boxId]);
$box = $stmt->fetch();

if ($box === false) {
    http_response_code(404);
    $pageTitle = '箱が見つかりません';
    require __DIR__ . '/../../includes/layout/header.php';
    echo '<p>指定された箱は存在しません。</p>';
    echo '<p><a href="/boxes/">箱一覧に戻る</a></p>';
    require __DIR__ . '/../../includes/layout/footer.php';
    exit;
}

$isLoose = $box['loose_location_id'] !== null;
$backTo  = '/boxes/show.php?id=' . $boxId;

// ---------------------------------------------------------------
// 2. メモの編集・廃止（POST で受け付ける。箱なしでは受け付けない）
// ---------------------------------------------------------------
$confirmRetire = false;
$memoError     = '';
$memoInput     = $box['note'] ?? '';
$showMemoForm  = isset($_GET['edit_memo']);   // 「メモを編集する」を押したとき

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';
    $step   = $_POST['step'] ?? '';

    if ($isLoose || !in_array($action, ['memo', 'retire'], true)) {
        redirect($backTo);
    }

    if ($action === 'memo') {
        // メモの保存（確認画面は挟まない）
        $memoInput = trim($_POST['note'] ?? '');
        if ($box['status'] === 'retired') {
            set_flash('error', '廃止した箱のメモは編集できません。');
            redirect($backTo);
        }
        if (mb_strlen($memoInput) > 500) {
            $memoError    = 'メモは500文字以内で入力してください。';
            $showMemoForm = true;   // 入力欄を開いたまま、エラーを表示する
        } else {
            $pdo->prepare('UPDATE boxes SET note = ? WHERE id = ?')
                ->execute([null_if_empty($memoInput), $boxId]);
            set_flash('success', 'メモを保存しました。');
            redirect($backTo);
        }
    } elseif ($action === 'retire' && $step === 'confirm') {
        $confirmRetire = true;
    } elseif ($action === 'retire' && $step === 'commit') {
        try {
            $pdo->beginTransaction();

            // 箱の行をロックしてから、状態と中身を確かめる
            $stmt = $pdo->prepare('SELECT status FROM boxes WHERE id = ? FOR UPDATE');
            $stmt->execute([$boxId]);
            $status = $stmt->fetchColumn();

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM stocks WHERE box_id = ?');
            $stmt->execute([$boxId]);
            $stockRows = (int)$stmt->fetchColumn();

            if ($status !== 'active') {
                $pdo->rollBack();
                set_flash('error', '使用中の箱だけ廃止できます。');
            } elseif ($stockRows > 0) {
                $pdo->rollBack();
                set_flash('error', '中身が入っているため廃止できません。');
            } else {
                $pdo->prepare("UPDATE boxes SET status = 'retired' WHERE id = ?")->execute([$boxId]);
                $pdo->commit();
                set_flash('success', '箱を廃止しました。');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        redirect($backTo);
    } else {
        redirect($backTo);
    }
}

// ---------------------------------------------------------------
// 3. 中身
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.size, p.color, p.pattern, p.season, c.name AS category_name, s.qty
     FROM stocks s
     JOIN products p   ON p.id = s.product_id
     JOIN categories c ON c.id = p.category_id
     WHERE s.box_id = ?
     ORDER BY p.id'
);
$stmt->execute([$boxId]);
$items = $stmt->fetchAll();
$total = array_sum(array_column($items, 'qty'));

$isActive  = $box['status'] === 'active';
$isEmpty   = empty($items);
$boxTitle  = $isLoose ? $box['location_name'] . 'の箱なし' : box_code($boxId);

$pageTitle = '箱詳細';
require __DIR__ . '/../../includes/layout/header.php';
?>

<h2><?= h($boxTitle) ?></h2>

<?php if ($confirmRetire): ?>
    <div class="confirm-box">
        <p>この箱を廃止しますか？　廃止すると、保管場所の選択肢に表示されなくなります。</p>
        <form method="post" class="button-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="retire">
            <button type="submit" name="step" value="commit">廃止する</button>
            <a href="<?= h($backTo) ?>" class="button button-secondary">キャンセル</a>
        </form>
    </div>
<?php endif; ?>

<div class="table-wrap">
    <table class="detail-table">
        <tr>
            <th>拠点</th>
            <td><?= h($box['location_name']) ?></td>
        </tr>
        <?php if ($isLoose): ?>
            <tr>
                <th>保管場所</th>
                <td>店頭など、箱に入っていない商品</td>
            </tr>
        <?php else: ?>
            <tr>
                <th>状態</th>
                <td><?= h(label('box', $box['status'])) ?></td>
            </tr>
            <tr>
                <th>メモ</th>
                <td>
                    <?php if ($showMemoForm && $box['status'] !== 'retired'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="memo">
                            <textarea name="note" rows="3"><?= h($memoInput) ?></textarea>
                            <?php if ($memoError !== ''): ?>
                                <p class="field-error"><?= h($memoError) ?></p>
                            <?php endif; ?>
                            <p class="button-row">
                                <button type="submit">保存する</button>
                                <a href="<?= h($backTo) ?>" class="button button-secondary">キャンセル</a>
                            </p>
                        </form>
                    <?php else: ?>
                        <?= nl2br(h($box['note'])) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>作成日</th>
                <td><?= h(date('Y/m/d', strtotime($box['created_at']))) ?></td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<p class="button-row">
    <?php if ($isActive && !$isEmpty): ?>
        <a href="/operations/relocation.php?box_id=<?= h((string)$boxId) ?>" class="button">保管場所変更</a>
    <?php endif; ?>
    <?php if (!$isLoose && $isActive): ?>
        <a href="/operations/box_transfer.php?box_id=<?= h((string)$boxId) ?>" class="button">箱ごと移動</a>
    <?php endif; ?>
    <?php if (!$isLoose && $box['status'] !== 'retired' && !$showMemoForm): ?>
        <a href="<?= h($backTo) ?>&edit_memo=1" class="button button-secondary">メモを編集する</a>
    <?php endif; ?>
    <?php if (!$isLoose && $isActive && $isEmpty): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="retire">
    <button type="submit" name="step" value="confirm" class="button-secondary">廃止する</button>
</form>
<?php endif; ?>
</p>

<h2>中身</h2>

<?php if ($isEmpty): ?>
    <p>この箱は空です。</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <tr>
                <th>商品コード</th>
                <th>商品名</th>
                <th>サイズ</th>
                <th>カテゴリ</th>
                <th>季節</th>
                <th>色</th>
                <th>柄</th>
                <th>数量</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <a href="/products/show.php?id=<?= h((string)$item['id']) ?>">
                            <?= h(product_code((int)$item['id'])) ?>
                        </a>
                    </td>
                    <td><?= h($item['name']) ?></td>
                    <td><?= h($item['size']) ?></td>
                    <td><?= h($item['category_name']) ?></td>
                    <td><?= h(label('season', $item['season'])) ?></td>
                    <td><?= h($item['color']) ?></td>
                    <td><?= h($item['pattern']) ?></td>
                    <td class="num"><?= h((string)$item['qty']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="subtotal-row">
                <td colspan="7">合計</td>
                <td class="num"><?= h((string)$total) ?></td>
            </tr>
        </table>
    </div>
<?php endif; ?>

<p><a href="/boxes/">箱一覧に戻る</a></p>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>