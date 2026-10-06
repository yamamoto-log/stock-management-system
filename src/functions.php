<?php
function str2html(string $string) :string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function db_open() :PDO {
    // $_ENVから値を取り出す
    $db_host = $_ENV['DB_HOST'] ?? 'localhost';
    $db_name = $_ENV['DB_NAME'] ?? 'stock_db';
    $db_user = $_ENV['DB_USER'] ?? 'root';
    $db_pass = $_ENV['DB_PASS'] ?? '';

    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $dbh = new PDO($dsn, $db_user, $db_pass, $opt);
        return $dbh;
     } catch (PDOException $e) {
        // echo "エラー！: <br>"; // 本番環境ではこちらを表示する
        echo "エラー！（練習用）: " . $e->getMessage() . "<br>";
        exit;
     }
}


/**
 * .envファイルを読み込んで $_ENV および getenv() にセットする関数
 *
 * @param string $path .envファイルのパス
 * @return void
 */
function loadEnv($path) {
    if (!file_exists($path)) {
    return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
     // コメント行（#で始める行）は無視する
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // KEY=VALUE の形式に分解
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        // 前後の余白や引用符（"や'）を除去
        $value = trim($value, "\t\n\r\0\x0B\"'");

        // すでに環境変数が設定されていなければセット
        if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}