-- =====================================================================
-- 制約のテスト（test_constraints.sql）
--
-- 目的：schema.sql で定義した制約が、意図どおりにデータを拒否・許可するか確かめる。
-- 実行：MariaDB にログインし、USE kimono_inventory; の後に SOURCE で実行する。
--       （SOURCE はエラーが出ても次の文へ進むので、「拒否されるはず」のテストも続けて確認できる）
-- 後始末：最後に ROLLBACK するので、テスト用のデータは残らない。
--         ただし AUTO_INCREMENT の番号は戻らないため、テスト後は schema.sql を実行し直すこと。
--
-- 各テストの「期待」と、実際の結果（エラーになったか・通ったか）を見比べる。
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 準備：テストに必要な親データ（すべて成功するはず）
-- ---------------------------------------------------------------------
INSERT INTO locations (id, name) VALUES (1, 'テスト奈良店'), (2, 'テスト本部');
INSERT INTO categories (id, name) VALUES (1, 'テスト着物');
INSERT INTO users (id, login_id, password_hash, name, location_id)
  VALUES (1, 'test_user', 'dummy_hash', 'テスト太郎', 1);
INSERT INTO products (id, category_id, name, target) VALUES (1, 1, 'テスト着物A', 'women');

-- ---------------------------------------------------------------------
-- products
-- ---------------------------------------------------------------------
-- T00a 期待：成功（季節を省略すると通年になる。直後の SELECT で all_season と表示される）
INSERT INTO products (id, category_id, name, target) VALUES (2, 1, 'テスト着物B', 'men');
SELECT id, target, season FROM products WHERE id = 2;

-- T00b 期待：エラー（strict モードでは、ENUM にない値は拒否される）
INSERT INTO products (category_id, name, target, season) VALUES (1, 'テスト着物C', 'women', 'spring');

-- 箱：1 = 奈良店の箱なし、2 = 奈良店の普通の箱、3 = 奈良店の普通の箱
INSERT INTO boxes (id, location_id, loose_location_id) VALUES (1, 1, 1);
INSERT INTO boxes (id, location_id) VALUES (2, 1), (3, 1);

-- ---------------------------------------------------------------------
-- stocks
-- ---------------------------------------------------------------------
-- T01 期待：成功（通常の在庫）
INSERT INTO stocks (box_id, product_id, qty) VALUES (2, 1, 5);

-- T02 期待：エラー（ck_stocks_qty：数量0は不可）
INSERT INTO stocks (box_id, product_id, qty) VALUES (3, 1, 0);

-- T03 期待：エラー（主キー重複：同じ箱に同じ商品の行は1つだけ）
INSERT INTO stocks (box_id, product_id, qty) VALUES (2, 1, 3);

-- T04 期待：エラー（fk_stocks_box_id：存在しない箱）
INSERT INTO stocks (box_id, product_id, qty) VALUES (999, 1, 1);

-- ---------------------------------------------------------------------
-- boxes
-- ---------------------------------------------------------------------
-- T05 期待：エラー（uk_boxes_loose_location_id：奈良店の箱なしは1つだけ）
INSERT INTO boxes (location_id, loose_location_id) VALUES (1, 1);

-- T06 期待：エラー（ck_boxes_loose_location_id：本部の箱なしが奈良店にある）
INSERT INTO boxes (location_id, loose_location_id) VALUES (1, 2);

-- T07 期待：成功（本部の箱なしは本部に1つ作れる）
INSERT INTO boxes (location_id, loose_location_id) VALUES (2, 2);

-- T08 期待：エラー（ck_boxes_loose_location_id：箱なし用の箱は箱ごと移動できない）
UPDATE boxes SET location_id = 2 WHERE id = 1;

-- ---------------------------------------------------------------------
-- operations
-- ---------------------------------------------------------------------
-- T09 期待：成功（入庫：状態なし）
INSERT INTO operations (id, type, user_id, to_location_id, reason)
  VALUES (1, 'stock_in', 1, 1, 'purchase');

-- T10 期待：成功（商品移動：輸送中）
INSERT INTO operations (id, type, user_id, from_location_id, to_location_id, status)
  VALUES (2, 'item_transfer', 1, 1, 2, 'in_transit');

-- T11 期待：エラー（type は必須で初期値なし。ERROR 1364 Field 'type' doesn't have a default value）
INSERT INTO operations (user_id, to_location_id) VALUES (1, 1);

-- T11b 期待：エラー（ck_operations_type：存在しない操作の種類）
INSERT INTO operations (type, user_id, to_location_id) VALUES ('stock_inn', 1, 1);

-- T12 期待：エラー（ck_operations_status：入庫に状態がある）
INSERT INTO operations (type, user_id, to_location_id, status)
  VALUES ('stock_in', 1, 1, 'in_transit');

-- T13 期待：エラー（ck_operations_status：商品移動に状態がない）
INSERT INTO operations (type, user_id, from_location_id, to_location_id)
  VALUES ('item_transfer', 1, 1, 2);

-- T14 期待：エラー（ck_operations_received：受取者だけで受取日時がない）
UPDATE operations SET status = 'received', received_by = 1 WHERE id = 2;

-- T15 期待：成功（受取者と受取日時をセットで記録）
UPDATE operations SET status = 'received', received_by = 1, received_at = NOW() WHERE id = 2;

-- ---------------------------------------------------------------------
-- operation_items
-- ---------------------------------------------------------------------
-- T16 期待：成功（入庫の明細：移動先のみ）
INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
  VALUES (1, 1, 2, NULL, 1);

-- T17 期待：成功（出庫の明細：移動元のみ）
INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
  VALUES (1, 1, 2, 2, NULL);

-- T18 期待：エラー（ck_operation_items_box：移動元も移動先もない）
INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
  VALUES (1, 1, 2, NULL, NULL);

-- T19 期待：エラー（ck_operation_items_diff_box：移動元と移動先が同じ箱）
INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
  VALUES (1, 1, 2, 2, 2);

-- T20 期待：エラー（ck_operation_items_qty：数量0は不可）
INSERT INTO operation_items (operation_id, product_id, qty, from_box_id, to_box_id)
  VALUES (1, 1, 0, NULL, 1);

-- ---------------------------------------------------------------------
-- 外部キーの RESTRICT
-- ---------------------------------------------------------------------
-- T21 期待：エラー（fk_stocks_product_id：在庫がある商品は削除できない）
DELETE FROM products WHERE id = 1;

-- T22 期待：エラー（fk_boxes_location_id など：箱がある拠点は削除できない）
DELETE FROM locations WHERE id = 1;

-- ---------------------------------------------------------------------
-- 後始末：テストデータをすべて取り消す
-- ---------------------------------------------------------------------
ROLLBACK;