-- init.sql（ローカル開発環境用）
-- パスワードは各自の環境に合わせて変更すること

-- 1. 空のDBを作る
CREATE DATABASE kimono_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- 2. アプリ専用のユーザーを作り、このDBの読み書きだけを許可する
CREATE USER 'kimono_app'@'localhost' IDENTIFIED BY '任意のパスワード';
GRANT SELECT, INSERT, UPDATE, DELETE ON kimono_inventory.* TO 'kimono_app'@'localhost';