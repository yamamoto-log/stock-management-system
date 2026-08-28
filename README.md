<<<<<<< HEAD
```markdown
# 拠点別在庫管理システム (Multi-Location Inventory Management System)

複数拠点（店舗・倉庫等）における商品在庫の一元管理および入出庫履歴（ログ）の追跡を行えるWebアプリケーションです。

---

## 📋 システム概要

拠点ごとの「現在の在庫数」を管理するだけでなく、入出庫の操作履歴（ログ）を自動で保存・表示することで、トレーサビリティ（追跡可能性）を考慮した設計になっています。

### 主な機能
- **拠点マスター管理** (`location.php`, `location_edit.php`, `location_delete.php`)
  - 拠点（店舗や倉庫など）の新規登録・一覧表示・名称変更（編集）・削除
- **商品マスター管理** (`product.php`)
  - 管理対象商品の新規登録・一覧表示
- **在庫・入出庫管理** (`inventory.php`)
  - 拠点×商品ごとの在庫登録および数量の加算（入庫）／減算（出庫）
  - 出庫時の在庫不足チェック（マイナス在庫を防止するバリデーション制御）
  - 拠点別在庫一覧のリアルタイム表示（`JOIN` 結合による名称補完）
- **入出庫履歴（ログ）管理** (`inventory.php`)
  - 入出庫実行時の日時・拠点・商品・種別・数量の自動記録
  - 最新10件の入出庫ログ一覧表示

---

## 🛠 使用技術 / 動作環境

| 項目 | 技術・環境 |
|---|---|
| **OS** | Linux (Ubuntu / Linux Mint) |
| **Web Server** | Apache 2.4 |
| **Language** | PHP 8.x (PDO拡張) |
| **Database** | MySQL / MariaDB (管理ツール: phpMyAdmin) |

---

## 🗄 データベース設計

### 構成概要
データベース名: `stock_db`

```text
 [ locations ]               [ products ]
   (拠点マスタ)                 (商品マスタ)
    - id (PK)                   - id (PK)
    - name                      - name
      │                           │
      ├──────────────┐            ├──────────────┐
      │ (1:N)        │ (1:N)      │ (1:N)        │ (1:N)
      ▼              ▼            ▼              ▼
 [ location_inventories ]    [ inventory_logs ]
    (拠点別在庫トランザクション)   (入出庫履歴ログ)
     - id (PK)                    - id (PK)
     - location_id (FK)           - location_id (FK)
     - product_id (FK)            - product_id (FK)
     - quantity                   - type ('in' / 'out')
                                  - quantity
                                  - created_at

```

### テーブル定義 (DDL)

```sql
CREATE DATABASE IF NOT EXISTS stock_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE stock_db;

-- 1. 拠点テーブル
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. 商品テーブル
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. 拠点別在庫テーブル
CREATE TABLE location_inventories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- 4. 入出庫ログテーブル
CREATE TABLE inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    product_id INT NOT NULL,
    type VARCHAR(10) NOT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

```

---

## 📁 画面・ファイル構成

| ファイル名 | 役割 | 主な処理 |
| --- | --- | --- |
| `location.php` | 拠点一覧・登録 | 拠点データの `INSERT` および `SELECT` 表示 |
| `location_edit.php` | 拠点名の編集 | 該当IDの拠点名を `UPDATE` |
| `location_delete.php` | 拠点の削除 | 該当IDの拠点データを `DELETE` |
| `product.php` | 商品一覧・登録 | 商品データの `INSERT` および `SELECT` 表示 |
| `inventory.php` | 在庫管理・入出庫・ログ | 在庫数の `INSERT` / `UPDATE`、ログの `INSERT`、`JOIN` による結合取得・一覧表示 |

---

## 🚀 セットアップ手順

1. **データベースの作成**
* phpMyAdmin にて `stock_db` を作成し、上記 DDL（SQL）を実行して4つのテーブルを作成します。


2. **ファイルの配置**
* Webサーバーの公開ディレクトリ（`/var/www/html/`）へ各 `.php` ファイルを配置します。


3. **パーミッション（権限）設定**
* ターミナルでApacheがファイルを読み込めるようパーミッションを設定します。


```bash
sudo chmod 644 /var/www/html/*.php

```


4. **DB接続情報の変更**
* 各PHPファイル内の `$db_user` および `$db_pass` をご自身の環境に合わせて変更します。


5. **動作確認**
* ブラウザで `http://localhost/location.php` へアクセスし、拠点・商品の登録および入出庫の実行を行います。



```

```
=======
# stock-management-system
>>>>>>> 2a6ab3c81e57333c27542e3f53a063053f06bf5c
