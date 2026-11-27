<?php
return [
    // true：開発中（エラーの詳細を画面に表示する）
    // false：本番（エラーの詳細を隠す）
    'debug' => getenv('APP_DEBUG') === 'true',
    'timezone' => 'Asia/Tokyo',
    'app_name' => '着物レンタル在庫管理',
];
