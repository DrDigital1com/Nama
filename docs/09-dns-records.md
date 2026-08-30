# רשומות ה-DNS ל-nama-c.com — דואר עצמאי על השרת

נבדק ואומת מול DNS חי ב-30/08/2026.

## מצב פתיחה

| רשומה | מצב |
|---|---|
| `A` nama-c.com | ✅ 104.21.24.171 / 172.67.219.195 (Cloudflare, proxied) — תקין לאתר |
| `TXT` google-site-verification | ✅ קיים — **להשאיר**, מאמת את Search Console / Site Kit |
| `TXT` default._domainkey | ✅ **DKIM כבר פורסם** |
| `MX` | ❌ **לא קיים** — דואר נכנס מת |
| `A` mail | ❌ לא קיים |
| `TXT` SPF | ❌ לא קיים |
| `TXT` _dmarc | ❌ לא קיים |

## ארבע רשומות להוספה

**Cloudflare → DNS → Records → Add record**

### 1. A — mail

| שדה | ערך |
|---|---|
| Type | `A` |
| Name | `mail` |
| IPv4 address | `91.204.209.51` |
| Proxy status | **DNS only** (ענן אפור) ⚠️ |
| TTL | Auto |

> **הענן חייב להיות אפור.** Cloudflare מעביר רק HTTP/HTTPS. ענן כתום כאן ישבור את כל תעבורת הדואר.

### 2. MX

| שדה | ערך |
|---|---|
| Type | `MX` |
| Name | `@` |
| Mail server | `mail.nama-c.com` |
| Priority | `0` |
| TTL | Auto |

### 3. TXT — SPF

| שדה | ערך |
|---|---|
| Type | `TXT` |
| Name | `@` |

```
v=spf1 ip4:91.204.209.51 ip4:213.5.176.100 ip4:193.33.186.113 ip4:193.33.186.114 ip4:193.33.186.115 ip4:193.33.186.116 ip4:193.33.186.117 ip4:193.33.186.118 ip4:193.33.186.119 ip4:193.33.186.120 ip4:193.33.186.121 ip4:193.33.186.122 ~all
```

**מאיפה הכתובות:**

| כתובת | מה זה |
|---|---|
| `91.204.209.51` | השרת עצמו |
| `213.5.176.100` | `gateway1.enmail.co` — הממסר שדרכו האחסון מנתב את הדואר היוצא |
| `193.33.186.113–122` | טווח היציאה שרשומת ה-SPF של `enmail.co` מצהירה עליו |

**למה לא `include:enmail.co`:** רשומת ה-SPF שלהם מופרדת ב**פסיקים** במקום ברווחים — תחביר שבור. `include` עליה עלול להחזיר PermError ולהפיל את כל ה-SPF שלנו. לכן רושמים את הכתובות ישירות.

**למה לא `+a`:** רשומת ה-`A` של הדומיין מצביעה ל-Cloudflare, לא לשרת. `+a` היה מאשר את Cloudflare — חסר תועלת.

**`~all` ולא `-all`:** softfail בשלב הראשון. אחרי שבועיים של מסירה תקינה אפשר להחמיר.

### 4. TXT — DMARC

| שדה | ערך |
|---|---|
| Type | `TXT` |
| Name | `_dmarc` |

```
v=DMARC1; p=none; rua=mailto:info@nama-c.com; pct=100
```

---

## שני שינויים ב-cPanel — בלעדיהם הרשומות לא יעזרו

### א. Email Routing → Local

**cPanel → Email Routing → `nama-c.com` → `Local Mail Exchanger`**

ה-MX הצביע קודם לגוגל, ולכן ההגדרה כנראה על `Remote`. עכשיו ה-MX מצביע לשרת עצמו — ואם ההגדרה תישאר Remote, השרת **יסרב לקבל** דואר לדומיין.

### ב. AutoSSL

**cPanel → SSL/TLS Status → Run AutoSSL**

אחרי שרשומת `mail` מתפרסמת, זה מנפיק תעודה ל-`mail.nama-c.com`. נדרש כדי שלקוחות דואר (Outlook, נייד) יתחברו ב-SSL בלי אזהרה.

---

## אימות

DNS מתפשט תוך דקות עד שעה.

```bash
dig +short MX  nama-c.com                       # → 0 mail.nama-c.com
dig +short A   mail.nama-c.com                  # → 91.204.209.51
dig +short TXT nama-c.com                       # → v=spf1 ...
dig +short TXT _dmarc.nama-c.com                # → v=DMARC1 ...
dig +short TXT default._domainkey.nama-c.com    # → v=DKIM1 ... (כבר קיים)
```

או https://mxtoolbox.com/SuperTool.aspx

אחר כך:
1. **WP Mail SMTP → Tools → Email Test** לג'ימייל
2. אם הגיע → **Show original** → לוודא `SPF: PASS` ו-`DKIM: PASS`
3. **mail-tester.com** → יעד 9/10
4. לשלוח מייל **מ**ג'ימייל **אל** `info@nama-c.com` ולוודא שהוא מגיע ל-cPanel

---

## מה השתנה בפועל

**דואר נכנס עבר מגוגל לשרת.** דואר שכבר יושב בתיבות של Google Workspace **נשאר שם ולא עובר** — הוא לא מיוגר. מהרגע שה-MX מתעדכן, דואר חדש מגיע לתיבות ב-cPanel.

לוודא ש-`info@nama-c.com` ושאר הכתובות שבשימוש אכן קיימות כתיבות ב-**cPanel → Email Accounts**, אחרת דואר אליהן יידחה.


---

# תוצאה — 30/08/2026

**נפתר.** מייל הבדיקה התקבל בג'ימייל מיד לאחר פרסום SPF + DKIM + DMARC.

## מה הייתה הסיבה

מאז 2024 Google דורשת SPF **או** DKIM כתנאי סף לכל שולח. הדומיין לא פרסם אף אחד מהם, ולכן Google **קיבלה את ההודעות והשליכה אותן בשקט** — בלי דחייה ובלי הודעת שגיאה.

זה מסביר בדיוק את התמונה שהטעתה אותנו לאורך כל האבחון:

| מה שראינו | הפירוש הנכון |
|---|---|
| WP Mail SMTP: "sent successfully" | נכון — ההודעה נמסרה ל-Exim המקומי |
| Track Delivery: `Accepted` בכל שורה | נכון — הממסר קיבל אותה |
| אפס כשלים, אפס דחיות, אפס bounces | נכון — אף אחד לא דחה כלום |
| שום דבר לא הגיע | Google השליכה בשקט אחרי הקבלה |

**הממסר `gateway1.enmail.co` היה תקין לאורך כל הדרך.** לא נדרשה פנייה לאחסון.

## הלקח

`Accepted` ביומני השרת אינו `Delivered`. כששרשרת השליחה נראית תקינה לחלוטין ובכל זאת שום דבר לא מגיע — לבדוק קודם כל אם הדומיין מפרסם SPF או DKIM, לפני שמחפשים תקלה בתשתית.
