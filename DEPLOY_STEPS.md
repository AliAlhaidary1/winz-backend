# خطوات النشر على VPS موجود — winz.sreamart.com

البيانات:
- النطاق: `winz.sreamart.com` (Cloudflare Proxied)
- IP: `72.62.38.26`
- Repo: `https://github.com/AliAlhaidary1/winz-backend.git`
- المنفذ الداخلي المختار للتطبيق: `8110` ( لتجنب تعارض المشاريع الموجودة )
- phpMyAdmin فقط عبر SSH tunnel على `8098`

> نفّذ الأوامر بالترتيب. لا توقف أو تعدّل حاويات المشاريع الأخرى.

---

## ١) الدخول عبر SSH وفحص الوضع الحالي
```bash
ssh root@72.62.38.26
# أو إن كان المستخدم مختلفاً: ssh USER@72.62.38.26

docker ps --format 'table {{.Names}}\t{{.Ports}}'
docker compose ls
ss -tlnp | grep -E ':(80|443|8110|8098|3306)\b' || true
```
إذا كان المنفذ 8110 مستخدماً، غيّره في `deploy/docker-compose.prod.yml` في السطر:
`- "127.0.0.1:8110:8000"` إلى منفذ حر، وحدّث Nginx بنفس الرقم.

---

## ٢) سحب الكود من GitHub
```bash
mkdir -p /srv
git clone https://github.com/AliAlhaidary1/winz-backend.git /srv/winz-api
cd /srv/winz-api
ls   # تأكد وجود docker-compose.yml و Dockerfile و deploy/
```

---

## ٣) إنشاء ملف البيئة وتغيير كلمة مرور قاعدة البيانات
```bash
cp deploy/.env.production.example .env
nano .env
```
املأ/عدّل这些 القيم بالضبط:
```env
APP_NAME="Winz API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://winz.sreamart.com
WINZ_API_TOKEN=WinzSecure2026!   # يجب أن يطابق ApiConfig.apiToken في التطبيق

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=winz
DB_USERNAME=winz
DB_PASSWORD=كلمة_مرور_قوية_جديدة

MYSQL_ROOT_PASSWORD=كلمة_مرور_root_قوية_جديدة
MYSQL_DATABASE=winz
MYSQL_USER=winz
MYSQL_PASSWORD=كلمة_مرور_قوية_جديدة   # نفس DB_PASSWORD
```
> مهم: `MYSQL_PASSWORD` و`DB_PASSWORD` يجب أن يكونا متطابقين.

ولّد `APP_KEY` (اختر إحدى الطريقتين):
```bash
# من أي PHP على السيرفر
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```
الصق الناتج في `.env` بجانب `APP_KEY=`.

---

## ٤) بناء وتشغيل الحاويات (باسم مشروع مستقل)
```bash
cd /srv/winz-api
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml up -d --build
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml ps
```
> `-p winz-api` يمنع تضارب الأسماء مع مشاريعك الأخرى.

---

## ٥) تثبيت الجداول والبيانات الأولية
```bash
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml exec -T app php artisan migrate --seed --force
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml exec -T app php artisan config:clear
```

---

## ٦) تأكد أن API يعمل محلياً على السيرفر
```bash
curl http://127.0.0.1:8110/api/health
# المتوقع: {"ok":true,"result":{"status":"ok"}}
```

---

## ٧) شهادة Cloudflare Origin وتفعيل Nginx
1. افتح Cloudflare → `SSL/TLS` → `Origin Server` → `Create Certificate`
   - Hostnames: `winz.sreamart.com` و`*.winz.sreamart.com`
   - مدة الشهادة: 15 سنة.
2. انسخ الشهادة والمفتاح إلى السيرفر:
```bash
mkdir -p /etc/ssl/cloudflare
nano /etc/ssl/cloudflare/winz.sreamart.com.pem   # الصق محتوى الشهادة
nano /etc/ssl/cloudflare/winz.sreamart.com.key   # الصق المفتاح الخاص
chmod 600 /etc/ssl/cloudflare/winz.sreamart.com.key
```
3. في Cloudflare → `SSL/TLS` → `Overview` → اختر **Full (Strict)**.
4. ثبّت إعداد Nginx:
```bash
cp /srv/winz-api/deploy/nginx/winz-cloudflare.conf /etc/nginx/sites-available/winz.conf
ln -sf /etc/nginx/sites-available/winz.conf /etc/nginx/sites-enabled/winz.conf
nginx -t && systemctl reload nginx
```

---

## ٨) الاختبار النهائي من أي جهاز
```bash
curl https://winz.sreamart.com/api/health
# المتوقع: {"ok":true,"result":{"status":"ok"}}
```
ثم من المتصفح افتح:
```
https://winz.sreamart.com/api/health
```

---

## ٩) تغيير كلمة مرور قاعدة البيانات لاحقاً (إن احتجت)
```bash
# أدخل كلمة root الحالية من .env
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml exec -T db \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e \
  "ALTER USER 'winz'@'%' IDENTIFIED BY 'كلمة_جديدة'; FLUSH PRIVILEGES;"
```
ثم عدّل `DB_PASSWORD` و`MYSQL_PASSWORD` في `.env` وأعد التشغيل:
```bash
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml up -d
```

---

## ١٠) النسخ الاحتياطي والتحديث
```bash
# نسخة يومية (cron)
0 3 * * * cd /srv/winz-api && docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml exec -T db \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" winz | gzip > /root/backups/winz-\$(date +\%F).sql.gz

# تحديث لاحق
cd /srv/winz-api
git pull
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml up -d --build
docker compose --env-file .env -p winz-api -f deploy/docker-compose.prod.yml exec -T app php artisan migrate --force
```

---

## ملاحظة أمنية
تمت إضافة مصادقة بسيطة: `/api/call` و`/api/restore` يتطلبان الرأس
`X-API-Key: WinzSecure2026!`، ويطابق `WINZ_API_TOKEN` في `.env`.
`/api/health` يبقى عاماً للمراقبة.

لأمان أفضل لاحقاً: غيّر التوكن في `.env` وفي `ApiConfig.apiToken` معاً،
وقصر الوصول على IP أجهزة نقاط البيع في Cloudflare WAF.

---

## ما أرسله لي بعد التنفيذ
أرسل لي نتيجة:
```
curl https://winz.sreamart.com/api/health
```
وسأعدّل `lib/src/config/api_config.dart` إلى:
`static const String baseUrl = 'https://winz.sreamart.com/api';`
ومعها سأجري الاختبار الكامل معك.

