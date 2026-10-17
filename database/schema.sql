1.
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

2.categories
CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_categories_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;






3.users
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
  CONSTRAINT uk_users_login_id UNIQUE (login_id),
  CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

4.products
CREATE TABLE products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(50)  NOT NULL,
  color       VARCHAR(50)  NULL DEFAULT NULL,
  pattern     VARCHAR(50)  NULL DEFAULT NULL,
  size        VARCHAR(20)  NULL DEFAULT NULL,
  image_path  VARCHAR(255) NULL DEFAULT NULL,
  note        TEXT         NULL DEFAULT NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

5.boxes
CREATE TABLE boxes (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  location_id       INT UNSIGNED NOT NULL,
  loose_location_id INT UNSIGNED NULL DEFAULT NULL,
  status            ENUM('active','in_transit','retired') NOT NULL DEFAULT 'active',
  note              TEXT         NULL DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT uk_boxes_loose_location UNIQUE (loose_location_id),
  CONSTRAINT fk_boxes_location       FOREIGN KEY (location_id)       REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_boxes_loose_location FOREIGN KEY (loose_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT ck_boxes_loose_location CHECK (loose_location_id IS NULL OR loose_location_id = location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

6.
CREATE TABLE stocks (
  box_id     INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty        INT          NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (box_id, product_id),
  CONSTRAINT fk_stocks_box     FOREIGN KEY (box_id)     REFERENCES boxes(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_stocks_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT ck_stocks_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

7.operations
CREATE TABLE operations (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type                 ENUM('stock_in','stock_out','adjustment','relocation','item_transfer','box_transfer') NOT NULL,
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
  CONSTRAINT ck_operations_status    CHECK ((type IN ('item_transfer','box_transfer')) = (status IS NOT NULL)),
  CONSTRAINT ck_operations_received  CHECK ((received_by IS NULL) = (received_at IS NULL)),
  CONSTRAINT ck_operations_cancelled CHECK ((cancelled_by IS NULL) = (cancelled_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


8.operation_items
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

9.operation_boxes
CREATE TABLE operation_boxes (
  operation_id INT UNSIGNED NOT NULL,
  box_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (operation_id, box_id),
  CONSTRAINT fk_operation_boxes_operation_id FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_operation_boxes_box_id       FOREIGN KEY (box_id)       REFERENCES boxes(id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

