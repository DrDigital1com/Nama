# הגדרת דואר יוצא דרך שרת האחסון (cPanel)

## המצב שאובחן

| מה | ממצא |
|---|---|
| WP Mail SMTP | מוגדר מול Google API, **נכשל** — לקוחות ששילמו לא מקבלים אישור הזמנה |
| MX של הדומיין | `smtp.google.com` — **דואר נכנס מנוהל ב-Google Workspace** |
| `mail.nama-c.com` | **לא קיים ב-DNS** (אומת: `gaierror` בפתרון שם) |
| SPF | **לא קיים** |
| DKIM | **לא קיים** |
| DMARC | **לא קיים** (Cloudflare מתריע) |
| רשומות DNS סה"כ | 4 בלבד |

## ההחלטה

לשלוח דואר יוצא דרך שרת האחסון, בלי גוגל.

**שני עוגנים:**

1. **לא נוגעים ב-MX.** משנים רק דואר יוצא. הדואר הנכנס נשאר כפי שהוא, והשינוי הפיך בכל רגע.
2. **לא יוצרים `mail.nama-c.com`.** יצירת הרשומה דורשת המתנה להנפקת תעודת SSL, ועד אז החיבור ייכשל באימות התעודה. במקום זה משתמשים ב**שם השרת** שכבר קיים ושכבר מכוסה בתעודה — אפס שינויי DNS בשלב א׳.

---

# שלב א׳ — לגרום למיילים להישלח (~15 דקות, בלי DNS)

## א1. ליצור תיבת דואר ייעודית

**cPanel → Email Accounts → Create**

| שדה | ערך |
|---|---|
| Username | `no-reply` |
| Domain | `nama-c.com` |
| Password | סיסמה חזקה, 16+ תווים. לשמור במנהל סיסמאות |
| Storage | 1GB |

> **ייתכן ש-cPanel יציג אזהרה** בסגנון *"this domain uses a remote mail exchanger"*. **זה תקין וצפוי** — הוא רק מזכיר שהדואר הנכנס הולך לגוגל. התיבה נוצרת בכל זאת, ומשמשת אותנו לאימות SMTP בלבד.

> **למה תיבה ייעודית:** הסיסמה נשמרת בשרת האתר. אם היא תדלוף או תתחלף — רק המיילים האוטומטיים נשברים, לא הדואר האישי.

## א2. לאתר את שם שרת הדואר

**cPanel → Email Accounts → ליד `no-reply@nama-c.com` → Connect Devices**

תחת **Secure SSL/TLS Settings → Outgoing Server** מופיע שם השרת. הוא ייראה כמו אחד מאלה:

```
mail.nama-c.com              ← להתעלם, לא קיים ב-DNS
server123.hostprovider.com   ← זה מה שאנחנו רוצים
```

**להשתמש בשם השרת הארוך**, לא ב-`mail.nama-c.com`. הוא כבר קיים ב-DNS, וכבר מכוסה בתעודת ה-SSL של השרת — ולכן החיבור יאומת בלי שגיאה.

לרשום: **שם השרת** + **הפורט** (465 או 587).

## א3. לשמור את הסיסמה מחוץ לבסיס הנתונים

WP Mail SMTP שומר את הסיסמה בטבלת `options` בטקסט גלוי — כלומר היא תופיע בכל גיבוי DB.

ב-`wp-config.php`, **לפני** השורה `/* That's all, stop editing! */`:

```php
define( 'WPMS_ON', true );
define( 'WPMS_SMTP_PASS', 'הסיסמה-של-no-reply' );
```

אחרי זה שדה הסיסמה בממשק יינעל ויציין שהערך מגיע מקובץ.

## א4. להגדיר את WP Mail SMTP

**WP Mail SMTP → Settings**

**From Email / From Name:**

| שדה | ערך |
|---|---|
| From Email | `no-reply@nama-c.com` |
| Force From Email | ✅ **לסמן** |
| From Name | `NAMA` |
| Force From Name | ✅ לסמן |

> **`Force From Email` הוא קריטי.** בלעדיו ווקומרס, MC4WP ו-Elementor Forms שולחים כל אחד מכתובת אחרת. כתובת שליחה שה-SPF לא מכסה = ספאם.

**Mailer:** לבחור **Other SMTP**

| שדה | ערך |
|---|---|
| SMTP Host | שם השרת מ-א2 |
| Encryption | **SSL** |
| SMTP Port | **465** |
| Auto TLS | ✅ |
| Authentication | ✅ |
| SMTP Username | `no-reply@nama-c.com` — **הכתובת המלאה** |
| SMTP Password | נעול (מגיע מ-wp-config) |

**Save Settings**

## א5. לבדוק

**WP Mail SMTP → Tools → Email Test** → כתובת Gmail אישית → **Send Email**

- ✅ הצליח → **שלב א׳ הושלם.** לעבור לשלב ב׳
- ❌ נכשל → טבלת השגיאות למטה

> בשלב הזה המייל עלול לנחות בספאם. **זה צפוי** — אין עדיין SPF/DKIM. שלב ב׳ מטפל בזה.

---

# שלב ב׳ — שהמיילים ינחתו בתיבה ולא בספאם (~15 דקות, DNS)

רק אחרי ששלב א׳ עבר.

## ב1. איפה לקחת את הערכים

**cPanel → Email Deliverability → ליד `nama-c.com` → Manage**

שם מוצגות רשומות ה-SPF וה-DKIM המדויקות של השרת.

> **הכפתור "Repair"/"Install" לא יעבוד כאן.** ה-DNS של הדומיין מנוהל ב-Cloudflare, לא בשרת השמות של cPanel. cPanel לא יכול לכתוב לרשומות שלך — הוא רק **מציג** מה צריך. מעתיקים ומדביקים ידנית.

## ב2. SPF

**Cloudflare → DNS → Records → Add record**

```
Type:  TXT
Name:  @
TTL:   Auto
```

הערך — **חייב לכלול גם את השרת וגם את גוגל**, כי ה-MX עדיין מצביע לגוגל:

```
v=spf1 a mx ip4:<ה-IP של השרת מ-cPanel> include:_spf.google.com ~all
```

> ⚠️ **רשומת SPF אחת בלבד לדומיין.** אם כבר קיימת — **לערוך אותה**, לא להוסיף שנייה. שתי רשומות SPF = שתיהן נפסלות.

> ⚠️ ה-IP לקחת מ-cPanel Email Deliverability, לא מהצילום של Cloudflare. ה-IP ביציאת דואר עשוי להיות שונה מזה של האתר.

## ב3. DKIM

להעתיק מ-cPanel את הערך המלא (ארוך מאוד):

```
Type:  TXT
Name:  default._domainkey
Value: v=DKIM1; k=rsa; p=<המפתח מ-cPanel>
```

> אין התנגשות עם ה-DKIM של גוגל — הוא משתמש בסלקטור אחר.

## ב4. DMARC

```
Type:  TXT
Name:  _dmarc
Value: v=DMARC1; p=none; rua=mailto:<כתובת שאתה קורא>; pct=100
```

> `p=none` = "דווח בלבד, אל תחסום". להתחיל רך. אחרי חודש של מעקב אפשר להחמיר ל-`p=quarantine`.

## ב5. לאמת שהתפרסם

הפצת DNS: דקות עד שעה.

```bash
dig +short TXT nama-c.com
dig +short TXT default._domainkey.nama-c.com
dig +short TXT _dmarc.nama-c.com
```
או https://mxtoolbox.com/spf.aspx

---

# שלב ג׳ — אימות אמיתי

## ג1. שהמייל מגיע מאומת

לשלוח Email Test ל-Gmail. לפתוח את המייל → **⋮ → Show original**.

צריך להופיע:
```
SPF:   PASS
DKIM:  PASS
DMARC: PASS
```

שלושתם PASS = נוחת בתיבה הראשית. אחד FAIL = חזרה לשלב ב׳.

## ג2. ציון

לשלוח מייל לכתובת מ-https://www.mail-tester.com — **יעד: 9/10 ומעלה.** הוא מפרט בדיוק מה חסר.

## ג3. איפה נוחתות התראות המנהל — בדיקה שקל לפספס

**cPanel → Email Routing → `nama-c.com`**

חייב להיות **Remote Mail Exchanger** (או Automatic).

> **למה זה קריטי:** ה-MX מצביע לגוגל. אם cPanel מוגדר ל-**Local**, השרת חושב שהוא אחראי על דואר הדומיין — ואז התראת הזמנה שווקומרס שולחת לכתובת @nama-c.com תיכנס לתיבה מקומית בשרת שאף אחד לא קורא, במקום להגיע לגוגל. ההודעה "תישלח בהצלחה" ופשוט תיעלם.

## ג4. בדיקה מקצה לקצה

1. לבצע הזמנת בדיקה בחנות
2. הלקוח קיבל `customer_processing_order`?
3. המנהל קיבל התראה?
4. **WooCommerce → סטטוס → יומנים → `transactional-emails`** — אפס `failed to send`

---

# טבלת שגיאות

| השגיאה | הסיבה | התיקון |
|---|---|---|
| `Connection timed out` / `Could not connect` | פורט 465 חסום ביציאה | לנסות **587 + TLS**. אם גם נכשל — לפנות לאחסון: *"האם יציאת SMTP חסומה לחשבון שלי?"* |
| `SSL certificate problem` / `Hostname mismatch` | ה-Host לא תואם לתעודה | להשתמש בשם השרת מ-Connect Devices, **לא** ב-`mail.nama-c.com` |
| `Could not authenticate` | שם משתמש או סיסמה | ה-Username חייב להיות הכתובת **המלאה**. לוודא סיסמה מול cPanel |
| `Connection refused` על 465 | השרת מצפה ל-TLS | לעבור ל-**587 + TLS** |
| נשלח אך מגיע לספאם | אין SPF/DKIM | שלב ב׳ |
| נשלח, אבל SPF FAIL | `Force From Email` לא מסומן | לסמן. כתובת השליחה חייבת להיות בדומיין שה-SPF מכסה |
| "נשלח" אבל המנהל לא מקבל | Email Routing = Local | שלב ג3 |
| עובד ואז מפסיק אחרי X מיילים | מכסת שליחה יומית | לבדוק מול האחסון מה המכסה |

---

# מגבלות שכדאי להכיר

SMTP של שרת אחסון משותף עובד, ובהיקף של ~50 הזמנות בחודש הוא בהחלט מספיק. שתי מגבלות מובנות:

1. **מוניטין IP משותף** — חולקים IP עם אתרים אחרים על אותו שרת
2. **מכסת שליחה יומית** — בדרך כלל 200–500 ליום

אם בעתיד יופיעו מיילים בספאם למרות SPF/DKIM תקינים, או שההיקף יגדל — מעבר לספק ייעודי (Brevo, SendLayer, Postmark) הוא שינוי של 10 דקות באותו מסך.

---

# נקודות פתוחות לטיפול אחר כך

- **תשובות לתיבה `no-reply`** נכנסות ל-Google (ה-MX). כדאי להגדיר Reply-To לכתובת שירות שאתה קורא.
- **מנוי Google Workspace** — לא בדקנו אם הוא עדיין פעיל. הדואר הנכנס תלוי בו. שווה לוודא בנפרד.
- **`Failed due to failed connection` ביומן Facebook for WooCommerce** — תקלה נפרדת: שם החיבור בכלל לא נוצר, בעוד שאצל גוגל החיבור נוצר והתקבלה שגיאה מסודרת. שתי בעיות שונות.
