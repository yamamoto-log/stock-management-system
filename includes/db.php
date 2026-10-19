<?php
// DBに接続し、PDOオブジェクトを返す
// 使い方：require_once __DIR__ . '/../includes/db.php'; のあと getDb() を呼ぶ

function getDb(): PDO
{
    static $pdo = null;      // 1回のリクエスト中は同じ接続を使い回す
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config/database.php';

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
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['dbname'],
            $config['charset']
        );
    }

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // エラー時に例外を投げる
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 結果を連想配列で受け取る
        PDO::ATTR_EMULATE_PREPARES   => false,                  // 本物のプリペアドステートメントを使う
    ]);
    $pdo->exec("SET time_zone = '+09:00'");

    return $pdo;
}
