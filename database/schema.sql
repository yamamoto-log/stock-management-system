-- =====================================================================
-- 着物レンタル店向け在庫管理アプリ  テーブル定義（schema.sql）
--
-- 実行前に対象のDBを選択しておくこと（例：USE kimono_inventory;）
-- DB名は公開先のサーバーで変わる可能性があるため、このファイルには書かない。
--
-- 注意：このファイルを実行すると、既存のテーブルとデータはすべて削除される。
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 既存テーブルの削除（参照している側＝子から先に消す）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS operation_boxes;
DROP TABLE IF EXISTS operation_items;
DROP TABLE IF EXISTS operations;
DROP TABLE IF EXISTS stocks;
DROP TABLE IF EXISTS boxes;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS locations;

-- ---------------------------------------------------------------------
-- 1. locations（拠点）
-- ---------------------------------------------------------------------
CREATE TABLE locations (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  note       TEXT         NULL DEFAULT NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_locations_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. categories（カテゴリ）
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_categories_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. users（ユーザー）  ※ locations を参照
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(50)  NOT NULL,
  role          ENUM('normal','admin') NOT NULL DEFAULT 'normal',
  location_id   INT UNSIGNED NOT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_users_login_id    UNIQUE (login_id),
  CONSTRAINT fk_users_location_id FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. products（商品）  ※ categories を参照
--    分類は「種類（category_id）× 対象（target）× 季節（season）」の3軸
--    target は画面で必ず選択させる（NOT NULL の ENUM は省略すると最初の値が入るため注意）
-- ---------------------------------------------------------------------
CREATE TABLE products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(50)  NOT NULL,
  target      ENUM('women','men','children') NOT NULL,
  season      ENUM('summer','winter','all_season') NOT NULL DEFAULT 'all_season',
  color       VARCHAR(50)  NULL DEFAULT NULL,
  pattern     VARCHAR(50)  NULL DEFAULT NULL,
  size        VARCHAR(20)  NULL DEFAULT NULL,
  image_path  VARCHAR(255) NULL DEFAULT NULL,
  note        TEXT         NULL DEFAULT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_products_category_id FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. boxes（箱。箱なし用の箱を含む）  ※ locations を参照
--    loose_location_id：箱なし用の箱のみ自拠点のIDを入れる（普通の箱はNULL）
-- ---------------------------------------------------------------------
CREATE TABLE boxes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  location_id       INT UNSIGNED NOT NULL,
  loose_location_id INT UNSIGNED NULL DEFAULT NULL,
  status            ENUM('active','in_transit','retired') NOT NULL DEFAULT 'active',
  note              TEXT         NULL DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_boxes_loose_location_id UNIQUE (loose_location_id),
  CONSTRAINT fk_boxes_location_id       FOREIGN KEY (location_id)       REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_boxes_loose_location_id FOREIGN KEY (loose_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT ck_boxes_loose_location_id CHECK (loose_location_id IS NULL OR loose_location_id = location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. stocks（在庫）  ※ boxes, products を参照
--    数量が0になった行は削除する（qty は常に1以上）
-- ---------------------------------------------------------------------
CREATE TABLE stocks (
  box_id     INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty        INT          NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (box_id, product_id),
  CONSTRAINT fk_stocks_box_id     FOREIGN KEY (box_id)     REFERENCES boxes(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_stocks_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT ck_stocks_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. operations（在庫操作のヘッダ）  ※ users, locations, operations（自己参照）を参照
--    status は移動（item_transfer / box_transfer）のみ使用し、それ以外はNULL
--    type は ENUM にしない。NOT NULL の ENUM は省略時に最初の値が自動で入るため、
--    VARCHAR（省略するとエラー）＋ CHECK 制約（値の制限）で表現する。
-- ---------------------------------------------------------------------
CREATE TABLE operations (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type                 VARCHAR(20)  NOT NULL,
  user_id              INT UNSIGNED NOT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  from_location_id     INT UNSIGNED NULL DEFAULT NULL,
  to_location_id       INT UNSIGNED NULL DEFAULT NULL,
  status               ENUM('in_transit','received','cancelled') NULL DEFAULT NULL,
  reason               ENUM('purchase','initial','disposal','sale','lost','other') NULL DEFAULT NULL,
  note                 TEXT         NULL DEFAULT NULL,
  related_operation_id INT UNSIGNED NULL DEFAULT NULL,
  received_by          INT UNSIGNED NULL DEFAULT NULL,
  received_at          DATETIME     NULL DEFAULT NULL,
  cancelled_by         INT UNSIGNED NULL DEFAULT NULL,
  cancelled_at         DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_operations_user_id              FOREIGN KEY (user_id)              REFERENCES users(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_operations_from_location_id     FOREIGN KEY (from_location_id)     REFERENCES locations(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_operations_to_location_id       FOREIGN KEY (to_location_id)       REFERENCES locations(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_operations_related_operation_id FOREIGN KEY (related_operation_id) REFERENCES operations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_operations_received_by          FOREIGN KEY (received_by)          REFERENCES users(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_operations_cancelled_by         FOREIGN KEY (cancelled_by)         REFERENCES users(id)      ON DELETE RESTRICT,
  CONSTRAINT ck_operations_type      CHECK (type IN ('stock_in','stock_out','adjustment','relocation','item_transfer','box_transfer')),
  CONSTRAINT ck_operations_status    CHECK ((type IN ('item_transfer','box_transfer')) = (status IS NOT NULL)),
  CONSTRAINT ck_operations_received  CHECK ((received_by IS NULL) = (received_at IS NULL)),
  CONSTRAINT ck_operations_cancelled CHECK ((cancelled_by IS NULL) = (cancelled_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. operation_items（商品の明細。箱ごと移動以外の操作で使用）
--    ※ operations, products, boxes を参照
-- ---------------------------------------------------------------------
CREATE TABLE operation_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_id INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  qty          INT          NOT NULL,
  from_box_id  INT UNSIGNED NULL DEFAULT NULL,
  to_box_id    INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_operation_items_operation_id FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_operation_items_product_id   FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_operation_items_from_box_id  FOREIGN KEY (from_box_id)  REFERENCES boxes(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_operation_items_to_box_id    FOREIGN KEY (to_box_id)    REFERENCES boxes(id)      ON DELETE RESTRICT,
  CONSTRAINT ck_operation_items_qty      CHECK (qty > 0),
  CONSTRAINT ck_operation_items_box      CHECK (from_box_id IS NOT NULL OR to_box_id IS NOT NULL),
  CONSTRAINT ck_operation_items_diff_box CHECK (from_box_id IS NULL OR to_box_id IS NULL OR from_box_id <> to_box_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. operation_boxes（箱ごと移動の明細）  ※ operations, boxes を参照
-- ---------------------------------------------------------------------
CREATE TABLE operation_boxes (
  operation_id INT UNSIGNED NOT NULL,
  box_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (operation_id, box_id),
  CONSTRAINT fk_operation_boxes_operation_id FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_operation_boxes_box_id       FOREIGN KEY (box_id)       REFERENCES boxes(id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;