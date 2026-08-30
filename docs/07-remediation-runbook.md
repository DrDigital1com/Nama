# ספר הפעולות — תיקון nama-c.com

מבוסס על ממצאי 30.8.2026 (`docs/06-findings-2026-08-30.md`).

**קידומת טבלאות באתר: `wpgh_`** · נתיב: `/home/drdignam/public_html/` · cPanel + LiteSpeed + Cloudflare.

---

## לפני שמתחילים

1. **גיבוי מלא** — קבצים + בסיס נתונים. יש Migrate Guru מותקן; אפשר גם דרך cPanel → Backup.
2. **לתעד מצב פתיחה:** להריץ PageSpeed Insights על דף הבית ועל עמוד מוצר, ולשמור צילום.
3. **סדר:** שלב אחד בכל פעם. אחרי כל שלב — לבצע **הזמנת בדיקה אמיתית** מקצה לקצה.
4. **שעה:** את שלבים 4 ו-5 לבצע בשעת עומס נמוכה (03:00–06:00).

---

# שלב 1 — מיילים לא נשלחים ⚠️ הכי דחוף

**למה ראשון:** לקוחות ששילמו לא מקבלים אישור הזמנה. זו פגיעה ישירה בלקוח, וזה גם מסתיר ממך הזמנות שהצליחו.

## אבחון
1. **WP Mail Logging → Logs** — לפתוח כשל אחרון ולקרוא את השגיאה המלאה.
2. **WP Mail SMTP → Settings** — לראות איזה Mailer מוגדר (כנראה Gmail / Google Workspace).
3. **WP Mail SMTP → Tools → Email Test** — לשלוח מייל בדיקה. השגיאה המלאה תופיע שם.

## התיקון
השגיאה מכילה `type.googleapis.com/google.rpc.ErrorInfo` — כלומר הבעיה בהרשאת Google. שלוש אפשרויות, לפי מה שהבדיקה מראה:

**א. טוקן OAuth פג / ההרשאה נשללה**
WP Mail SMTP → Settings → Gmail → **Remove OAuth Connection** → **Allow plugin to send emails using your Google account** → לאשר מחדש עם החשבון הנכון.

**ב. חריגה ממכסת השליחה**
Google Workspace מגביל שליחה יומית. אם זו הסיבה — לעבור לספק ייעודי.

**ג. הפתרון היציב (מומלץ)**
לעבור ל-Mailer ייעודי לדואר עסקי: **Brevo**, **SendLayer**, **Mailgun** או **Postmark**. הם לא מוגבלים במכסות Gmail ולא נופלים על OAuth שפג.
WP Mail SMTP → Settings → Mailer → לבחור ספק → להזין API key → לאמת ב-Email Test.

## אימות
- Email Test מחזיר הצלחה
- לבצע הזמנת בדיקה → לוודא שהתקבל מייל אישור אצל הלקוח **ואצל המנהל**
- אחרי 24 שעות: `WooCommerce → סטטוס → יומנים → transactional-emails` — אפס שורות `failed to send`

---

# שלב 2 — OPcache מלא

**המצב:** 127.98MB בשימוש, **0.02MB פנוי**, hit rate 58.2%. הזיכרון גמור, 42% מהבקשות מקמפלות PHP מחדש.

## התיקון
**cPanel → MultiPHP INI Editor → Editor Mode** → לבחור את הדומיין → להוסיף/לעדכן:

```ini
opcache.enable=1
opcache.memory_consumption=512
opcache.interned_strings_buffer=64
opcache.max_accelerated_files=50000
opcache.revalidate_freq=2
opcache.validate_timestamps=1
opcache.save_comments=1
opcache.max_wasted_percentage=10
```

> `memory_consumption=512` ולא 256: 30 תוספים + WPML + Elementor + Woodmart מגיעים בקלות מעל 128MB, ואת זה כבר ראינו במדידה.

אם אין MultiPHP INI Editor — לפנות לתמיכת האחסון עם הבלוק הזה. זו בקשה שגרתית.

## אימות
להריץ שוב `/wp-admin/?nama_audit=1` ולהסתכל במקטע 1:
- **יעד:** hit rate מעל 97%, ו"פנוי" מעל 100MB
- אם hit rate עדיין נמוך אחרי יממה — להעלות ל-768MB

---

# שלב 3 — Redis Object Cache (התיקון לצ׳קאאוט של 4.5 שניות)

**המצב:** עמוד תשלום 4,454ms, עגלה 2,914ms, מול 96ms בדף הבית.

עמודי עגלה/תשלום דינמיים ולא נהנים ממטמון דפים. רק Object Cache עוזר להם.

## התיקון
1. **לוודא ש-Redis זמין:** cPanel → לחפש "Redis". אם אין — לפתוח פנייה לאחסון: *"אני צריך Redis server + PHP redis extension עבור הדומיין nama-c.com"*.
2. להתקין את התוסף **Redis Object Cache** (Till Krüss).
3. ב-`wp-config.php`, **לפני** השורה `/* That's all, stop editing! */`:
   ```php
   define( 'WP_REDIS_HOST', '127.0.0.1' );
   define( 'WP_REDIS_PORT', 6379 );
   define( 'WP_REDIS_DATABASE', 0 );
   define( 'WP_REDIS_PREFIX', 'namac_' );
   define( 'WP_REDIS_MAXTTL', 86400 );
   ```
   > `WP_REDIS_PREFIX` הוא חובה — יש עוד התקנה על השרת (טבלאות `wpq5_`). בלי קידומת שונה שני האתרים ידרסו זה את המטמון של זה.
4. **Settings → Redis → Enable Object Cache**

## אימות
- בתוסף: Status = Connected, Hit Ratio מעל 90% אחרי יום
- להריץ שוב את האודיט: מקטע 6 — **יעד: עמוד תשלום מתחת ל-800ms**
- **מיד אחרי ההפעלה: לבצע הזמנת בדיקה.** Object Cache שגוי יכול לשבור סשנים של עגלה.

## אם אין Redis באחסון
אין תחליף אמיתי. מה שכן אפשר לעשות בינתיים:
- לכבות תוספים שלא בשימוש (ראה שלב 8)
- להשתמש ב-WP Rocket → Preload כדי לחמם עמודים סטטיים

---

# שלב 4 — ניקוי בסיס הנתונים

**המצב:** `wpgh_options` מכילה 15.3MB נתונים ו-**427MB שטח מבוזבז**. הטבלה נקראת בכל בקשה.

> **אחרי גיבוי בלבד, בשעת עומס נמוכה.** הפקודות ב-`sql/cleanup.sql`.

```sql
-- 1. לשחרר את השטח המבוזבז (הפעולה הכי משמעותית)
OPTIMIZE TABLE wpgh_options;

-- 2. transients פגי תוקף
DELETE FROM wpgh_options
WHERE option_name LIKE '\_transient\_timeout\_%'
  AND option_value < UNIX_TIMESTAMP();

-- 3. סשנים שפג תוקפם
DELETE FROM wpgh_woocommerce_sessions WHERE session_expiry < UNIX_TIMESTAMP();
```

ואם יש WP-CLI:
```bash
wp transient delete --expired
wp action-scheduler clean --batch-size=1000 --before='1 month ago'
wp db optimize
```

**שרידים למחיקה** — רק אחרי אימות שהתוסף באמת לא בשימוש:
- `wpgh_hustle_tracking` (18,138 שורות) — תוסף Hustle אינו פעיל
- טבלאות בקידומת `wpq5_` — התקנה אחרת. **לוודא מול האחסון שאין אתר חי שמשתמש בהן.**

> **מניעת חזרה:** אחרי שלב 3, ה-transients יישמרו ב-Redis ולא ב-`wp_options`. זה מונע את הצטברות הבלגן מחדש.

## אימות
להריץ את האודיט: מקטע 7 — עודף אמור לרדת מ-571MB לפחות מ-20MB.

---

# שלב 5 — עמוד החנות נמחק

**המצב:** `woocommerce_shop_page_id = 10`, אבל הפוסט הזה נמחק.

## התיקון
1. לבדוק אם קיים עמוד "Shop"/"חנות" מפורסם: **עמודים → כל העמודים**
2. אם לא — ליצור עמוד חדש בשם "חנות" (ריק; ווקומרס מציגה בו את המוצרים)
3. **WooCommerce → הגדרות → מוצרים → עמוד חנות** → לבחור אותו
4. **WPML:** לוודא שיש תרגום לעמוד בכל שפה פעילה
5. **הגדרות → קישורים ידידותיים → שמירה** (מרענן את מבנה הקישורים)

## אימות
לפתוח את `nama-c.com/shop/` — צריכה להופיע רשימת המוצרים, לא 404.

---

# שלב 6 — תמונות

**המצב:** 104 תמונות מעל 300KB. הכבדות: 21MB, 14.6MB, 14.1MB, 13.8MB, 11.8MB. סה"כ 279MB ל-35 מוצרים בלבד.

## התיקון
1. **לפני הכל — לבדוק אם הכבדות בכלל בשימוש.** קבצי `shutterstock_*` בגודל 14–21MB הם כמעט תמיד מקור שהועלה בטעות. אם לא בשימוש — למחוק (חוסך מאות MB).
2. להתקין **ShortPixel** או **Imagify**.
3. הגדרות: **Lossy**, המרה אוטומטית ל-**WebP**, הגשה עם `<picture>`, וגם שינוי גודל מקסימלי ל-**2048px**.
4. להריץ **Bulk Optimize** על כל ספריית המדיה.
5. ב-**WP Rocket → Media**: לוודא ש-LazyLoad פעיל לתמונות.

> יש כבר 116 קבצי WebP — כלומר ההמרה קיימת חלקית ולא הורצה על הכל.

## אימות
PageSpeed Insights על עמוד מוצר — **יעד LCP מתחת ל-2.5 שניות**.

---

# שלב 7 — WP Rocket: החרגות הצ׳קאאוט

**המצב היום תקין:** `cf-cache-status: DYNAMIC` על עגלה ותשלום — Cloudflare לא מגיש אותם ממטמון. **לא לשנות את זה.**

מה שכן צריך לוודא ב-**WP Rocket → File Optimization**, ברשימות ההחרגה של Minify/Combine ושל Delay JavaScript:

```
woocommerce/assets/js/frontend/checkout
woocommerce/assets/js/frontend/add-to-cart
wc-checkout
wc-cart-fragments
jquery.blockUI
WCGatewayTranzila
```

ו-**WP Rocket → Advanced Rules → Never Cache URLs**:
```
/cart/
/checkout/
/my-account/
```

> WP Rocket מזהה ווקומרס ומחיל חלק מזה אוטומטית — אבל עם WPML + YayCurrency + טרנזילה כדאי לוודא ידנית.

## אימות
```bash
curl -sSI https://nama-c.com/checkout/ | grep -i cf-cache-status
```
צריך להחזיר `DYNAMIC` או `BYPASS`. **לעולם לא `HIT`.**

---

# שלב 8 — תוספים

30 תוספים פעילים. שווה בדיקה עסקית:

| תוסף | שאלה |
|---|---|
| AffiliateWP **וגם** SliceWP + SliceWP Pro + Cross Site Tracking | **שתי מערכות שותפים במקביל.** שתיהן טוענות קוד בכל בקשה, כולל בצ׳קאאוט. האם שתיהן באמת בשימוש? |
| Order Export & Order Import | בשימוש שוטף או חד-פעמי? |
| Duplicate Page | כלי פיתוח — אפשר לכבות בייצור |
| Migrate Guru | להפעיל רק בזמן העברה |
| Classic Editor | נחוץ? |

**עדכונים ממתינים** — לבצע ב-staging תחילה:
- **Tranzila Gateway** 0.0.24.6 → יש עדכון. **חובה לבדוק הזמנה מלאה אחרי העדכון.**
- **Elementor Pro** 4.2.1 → יש עדכון

---

# שלב 9 — דריסות תבנית

4 קבצים מיושנים בתבנית הבת. **אף אחד מהם אינו תבנית צ׳קאאוט**, ולכן זו תחזוקה ולא תיקון תקלה:

| קובץ | בתבנית | בליבה |
|---|---|---|
| `cart/mini-cart.php` | 10.0.0 | 11.0.0 |
| `cart/cart.php` | 10.1.0 | 11.0.0 |
| `single-product/add-to-cart/grouped.php` | 10.2.0 | 11.0.0 |
| `single-product/add-to-cart/variable.php` | 9.6.0 | 10.9.0 |

להשוות כל קובץ מול `wp-content/plugins/woocommerce/templates/<אותו נתיב>` ולמזג את השינויים החדשים. לא להחליף עיוור — יש שם התאמות של Woodmart.

---

# שלב 10 — קוד: שיפורים בטוחים

יש לך **WPCode Lite** מותקן. הקטעים בתיקייה `snippets/` מוכנים להדבקה:

| קובץ | מה הוא עושה | סיכון |
|---|---|---|
| `01-cart-fragments.php` | מבטל את קריאת ה-AJAX של העגלה בעמודים שאינם חנות | **בינוני** — לקרוא את האזהרה בקובץ |
| `02-lcp-product-image.php` | טוען את תמונת המוצר הראשית מיד במקום lazy | נמוך |
| `03-cleanup.php` | מגביל גרסאות פוסטים ומאט את ה-Heartbeat | נמוך |

**WPCode → Add Snippet → Add Your Custom Code → PHP Snippet** → להדביק → **Save & Activate**.

---

# סיכום: סדר, זמן, השפעה

| # | פעולה | זמן | השפעה |
|---|---|---|---|
| 1 | תיקון שליחת מיילים | 30 דק׳ | לקוחות מקבלים אישור |
| 2 | OPcache ל-512MB | 10 דק׳ | 20–40% בכל בקשה |
| 3 | Redis Object Cache | 1–2 שעות | הצ׳קאאוט מ-4.5ש׳ ל-<1ש׳ |
| 4 | ניקוי DB | 30 דק׳ | 427MB, שאילתות מהירות |
| 5 | עמוד חנות | 15 דק׳ | עמוד שבור |
| 6 | דחיסת תמונות | 2–3 שעות | LCP |
| 7 | החרגות WP Rocket | 20 דק׳ | יציבות צ׳קאאוט |
| 8 | ניקוי תוספים | משתנה | TTFB |
| 9 | דריסות תבנית | 1–2 שעות | תחזוקה |
| 10 | קטעי קוד | 15 דק׳ | שיפור נוסף |

---

# מה שספר הפעולות הזה לא פותר

שלבים 1–10 מטפלים ב**ביצועים, בתשתית ובתקלות שזוהו**. הם ישפרו את חוויית הצ׳קאאוט מהותית, ומן הסתם גם את שיעור ההמרה.

**אבל הם לא עונים על השאלה המרכזית:** האם 867 ההזמנות שבוטלו הן לקוחות שנטשו בעמוד של טרנזילה, או לקוחות ששילמו וההודעה לא חזרה לאתר.

זו שאלה של נתונים, לא של תיקון. שתי דרכים לענות עליה, ושתיהן קצרות:
1. **להריץ את אודיט 1.2.0** ולשלוח את מקטע **4ב** — הוא בודק אם להזמנות שבוטלו יש מזהה עסקה.
2. **להוציא דוח עסקאות מטרנזילה** לחודש האחרון ולהצליב מול ההזמנות שבוטלו באותן שעות.

אם יתברר שהלקוחות שילמו — זו תקלה נפרדת ודחופה בהרבה מכל מה שכאן, ויש כסף שצריך להחזיר או הזמנות שצריך לשלוח.
