<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = getDb();

// ---------------------------------------------------------------
// 1. 登録か編集かを判断する（URL に id があれば編集）
// ---------------------------------------------------------------
$productId = (int)($_GET['id'] ?? 0);
$isEdit    = $productId > 0;
$formKey   = 'product_edit_' . $productId;   // 登録と、商品ごとの編集を分けて保存する

$product = null;
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if ($product === false) {
        http_response_code(404);
        exit('指定された商品は存在しません。');
    }
}

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY id')->fetchAll();

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_product(array $input, array $categories): array
{
    $errors = [];

    if ($input['name'] === '') {
        $errors['name'] = '商品名を入力してください。';
    } elseif (mb_strlen($input['name']) > 50) {
        $errors['name'] = '商品名は50文字以内で入力してください。';
    }

    $categoryIds = array_map('intval', array_column($categories, 'id'));
    if (!in_array((int)$input['category_id'], $categoryIds, true)) {
        $errors['category_id'] = 'カテゴリを選択してください。';
    }

    if (!array_key_exists($input['target'], labels('target'))) {
        $errors['target'] = '対象を選択してください。';
    }
    if (!array_key_exists($input['season'], labels('season'))) {
        $errors['season'] = '季節を選択してください。';
    }

    if (mb_strlen($input['size']) > 20) {
        $errors['size'] = 'サイズは20文字以内で入力してください。';
    }
    if (mb_strlen($input['color']) > 50) {
        $errors['color'] = '色は50文字以内で入力してください。';
    }
    if (mb_strlen($input['pattern']) > 50) {
        $errors['pattern'] = '柄は50文字以内で入力してください。';
    }
    if (mb_strlen($input['note']) > 1000) {
        $errors['note'] = '備考は1000文字以内で入力してください。';
    }

    return $errors;
}

// ---------------------------------------------------------------
// 3. step で処理を切り替える
// ---------------------------------------------------------------
$step        = $_POST['step'] ?? '';
$errors      = [];
$showConfirm = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 最初に開いたとき：登録なら初期値、編集なら今の内容
    form_clear($formKey);
    $input = [
        'name'        => $product['name'] ?? '',
        'category_id' => (string)($product['category_id'] ?? ''),
        'target'      => $product['target'] ?? 'women',
        'season'      => $product['season'] ?? 'all_season',
        'size'        => $product['size'] ?? '',
        'color'       => $product['color'] ?? '',
        'pattern'     => $product['pattern'] ?? '',
        'note'        => $product['note'] ?? '',
    ];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        $input = [
            'name'        => trim($_POST['name'] ?? ''),
            'category_id' => $_POST['category_id'] ?? '',
            'target'      => $_POST['target'] ?? '',
            'season'      => $_POST['season'] ?? '',
            'size'        => trim($_POST['size'] ?? ''),
            'color'       => trim($_POST['color'] ?? ''),
            'pattern'     => trim($_POST['pattern'] ?? ''),
            'note'        => trim($_POST['note'] ?? ''),
        ];
        $errors = validate_product($input, $categories);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? [];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            redirect($_SERVER['REQUEST_URI']);   // 確認内容がない（セッション切れなど）
        }
        // 登録の直前にもう一度チェックする（念のため）
        if (!empty(validate_product($input, $categories))) {
            redirect($_SERVER['REQUEST_URI']);
        }

        $values = [
            $input['name'],
            (int)$input['category_id'],
            $input['target'],
            $input['season'],
            null_if_empty($input['size']),
            null_if_empty($input['color']),
            null_if_empty($input['pattern']),
            null_if_empty($input['note']),
        ];

        if ($isEdit) {
            $stmt = $pdo->prepare(
                'UPDATE products
                 SET name = ?, category_id = ?, target = ?, season = ?,
                     size = ?, color = ?, pattern = ?, note = ?
                 WHERE id = ?'
            );
            $stmt->execute([...$values, $productId]);
            $savedId = $productId;
            $message = '商品を更新しました。';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO products (name, category_id, target, season, size, color, pattern, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute($values);
            $savedId = (int)$pdo->lastInsertId();
            $message = '商品を登録しました。';
        }

        form_clear($formKey);
        set_flash('success', $message);
        redirect('/products/show.php?id=' . $savedId);
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

// 確認画面で表示するカテゴリ名
$categoryName = '';
foreach ($categories as $category) {
    if ((string)$category['id'] === (string)($input['category_id'] ?? '')) {
        $categoryName = $category['name'];
    }
}

$pageTitle = $isEdit ? '商品編集' : '商品登録';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($isEdit): ?>
    <p>商品コード：<?= h(product_code($productId)) ?></p>
<?php endif; ?>

<?php if ($showConfirm): ?>
    <!-- ========== 確認画面 ========== -->
    <p>次の内容で<?= $isEdit ? '更新' : '登録' ?>します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>商品名</th>
                <td><?= h($input['name']) ?></td>
            </tr>
            <tr>
                <th>カテゴリ</th>
                <td><?= h($categoryName) ?></td>
            </tr>
            <tr class="emphasis-row">
                <th>対象</th>
                <td><?= h(label('target', $input['target'])) ?>　<span class="emphasis-note">← 対象が正しいか確認してください</span></td>
            </tr>
            <th>季節</th>
            <td><?= h(label('season', $input['season'])) ?></td>
            </tr>
            <tr>
                <th>サイズ</th>
                <td><?= h($input['size']) ?></td>
            </tr>
            <tr>
                <th>色</th>
                <td><?= h($input['color']) ?></td>
            </tr>
            <tr>
                <th>柄</th>
                <td><?= h($input['pattern']) ?></td>
            </tr>
            <tr>
                <th>備考</th>
                <td><?= nl2br(h($input['note'])) ?></td>
            </tr>
        </table>
    </div>

    <form method="post" class="button-row">
        <?= csrf_field() ?>
        <button type="submit" name="step" value="back" class="button-secondary">入力に戻る</button>
        <button type="submit" name="step" value="commit"><?= $isEdit ? '更新する' : '登録する' ?></button>
    </form>

<?php else: ?>
    <!-- ========== 入力画面 ========== -->
    <?php if (!empty($errors)): ?>
        <p class="flash flash-error">入力内容に誤りがあります。</p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="form-row">
            <label for="name">商品名（必須）</label>
            <input type="text" id="name" name="name" value="<?= h($input['name']) ?>">
            <?= field_error($errors, 'name') ?>
        </div>

        <div class="form-row">
            <label for="category_id">カテゴリ（必須）</label>
            <select id="category_id" name="category_id">
                <option value="">選択してください</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= h((string)$category['id']) ?>"
                        <?= (string)$category['id'] === (string)$input['category_id'] ? 'selected' : '' ?>>
                        <?= h($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'category_id') ?>
        </div>

        <div class="form-row">
            <label for="target">対象（必須）</label>
            <select id="target" name="target">
                <?php foreach (labels('target') as $value => $text): ?>
                    <option value="<?= h($value) ?>" <?= $input['target'] === $value ? 'selected' : '' ?>><?= h($text) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'target') ?>
        </div>

        <div class="form-row">
            <label for="season">季節（必須）</label>
            <select id="season" name="season">
                <?php foreach (labels('season') as $value => $text): ?>
                    <option value="<?= h($value) ?>" <?= $input['season'] === $value ? 'selected' : '' ?>><?= h($text) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'season') ?>
        </div>

        <div class="form-row">
            <label for="size">サイズ</label>
            <input type="text" id="size" name="size" value="<?= h($input['size']) ?>">
            <?= field_error($errors, 'size') ?>
        </div>

        <div class="form-row">
            <label for="color">色</label>
            <input type="text" id="color" name="color" value="<?= h($input['color']) ?>">
            <?= field_error($errors, 'color') ?>
        </div>

        <div class="form-row">
            <label for="pattern">柄</label>
            <input type="text" id="pattern" name="pattern" value="<?= h($input['pattern']) ?>">
            <?= field_error($errors, 'pattern') ?>
        </div>

        <div class="form-row">
            <label for="note">備考</label>
            <textarea id="note" name="note" rows="4"><?= h($input['note']) ?></textarea>
            <?= field_error($errors, 'note') ?>
        </div>

        <p class="button-row">
            <button type="submit" name="step" value="confirm">確認する</button>
            <a href="<?= $isEdit ? '/products/show.php?id=' . h((string)$productId) : '/products/' ?>"
                class="button button-secondary">キャンセル</a>
        </p>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>