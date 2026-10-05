<?php
function str2html(string $string) :string {
    return htmlspecialchars(($string, ENT_QUOTES, 'UTF-8');
}

funtion db_open()  :PDO{
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ];
    $dbh = new PDO('mysql:host={$db_host};dbname={db_name}, $db_user, $db_pass, $opt');
    return $dbh;
}