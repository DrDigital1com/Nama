-- =====================================================================
-- Nama — ניתוח ההזמנות שבוטלו אתמול
-- =====================================================================
-- קידומת: wpgh_   |   אחסון: HPOS
-- השאילתות אינן תלויות בשער מסוים — הן קוראות את payment_method מה-DB.
-- כל השאילתות כאן הן SELECT בלבד — בטוחות להרצה בייצור.
--
-- להרצה: cPanel -> phpMyAdmin -> בחירת בסיס הנתונים -> SQL
--        או: wp db query < sql/yesterday-cancelled.sql
--
-- הזמנים בבסיס הנתונים הם UTC. העמודות עם הסיומת _il מציגות
-- שעון ישראל (UTC+3).
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. כל ההזמנות מ-48 השעות האחרונות  ⭐ השאילתה המרכזית
-- ---------------------------------------------------------------------
-- העמודה החשובה ביותר היא transaction_id.
--   יש ערך + הסטטוס cancelled/failed  =  הלקוח שילם וההזמנה לא עודכנה
--   ריק + cancelled                   =  הלקוח לא השלים תשלום בעמוד הסליקה

SELECT
    id                                                   AS הזמנה,
    status                                               AS סטטוס,
    DATE_ADD(date_created_gmt, INTERVAL 3 HOUR)          AS נוצרה_il,
    DATE_ADD(date_updated_gmt, INTERVAL 3 HOUR)          AS עודכנה_il,
    TIMESTAMPDIFF(MINUTE, date_created_gmt, date_updated_gmt) AS דקות_עד_שינוי,
    COALESCE(NULLIF(payment_method, ''), '(ריק)')        AS שער,
    total_amount                                         AS סכום,
    COALESCE(NULLIF(transaction_id, ''), '—')            AS מזהה_עסקה,
    CASE
        WHEN transaction_id IS NOT NULL AND transaction_id <> ''
             AND status IN ('wc-cancelled','wc-failed','wc-pending')
        THEN '*** שולם ולא עודכן ***'
        ELSE ''
    END                                                  AS התראה
FROM wpgh_wc_orders
WHERE type = 'shop_order'
  AND date_created_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
ORDER BY date_created_gmt DESC;


-- ---------------------------------------------------------------------
-- 2. האם השער בכלל הגיב? המטא שנשמר על ההזמנות שבוטלו
-- ---------------------------------------------------------------------
-- אם מופיעים כאן מפתחות של טרנזילה — השער החזיר תשובה לפני הביטול,
-- כלומר הלקוח לא פשוט נטש בעמוד הסליקה.

SELECT
    o.id                       AS הזמנה,
    o.status                   AS סטטוס,
    m.meta_key                 AS מפתח,
    LEFT(m.meta_value, 120)    AS ערך
FROM wpgh_wc_orders o
JOIN wpgh_wc_orders_meta m ON m.order_id = o.id
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-cancelled','wc-failed')
  AND o.date_created_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
  AND (
        m.meta_key LIKE '%tranzila%'
     OR m.meta_key LIKE '%transaction%'
     OR m.meta_key LIKE '%payment%'
     OR m.meta_key LIKE '%confirm%'
     OR m.meta_key LIKE '%response%'
     OR m.meta_key LIKE '%error%'
      )
ORDER BY o.id DESC, m.meta_key;


-- ---------------------------------------------------------------------
-- 3. הערות ההזמנה — מה ווקומרס עצמה רשמה
-- ---------------------------------------------------------------------
-- הערות מערכת מתעדות דחיות של השער ושינויי סטטוס, לרוב במילים מפורשות.

SELECT
    c.comment_post_ID                              AS הזמנה,
    DATE_ADD(c.comment_date_gmt, INTERVAL 3 HOUR)  AS מתי_il,
    LEFT(c.comment_content, 250)                   AS הערה
FROM wpgh_comments c
WHERE c.comment_type = 'order_note'
  AND c.comment_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
ORDER BY c.comment_post_ID DESC, c.comment_date_gmt;


-- ---------------------------------------------------------------------
-- 4. מגמה יומית — 14 הימים האחרונים
-- ---------------------------------------------------------------------
-- מראה אם אתמול היה חריג או שזה קצב קבוע.

SELECT
    DATE(DATE_ADD(date_created_gmt, INTERVAL 3 HOUR))    AS תאריך_il,
    COUNT(*)                                             AS סהכ,
    SUM(status IN ('wc-processing','wc-completed'))       AS הצליחו,
    SUM(status = 'wc-cancelled')                          AS בוטלו,
    SUM(status = 'wc-failed')                             AS נכשלו,
    SUM(status = 'wc-pending')                            AS ממתינות,
    ROUND(100 * SUM(status IN ('wc-processing','wc-completed')) / NULLIF(COUNT(*),0), 1)
                                                          AS אחוז_הצלחה
FROM wpgh_wc_orders
WHERE type = 'shop_order'
  AND date_created_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 14 DAY)
GROUP BY תאריך_il
ORDER BY תאריך_il DESC;


-- ---------------------------------------------------------------------
-- 5. פילוח לפי שעה — האם הכשלים מרוכזים בשעה מסוימת
-- ---------------------------------------------------------------------
-- ריכוז בשעה אחת מצביע על עומס, timeout או חלון תחזוקה אצל הסולק.

SELECT
    HOUR(DATE_ADD(date_created_gmt, INTERVAL 3 HOUR))    AS שעה_il,
    COUNT(*)                                             AS סהכ,
    SUM(status IN ('wc-processing','wc-completed'))       AS הצליחו,
    SUM(status IN ('wc-cancelled','wc-failed'))           AS נפלו
FROM wpgh_wc_orders
WHERE type = 'shop_order'
  AND date_created_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
GROUP BY שעה_il
ORDER BY שעה_il;
