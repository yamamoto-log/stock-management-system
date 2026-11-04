<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = getDb();

// ---------------------------------------------------------------
// 1. 登録か編集かを判断する
// ---------------------------------------------------------------
$locationId = (int)($_GET['id'] ?? 0);
$isEdit     = $locationId > 0;
$formKey    = 'location_edit_' . $locationId;

$location = null;
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM locations WHERE id = ?');
    $stmt->execute([$locationId]);
    $location = $stmt->fetch();
    if ($location === false) {
        http_response_code(404);
        exit('指定された拠点は存在しません。');
    }
}

// ---------------------------------------------------------------
// 2. 入力値のチェック
// ---------------------------------------------------------------
function validate_location(array $input, PDO $pdo, int $locationId): array
{
    $errors = [];

    if ($input['name'] === '') {
        $errors['name'] = '拠点名を入力してください。';
    } elseif (mb_strlen($input['name']) > 50) {
        $errors['name'] = '拠点名は50文字以内で入力してください。';
    } else {
        // 他の拠点と同じ名前でないか（編集のときは、自分自身は除く）
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE name = ? AND id <> ?');
        $stmt->execute([$input['name'], $locationId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors['name'] = '同じ拠点名がすでに登録されています。';
        }
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
    form_clear($formKey);
    $input = [
        'name' => $location['name'] ?? '',
        'note' => $location['note'] ?? '',
    ];
} else {
    verify_csrf();

    if ($step === 'confirm') {
        $input = [
            'name' => trim($_POST['name'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
        ];
        $errors = validate_location($input, $pdo, $locationId);
        if (empty($errors)) {
            form_save($formKey, $input);
            $showConfirm = true;
        }
    } elseif ($step === 'back') {
        $input = form_load($formKey) ?? [];
    } elseif ($step === 'commit') {
        $input = form_load($formKey);
        if ($input === null) {
            redirect($_SERVER['REQUEST_URI']);
        }

        // 確認画面を見ている間に、同じ名前の拠点が登録されたかもしれないので、もう一度確かめる
        $errors = validate_location($input, $pdo, $locationId);
        if (!empty($errors)) {
            $showConfirm = false;   // 入力画面に戻してエラーを表示する
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare('UPDATE locations SET name = ?, note = ? WHERE id = ?');
                $stmt->execute([$input['name'], null_if_empty($input['note']), $locationId]);
                $message = '拠点を更新しました。';
            } else {
                // 拠点と箱なし用の箱を、1つのトランザクションで追加する
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('INSERT INTO locations (name, note) VALUES (?, ?)');
                    $stmt->execute([$input['name'], null_if_empty($input['note'])]);
                    $newId = (int)$pdo->lastInsertId();

                    $stmt = $pdo->prepare('INSERT INTO boxes (location_id, loose_location_id) VALUES (?, ?)');
                    $stmt->execute([$newId, $newId]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $message = '拠点を登録しました。';
            }

            form_clear($formKey);
            set_flash('success', $message);
            redirect('/locations/');
        }
    } else {
        redirect($_SERVER['REQUEST_URI']);
    }
}

$pageTitle = $isEdit ? '拠点編集' : '拠点登録';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php if ($showConfirm): ?>
    <!-- ========== 確認画面 ========== -->
    <p>次の内容で<?= $isEdit ? '更新' : '登録' ?>します。よろしいですか？</p>

    <div class="table-wrap">
        <table class="detail-table">
            <tr>
                <th>拠点名</th>
                <td><?= h($input['name']) ?></td>
            </tr>
            <tr>
                <th>備考</th>
                <td><?= nl2br(h($input['note'])) ?></td>
            </tr>
        </table>
    </div>

    <?php if (!$isEdit): ?>
        <p class="note">※ 登録すると、この拠点の「箱なし」の保管場所が自動で作られます。</p>
    <?php endif; ?>

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
            <label for="name">拠点名（必須）</label>
            <input type="text" id="name" name="name" value="<?= h($input['name']) ?>">
            <?= field_error($errors, 'name') ?>
        </div>

        <div class="form-row">
            <label for="note">備考</label>
            <textarea id="note" name="note" rows="4"><?= h($input['note']) ?></textarea>
            <?= field_error($errors, 'note') ?>
        </div>

        <p class="button-row">
            <button type="submit" name="step" value="confirm">確認する</button>
            <a href="/locations/" class="button button-secondary">キャンセル</a>
        </p>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>