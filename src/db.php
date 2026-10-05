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

$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? 'stock_db';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';

try {
    $dbh = db_open();
} catch (PDOException $e) {
    // echo "エラー！: <br>"; // 本番環境ではこちらを表示する
    echo "エラー！（練習用）: " . $e->getMessage() . "<br>";
    exit;
}
