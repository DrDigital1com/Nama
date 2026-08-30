-- =====================================================================
-- Nama — שאילתות אבחון להזמנות לא מושלמות (WooCommerce)
-- =====================================================================
-- שימוש:  wp db query < sql/orders-diagnostics.sql
--         או להריץ בלוק-בלוק ב-phpMyAdmin / Adminer.
--
-- החליפו את הקידומת wp_ בקידומת האמיתית של האתר אם היא שונה.
-- כל השאילתות הן קריאה בלבד (SELECT) — בטוחות להרצה בייצור.
--
-- שני מבני אחסון אפשריים:
--   A) HPOS פעיל   -> טבלת wp_wc_orders
--   B) HPOS כבוי   -> טבלת wp_posts
-- הריצו קודם את בדיקה 0 כדי לדעת באיזה בלוק להשתמש.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 0. איזה מבנה אחסון פעיל?
-- ---------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE table_schema = DATABASE() AND table_name = 'wp_wc_orders') AS hpos_table_exists,
    (SELECT COUNT(*) FROM wp_options
      WHERE option_name = 'woocommerce_custom_orders_table_enabled'
        AND option_value = 'yes') AS hpos_enabled;


-- =====================================================================
-- בלוק A — HPOS פעיל (wp_wc_orders)
-- =====================================================================

-- A1. פילוח כל ההזמנות לפי סטטוס
SELECT status,
       COUNT(*) AS orders,
       ROUND(100 * COUNT(*) / SUM(COUNT(*)) OVER (), 1) AS pct,
       ROUND(SUM(total_amount), 2) AS total_value
FROM wp_wc_orders
WHERE type = 'shop_order'
GROUP BY status
ORDER BY orders DESC;

-- A2. מגמה חודשית — 12 חודשים אחרונים
SELECT DATE_FORMAT(date_created_gmt, '%Y-%m')                       AS month,
       SUM(status IN ('wc-processing','wc-completed'))              AS succeeded,
       SUM(status = 'wc-pending')                                   AS pending,
       SUM(status = 'wc-failed')                                    AS failed,
       SUM(status = 'wc-cancelled')                                 AS cancelled,
       ROUND(100 * SUM(status IN ('wc-pending','wc-failed'))
             / NULLIF(SUM(status IN ('wc-pending','wc-failed','wc-processing','wc-completed')), 0), 1) AS incomplete_pct
FROM wp_wc_orders
WHERE type = 'shop_order'
  AND status <> 'wc-checkout-draft'
  AND date_created_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH)
GROUP BY month
ORDER BY month DESC;

-- A3. ביצועים לפי שער תשלום — כאן מתגלה השער הבעייתי
SELECT COALESCE(NULLIF(payment_method, ''), '(ללא שער)')            AS gateway,
       COALESCE(NULLIF(payment_method_title, ''), '-')              AS gateway_title,
       SUM(status IN ('wc-processing','wc-completed'))              AS succeeded,
       SUM(status = 'wc-pending')                                   AS pending,
       SUM(status = 'wc-failed')                                    AS failed,
       ROUND(100 * SUM(status IN ('wc-pending','wc-failed'))
             / NULLIF(SUM(status IN ('wc-pending','wc-failed','wc-processing','wc-completed')), 0), 1) AS incomplete_pct,
       ROUND(SUM(CASE WHEN status IN ('wc-pending','wc-failed') THEN total_amount ELSE 0 END), 2) AS lost_value
FROM wp_wc_orders
WHERE type = 'shop_order'
  AND status <> 'wc-checkout-draft'
  AND date_created_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
GROUP BY gateway, gateway_title
ORDER BY (pending + failed) DESC;

-- A4. פילוח לפי שעה ביום — מזהה עומס/timeout בשעות שיא
SELECT HOUR(date_created_gmt)                                       AS hour_utc,
       SUM(status IN ('wc-processing','wc-completed'))              AS succeeded,
       SUM(status IN ('wc-pending','wc-failed'))                    AS incomplete,
       ROUND(100 * SUM(status IN ('wc-pending','wc-failed'))
             / NULLIF(COUNT(*), 0), 1)                              AS incomplete_pct
FROM wp_wc_orders
WHERE type = 'shop_order'
  AND status IN ('wc-pending','wc-failed','wc-processing','wc-completed')
  AND date_created_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 90 DAY)
GROUP BY hour_utc
ORDER BY hour_utc;

-- A5. גיל ההזמנות התקועות — כמה זמן הן כבר ב-pending
SELECT CASE
         WHEN date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)  THEN '0-1 שעות'
         WHEN date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN '1-24 שעות'
         WHEN date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)   THEN '1-7 ימים'
         WHEN date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)  THEN '7-30 ימים'
         ELSE 'מעל 30 ימים'
       END AS age_bucket,
       COUNT(*) AS orders
FROM wp_wc_orders
WHERE type = 'shop_order' AND status = 'wc-pending'
GROUP BY age_bucket
ORDER BY orders DESC;
-- פירוש: אם רוב ה-pending הן "מעל 30 ימים" — הבעיה היא שהניקוי האוטומטי לא רץ (קרון).
--         אם רוב ה-pending הן "0-1 שעות" — זו התנהגות תקינה של הזמנות שרק נוצרו.

-- A6. הזמנות שנוצרו ללא שער תשלום כלל
SELECT id, status, date_created_gmt, total_amount
FROM wp_wc_orders
WHERE type = 'shop_order'
  AND status <> 'wc-checkout-draft'
  AND (payment_method IS NULL OR payment_method = '')
ORDER BY date_created_gmt DESC
LIMIT 50;
-- פירוש: הצ׳קאאוט נשבר אחרי יצירת ההזמנה ולפני הפעלת השער — שגיאת JS, timeout או nonce.

-- A7. האם הזמנות pending כוללות טרנזקציה? (שולם אבל לא עודכן!)
SELECT o.id, o.status, o.date_created_gmt, o.payment_method, o.total_amount,
       o.transaction_id
FROM wp_wc_orders o
WHERE o.type = 'shop_order'
  AND o.status IN ('wc-pending','wc-failed')
  AND o.transaction_id IS NOT NULL
  AND o.transaction_id <> ''
ORDER BY o.date_created_gmt DESC
LIMIT 100;
-- ** קריטי ** אם יש כאן תוצאות — הלקוחות שילמו, קיים transaction_id,
--    אבל ההזמנה לא עברה ל-processing. זהו כשל callback מובהק. יש להשלים אותן ידנית.


-- =====================================================================
-- בלוק B — HPOS כבוי (wp_posts + wp_postmeta)
-- =====================================================================

-- B1. פילוח כל ההזמנות לפי סטטוס
SELECT post_status AS status,
       COUNT(*) AS orders,
       ROUND(100 * COUNT(*) / SUM(COUNT(*)) OVER (), 1) AS pct
FROM wp_posts
WHERE post_type = 'shop_order'
GROUP BY post_status
ORDER BY orders DESC;

-- B2. מגמה חודשית
SELECT DATE_FORMAT(post_date_gmt, '%Y-%m')                          AS month,
       SUM(post_status IN ('wc-processing','wc-completed'))         AS succeeded,
       SUM(post_status = 'wc-pending')                              AS pending,
       SUM(post_status = 'wc-failed')                               AS failed,
       ROUND(100 * SUM(post_status IN ('wc-pending','wc-failed'))
             / NULLIF(SUM(post_status IN ('wc-pending','wc-failed','wc-processing','wc-completed')), 0), 1) AS incomplete_pct
FROM wp_posts
WHERE post_type = 'shop_order'
  AND post_status <> 'wc-checkout-draft'
  AND post_date_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH)
GROUP BY month
ORDER BY month DESC;

-- B3. ביצועים לפי שער תשלום
SELECT COALESCE(NULLIF(pm.meta_value, ''), '(ללא שער)')             AS gateway,
       SUM(p.post_status IN ('wc-processing','wc-completed'))       AS succeeded,
       SUM(p.post_status = 'wc-pending')                            AS pending,
       SUM(p.post_status = 'wc-failed')                             AS failed,
       ROUND(100 * SUM(p.post_status IN ('wc-pending','wc-failed'))
             / NULLIF(SUM(p.post_status IN ('wc-pending','wc-failed','wc-processing','wc-completed')), 0), 1) AS incomplete_pct
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_payment_method'
WHERE p.post_type = 'shop_order'
  AND p.post_status <> 'wc-checkout-draft'
  AND p.post_date_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
GROUP BY gateway
ORDER BY (pending + failed) DESC;

-- B4. הזמנות pending/failed שיש להן transaction_id — שולמו ולא עודכנו
SELECT p.ID, p.post_status, p.post_date_gmt,
       MAX(CASE WHEN pm.meta_key = '_payment_method'  THEN pm.meta_value END) AS gateway,
       MAX(CASE WHEN pm.meta_key = '_transaction_id'  THEN pm.meta_value END) AS txn,
       MAX(CASE WHEN pm.meta_key = '_order_total'     THEN pm.meta_value END) AS total
FROM wp_posts p
JOIN wp_postmeta pm ON pm.post_id = p.ID
WHERE p.post_type = 'shop_order'
  AND p.post_status IN ('wc-pending','wc-failed')
GROUP BY p.ID, p.post_status, p.post_date_gmt
HAVING txn IS NOT NULL AND txn <> ''
ORDER BY p.post_date_gmt DESC
LIMIT 100;


-- =====================================================================
-- בדיקות תשתית (זהות בשני המבנים)
-- =====================================================================

-- C1. תור Action Scheduler — האם הוא תקוע?
SELECT status,
       COUNT(*) AS actions,
       MIN(scheduled_date_gmt) AS oldest,
       MAX(scheduled_date_gmt) AS newest
FROM wp_actionscheduler_actions
GROUP BY status;
-- פירוש: אם oldest של pending הוא לפני שעות/ימים — התור תקוע. זו סיבה ישירה
--         להזמנות שלא מתעדכנות אחרי תשלום.

-- C2. אילו hooks נכשלים
SELECT hook, COUNT(*) AS failures, MAX(scheduled_date_gmt) AS last_failure
FROM wp_actionscheduler_actions
WHERE status = 'failed'
GROUP BY hook
ORDER BY failures DESC
LIMIT 20;

-- C3. נפח autoload — משפיע ישירות על מהירות כל בקשה
SELECT COUNT(*) AS autoloaded_options,
       ROUND(SUM(LENGTH(option_value)) / 1024, 1) AS total_kb
FROM wp_options
WHERE autoload IN ('yes','on','auto','auto-on');
-- יעד: מתחת ל-300 KB. מעל 800 KB — בעיית ביצועים ממשית.

-- C4. עשרים האפשרויות הכבדות ב-autoload
SELECT option_name, ROUND(LENGTH(option_value) / 1024, 1) AS kb
FROM wp_options
WHERE autoload IN ('yes','on','auto','auto-on')
ORDER BY LENGTH(option_value) DESC
LIMIT 20;

-- C5. גודל טבלאות ועודף
SELECT table_name,
       ROUND((data_length + index_length) / 1048576, 2) AS size_mb,
       ROUND(data_free / 1048576, 2)                    AS overhead_mb,
       table_rows,
       engine
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC
LIMIT 25;

-- C6. סשנים של ווקומרס שפג תוקפם
SELECT COUNT(*) AS total_sessions,
       SUM(session_expiry < UNIX_TIMESTAMP()) AS expired_sessions
FROM wp_woocommerce_sessions;

-- C7. transients פגי תוקף
SELECT COUNT(*) AS expired_transients
FROM wp_options
WHERE option_name LIKE '\_transient\_timeout\_%'
  AND option_value < UNIX_TIMESTAMP();

-- C8. שורות postmeta יתומות
SELECT COUNT(*) AS orphan_postmeta
FROM wp_postmeta pm
LEFT JOIN wp_posts p ON p.ID = pm.post_id
WHERE p.ID IS NULL;
