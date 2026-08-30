#!/usr/bin/env python3
# אימות תצורת הדואר של nama-c.com מרחוק.
# שימוש:  pip install dnspython && python3 tools/verify-mail-dns.py
# בודק MX, SPF, DKIM ו-DMARC — כולל פענוח מפתח ה-DKIM ובדיקה
# שרשומת ה-mail אינה מוסתרת מאחורי Cloudflare.
import base64, ipaddress, sys
import dns.resolver as R

DOM = "nama-c.com"
r = R.Resolver(configure=True); r.nameservers = ["1.1.1.1", "8.8.8.8"]

CF = [ipaddress.ip_network(n) for n in (
 "173.245.48.0/20","103.21.244.0/22","103.22.200.0/22","103.31.4.0/22",
 "141.101.64.0/18","108.162.192.0/18","190.93.240.0/20","188.114.96.0/20",
 "197.234.240.0/22","198.41.128.0/17","162.158.0.0/15","104.16.0.0/13",
 "104.24.0.0/14","172.64.0.0/13","131.0.72.0/22")]
is_cf = lambda ip: any(ipaddress.ip_address(ip) in n for n in CF)

def q(name, rt):
    try:
        rs = r.resolve(name, rt, lifetime=10)
        if rt == "TXT":
            return ["".join(s.decode() for s in x.strings) for x in rs]
        return [str(x) for x in rs]
    except Exception:
        return []

ok = fail = warn = 0
def res(status, label, detail=""):
    global ok, fail, warn
    icon = {"ok":"✅","fail":"❌","warn":"⚠️"}[status]
    if status=="ok": ok+=1
    elif status=="fail": fail+=1
    else: warn+=1
    print(f"{icon} {label}")
    if detail: print(f"     {detail}")

print("="*68); print(f"  אימות תצורת דואר — {DOM}"); print("="*68)

# ---------- MX ----------
print("\n── MX ──")
mx = q(DOM, "MX")
if not mx:
    res("fail","רשומת MX","לא קיימת — דואר נכנס לא יגיע")
else:
    res("ok","רשומת MX קיימת", " | ".join(mx))
    host = mx[0].split()[-1].rstrip(".")
    ips = q(host, "A")
    if not ips:
        res("fail", f"{host} נפתר", "השם ב-MX לא קיים ב-DNS")
    elif is_cf(ips[0]):
        res("fail", f"{host} מאחורי Cloudflare", f"{ips[0]} — ענן כתום! ישבור את כל הדואר")
    else:
        res("ok", f"{host} מצביע לשרת", f"{ips[0]} — לא Cloudflare, כלומר ענן אפור ✓")

# ---------- SPF ----------
print("\n── SPF ──")
txts = q(DOM, "TXT")
spfs = [t for t in txts if t.lower().startswith("v=spf1")]
if len(spfs) == 0:
    res("fail","רשומת SPF","לא קיימת")
elif len(spfs) > 1:
    res("fail","מספר רשומות SPF",f"{len(spfs)} רשומות — שתיהן נפסלות")
else:
    spf = spfs[0]
    res("ok","רשומת SPF אחת בדיוק")
    res("fail" if "," in spf else "ok","הפרדה ברווחים",
        "יש פסיקים — תחביר שבור!" if "," in spf else "אין פסיקים")
    res("ok" if len(spf)<=255 else "warn", f"אורך {len(spf)} תווים",
        "מתחת ל-255 ✓" if len(spf)<=255 else "מעל 255 — מפוצל למחרוזות")
    parts = spf.split()
    lookups = sum(1 for p in parts if p.split(":")[0] in ("include","a","mx","ptr","exists","redirect"))
    res("ok" if lookups<=10 else "fail", f"בקשות DNS: {lookups}", "המגבלה היא 10")
    tail = parts[-1]
    res("ok" if tail in ("~all","-all") else "warn", f"מסתיים ב-{tail}",
        "softfail — מתאים לשלב ראשון" if tail=="~all" else "")
    have = {p[4:] for p in parts if p.startswith("ip4:")}
    need = {"91.204.209.51":"השרת","213.5.176.100":"הממסר gateway1.enmail.co"}
    for ip,what in need.items():
        res("ok" if ip in have else "fail", f"מאשר {ip}", what)
    gw = sum(1 for ip in have if ip.startswith("193.33.186."))
    res("ok" if gw>=10 else "warn", f"טווח יציאת הממסר: {gw}/10 כתובות")

# ---------- DKIM ----------
print("\n── DKIM ──")
dk = [t for t in q(f"default._domainkey.{DOM}","TXT") if "DKIM1" in t]
if not dk:
    res("fail","DKIM","לא קיים בסלקטור default")
else:
    d = dk[0]
    res("ok","רשומת DKIM קיימת (סלקטור default)")
    tags = dict(p.strip().split("=",1) for p in d.split(";") if "=" in p)
    res("ok" if tags.get("k","rsa").strip()=="rsa" else "warn", f"אלגוריתם: {tags.get('k','rsa').strip()}")
    key = tags.get("p","").strip()
    if not key:
        res("fail","מפתח ציבורי","ריק — הרשומה מבוטלת")
    else:
        try:
            raw = base64.b64decode(key + "="*(-len(key)%4))
            bits = (len(raw)-38)*8
            res("ok" if bits>=1024 else "warn", f"מפתח תקין ~{bits} ביט",
                "2048 ביט — חזק ✓" if bits>=2000 else "1024 ביט — עובד, 2048 עדיף")
        except Exception as e:
            res("fail","פענוח המפתח",f"base64 לא תקין: {e}")

# ---------- DMARC ----------
print("\n── DMARC ──")
dm = [t for t in q(f"_dmarc.{DOM}","TXT") if t.lower().startswith("v=dmarc1")]
if not dm:
    res("fail","DMARC","לא קיים")
else:
    d = dm[0]
    res("ok","רשומת DMARC קיימת")
    tags = dict(p.strip().split("=",1) for p in d.split(";") if "=" in p)
    pol = tags.get("p","").strip()
    res("ok" if pol in ("none","quarantine","reject") else "fail", f"מדיניות p={pol}",
        "none = דווח בלבד, נכון לשלב הראשון" if pol=="none" else "")
    res("ok" if "rua" in tags else "warn", "כתובת דוחות", tags.get("rua","חסרה — לא תקבל דוחות"))

# ---------- שאריות ----------
print("\n── ניקיון ──")
goog = [t for t in txts if "google-site-verification" in t]
res("ok", f"רשומות google-site-verification: {len(goog)}", "תקין — מאמת את Search Console, לא נוגע בדואר")
for h in ("smtp.nama-c.com","autodiscover.nama-c.com"):
    res("ok" if not q(h,"A") else "warn", f"{h}", "לא קיים ✓" if not q(h,"A") else "קיים — לוודא שאינו מבלבל")

print("\n" + "="*68)
print(f"  תקין: {ok}    אזהרות: {warn}    כשלים: {fail}")
print("="*68)
sys.exit(0)
