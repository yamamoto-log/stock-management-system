<?php
// DBに接続し、PDOオブジェクトを返す
// 使い方：require_once __DIR__ . '/../includes/db.php'; のあと getDb() を呼ぶ

function getDb(): PDO
{
    static $pdo = null;      // 1回のリクエスト中は同じ接続を使い回す
    if ($pdo !== null) {
        return $pdo;
    }

    // 環境変数 DB_HOST があれば（本番）環境変数から、なければ（開発）config/database.php から読む
    if (getenv('DB_HOST') !== false) {
        $config = [
            'host'     => getenv('DB_HOST'),
            'port'     => getenv('DB_PORT') ?: '3306',
            'socket'   => '',
            'dbname'   => getenv('DB_NAME'),
            'user'     => getenv('DB_USER'),
            'password' => getenv('DB_PASSWORD'),
            'charset'  => 'utf8mb4',
        ];
    } else {
        $config = require __DIR__ . '/../config/database.php';
    }

    // ソケットの指定があればソケットで、なければホスト名で接続する
    if (!empty($config['socket'])) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $config['socket'],
            $config['dbname'],
            $config['charset']
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? '3306',   // 開発の設定に port がなければ 3306
            $config['dbname'],
            $config['charset']
        );
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // エラー時に例外を投げる
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 結果を連想配列で受け取る
        PDO::ATTR_EMULATE_PREPARES   => false,                  // 本物のプリペアドステートメントを使う
    ];

    // 本番（DB がインターネットの向こうにある）では、通信を暗号化する
    // DB_SSL_CA：サーバーの証明書を確かめるための、認証局の証明書のファイル
    if (getenv('DB_SSL_CA') !== false) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = getenv('DB_SSL_CA');
    }

    $pdo = new PDO($dsn, $config['user'], $config['password'], $options);
    
    $pdo->exec("SET time_zone = '+09:00'");

    return $pdo;
}
