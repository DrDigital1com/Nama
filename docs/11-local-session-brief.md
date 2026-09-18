# בקשה לסשן הלוקאלי — להדבקה

הטקסט המלא נמצא בבלוק למטה. להעתיק ולהדביק בשיחה
**nama-c.com WordPress/WooCommerce** שרצה לוקאלית ויכולה לשלוט בטאב כרום.

---

```
אתה רץ לוקאלית ויכול לשלוט בדפדפן — אז אתה יכול לעבוד ישירות מול
האתר. סשן קודם רץ בענן והיה חסום לאתר, ולכן כל העבודה עד עכשיו
נמסרה כקבצים. עכשיו אפשר לבצע.

=== האתר ===
https://nama-c.com  |  WooCommerce  |  cPanel: drdignam
קידומת טבלאות: wpgh_   |   אחסון הזמנות: HPOS פעיל
WordPress 7.1 · WooCommerce 11.0.1 · PHP 8.4.24 · LiteSpeed + Cloudflare
IP השרת: 91.204.209.51

הרקע המלא נמצא בריפו DrDigital1com/Nama, ענף
claude/wordpress-woocommerce-audit-ok4ig3 — במיוחד:
  docs/10-handoff.md              תדריך מלא
  docs/06-findings-2026-08-30.md  ממצאי האודיט
  sql/yesterday-cancelled.sql     השאילתות למשימה 2
אם אין לך את הריפו — תבקש ממני ואשלח, או תעבוד בלעדיו.

באתר כבר מותקן תוסף אבחון שכתבתי: להריץ אותו בכתובת
/wp-admin/?nama_audit=json  (או =1 לתצוגה).

=== המשימות, לפי הסדר ===

1. שערי תשלום — דחוף
עברנו מטרנזילה לזד קרדיט. יש גל כשלים.
   א. לוודא שרק זד קרדיט פעיל, ושהוא לא במצב בדיקה/sandbox
   ב. לכבות את שער טרנזילה: WooCommerce -> הגדרות -> תשלומים
   ג. להשבית את התוסף Tranzila Gateway — להשבית בלבד, לא למחוק.
      יש הזמנות ישנות שאולי יידרש לזכות דרכו.
   ד. לאמת מהנתונים ולא מהמסך:
      SELECT REPLACE(REPLACE(option_name,'woocommerce_',''),'_settings','') AS gw,
             CASE WHEN option_value LIKE '%s:7:"enabled";s:3:"yes"%'
                  THEN 'ACTIVE' ELSE 'off' END AS state,
             CASE WHEN option_value LIKE '%s:8:"testmode";s:3:"yes"%'
                    OR option_value LIKE '%s:7:"sandbox";s:3:"yes"%'
                  THEN 'TEST MODE!' ELSE '' END AS warn
      FROM wpgh_options
      WHERE option_name LIKE 'woocommerce\_%\_settings'
        AND option_value LIKE '%"enabled"%';

2. 7 ההזמנות שבוטלו אתמול — הכי חשוב
   SELECT id, status,
          DATE_ADD(date_created_gmt, INTERVAL 3 HOUR) AS created_il,
          TIMESTAMPDIFF(MINUTE, date_created_gmt, date_updated_gmt) AS mins,
          COALESCE(NULLIF(payment_method,''),'(empty)') AS gw,
          total_amount,
          COALESCE(NULLIF(transaction_id,''),'-') AS txn
   FROM wpgh_wc_orders
   WHERE type='shop_order'
     AND date_created_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)
   ORDER BY date_created_gmt DESC;

   איך לקרוא את זה:
   - יש txn + הסטטוס cancelled  ->  הלקוח שילם וההזמנה בוטלה.
     חמור ודחוף. לצלב מול דוח העסקאות של זד קרדיט לפני כל עדכון.
     בשום אופן לא לעדכן סטטוסים בגורף.
   - אין txn + cancelled        ->  הלקוח לא השלים תשלום בעמוד הסליקה
   - שער (empty)                ->  הצ'קאאוט נשבר לפני ההפניה לסליקה
   - mins בערך 60               ->  ביטול אוטומטי (שמירת מלאי). צפוי.
   - mins מתחת ל-5              ->  משהו ביטל אותן מיד. חשוד.

   בנוסף: לקרוא את יומן זד קרדיט מאתמול,
   WooCommerce -> סטטוס -> יומנים.

3. תצורת זד קרדיט
   החלפת שער היא חשודה מיידית לגל כשלים. לבדוק:
   - כתובת ה-callback המוגדרת במסוף זד קרדיט מול מה שהתוסף מצפה לו
   - האם POST לכתובת הזו נתקל בהפניה 301 (הפניה הופכת POST ל-GET
     ומאבדת את גוף הבקשה — שובר callback לחלוטין)
   - מפתחות מסוף בתוקף
   - מטבע ILS בשני הצדדים
   - האם המסוף מצפה לסכום באגורות או בשקלים עשרוניים

4. להתקין שני סטטוסי הזמנה
   dist/nama-order-statuses.zip בריפו — מוסיף "מוכן למשלוח" ו-
   "בחברת המשלוחים" אחרי "בטיפול". תומך HPOS, מסמן כשולם,
   פעולות קבוצתיות. אם אין לך את הקובץ — תבקש.

5. לסגור את אימות הדואר
   הדואר תוקן (הסיבה: לא היו SPF ו-DKIM כלל, וגוגל השליכה בשקט).
   נשאר לאמת:
   - לפתוח מייל בדיקה בג'ימייל -> Show original -> SPF/DKIM/DMARC PASS
   - mail-tester.com, יעד 9/10
   - להעביר את סיסמת ה-SMTP מ-wpgh_options ל-wp-config.php:
       define('WPMS_ON', true);
       define('WPMS_SMTP_PASS', '...');
   - לשלוח מייל אל info@nama-c.com ולוודא שהוא מגיע ל-cPanel
   - הזמנת בדיקה -> לוודא אישור ללקוח והתראה למנהל
   - WooCommerce -> סטטוס -> יומנים -> transactional-emails: אפס כשלים

=== אזהרות ===
- אל תכבה את Cloudflare. הוא מוגדר נכון — cf-cache-status: DYNAMIC
  על עגלה ותשלום, בדיוק כנדרש.
- רשומת ה-DNS של mail חייבת להישאר DNS only (ענן אפור).
- רשומת SPF אחת בלבד לדומיין.
- גיבוי לפני כל שינוי מבני. Migrate Guru מותקן.
- אחרי כל שינוי שנוגע בצ'קאאוט — הזמנת בדיקה אמיתית.
- לא לעדכן סטטוס הזמנות בגורף לפני צילוב מול דוח הסליקה.

=== איך לעבוד ===
משימה אחת בכל פעם. לפני כל שינוי באתר — להראות לי מה אתה עומד
לעשות ולחכות לאישור. אחרי כל שינוי — לאמת ולדווח מה יצא.

תתחיל ממשימה 1, ותגיד לי מה מצב השערים.
```
