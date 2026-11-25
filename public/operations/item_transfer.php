<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/stock.php';
require_login();

$pdo  = getDb();
$user = current_user();


// 出庫（stock_out.php）とほぼ同じ形です。違うところに ★ を付けています。

// ---------------------------------------------------------------
// 1. 送り元の拠点を決める
//    URL の location_id → なければ、URL の箱がある拠点 → なければ所属拠点
// ---------------------------------------------------------------
$locations     = $pdo->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY id")->fetchAll();
$locationNames = array_column($locations, 'name', 'id');

$startProductId = (int)($_GET['product_id'] ?? 0);
$startBoxId     = (int)($_GET['box_id'] ?? 0);

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

// ★ 送り先の候補（送り元以外の利用中の拠点）
$destinations = $locationNames;
unset($destinations[$locationId]);

$formKey      = 'item_transfer_' . $locationId;
$stockOptions = stock_options($pdo, $locationId);

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_item_transfer(array $input, array $stockOptions, array $destinations): array
{
    $errors = ['line' => []];

    if (empty($input['lines'])) {
        $errors['lines'] = '明細を1行以上入力してください。';
    }

    $seen = [];
    foreach ($input['lines'] as $i => $line) {
        $key = $line['stock_key'];
        if (!isset($stockOptions[$key])) {
            $errors['line'][$i] = '保管場所と商品を選択してください。';
        } elseif (!ctype_digit($line['qty']) || (int)$line['qty'] < 1) {
            $errors['line'][$i] = '数量は1以上の整数で入力してください。';
        } elseif ((int)$line['qty'] > $stockOptions[$key]['qty']) {
            $errors['line'][$i] = '在庫が足りません（残り ' . $stockOptions[$key]['qty'] . '）。';
        } elseif (isset($seen[$key])) {
            $errors['line'][$i] = '同じ商品・保管場所の行がほかにもあります。1つの行にまとめてください。';
        }
        $seen[$key] = true;
    }

    // ★ 送り先
    if (!isset($destinations[(int)$input['to_location_id']])) {
        $errors['to_location_id'] = '送り先の拠点を選択してください。';
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
    $startKey = $startBoxId . ':' . $startProductId;
    $input = [
        'lines'          => [[
            'stock_key' => isset($stockOptions[$startKey]) ? $startKey : '',
            'qty'       => '',
        ]],
        'to_location_id' => '',
        'note'           => '',
    ];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        $stockKeys = $_POST['stock_key'] ?? [];
        $qtys      = $_POST['qty'] ?? [];

        $lines = [];
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

        $input = [
            'lines'          => $lines,
            'to_location_id' => (string)($_POST['to_location_id'] ?? ''),
            'note'           => trim($_POST['note'] ?? ''),
        ];
        $errors = validate_item_transfer($input, $stockOptions, $destinations);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        } elseif (empty($input['lines'])) {
            $input['lines'][] = ['stock_key' => '', 'qty' => ''];
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? ['lines' => [['stock_key' => '', 'qty' => '']], 'to_location_id' => '', 'note' => ''];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            set_flash('error', '確認中の内容が見つかりませんでした。別の画面で同じ操作をしたか、時間がたって無効になった可能性があります。もう一度入力してください。');
            redirect($_SERVER['REQUEST_URI']);
        }

        $operationId = 0;
        try {
            $pdo->beginTransaction();

            // ★ 送り先の拠点が、まだ利用中かを確かめる
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id = ? AND status = 'active'");
            $stmt->execute([(int)$input['to_location_id']]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new StockException('送り先の拠点が利用停止になっています。送り先を選び直してください。');
            }

            // ★ 状態を「輸送中」にして記録する
            $operationId = create_operation($pdo, [
                'type'             => 'item_transfer',
                'user_id'          => $user['id'],
                'from_location_id' => $locationId,
                'to_location_id'   => (int)$input['to_location_id'],
                'status'           => 'in_transit',
                'note'             => null_if_empty($input['note']),
            ]);

            foreach ($input['lines'] as $line) {
                [$boxId, $productId] = array_map('intval', explode(':', $line['stock_key']));
                $qty  = (int)$line['qty'];
                $name = $stockOptions[$line['stock_key']]['name'] ?? '選んだ商品';

                lock_box($pdo, $boxId, $locationId);
                remove_stock($pdo, $boxId, $productId, $qty, $name);
                // ★ 送り先の保管場所は、受取確認のときに書き込む
                add_operation_item($pdo, $operationId, $productId, $qty, $boxId, null);
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
            set_flash('success', '発送しました。送り先で検品が終わったら、受取確認をしてください。');
            redirect('/transfers/show.php?id=' . $operationId);   // ★ 移動詳細へ
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

$isOtherLocation = $locationId !== $user['location_id'];

$pageTitle = '商品移動（発送）';
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
            （<?= h($locationNames[$locationId]) ?>）から発送します。拠点が正しいか確認してください。
        </p>
    <?php endif; ?>

    <p>次の内容で発送します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>送り元</th>
                <td><?= h($locationNames[$locationId]) ?></td>
            </tr>
            <tr class="emphasis-row">
                <th>送り先</th>
                <td><?= h($destinations[(int)$input['to_location_id']] ?? '') ?>　<span class="emphasis-note">← 送り先が正しいか確認してください</span></td>
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
                <th>保管場所 / 商品</th>
                <th>発送数</th>
                <th>残数<br>（発送前 → 後）</th>
            </tr>
            <?php foreach ($input['lines'] as $line): ?>
                <?php $option = $stockOptions[$line['stock_key']] ?? null; ?>
                <tr>
                    <td><?= h($option['name'] ?? '') ?></td>
                    <td class="num"><?= h($line['qty']) ?></td>
                    <td class="num">
                        <?php if ($option !== null): ?>
                            <?= h((string)$option['qty']) ?> → <?= h((string)($option['qty'] - (int)$line['qty'])) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <p class="note">※ 発送すると送り元の在庫から減り、送り先で受取確認をするまで「輸送中」になります。</p>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit">発送する</button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->
    <form method="get" class="search-form">
        <div class="form-row">
            <label for="location_id">送り元の拠点</label>
            <select id="location_id" name="location_id">
                <?php foreach ($locations as $location): ?>
                    <option value="<?= h((string)$location['id']) ?>" <?= $locationId === (int)$location['id'] ? 'selected' : '' ?>>
                        <?= h($location['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button-secondary">切り替える</button>
        <span class="note">※ 切り替えると、入力中の明細は消えます。</span>
    </form>

    <?php if (empty($stockOptions)): ?>
        <p>この拠点には、発送できる在庫がありません。</p>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <p class="flash flash-error">入力内容に誤りがあります。</p>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <h2>明細</h2>
            <?= field_error($errors, 'lines') ?>

            <div id="lines">
                <?php foreach ($input['lines'] as $i => $line): ?>
                    <div class="line-row">
                        <select name="stock_key[]">
                            <option value="">保管場所 / 商品を選択</option>
                            <?= stock_option_tags($stockOptions, $line['stock_key']) ?>
                        </select>
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
                    <select name="stock_key[]">
                        <option value="">保管場所 / 商品を選択</option>
                        <?= stock_option_tags($stockOptions) ?>
                    </select>
                    <input type="number" name="qty[]" min="1" placeholder="数量">
                    <button type="button" class="button-secondary remove-line">削除</button>
                </div>
            </template>

            <!-- ★ 送り先 -->
            <div class="form-row">
                <label for="to_location_id">送り先の拠点（必須）</label>
                <select id="to_location_id" name="to_location_id">
                    <option value="">選択してください</option>
                    <?php foreach ($destinations as $id => $name): ?>
                        <option value="<?= h((string)$id) ?>" <?= (string)$id === $input['to_location_id'] ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'to_location_id') ?>
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
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>