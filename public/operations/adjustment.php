<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 拠点と、増やすか減らすかを決める
// ---------------------------------------------------------------
$locations     = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();
$locationNames = array_column($locations, 'name', 'id');

$locationId = (int)($_GET['location_id'] ?? $user['location_id']);
if (!isset($locationNames[$locationId])) {
    $locationId = $user['location_id'];
}

$modes = ['increase' => '増やす', 'decrease' => '減らす'];
$mode  = $_GET['mode'] ?? 'increase';
if (!isset($modes[$mode])) {
    $mode = 'increase';
}
$isIncrease = $mode === 'increase';

$formKey = 'adjustment_' . $mode . '_' . $locationId;

// 増やすときは入庫と同じ選択肢、減らすときは出庫と同じ選択肢
if ($isIncrease) {
    $products   = product_options($pdo);
    $storages   = storage_options($pdo, $locationId);
    $looseBoxId = array_key_first($storages);
} else {
    $stockOptions = stock_options($pdo, $locationId);
}

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_adjustment(array $input, bool $isIncrease, array $options): array
{
    $errors = ['line' => []];

    if (empty($input['lines'])) {
        $errors['lines'] = '明細を1行以上入力してください。';
    }

    $seen = [];
    foreach ($input['lines'] as $i => $line) {
        if ($isIncrease) {
            [$products, $storages] = $options;
            $key = $line['box_id'] . ':' . $line['product_id'];
            if (!isset($products[(int)$line['product_id']])) {
                $errors['line'][$i] = '商品を選択してください。';
            } elseif (!isset($storages[(int)$line['box_id']])) {
                $errors['line'][$i] = '保管場所を選択してください。';
            } elseif (!ctype_digit($line['qty']) || (int)$line['qty'] < 1 || (int)$line['qty'] > 9999) {
                $errors['line'][$i] = '数量は1〜9999の整数で入力してください。';
            }
        } else {
            [$stockOptions] = $options;
            $key = $line['stock_key'];
            if (!isset($stockOptions[$key])) {
                $errors['line'][$i] = '保管場所と商品を選択してください。';
            } elseif (!ctype_digit($line['qty']) || (int)$line['qty'] < 1) {
                $errors['line'][$i] = '数量は1以上の整数で入力してください。';
            } elseif ((int)$line['qty'] > $stockOptions[$key]['qty']) {
                $errors['line'][$i] = '在庫が足りません（残り ' . $stockOptions[$key]['qty'] . '）。';
            }
        }
        if (!isset($errors['line'][$i]) && isset($seen[$key])) {
            $errors['line'][$i] = '同じ商品・保管場所の行がほかにもあります。1つの行にまとめてください。';
        }
        $seen[$key] = true;
    }

    if ($input['note'] === '') {
        $errors['note'] = '在庫調整の理由を入力してください。';
    } elseif (mb_strlen($input['note']) > 500) {
        $errors['note'] = '理由は500文字以内で入力してください。';
    }

    if (empty($errors['line'])) {
        unset($errors['line']);
    }
    return $errors;
}

$validateOptions = $isIncrease ? [$products, $storages] : [$stockOptions];

// 空の明細の1行
$emptyLine = $isIncrease
    ? ['product_id' => '', 'box_id' => (string)$looseBoxId, 'qty' => '']
    : ['stock_key' => '', 'qty' => ''];

// ---------------------------------------------------------------
// 3. step で処理を切り替える
// ---------------------------------------------------------------
$step        = $_POST['step'] ?? '';
$errors      = [];
$commitError = '';
$showConfirm = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    form_clear($formKey);
    $input = ['lines' => [$emptyLine], 'note' => ''];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        $qtys  = $_POST['qty'] ?? [];
        $lines = [];

        if ($isIncrease) {
            $productIds = $_POST['product_id'] ?? [];
            $boxIds     = $_POST['box_id'] ?? [];
            foreach ($productIds as $i => $productId) {
                $line = [
                    'product_id' => (string)$productId,
                    'box_id'     => (string)($boxIds[$i] ?? ''),
                    'qty'        => trim((string)($qtys[$i] ?? '')),
                ];
                if ($line['product_id'] === '' && $line['qty'] === '') {
                    continue;
                }
                $lines[] = $line;
            }
        } else {
            $stockKeys = $_POST['stock_key'] ?? [];
            foreach ($stockKeys as $i => $stockKey) {
                $line = [
                    'stock_key' => (string)$stockKey,
                    'qty'       => trim((string)($qtys[$i] ?? '')),
                ];
                if ($line['stock_key'] === '' && $line['qty'] === '') {
                    continue;
                }
                $lines[] = $line;
            }
        }

        $input  = ['lines' => $lines, 'note' => trim($_POST['note'] ?? '')];
        $errors = validate_adjustment($input, $isIncrease, $validateOptions);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        } elseif (empty($input['lines'])) {
            $input['lines'][] = $emptyLine;
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? ['lines' => [$emptyLine], 'note' => ''];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            set_flash('error', '確認中の内容が見つかりませんでした。別の画面で同じ操作をしたか、時間がたって無効になった可能性があります。もう一度入力してください。');
            redirect($_SERVER['REQUEST_URI']);
        }

        try {
            $pdo->beginTransaction();

            $operationId = create_operation($pdo, [
                'type'             => 'adjustment',
                'user_id'          => $user['id'],
                'from_location_id' => $locationId,
                'to_location_id'   => $locationId,
                'note'             => $input['note'],
            ]);

            foreach ($input['lines'] as $line) {
                if ($isIncrease) {
                    $boxId     = (int)$line['box_id'];
                    $productId = (int)$line['product_id'];
                    $qty       = (int)$line['qty'];
                    lock_box($pdo, $boxId, $locationId);
                    add_stock($pdo, $boxId, $productId, $qty);
                    add_operation_item($pdo, $operationId, $productId, $qty, null, $boxId);
                } else {
                    [$boxId, $productId] = array_map('intval', explode(':', $line['stock_key']));
                    $qty  = (int)$line['qty'];
                    $name = $stockOptions[$line['stock_key']]['name'] ?? '選んだ商品';
                    lock_box($pdo, $boxId, $locationId);
                    remove_stock($pdo, $boxId, $productId, $qty, $name);
                    add_operation_item($pdo, $operationId, $productId, $qty, $boxId, null);
                }
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
            set_flash('success', '在庫調整（' . $modes[$mode] . '）を登録しました：'
                . implode('、', array_map(fn($line) => line_name($line, $isIncrease, $products ?? [], $stockOptions ?? []) . ' ×' . $line['qty'], $input['lines'])));
            redirect('/operations/adjustment.php?location_id=' . $locationId . '&mode=' . $mode);
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

/**
 * 明細の1行を「保管場所 / 商品」の名前にする（確認画面と登録後のメッセージで使う）
 */
function line_name(array $line, bool $isIncrease, array $products, array $stockOptions, array $storages = []): string
{
    if ($isIncrease) {
        $storage = $storages[(int)$line['box_id']] ?? '';
        return ($storage !== '' ? $storage . ' / ' : '') . ($products[(int)$line['product_id']] ?? '');
    }
    return $stockOptions[$line['stock_key']]['name'] ?? '';
}

$isOtherLocation = $locationId !== $user['location_id'];

$pageTitle = '在庫調整';
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
            （<?= h($locationNames[$locationId]) ?>）の在庫を調整します。拠点が正しいか確認してください。
        </p>
    <?php endif; ?>

    <p>次の内容で在庫を<strong><?= h($modes[$mode]) ?></strong>調整をします。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>拠点</th>
                <td><?= h($locationNames[$locationId]) ?></td>
            </tr>
            <tr>
                <th>調整</th>
                <td><?= h($modes[$mode]) ?></td>
            </tr>
            <tr>
                <th>理由</th>
                <td><?= nl2br(h($input['note'])) ?></td>
            </tr>
        </table>
    </div>

    <div class="table-wrap">
        <table>
            <tr>
                <th>保管場所 / 商品</th>
                <th><?= $isIncrease ? '増やす数' : '減らす数' ?></th>
            </tr>
            <?php foreach ($input['lines'] as $line): ?>
                <tr>
                    <td><?= h(line_name($line, $isIncrease, $products ?? [], $stockOptions ?? [], $storages ?? [])) ?></td>
                    <td class="num"><?= h($line['qty']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit">調整する</button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->
    <form method="get" class="search-form">
        <div class="form-row">
            <label for="location_id">拠点</label>
            <select id="location_id" name="location_id">
                <?php foreach ($locations as $location): ?>
                    <option value="<?= h((string)$location['id']) ?>"
                        <?= $locationId === (int)$location['id'] ? 'selected' : '' ?>>
                        <?= h($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="mode">調整</label>
            <select id="mode" name="mode">
                <?php foreach ($modes as $value => $text): ?>
                    <option value="<?= h($value) ?>" <?= $mode === $value ? 'selected' : '' ?>><?= h($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button-secondary">切り替える</button>
        <span class="note">※ 切り替えると、入力中の明細は消えます。</span>
    </form>

    <?php if (!$isIncrease && empty($stockOptions)): ?>
        <p>この拠点には、減らせる在庫がありません。</p>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <p class="flash flash-error">入力内容に誤りがあります。</p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <h2>明細（<?= h($modes[$mode]) ?>）</h2>
            <?= field_error($errors, 'lines') ?>

            <div id="lines">
                <?php foreach ($input['lines'] as $i => $line): ?>
                    <div class="line-row">
                        <?php if ($isIncrease): ?>
                            <select name="product_id[]">
                                <option value="">商品を選択</option>
                                <?php foreach ($products as $id => $text): ?>
                                    <option value="<?= h((string)$id) ?>" <?= (string)$id === $line['product_id'] ? 'selected' : '' ?>><?= h($text) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="box_id[]">
                                <?php foreach ($storages as $id => $text): ?>
                                    <option value="<?= h((string)$id) ?>" <?= (string)$id === $line['box_id'] ? 'selected' : '' ?>><?= h($text) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select name="stock_key[]">
                                <option value="">保管場所 / 商品を選択</option>
                                <?php foreach ($stockOptions as $key => $option): ?>
                                    <option value="<?= h($key) ?>" <?= $key === $line['stock_key'] ? 'selected' : '' ?>><?= h($option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input type="number" name="qty[]" min="1" value="<?= h($line['qty']) ?>" placeholder="数量">
                        <button type="button" class="button-secondary remove-line">削除</button>
                        <?php if (isset($errors['line'][$i])): ?>
                            <p class="field-error"><?= h($errors['line'][$i]) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p><button type="button" id="add-line" class="button-secondary">＋ 行を追加</button></p>

            <template id="line-template">
                <div class="line-row">
                    <?php if ($isIncrease): ?>
                        <select name="product_id[]">
                            <option value="">商品を選択</option>
                            <?php foreach ($products as $id => $text): ?>
                                <option value="<?= h((string)$id) ?>"><?= h($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="box_id[]">
                            <?php foreach ($storages as $id => $text): ?>
                                <option value="<?= h((string)$id) ?>" <?= $id === $looseBoxId ? 'selected' : '' ?>><?= h($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="stock_key[]">
                            <option value="">保管場所 / 商品を選択</option>
                            <?php foreach ($stockOptions as $key => $option): ?>
                                <option value="<?= h($key) ?>"><?= h($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="number" name="qty[]" min="1" placeholder="数量">
                    <button type="button" class="button-secondary remove-line">削除</button>
                </div>
            </template>

            <div class="form-row">
                <label for="note">理由（必須）</label>
                <textarea id="note" name="note" rows="3" placeholder="例：棚卸しで数え直したところ、記録より1枚多かった"><?= h($input['note']) ?></textarea>
                <?= field_error($errors, 'note') ?>
            </div>

            <p class="button-row">
                <button type="submit" name="step" value="confirm">確認する</button>
            </p>
        </form>

        <script>
            const lines = document.getElementById('lines');
            const template = document.getElementById('line-template');

            document.getElementById('add-line').addEventListener('click', () => {
                lines.appendChild(template.content.cloneNode(true));
            });

            lines.addEventListener('click', (event) => {
                if (!event.target.classList.contains('remove-line')) {
                    return;
                }
                const row = event.target.closest('.line-row');
                if (lines.querySelectorAll('.line-row').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('select, input').forEach((field) => {
                        if (field.name !== 'box_id[]') {
                            field.value = '';
                        }
                    });
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>