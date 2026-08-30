# תוכנית שיפור מהירות — WooCommerce

> סדר העבודה כאן הוא לפי **יחס תועלת/מאמץ**. שלבים 1–4 נותנים את רוב השיפור בשעות ספורות. אין טעם לגעת בשלב 8 לפני שהושלמו 1–4.

---

## איך למדוד נכון (לפני שנוגעים במשהו)

מדידה אחת אינה מדידה. לפני כל שינוי, לתעד בסיס השוואה:

| כלי | מה מודדים | הערה |
|---|---|---|
| PageSpeed Insights | LCP, CLS, INP + **Core Web Vitals אמיתיים** | הנתונים בשדה (CrUX) הם היחידים שמשפיעים על דירוג |
| GTmetrix / WebPageTest | Waterfall מלא, TTFB | להגדיר מיקום בדיקה קרוב לקהל היעד |
| Query Monitor (תוסף) | מספר שאילתות, זמן PHP, **איזה תוסף אשם** | הכלי החשוב ביותר. להתקין זמנית |
| `curl -w` | TTFB נטו | `curl -sS -o /dev/null -w "%{time_starttransfer}\n" https://nama-c.com/` |

**למדוד 5 עמודים, לא אחד:** דף הבית, ארכיון קטגוריה, עמוד מוצר, עגלה, תשלום.
עמודי העגלה והתשלום הם היחידים שלא נהנים ממטמון דפים — הם המבחן האמיתי של מהירות השרת.

**יעדים ריאליים לחנות ווקומרס:**

| מדד | יעד | קריטי מעל |
|---|---|---|
| TTFB (דף מקוּשר) | < 200ms | 600ms |
| TTFB (עמוד עגלה/תשלום) | < 600ms | 1500ms |
| LCP | < 2.5s | 4s |
| INP | < 200ms | 500ms |
| שאילתות DB לעמוד | < 80 | 200 |
| זמן PHP לעמוד | < 400ms | 1s |

---

## שלב 1: OPcache — התיקון היחיד הגדול ביותר

בלי OPcache, כל בקשה מקמפלת מחדש אלפי קבצי PHP. בווקומרס זה מכפיל את זמן העיבוד.

```ini
; php.ini
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.save_comments=1
```

**אימות:** בדוח `nama-audit.php`, מקטע 1, שורת OPcache. יעד hit rate: **מעל 98%**. אחוז נמוך = הזיכרון שהוקצה קטן מדי.

**שיפור צפוי:** 20–40% בזמן העיבוד של כל בקשה.

---

## שלב 2: Object Cache מתמיד (Redis)

זה השלב שהכי מוזנח והכי משפיע על **עמודי העגלה והתשלום** — בדיוק העמודים שמטמון דפים לא עוזר להם.

בלי Object Cache, כל בקשה מריצה מחדש את אותן עשרות-מאות שאילתות (אפשרויות, מטא של מוצרים, טקסונומיות).

**התקנה:**
1. Redis בשרת (`redis-server` + `php-redis`).
2. תוסף `Redis Object Cache` (Till Krüss).
3. `wp-config.php`:
   ```php
   define( 'WP_REDIS_HOST', '127.0.0.1' );
   define( 'WP_REDIS_PORT', 6379 );
   define( 'WP_REDIS_DATABASE', 0 );
   define( 'WP_REDIS_PREFIX', 'nama_' );   // חובה אם יש כמה אתרים על אותו Redis
   define( 'WP_REDIS_MAXTTL', 86400 );
   ```
4. להפעיל דרך התוסף (Enable Object Cache).

**אימות:** בדוח, מקטע 1 → "Persistent Object Cache: כן — Redis". ובתוסף עצמו — יחס Hit Rate מעל 90%.

**שיפור צפוי:** 30–60% בזמן תגובה של עמודים דינמיים.

---

## שלב 3: מטמון דפים — עם ההחרגות הנכונות

מטמון דפים מגיש HTML מוכן לאורחים ומוריד TTFB לעשרות אלפיות שנייה.

**כלל ברזל אחד:** תוסף מטמון **אחד** בלבד. שניים גורמים לשבירת צ׳קאאוט — ראה `docs/02-incomplete-orders.md` שלב 4.

**החרגות חובה** (זהות לרשימה בפלייבוק ההזמנות):
- נתיבים: `/cart/`, `/checkout/`, `/my-account/`, `/wc-api/*`, `*?add-to-cart=*`, `*?wc-ajax=*`
- קוקיז: `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`, `wordpress_logged_in_`

**המלצה:** LiteSpeed Cache (אם השרת LiteSpeed) או WP Rocket. שניהם מכירים את ווקומרס ומחילים את ההחרגות אוטומטית.

**אימות:**
```bash
curl -sSI https://nama-c.com/           | grep -iE 'x-cache|cf-cache|age'   # צריך HIT
curl -sSI https://nama-c.com/checkout/  | grep -iE 'x-cache|cf-cache|age'   # חייב BYPASS
```

---

## שלב 4: תמונות — הגורם מספר 1 ל-LCP איטי

בחנות, ה-LCP הוא כמעט תמיד תמונת מוצר.

**מה לעשות:**
1. **המרה ל-WebP** — חיסכון 25–40% ללא אובדן איכות נראה. ShortPixel / Imagify / EWWW, או ב-CDN (Cloudflare Polish).
2. **גודל נכון**: תמונת מוצר ראשית ברוחב 1200px מספיקה. לא להעלות קבצים של 3000px.
3. **יעד משקל**: מתחת ל-150KB לתמונת מוצר.
4. **Lazy loading** לכל התמונות **מלבד** תמונת ה-LCP — התמונה הראשית של המוצר או באנר ההירו חייבים להיטען מיד:
   ```php
   // functions.php של תבנית הבת — מונע lazy-load על תמונת המוצר הראשית
   add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment, $size ) {
       if ( is_product() && 'woocommerce_single' === $size ) {
           $attr['loading']       = 'eager';
           $attr['fetchpriority'] = 'high';
       }
       return $attr;
   }, 10, 3 );
   ```
5. **לצמצם גדלי תמונה רשומים.** כל גודל = עוד קובץ בכל העלאה. מעל 12 גדלים זה בזבוז דיסק וזמן.

**שיפור צפוי:** 1–3 שניות ב-LCP באתרים עם תמונות לא מאופטמות.

---

## שלב 5: לצמצם את מה שנטען

### Cart Fragments
ווקומרס טוענת `wc-cart-fragments.js` שמבצעת קריאת AJAX **בכל טעינת עמוד** כדי לעדכן את מונה העגלה. בעמודים שאינם חנות זה מיותר ומוסיף בקשה חוסמת.

```php
// functions.php — משבית fragments מחוץ לעמודי החנות
add_action( 'wp_enqueue_scripts', function () {
    if ( function_exists( 'is_woocommerce' ) &&
         ! is_woocommerce() && ! is_cart() && ! is_checkout() ) {
        wp_dequeue_script( 'wc-cart-fragments' );
    }
}, 99 );
```
> אם התבנית מציגה מונה עגלה בתפריט של כל עמוד — לא להשבית, או להחליף במונה מבוסס קוקי.

### נכסים של ווקומרס מחוץ לחנות
`woocommerce.css`, `woocommerce-layout.css`, `woocommerce-smallscreen.css` נטענים בכל עמוד באתר — כולל דף הבית והבלוג. תוספים כמו **Perfmatters** או **Asset CleanUp** מאפשרים לכבות אותם לפי עמוד.

### תוספים
כל תוסף פעיל נטען בכל בקשה — כולל קריאות AJAX של העגלה. **מעל 30 תוספים פעילים זה כמעט תמיד המקור לאיטיות.**
- למפות: איזה תוסף מייצר ערך עסקי אמיתי?
- לכבות את מה שלא בשימוש (לא רק לבטל הפעלה — למחוק).
- להשתמש ב-Query Monitor כדי לראות **איזה תוסף** צורך את הזמן.

---

## שלב 6: בסיס הנתונים

### Autoload — הרוצח השקט
כל אפשרות עם `autoload=yes` נטענת ומפוענחת **בכל בקשה בודדת**.

```sql
SELECT COUNT(*), ROUND(SUM(LENGTH(option_value))/1024,1) AS kb
FROM wp_options WHERE autoload IN ('yes','on','auto','auto-on');
```
- **מתחת ל-300KB** — תקין
- **300–800KB** — לטפל
- **מעל 800KB** — בעיה ממשית, מאות אלפיות שנייה בכל בקשה

לזהות את הכבדות (בדיקה C4 ב-SQL), ולטפל: למחוק שאריות של תוספים שהוסרו, ולהעביר ל-`autoload=no` מה שלא נחוץ בכל בקשה.

### ניקוי שוטף
```bash
wp transient delete --expired
wp action-scheduler clean --batch-size=1000 --before='1 month ago'
wp db optimize                       # אחרי גיבוי, בשעת עומס נמוכה
```

### HPOS
להפעיל את **High-Performance Order Storage** אם כל תוספי ההזמנות תומכים. מעביר הזמנות מ-`wp_posts`/`wp_postmeta` לטבלאות ייעודיות עם אינדקסים נכונים. מעל 20K הזמנות ההבדל דרמטי — במיוחד במסכי הניהול.

### MyISAM → InnoDB
טבלה ב-MyISAM נועלת את **כל הטבלה** בכל כתיבה. בשעת עומס בחנות זה יוצר תור ו-timeouts בצ׳קאאוט.
```sql
ALTER TABLE `wp_options` ENGINE=InnoDB;
```

---

## שלב 7: CDN ורשת

- **CDN** (Cloudflare / BunnyCDN) לכל הנכסים הסטטיים.
- **HTTP/2 או HTTP/3** — לאמת: `curl -sSI --http2 https://nama-c.com/ | head -1`
- **Brotli** במקום gzip — כ-15% קטן יותר.
- **Preconnect** למקורות חיצוניים קריטיים:
  ```html
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  ```
- **גופנים:** לארח מקומית, `font-display: swap`, ולטעון רק את המשקלים שבשימוש בפועל. גופן עברי מלא יכול לשקול 200KB+.
- **סקריפטים של צד שלישי** (פיקסלים, צ׳אט, מפות) — לטעון מושהה. הם לא משפיעים על TTFB אבל הורסים INP ו-TBT.

---

## שלב 8: קוד ותבנית (רק אחרי 1–7)

- **N+1 queries** בלולאות מוצרים — לאתר עם Query Monitor.
- **Transients** לתוצאות כבדות (ספירות, פילטרים, המלצות).
- **`wc_get_products()` / `WP_Query`** במקום שאילתות SQL ידניות.
- **`pre_get_posts`** להגבלת מספר המוצרים לעמוד — 12–24 ולא 100.
- **AJAX / אינסוף גלילה** לארכיוני מוצרים גדולים.

---

## סדר ביצוע מומלץ

| # | פעולה | מאמץ | השפעה |
|---|---|---|---|
| 1 | הפעלת OPcache | דקות | ★★★★★ |
| 2 | Redis Object Cache | שעה | ★★★★★ |
| 3 | מטמון דפים + החרגות נכונות | שעה | ★★★★★ |
| 4 | דחיסת תמונות + WebP | 2–4 שעות | ★★★★★ |
| 5 | ניקוי autoload | שעה | ★★★★ |
| 6 | צמצום תוספים | משתנה | ★★★★ |
| 7 | השבתת cart fragments | דקות | ★★★ |
| 8 | CDN + Brotli + HTTP/3 | שעה | ★★★ |
| 9 | HPOS + InnoDB | שעה | ★★★ |
| 10 | אופטימיזציית קוד | ימים | ★★ |

**אזהרה:** לבצע שינוי אחד בכל פעם, ולמדוד אחריו. שינוי מרובה בבת אחת מקשה לזהות מה עזר — וגם מה שבר את הצ׳קאאוט.
