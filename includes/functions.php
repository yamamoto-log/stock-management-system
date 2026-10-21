<?php
// どの画面でも使う共通関数

/**
 * HTMLに出力する値をエスケープする（XSS対策）
 * 画面に表示する値は、必ずこの関数を通す
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 別の画面へ移動して、処理を終了する
 */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * 商品の表示コード（例：P-0123）
 */
function product_code(int $id): string
{
    return sprintf('P-%04d', $id);
}

/**
 * 箱の表示コード（例：BOX-0015）
 */
function box_code(int $id): string
{
    return sprintf('BOX-%04d', $id);
}

/**
 * ENUMなどの値を、画面に表示する日本語に変換する
 * 例：label('season', 'summer') → 夏物
 */
function label(string $type, ?string $value): string
{
    $labels = [
        'status'    => ['active' => '利用中', 'inactive' => '停止中'],
        'role'      => ['normal' => '一般', 'admin' => '管理者'],
        'target'    => ['women' => '女', 'men' => '男', 'children' => '子'],
        'season'    => ['summer' => '夏物', 'winter' => '冬物', 'all_season' => '通年'],
        'box'       => ['active' => '使用中', 'in_transit' => '輸送中', 'retired' => '廃止'],
        'transfer'  => ['in_transit' => '輸送中', 'received' => '受取済', 'cancelled' => '取消済'],
        'operation' => [
            'stock_in'      => '入庫',
            'stock_out'     => '出庫',
            'adjustment'    => '在庫調整',
            'relocation'    => '保管場所変更',
            'item_transfer' => '商品移動',
            'box_transfer'  => '箱ごと移動',
        ],
        'reason' => [
            'purchase' => '新規購入',
            'initial'  => '初期登録',
            'disposal' => '廃棄',
            'sale'     => '売却',
            'lost'     => '紛失',
            'other'    => 'その他',
        ],
    ];

    return $labels[$type][$value] ?? '';
}

/**
 * 次の画面に1回だけ表示するメッセージを保存する
 * 例：set_flash('success', '入庫を登録しました');
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * 保存したメッセージを取り出す（取り出したら消える）
 */
function get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * 確認画面の入力内容をセッションに保存する
 */
function form_save(string $key, array $data): void
{
    $_SESSION['forms'][$key] = $data;
}

/**
 * 保存した入力内容を取り出す（なければ null）
 */
function form_load(string $key): ?array
{
    return $_SESSION['forms'][$key] ?? null;
}

/**
 * 保存した入力内容を消す
 */
function form_clear(string $key): void
{
    unset($_SESSION['forms'][$key]);
}