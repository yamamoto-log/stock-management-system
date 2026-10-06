<?php
require_once 'functions.php';

// .envファイルを読み込んで$_ENVに値をセット
loadEnv(__DIR__. '/../.env');

// 取り出した変数を使ってDB接続を作成
$dbh = db_open();