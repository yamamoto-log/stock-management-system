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
}

// タイムゾーン
date_default_timezone_set($appConfig['timezone']);

// セッション開始
session_start();

// 共通の処理
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';