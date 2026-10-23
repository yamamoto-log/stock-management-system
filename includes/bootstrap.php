<?php
// すべての画面の先頭で読み込む共通ファイル
// 使い方：require_once __DIR__ . '/../includes/bootstrap.php';

$appConfig = require __DIR__ . '/../config/app.php';

// エラー表示（開発中は表示、本番は非表示にしてログに記録）
if ($appConfig['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    // 予期しないエラーが起きたら、詳細を隠して共通のメッセージを表示する
    set_exception_handler(function (Throwable $e) {
        error_log((string)$e);
        http_response_code(500);
        echo 'エラーが発生しました。時間をおいて、もう一度操作してください。';
    });
}

// タイムゾーン
date_default_timezone_set($appConfig['timezone']);



// セッションの Cookie の設定（session_start() より前に行う）
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,          // ブラウザを閉じたら Cookie を消す
    'path'     => '/',
    'secure'   => $isHttps,   // HTTPS のときだけ Secure を付ける
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');   // サーバーが発行していないセッションIDを受け付けない

session_start();



// 共通の処理
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';