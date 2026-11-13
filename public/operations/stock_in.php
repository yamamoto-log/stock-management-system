<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();

// ---------------------------------------------------------------
// 1. 拠点を決める（URL の location_id、なければ所属拠点）
// ---------------------------------------------------------------
$locations = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();
$locationNames = array_column($locations, 'name', 'id');   // [拠点ID => 拠点名]

$locationId = (int)($_GET['location_id'] ?? $user['location_id']);
if (!isset($locationNames[$locationId])) {
    $locationId = $user['location_id'];
}

// 商品詳細から開いたときは、その商品を入力済みにし、登録後はその商品詳細に戻る
$returnProductId = (int)($_GET['product_id'] ?? 0);

$formKey  = 'stock_in_' . $locationId;
$products = product_options($pdo);
$storages = storage_options($pdo, $locationId);
$looseBoxId = array_key_first($storages);   // 先頭は箱なし
$reasons  = array_intersect_key(labels('reason'), array_flip(['purchase', 'initial', 'other']));

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_stock_in(array $input, array $products, array $storages, array $reasons): array
{
    $errors = ['line' => []];

    if (empty($input['lines'])) {
        $errors['lines'] = '明細を1行以上入力してください。';
    }

    $seen = [];
    foreach ($input['lines'] as $i => $line) {
        if (!isset($products[(int)$line['product_id']])) {
            $errors['line'][$i] = '商品を選択してください。';
        } elseif (!isset($storages[(int)$line['box_id']])) {
            $errors['line'][$i] = '保管場所を選択してください。';
        } elseif (!ctype_digit($line['qty']) || (int)$line['qty'] < 1 || (int)$line['qty'] > 9999) {
            $errors['line'][$i] = '数量は1〜9999の整数で入力してください。';
        } else {
            $key = $line['box_id'] . ':' . $line['product_id'];
            if (isset($seen[$key])) {
                $errors['line'][$i] = '同じ商品・保管場所の行がほかにもあります。1つの行にまとめてください。';
            }
            $seen[$key] = true;
        }
    }

    if (!isset($reasons[$input['reason']])) {
        $errors['reason'] = '理由を選択してください。';
    }
    if (mb_strlen($input['note']) > 500) {
        $errors['note'] = '備考は500文字以内で入力してください。';
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
    $input = [
        'lines' => [[
            'product_id' => isset($products[$returnProductId]) ? (string)$returnProductId : '',
            'box_id'     => (string)$looseBoxId,
            'qty'        => '',
        ]],
        'reason' => 'purchase',
        'note'   => '',
    ];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        // 3つの配列（商品・保管場所・数量）を、行ごとにまとめる
        $productIds = $_POST['product_id'] ?? [];
        $boxIds     = $_POST['box_id'] ?? [];
        $qtys       = $_POST['qty'] ?? [];

        $lines = [];
        foreach ($productIds as $i => $productId) {
            $line = [
                'product_id' => (string)$productId,
                'box_id'     => (string)($boxIds[$i] ?? ''),
                'qty'        => trim((string)($qtys[$i] ?? '')),
            ];
            if ($line['product_id'] === '' && $line['qty'] === '') {
                continue;   // 何も入力していない行は無視する
            }
            $lines[] = $line;
        }

        $input = [
            'lines'  => $lines,
            'reason' => $_POST['reason'] ?? '',
            'note'   => trim($_POST['note'] ?? ''),
        ];
        $errors = validate_stock_in($input, $products, $storages, $reasons);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        } elseif (empty($input['lines'])) {
            $input['lines'][] = ['product_id' => '', 'box_id' => (string)$looseBoxId, 'qty' => ''];
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? [];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            set_flash('error', '確認中の内容が見つかりませんでした。別の画面で同じ操作をしたか、時間がたって無効になった可能性があります。もう一度入力してください。');
            redirect($_SERVER['REQUEST_URI']);
        }

        try {
            $pdo->beginTransaction();

            $operationId = create_operation($pdo, [
                'type'           => 'stock_in',
                'user_id'        => $user['id'],
                'to_location_id' => $locationId,
                'reason'         => $input['reason'],
                'note'           => null_if_empty($input['note']),
            ]);

            foreach ($input['lines'] as $line) {
                $boxId     = (int)$line['box_id'];
                $productId = (int)$line['product_id'];
                $qty       = (int)$line['qty'];

                lock_box($pdo, $boxId, $locationId);
                add_stock($pdo, $boxId, $productId, $qty);
                add_operation_item($pdo, $operationId, $productId, $qty, null, $boxId);
            }

            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            $commitError = $e->getMessage();
            $showConfirm = true;            // 確認画面に戻して、理由を表示する
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($commitError === '') {
            form_clear($formKey);

            $summary = [];
            foreach ($input['lines'] as $line) {
                $summary[] = $products[(int)$line['product_id']] . ' ×' . $line['qty'];
            }
            set_flash('success', '入庫を登録しました：' . implode('、', $summary));

            if ($returnProductId > 0) {
                redirect('/products/show.php?id=' . $returnProductId);
            }
            redirect('/operations/stock_in.php?location_id=' . $locationId);
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

$isOtherLocation = $locationId !== $user['location_id'];

$pageTitle = '入庫';
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
            （<?= h($locationNames[$locationId]) ?>）に入庫します。拠点が正しいか確認してください。
        </p>
    <?php endif; ?>

    <p>次の内容で入庫します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>拠点</th>
                <td><?= h($locationNames[$locationId]) ?></td>
            </tr>
            <tr>
                <th>理由</th>
                <td><?= h(label('reason', $input['reason'])) ?></td>
            </tr>
            <tr>
                <th>備考</th>
                <td><?= nl2br(h($input['note'])) ?></td>
            </tr>
        </table>
    </div>

    <div class="table-wrap">
        <table>
            <tr>
                <th>商品</th>
                <th>入れる保管場所</th>
                <th>数量</th>
            </tr>
            <?php foreach ($input['lines'] as $line): ?>
                <tr>
                    <td><?= h($products[(int)$line['product_id']] ?? '') ?></td>
                    <td><?= h($storages[(int)$line['box_id']] ?? '') ?></td>
                    <td class="num"><?= h($line['qty']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit">入庫する</button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->

    <!-- 拠点の切り替え（GET で開き直す） -->
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
        <?php if ($returnProductId > 0): ?>
            <input type="hidden" name="product_id" value="<?= h((string)$returnProductId) ?>">
        <?php endif; ?>
        <button type="submit" class="button-secondary">切り替える</button>
        <span class="note">※ 切り替えると、入力中の明細は消えます。</span>
    </form>

    <?php if (!empty($errors)): ?>
        <p class="flash flash-error">入力内容に誤りがあります。</p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <h2>明細</h2>
        <?= field_error($errors, 'lines') ?>

        <div id="lines">
            <?php foreach ($input['lines'] as $i => $line): ?>
                <div class="line-row">
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
                    <input type="number" name="qty[]" min="1" max="9999" value="<?= h($line['qty']) ?>" placeholder="数量">
                    <button type="button" class="button-secondary remove-line">削除</button>
                    <?php if (isset($errors['line'][$i])): ?>
                        <p class="field-error"><?= h($errors['line'][$i]) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <p><button type="button" id="add-line" class="button-secondary">＋ 行を追加</button></p>

        <!-- 行を追加するときの見本（画面には表示されない） -->
        <template id="line-template">
            <div class="line-row">
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
                <input type="number" name="qty[]" min="1" max="9999" placeholder="数量">
                <button type="button" class="button-secondary remove-line">削除</button>
            </div>
        </template>

        <div class="form-row">
            <label for="reason">理由</label>
            <select id="reason" name="reason">
                <?php foreach ($reasons as $value => $text): ?>
                    <option value="<?= h($value) ?>" <?= $input['reason'] === $value ? 'selected' : '' ?>><?= h($text) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'reason') ?>
        </div>

        <div class="form-row">
            <label for="note">備考</label>
            <textarea id="note" name="note" rows="3"><?= h($input['note']) ?></textarea>
            <?= field_error($errors, 'note') ?>
        </div>

        <p class="button-row">
            <button type="submit" name="step" value="confirm">確認する</button>
        </p>
    </form>

    <script src="/assets/js/lines.js"></script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>