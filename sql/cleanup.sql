-- =====================================================================
-- Nama — ניקוי בסיס נתונים
-- =====================================================================
-- קידומת הטבלאות באתר: wpgh_
--
-- ⚠️  להריץ אך ורק אחרי גיבוי מלא, ובשעת עומס נמוכה.
-- ⚠️  בניגוד ל-sql/orders-diagnostics.sql, הקובץ הזה משנה נתונים.
--     להריץ בלוק-בלוק ולקרוא את ההערה מעל כל בלוק.
-- =====================================================================


-- ---------------------------------------------------------------------
-- בלוק 1 — שחרור השטח המבוזבז ב-wp_options   ⭐ הפעולה המשמעותית ביותר
-- ---------------------------------------------------------------------
-- המצב שנמדד: 15.3MB נתונים מול 427MB שטח מבוזבז.
-- הטבלה הזו נקראת בכל בקשה בודדת לאתר.
-- הפעולה בונה את הטבלה מחדש ונועלת אותה לכמה שניות.

OPTIMIZE TABLE wpgh_options;

-- לאימות (עמודת data_free אמורה לרדת כמעט לאפס):
SELECT table_name,
       ROUND((data_length + index_length) / 1048576, 2) AS size_mb,
       ROUND(data_free / 1048576, 2)                    AS free_mb
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name = 'wpgh_options';


-- ---------------------------------------------------------------------
-- בלוק 2 — transients פגי תוקף
-- ---------------------------------------------------------------------
-- בטוח לחלוטין: וורדפרס מייצרת אותם מחדש לפי הצורך.
-- קודם המחיקה של רשומות ה-timeout, ואז של הערכים היתומים שנשארו.

DELETE FROM wpgh_options
WHERE option_name LIKE '\_transient\_timeout\_%'
  AND option_value < UNIX_TIMESTAMP();

DELETE o FROM wpgh_options o
LEFT JOIN wpgh_options t
       ON t.option_name = CONCAT('_transient_timeout_',
                                 SUBSTRING(o.option_name, 12))
WHERE o.option_name LIKE '\_transient\_%'
  AND o.option_name NOT LIKE '\_transient\_timeout\_%'
  AND t.option_id IS NULL;


-- ---------------------------------------------------------------------
-- בלוק 3 — סשני ווקומרס שפג תוקפם
-- ---------------------------------------------------------------------
-- בטוח: סשן שפג ממילא אינו בשימוש.
-- הטבלה נמדדה עם 18MB שטח מבוזבז מול 4MB נתונים.

DELETE FROM wpgh_woocommerce_sessions
WHERE session_expiry < UNIX_TIMESTAMP();

OPTIMIZE TABLE wpgh_woocommerce_sessions;


-- ---------------------------------------------------------------------
-- בלוק 4 — היסטוריית Action Scheduler
-- ---------------------------------------------------------------------
-- נמדדו 19,580 פעולות (34MB) ו-64,029 שורות יומן (12MB).
-- מוחקים רק פעולות שהושלמו ובוטלו, ורק כאלה מלפני יותר מחודש.
-- ⚠️  לא נוגעים ב-pending, in-progress או failed — הן עדיין רלוונטיות.
--
-- אם יש WP-CLI, עדיף להשתמש בפקודה הרשמית שמטפלת גם ביומנים:
--     wp action-scheduler clean --batch-size=1000 --before='1 month ago'

DELETE l FROM wpgh_actionscheduler_logs l
JOIN wpgh_actionscheduler_actions a ON a.action_id = l.action_id
WHERE a.status IN ('complete', 'canceled')
  AND a.scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH);

DELETE FROM wpgh_actionscheduler_actions
WHERE status IN ('complete', 'canceled')
  AND scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH);

OPTIMIZE TABLE wpgh_actionscheduler_actions;
OPTIMIZE TABLE wpgh_actionscheduler_logs;


-- ---------------------------------------------------------------------
-- בלוק 5 — postmeta יתומים
-- ---------------------------------------------------------------------
-- 112 שורות בלבד. השפעה זניחה, אבל ניקיון.

DELETE pm FROM wpgh_postmeta pm
LEFT JOIN wpgh_posts p ON p.ID = pm.post_id
WHERE p.ID IS NULL;


-- ---------------------------------------------------------------------
-- בלוק 6 — טבלאות שרידים   ⚠️  לבדוק לפני, לא להריץ עיוור
-- ---------------------------------------------------------------------
-- א. wpgh_hustle_tracking (18,138 שורות) — תוסף Hustle אינו ברשימת
--    התוספים הפעילים. אם הוא לא מותקן כלל, הטבלה מיותרת.
--
--    DROP TABLE wpgh_hustle_tracking;
--
-- ב. טבלאות בקידומת wpq5_ — התקנת וורדפרס אחרת על אותו בסיס נתונים.
--    ⚠️  לוודא מול האחסון שאין אתר חי שמשתמש בהן. אחרת הוא יישבר.
--
--    לרשימה:
--    SELECT table_name,
--           ROUND((data_length + index_length) / 1048576, 2) AS size_mb
--    FROM information_schema.TABLES
--    WHERE table_schema = DATABASE() AND table_name LIKE 'wpq5\_%';


-- ---------------------------------------------------------------------
-- בלוק 7 — wpgh_wpml_mails (47MB, הטבלה הגדולה באתר)
-- ---------------------------------------------------------------------
-- טבלת יומן מיילים. לפני מחיקה כדאי לראות מה יש בה ומאיזו תקופה:

SELECT COUNT(*) AS total,
       MIN(date_created) AS oldest,
       MAX(date_created) AS newest
FROM wpgh_wpml_mails;

-- אם היא רק יומן ואין בה ערך תפעולי, אפשר לגזום רשומות ישנות.
-- ⚠️  לאמת קודם ששם עמודת התאריך נכון בגרסה שמותקנת אצלך.
--
-- DELETE FROM wpgh_wpml_mails
-- WHERE date_created < DATE_SUB(NOW(), INTERVAL 3 MONTH);
-- OPTIMIZE TABLE wpgh_wpml_mails;


-- ---------------------------------------------------------------------
-- אימות סופי
-- ---------------------------------------------------------------------
SELECT table_name,
       ROUND((data_length + index_length) / 1048576, 2) AS size_mb,
       ROUND(data_free / 1048576, 2)                    AS free_mb,
       table_rows
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC
LIMIT 15;

-- סך העודף אמור לרדת מ-571MB לפחות מ-20MB.
