<?php
// .env ファイルを自前で読み込む関数
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // コメント行を無視
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            // 前後の空白やクォーテーション (", ') を取り除く
            $name = trim($name);
            $value = trim(trim($value), '"\'');
            $_ENV[$name] = $value;
        }
    }
}

// デフォルトのホスト名は WSL 環境対策として 127.0.0.1 にしておくと確実です
$db_host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db_name = $_ENV['DB_NAME'] ?? 'stock_db';
$db_user = $_ENV['DB_USER'] ?? 'stock_user';
$db_pass = $_ENV['DB_PASS'] ?? 'narait';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 接続確認テスト用（接続成功したらこの行は消してOKです）
    // echo "接続成功！"; 
} catch (PDOException $e) {
    exit('DB接続エラー: ' . $e->getMessage());
}
