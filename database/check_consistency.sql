-- =====================================================================
-- 記録と在庫の一致の確認（check_consistency.sql）
--
-- 箱ごと・商品ごとに、記録（operation_items）を足し引きした数と、
-- 今の在庫（stocks）を比べ、違う行を表示する。
-- 0行なら、記録と在庫が一致している。
-- =====================================================================

SELECT box_id, product_id,
       SUM(ledger_qty) AS ledger_qty,
       SUM(stock_qty)  AS stock_qty
FROM (
    -- 記録：入ったもの（＋）
    SELECT to_box_id AS box_id, product_id, qty AS ledger_qty, 0 AS stock_qty
      FROM operation_items WHERE to_box_id IS NOT NULL
    UNION ALL
    -- 記録：出たもの（−）
    SELECT from_box_id, product_id, -qty, 0
      FROM operation_items WHERE from_box_id IS NOT NULL
    UNION ALL
    -- 記録：取消で戻したもの（＋）
    SELECT returned_box_id, product_id, qty, 0
      FROM operation_items WHERE returned_box_id IS NOT NULL
    UNION ALL
    -- 今の在庫
    SELECT box_id, product_id, 0, qty
      FROM stocks
) t
GROUP BY box_id, product_id
HAVING SUM(ledger_qty) <> SUM(stock_qty);