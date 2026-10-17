<?php
// DB接続設定の見本
// このファイルを database.php という名前でコピーし、各自の環境に合わせて値を書き換えること。
// database.php は .gitignore で除外しているため、GitHub には公開されない。

return [
    'host'     => 'localhost',
    'socket'   => '',   // ソケットで接続する場合のみ指定（例：/run/mysqld/mysqld.sock）
    'dbname'   => 'kimono_inventory',
    'user'     => 'kimono_app',
    'password' => 'change_me',
    'charset'  => 'utf8mb4',
];
