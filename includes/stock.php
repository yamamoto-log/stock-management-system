<?php
// 在庫を扱う共通の処理（入庫・出庫・在庫調整・保管場所変更・移動で使う）

/**
 * 在庫の操作ができないときの例外（利用者に表示するメッセージを持つ）
 */
class StockException extends RuntimeException {}

// ---------------------------------------------------------------
// 選択肢を作る
// ---------------------------------------------------------------

/**
 * 拠点の保管場所の選択肢（箱なしが先、使用中の箱だけ）
 * 戻り値：[箱ID => '箱なし' や 'BOX-0004（夏物…）']
 */
function storage_options(PDO $pdo, int $locationId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, loose_location_id, note FROM boxes
         WHERE location_id = ? AND status = 'active'
         ORDER BY (loose_location_id IS NULL), id"
    );
    $stmt->execute([$locationId]);

    $options = [];
    foreach ($stmt->fetchAll() as $box) {
        if ($box['loose_location_id'] !== null) {
            $options[(int)$box['id']] = '箱なし';
        } else {
            $memo = $box['note'] ? '（' . mb_strimwidth($box['note'], 0, 24, '…') . '）' : '';
            $options[(int)$box['id']] = box_code((int)$box['id']) . $memo;
        }
    }
    return $options;
}

/**
 * 利用中の商品の選択肢
 * 戻り値：[商品ID => 'P-0001 訪問着 M（桃）']
 */
function product_options(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, name, size, color FROM products WHERE status = 'active' ORDER BY id"
    )->fetchAll();

    $options = [];
    foreach ($rows as $row) {
        $text = product_code((int)$row['id']) . ' ' . $row['name'];
        if ($row['size'] !== null) {
            $text .= ' ' . $row['size'];
        }
        if ($row['color'] !== null) {
            $text .= '（' . $row['color'] . '）';
        }
        $options[(int)$row['id']] = $text;
    }
    return $options;
}

/**
 * 拠点にある在庫の選択肢（出庫・減らす調整で使う）
 * 戻り値：['箱ID:商品ID' => ['box_id' => …, 'product_id' => …, 'qty' => …, 'label' => '箱なし / P-0001 訪問着 M（残 4）']]
 */
function stock_options(PDO $pdo, int $locationId): array
{
    $stmt = $pdo->prepare(
        "SELECT s.box_id, s.product_id, s.qty, b.loose_location_id
         FROM stocks s
         JOIN boxes b ON b.id = s.box_id
         WHERE b.location_id = ? AND b.status = 'active'
         ORDER BY (b.loose_location_id IS NULL), b.id, s.product_id"
    );
    $stmt->execute([$locationId]);

    $products = product_options($pdo);
    $options  = [];
    foreach ($stmt->fetchAll() as $row) {
        $boxId     = (int)$row['box_id'];
        $productId = (int)$row['product_id'];
        $storage   = $row['loose_location_id'] !== null ? '箱なし' : box_code($boxId);
        $options[$boxId . ':' . $productId] = [
            'box_id'     => $boxId,
            'product_id' => $productId,
            'qty'        => (int)$row['qty'],
            'name'       => $storage . ' / ' . ($products[$productId] ?? product_code($productId)),
            'label'      => $storage . ' / ' . ($products[$productId] ?? product_code($productId))
                . '（残 ' . $row['qty'] . '）',
        ];
    }
    return $options;
}

// ---------------------------------------------------------------
// 在庫を変える（必ずトランザクションの中で呼ぶ）
// ---------------------------------------------------------------

/**
 * 箱の行をロックし、その拠点の使用中の箱であることを確かめる
 */
function lock_box(PDO $pdo, int $boxId, int $locationId): void
{
    $stmt = $pdo->prepare('SELECT location_id, status FROM boxes WHERE id = ? FOR UPDATE');
    $stmt->execute([$boxId]);
    $box = $stmt->fetch();

    if ($box === false || (int)$box['location_id'] !== $locationId || $box['status'] !== 'active') {
        throw new StockException('選んだ保管場所が使えない状態になっています。画面を開き直してください。');
    }
}

/**
 * 在庫を増やす（行がなければ作る）
 */
function add_stock(PDO $pdo, int $boxId, int $productId, int $qty): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO stocks (box_id, product_id, qty) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
    );
    $stmt->execute([$boxId, $productId, $qty]);
}

/**
 * 在庫を減らす（行をロックして残数を確かめ、0になったら行を削除する）
 */
function remove_stock(PDO $pdo, int $boxId, int $productId, int $qty, string $label): void
{
    $stmt = $pdo->prepare('SELECT qty FROM stocks WHERE box_id = ? AND product_id = ? FOR UPDATE');
    $stmt->execute([$boxId, $productId]);
    $current = $stmt->fetchColumn();
    $current = $current === false ? 0 : (int)$current;

    if ($current < $qty) {
        throw new StockException($label . ' の在庫が足りません（残り ' . $current . '）。');
    }

    if ($current === $qty) {
        $pdo->prepare('DELETE FROM stocks WHERE box_id = ? AND product_id = ?')
            ->execute([$boxId, $productId]);
    } else {
        $pdo->prepare('UPDATE stocks SET qty = qty - ? WHERE box_id = ? AND product_id = ?')
            ->execute([$qty, $boxId, $productId]);
    }
}

/**
 * 操作の記録を追加して、その ID を返す
 */
function create_operation(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO operations (type, user_id, from_location_id, to_location_id, reason, note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['type'],
        $data['user_id'],
        $data['from_location_id'] ?? null,
        $data['to_location_id'] ?? null,
        $data['reason'] ?? null,
        $data['note'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * 操作の明細を追加する
 */
function add_operation_item(PDO $pdo, int $operationId, int $productId, int $qty, ?int $fromBoxId, ?int $toBoxId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$operationId, $productId, $qty, $fromBoxId, $toBoxId]);
}
