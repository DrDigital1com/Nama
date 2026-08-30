#!/usr/bin/env bash
# =====================================================================
# Nama — בדיקת מערכת מהירה דרך WP-CLI
# =====================================================================
# שימוש:
#   cd /path/to/wordpress
#   bash nama-cli-audit.sh            > nama-report.txt 2>&1
#   bash nama-cli-audit.sh --path=/var/www/html > nama-report.txt 2>&1
#
# דורש: WP-CLI מותקן, הרשאת קריאה לאתר, WooCommerce פעיל.
# כל הפעולות הן קריאה בלבד — הסקריפט לא משנה דבר באתר.
# =====================================================================

set -uo pipefail

WP_ARGS=("$@")
wpx() { wp "$@" "${WP_ARGS[@]}" 2>&1; }

hr()  { printf '\n%s\n' "======================================================================"; }
sec() { hr; printf '%s\n' "$1"; hr; }

printf 'Nama WooCommerce Audit — %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S UTC')"

sec "0. זיהוי האתר"
wpx option get siteurl
wpx core version
wpx plugin get woocommerce --field=version 2>/dev/null || echo "WooCommerce לא נמצא"
php -v | head -1
wpx db size --tables --format=table 2>/dev/null | head -30

sec "1. תוספים פעילים"
wpx plugin list --status=active --fields=name,version,update --format=table

sec "2. תוספים שממתינים לעדכון"
wpx plugin list --update=available --fields=name,version,update_version --format=table

sec "3. תבנית"
wpx theme list --fields=name,status,version,update --format=table

sec "4. פילוח הזמנות לפי סטטוס"
PREFIX="$(wpx db prefix | tr -d '[:space:]')"
HPOS="$(wpx db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=DATABASE() AND table_name='${PREFIX}wc_orders';" --skip-column-names 2>/dev/null | tr -d '[:space:]')"

if [ "${HPOS:-0}" = "1" ]; then
  echo "-- מבנה: HPOS (${PREFIX}wc_orders) --"
  OT="${PREFIX}wc_orders"; OS="status"; OD="date_created_gmt"; OW="type='shop_order'"
  wpx db query "SELECT status, COUNT(*) AS orders FROM ${OT} WHERE ${OW} GROUP BY status ORDER BY orders DESC;"

  sec "5. מגמה חודשית (12 חודשים)"
  wpx db query "SELECT DATE_FORMAT(${OD},'%Y-%m') AS month,
      SUM(${OS} IN ('wc-processing','wc-completed')) AS succeeded,
      SUM(${OS}='wc-pending') AS pending,
      SUM(${OS}='wc-failed')  AS failed,
      ROUND(100*SUM(${OS} IN ('wc-pending','wc-failed'))/NULLIF(SUM(${OS} IN ('wc-pending','wc-failed','wc-processing','wc-completed')),0),1) AS incomplete_pct
    FROM ${OT} WHERE ${OW} AND ${OS}<>'wc-checkout-draft'
      AND ${OD} >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month DESC;"

  sec "6. ביצועים לפי שער תשלום (6 חודשים)"
  wpx db query "SELECT COALESCE(NULLIF(payment_method,''),'(ללא שער)') AS gateway,
      SUM(${OS} IN ('wc-processing','wc-completed')) AS succeeded,
      SUM(${OS}='wc-pending') AS pending,
      SUM(${OS}='wc-failed')  AS failed,
      ROUND(100*SUM(${OS} IN ('wc-pending','wc-failed'))/NULLIF(SUM(${OS} IN ('wc-pending','wc-failed','wc-processing','wc-completed')),0),1) AS incomplete_pct
    FROM ${OT} WHERE ${OW} AND ${OS}<>'wc-checkout-draft'
      AND ${OD} >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
    GROUP BY gateway ORDER BY (pending+failed) DESC;"

  sec "7. *** הזמנות ששולמו אך לא עודכנו (transaction_id קיים אך סטטוס pending/failed) ***"
  wpx db query "SELECT id, status, date_created_gmt, payment_method, total_amount, transaction_id
    FROM ${OT} WHERE ${OW} AND ${OS} IN ('wc-pending','wc-failed')
      AND transaction_id IS NOT NULL AND transaction_id<>''
    ORDER BY ${OD} DESC LIMIT 50;"

  sec "8. גיל ההזמנות התקועות"
  wpx db query "SELECT CASE
      WHEN ${OD} > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)  THEN '0-1h'
      WHEN ${OD} > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN '1-24h'
      WHEN ${OD} > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)   THEN '1-7d'
      WHEN ${OD} > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)  THEN '7-30d'
      ELSE '30d+' END AS age_bucket, COUNT(*) AS orders
    FROM ${OT} WHERE ${OW} AND ${OS}='wc-pending' GROUP BY age_bucket;"
else
  echo "-- מבנה: posts מסורתי (${PREFIX}posts) --"
  wpx db query "SELECT post_status AS status, COUNT(*) AS orders FROM ${PREFIX}posts WHERE post_type='shop_order' GROUP BY post_status ORDER BY orders DESC;"

  sec "5. מגמה חודשית (12 חודשים)"
  wpx db query "SELECT DATE_FORMAT(post_date_gmt,'%Y-%m') AS month,
      SUM(post_status IN ('wc-processing','wc-completed')) AS succeeded,
      SUM(post_status='wc-pending') AS pending,
      SUM(post_status='wc-failed')  AS failed
    FROM ${PREFIX}posts WHERE post_type='shop_order' AND post_status<>'wc-checkout-draft'
      AND post_date_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month DESC;"

  sec "6. ביצועים לפי שער תשלום (6 חודשים)"
  wpx db query "SELECT COALESCE(NULLIF(pm.meta_value,''),'(ללא שער)') AS gateway,
      SUM(p.post_status IN ('wc-processing','wc-completed')) AS succeeded,
      SUM(p.post_status='wc-pending') AS pending,
      SUM(p.post_status='wc-failed')  AS failed
    FROM ${PREFIX}posts p
    LEFT JOIN ${PREFIX}postmeta pm ON pm.post_id=p.ID AND pm.meta_key='_payment_method'
    WHERE p.post_type='shop_order' AND p.post_status<>'wc-checkout-draft'
      AND p.post_date_gmt >= DATE_SUB(UTC_DATE(), INTERVAL 6 MONTH)
    GROUP BY gateway ORDER BY (pending+failed) DESC;"

  sec "7. *** הזמנות ששולמו אך לא עודכנו ***"
  wpx db query "SELECT p.ID, p.post_status, p.post_date_gmt,
      MAX(CASE WHEN pm.meta_key='_payment_method' THEN pm.meta_value END) AS gateway,
      MAX(CASE WHEN pm.meta_key='_transaction_id' THEN pm.meta_value END) AS txn
    FROM ${PREFIX}posts p JOIN ${PREFIX}postmeta pm ON pm.post_id=p.ID
    WHERE p.post_type='shop_order' AND p.post_status IN ('wc-pending','wc-failed')
    GROUP BY p.ID, p.post_status, p.post_date_gmt
    HAVING txn IS NOT NULL AND txn<>'' ORDER BY p.post_date_gmt DESC LIMIT 50;"
fi

sec "9. תור Action Scheduler"
wpx db query "SELECT status, COUNT(*) AS actions, MIN(scheduled_date_gmt) AS oldest
  FROM ${PREFIX}actionscheduler_actions GROUP BY status;"
echo "-- hooks שנכשלו --"
wpx db query "SELECT hook, COUNT(*) AS failures FROM ${PREFIX}actionscheduler_actions
  WHERE status='failed' GROUP BY hook ORDER BY failures DESC LIMIT 15;"

sec "10. WP-Cron — משימות באיחור"
wpx cron event list --fields=hook,next_run_relative,recurrence --format=table | head -40
echo "-- בדיקת תקינות הקרון --"
wpx cron test

sec "11. Autoload — נפח שנטען בכל בקשה"
wpx db query "SELECT COUNT(*) AS autoloaded, ROUND(SUM(LENGTH(option_value))/1024,1) AS total_kb
  FROM ${PREFIX}options WHERE autoload IN ('yes','on','auto','auto-on');"
echo "-- 15 הכבדות ביותר --"
wpx db query "SELECT option_name, ROUND(LENGTH(option_value)/1024,1) AS kb
  FROM ${PREFIX}options WHERE autoload IN ('yes','on','auto','auto-on')
  ORDER BY LENGTH(option_value) DESC LIMIT 15;"

sec "12. גודל טבלאות ועודף"
wpx db query "SELECT table_name, ROUND((data_length+index_length)/1048576,2) AS size_mb,
    ROUND(data_free/1048576,2) AS overhead_mb, table_rows, engine
  FROM information_schema.TABLES WHERE table_schema=DATABASE()
  ORDER BY (data_length+index_length) DESC LIMIT 20;"

sec "13. סשנים ו-transients"
wpx db query "SELECT COUNT(*) AS sessions, SUM(session_expiry < UNIX_TIMESTAMP()) AS expired
  FROM ${PREFIX}woocommerce_sessions;"
wpx db query "SELECT COUNT(*) AS expired_transients FROM ${PREFIX}options
  WHERE option_name LIKE '\_transient\_timeout\_%' AND option_value < UNIX_TIMESTAMP();"

sec "14. הגדרות ווקומרס קריטיות"
for opt in woocommerce_hold_stock_minutes woocommerce_manage_stock woocommerce_enable_guest_checkout \
           woocommerce_checkout_page_id woocommerce_cart_page_id woocommerce_currency \
           woocommerce_custom_orders_table_enabled woocommerce_enable_ajax_add_to_cart; do
  printf '%-48s = %s\n' "$opt" "$(wpx option get "$opt" 2>/dev/null || echo '(לא מוגדר)')"
done

sec "15. יומני WooCommerce — 40 שורות שגיאה אחרונות"
LOGDIR="$(wpx eval 'echo defined("WC_LOG_DIR") ? WC_LOG_DIR : "";' 2>/dev/null | tr -d '\r')"
if [ -n "${LOGDIR}" ] && [ -d "${LOGDIR}" ]; then
  grep -rihE 'critical|error|declined|timeout|invalid|refus|signature' "${LOGDIR}" 2>/dev/null | tail -40
else
  echo "תיקיית יומנים לא נמצאה"
fi

sec "16. debug.log — 40 שורות אחרונות"
DBG="$(wpx eval 'echo WP_CONTENT_DIR;' 2>/dev/null | tr -d '\r')/debug.log"
if [ -f "${DBG}" ]; then
  ls -lh "${DBG}"
  tail -40 "${DBG}"
else
  echo "debug.log לא קיים (יומן שגיאות כבוי)"
fi

sec "17. בדיקת בריאות רשמית של WordPress"
wpx site health check 2>/dev/null || echo "(פקודה לא זמינה בגרסת WP-CLI הזו)"

hr
echo "סיום. יש לשלוח את הקובץ כולו לניתוח."
