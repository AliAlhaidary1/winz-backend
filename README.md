# Winz API —Laravel 12

BACKEND API for the Winz application.

## الإعداد السريع (Arabic)

1. تأكد من تثبيت Docker و Docker Compose على جهازك.
2. من داخل مجلد `winz-api` شغّل الأوامر التالية بالترتيب:

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

3. الرابط الأساسي للـ API على جهازك المحلي:

```
http://127.0.0.1:8100/api
```

4. phpMyAdmin متاح على `http://127.0.0.1:8099` (المستخدم `root` وكلمة المرور `secret`).

### تغيير الرابط إلى IP السيرفر لاحقًا (Arabic)

عند نشر التطبيق على سيرفر حقيقي، استبدل `127.0.0.1` في قاعدة URL بعنوان IP للسيرفر (أو اسم النطاق), مثال:

```
http://192.168.1.50:8100/api
```

ثم حدّث قيمة `APP_URL` في ملف `.env` لتطابق العنوان الجديد:

```env
APP_URL=http://192.168.1.50:8100
```

بعد التعديل أعد تشغيل الحاويات:

```bash
docker compose restart app
```

> ملاحظة: تأكد من فتح المنفذ `8100` في جدار الحماية (firewall) على السيرفر،
> ولا تنسَ توليد مفتاح التطبيق الجديد باستخدام `php artisan key:generate`.

## Quick start (English)

1. Make sure Docker and Docker Compose are installed.
2. From the `winz-api` directory, run these commands in order:

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

3. Base API URL on your local machine:

```
http://127.0.0.1:8100/api
```

4. phpMyAdmin is available at `http://127.0.0.1:8099` (user `root`, password `secret`).

### Switching to a server IP later (English)

When deploying to a real server, replace `127.0.0.1` in the base URL with the
server's IP address (or domain name), for example:

```
http://192.168.1.50:8100/api
```

Also update `APP_URL` in `.env` to match the new address:

```env
APP_URL=http://192.168.1.50:8100
```

Then restart the containers:

```bash
docker compose restart app
```

> Note: make sure port `8100` is open in the server firewall, and remember to
> regenerate the application key with `php artisan key:generate`.
