<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 拠点と移動元を決める
//    URL の location_id → なければ、URL の箱がある拠点 → なければ所属拠点
// ---------------------------------------------------------------
$locations     = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();
$locationNames = array_column($locations, 'name', 'id');

$startBoxId      = (int)($_GET['box_id'] ?? 0);
$startProductId  = (int)($_GET['product_id'] ?? 0);   // 商品詳細から開いたとき、この商品だけにチェック

if (isset($_GET['location_id'])) {
    $locationId = (int)$_GET['location_id'];
} elseif ($startBoxId > 0) {
    $stmt = $pdo->prepare('SELECT location_id FROM boxes WHERE id = ?');
    $stmt->execute([$startBoxId]);
    $locationId = (int)$stmt->fetchColumn();
} else {
    $locationId = $user['location_id'];
}
if (!isset($locationNames[$locationId])) {
    $locationId = $user['location_id'];
}

$storages = storage_options($pdo, $locationId);   // [箱ID => '箱なし' / 'BOX-0004（メモ）']
$fromBoxId = isset($storages[$startBoxId]) ? $startBoxId : (int)array_key_first($storages);

// 移動先の候補（移動元は除く）
$destinations = $storages;
unset($destinations[$fromBoxId]);

// 移動元の中身
$products = product_options($pdo);
$stmt = $pdo->prepare('SELECT product_id, qty FROM stocks WHERE box_id = ? ORDER BY product_id');
$stmt->execute([$fromBoxId]);
$items = [];
foreach ($stmt->fetchAll() as $row) {
    $items[(int)$row['product_id']] = (int)$row['qty'];   // [商品ID => 残数]
}

$formKey = 'relocation_' . $fromBoxId;

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_relocation(array $input, array $items, array $destinations): array
{
    $errors = ['line' => []];

    if (empty($input['lines'])) {
        $errors['lines'] = '移す商品を1つ以上選んでください。';
    }
    foreach ($input['lines'] as $productId => $qty) {
        if (!isset($items[$productId])) {
            $errors['line'][$productId] = 'この商品は移動元にありません。';
        } elseif (!ctype_digit($qty) || (int)$qty < 1) {
            $errors['line'][$productId] = '移す数は1以上の整数で入力してください。';
        } elseif ((int)$qty > $items[$productId]) {
            $errors['line'][$productId] = '在庫が足りません（残り ' . $items[$productId] . '）。';
        }
    }

    if ($input['dest_type'] === 'existing') {
        if (!isset($destinations[(int)$input['to_box_id']])) {
            $errors['to_box_id'] = '移動先を選択してください。';
        }
    } elseif ($input['dest_type'] === 'new') {
        if (mb_strlen($input['new_note']) > 500) {
            $errors['new_note'] = 'メモは500文字以内で入力してください。';
        }
    } else {
        $errors['dest_type'] = '移動先の種類を選択してください。';
    }

    if (empty($errors['line'])) {
        unset($errors['line']);
    }
    return $errors;
}

// ---------------------------------------------------------------
// 3. step で処理を切り替える
// ---------------------------------------------------------------
$step        = $_POST['step'] ?? '';
$errors      = [];
$commitError = '';
$showConfirm = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    form_clear($formKey);
    $checked = [];
    foreach ($items as $productId => $qty) {
        // 商品詳細から開いたときはその商品だけ、それ以外はすべてにチェック
        if ($startProductId === 0 || $startProductId === $productId) {
            $checked[$productId] = true;
        }
    }
    $input = [
        'lines'     => array_map('strval', array_intersect_key($items, $checked)),
        'dest_type' => 'existing',
        'to_box_id' => (string)array_key_first($destinations),
        'new_note'  => '',
    ];
    $qtyInputs = array_map('strval', $items);   // 移す数の初期値は残数

} else {
    verify_csrf();

    if ($step === 'confirm') {
        $postChecks = $_POST['check'] ?? [];
        $postQtys   = $_POST['qty'] ?? [];

        $lines = [];
        foreach ($postChecks as $productId => $on) {
            $lines[(int)$productId] = trim((string)($postQtys[$productId] ?? ''));
        }
        $input = [
            'lines'     => $lines,
            'dest_type' => $_POST['dest_type'] ?? '',
            'to_box_id' => (string)($_POST['to_box_id'] ?? ''),
            'new_note'  => trim($_POST['new_note'] ?? ''),
        ];
        // 入力画面に戻したとき、チェックしていない行の数も残す
        $qtyInputs = array_map('strval', $items);
        foreach ($postQtys as $productId => $qty) {
            $qtyInputs[(int)$productId] = trim((string)$qty);
        }

        $errors = validate_relocation($input, $items, $destinations);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        }
    } elseif ($step === 'back') {
        $input     = form_load($formKey) ?? ['lines' => [], 'dest_type' => 'existing', 'to_box_id' => '', 'new_note' => ''];
        $qtyInputs = array_map('strval', $items);
        foreach ($input['lines'] as $productId => $qty) {
            $qtyInputs[$productId] = $qty;
        }
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            set_flash('error', '確認中の内容が見つかりませんでした。別の画面で同じ操作をしたか、時間がたって無効になった可能性があります。もう一度入力してください。');
            redirect($_SERVER['REQUEST_URI']);
        }

        $isNewBox = $input['dest_type'] === 'new';
        $toBoxId  = 0;

        try {
            $pdo->beginTransaction();

            // 新しい箱を作る
            if ($isNewBox) {
                $pdo->prepare('INSERT INTO boxes (location_id, note) VALUES (?, ?)')
                    ->execute([$locationId, null_if_empty($input['new_note'])]);
                $toBoxId = (int)$pdo->lastInsertId();
            } else {
                $toBoxId = (int)$input['to_box_id'];
            }

            // 2つの箱を、番号の小さい順にロックする（デッドロックを防ぐ）
            $boxIds = [$fromBoxId, $toBoxId];
            sort($boxIds);
            foreach ($boxIds as $boxId) {
                lock_box($pdo, $boxId, $locationId);
            }

            $operationId = create_operation($pdo, [
                'type'             => 'relocation',
                'user_id'          => $user['id'],
                'from_location_id' => $locationId,
                'to_location_id'   => $locationId,
            ]);

            foreach ($input['lines'] as $productId => $qty) {
                $qty  = (int)$qty;
                $name = ($products[$productId] ?? product_code($productId));
                remove_stock($pdo, $fromBoxId, $productId, $qty, $name);
                add_stock($pdo, $toBoxId, $productId, $qty);
                add_operation_item($pdo, $operationId, $productId, $qty, $fromBoxId, $toBoxId);
            }

            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            $commitError = $e->getMessage();
            $showConfirm = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($commitError === '') {
            form_clear($formKey);
            if ($isNewBox) {
                $code = box_code($toBoxId);
                set_flash('success', "保管場所を変更しました。新しい箱 {$code} を作りました。ラベルに {$code} と書いてください。");
            } else {
                set_flash('success', '保管場所を変更しました。');
            }
            redirect('/boxes/show.php?id=' . $toBoxId);
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

$isOtherLocation = $locationId !== $user['location_id'];

$pageTitle = '保管場所変更';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($showConfirm): ?>
    <!-- ========== 確認画面 ========== -->
    <?php if ($commitError !== ''): ?>
        <p class="flash flash-error"><?= h($commitError) ?></p>
    <?php endif; ?>

    <?php if ($isOtherLocation): ?>
        <p class="warning-box">
            所属拠点（<?= h($locationNames[$user['location_id']] ?? '') ?>）以外の拠点
            （<?= h($locationNames[$locationId]) ?>）の保管場所を変更します。拠点が正しいか確認してください。
        </p>
    <?php endif; ?>

    <p>次の内容で保管場所を変更します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>拠点</th>
                <td><?= h($locationNames[$locationId]) ?></td>
            </tr>
            <tr>
                <th>移動元</th>
                <td><?= h($storages[$fromBoxId] ?? '') ?></td>
            </tr>
            <tr>
                <th>移動先</th>
                <td>
                    <?php if ($input['dest_type'] === 'new'): ?>
                        新しい箱を作る（番号は登録後に表示されます）
                        <?php if ($input['new_note'] !== ''): ?><br>メモ：<?= nl2br(h($input['new_note'])) ?><?php endif; ?>
                        <?php else: ?>
                            <?= h($destinations[(int)$input['to_box_id']] ?? '') ?>
                        <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="table-wrap">
        <table>
            <tr>
                <th>商品</th>
                <th>移す数</th>
                <th>移動元の残数<br>（変更前 → 後）</th>
            </tr>
            <?php foreach ($input['lines'] as $productId => $qty): ?>
                <tr>
                    <td><?= h($products[$productId] ?? product_code($productId)) ?></td>
                    <td class="num"><?= h($qty) ?></td>
                    <td class="num"><?= h((string)($items[$productId] ?? 0)) ?> → <?= h((string)(($items[$productId] ?? 0) - (int)$qty)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit">変更する</button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->
    <form method="get" class="search-form">
        <div class="form-row">
            <label for="location_id">拠点</label>
            <select id="location_id" name="location_id">
                <?php foreach ($locations as $location): ?>
                    <option value="<?= h((string)$location['id']) ?>" <?= $locationId === (int)$location['id'] ? 'selected' : '' ?>>
                        <?= h($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="box_id">移動元</label>
            <select id="box_id" name="box_id">
                <?php foreach ($storages as $id => $text): ?>
                    <option value="<?= h((string)$id) ?>" <?= $id === $fromBoxId ? 'selected' : '' ?>><?= h($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button-secondary">切り替える</button>
        <span class="note">※ 拠点を変えたときは、移動元を選び直してから、もう一度「切り替える」を押してください。</span>
    </form>

    <?php if (empty($items)): ?>
        <p>この保管場所には、移せる商品がありません。</p>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <p class="flash flash-error">入力内容に誤りがあります。</p>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <h2>移す商品</h2>
            <?= field_error($errors, 'lines') ?>

            <div class="table-wrap">
                <table>
                    <tr>
                        <th><label><input type="checkbox" id="check-all"> すべて</label></th>
                        <th>商品</th>
                        <th>残数</th>
                        <th>移す数</th>
                    </tr>
                    <?php foreach ($items as $productId => $remain): ?>
                        <tr>
                            <td><input type="checkbox" class="line-check" name="check[<?= h((string)$productId) ?>]" value="1"
                                    <?= isset($input['lines'][$productId]) ? 'checked' : '' ?>></td>
                            <td><?= h($products[$productId] ?? product_code($productId)) ?></td>
                            <td class="num"><?= h((string)$remain) ?></td>
                            <td>
                                <input type="number" name="qty[<?= h((string)$productId) ?>]" min="1" max="<?= h((string)$remain) ?>"
                                    value="<?= h($qtyInputs[$productId] ?? (string)$remain) ?>" style="width: 6em;">
                                <?php if (isset($errors['line'][$productId])): ?>
                                    <p class="field-error"><?= h($errors['line'][$productId]) ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <h2>移動先</h2>
            <?= field_error($errors, 'dest_type') ?>
            <div class="form-row">
                <label>
                    <input type="radio" name="dest_type" value="existing" style="width:auto;"
                        <?= $input['dest_type'] === 'existing' ? 'checked' : '' ?> <?= empty($destinations) ? 'disabled' : '' ?>>
                    既存の保管場所
                </label>
                <select name="to_box_id">
                    <?php foreach ($destinations as $id => $text): ?>
                        <option value="<?= h((string)$id) ?>" <?= (string)$id === $input['to_box_id'] ? 'selected' : '' ?>><?= h($text) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'to_box_id') ?>
            </div>
            <div class="form-row">
                <label>
                    <input type="radio" name="dest_type" value="new" style="width:auto;"
                        <?= $input['dest_type'] === 'new' ? 'checked' : '' ?>>
                    新しい箱を作る
                </label>
                <textarea name="new_note" rows="2" placeholder="メモ（例：夏物の浴衣。倉庫の奥の棚）"><?= h($input['new_note']) ?></textarea>
                <?= field_error($errors, 'new_note') ?>
            </div>

            <p class="button-row">
                <button type="submit" name="step" value="confirm">確認する</button>
            </p>
        </form>

        <script>
            // 「すべて」のチェックで、全部の商品のチェックを切り替える
            const checkAll = document.getElementById('check-all');
            const checks = document.querySelectorAll('.line-check');
            checkAll.checked = [...checks].every((c) => c.checked);
            checkAll.addEventListener('change', () => {
                checks.forEach((c) => {
                    c.checked = checkAll.checked;
                });
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>