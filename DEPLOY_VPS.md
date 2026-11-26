# نشر Winz Backend على VPS مع Docker

> هذه تعليمات فقط؛ لم يتم تنفيذ أي نشر. كل الملفات تحت `winz-api/deploy/`.

## ١) تجهيز السيرفر (مرة واحدة)
```bash
# Ubuntu 22.04 / 24.04
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl nginx certbot python3-certbot-nginx

# Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker

# جدار الحماية: لا تفتح MySQL أو phpMyAdmin للإنترنت
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

## ٢) نسخ المشروع
```bash
cd ~
git clone <YOUR_BACKEND_REPO_URL> winz-api
cd winz-api/deploy
```

## ٣) إعداد البيئة
```bash
cp ../.env.production.example ../.env   # أو انسخ من deploy/.env.production.example
nano ../.env
```
املأ:
- `APP_URL=https://YOUR-DOMAIN`
- `MYSQL_ROOT_PASSWORD` و`MYSQL_PASSWORD` بكلمات مرور قوية
- `DB_USERNAME=winz` و`DB_PASSWORD` نفس `MYSQL_PASSWORD`

ولّد مفتاح التطبيق:
```bash
cd ~/winz-api
docker compose --env-file .env -f deploy/docker-compose.prod.yml up -d --build
docker compose --env-file .env -f deploy/docker-compose.prod.yml exec -T app php artisan key:generate
docker compose --env-file .env -f deploy/docker-compose.prod.yml exec -T app php artisan migrate --seed --force
```

> ملاحظة: `key:generate` يعدّل `.env` داخل الحاوية/المجلد المشترك؛ تأكد أن `APP_KEY` أصبح غير فارغ في `.env` على المضيف.

## ٤) التأكد من عمل الـ API
```bash
curl https://YOUR-DOMAIN/api/health
# المتوقع: {"ok":true,"result":{"status":"ok"}}
```

## ٥) Nginx + SSL
```bash
sudo cp ~/winz-api/deploy/nginx/winz.conf /etc/nginx/sites-available/winz.conf
sudo ln -s /etc/nginx/sites-available/winz.conf /etc/nginx/sites-enabled/
# بدّل api.example.com باسم نطاقك في الملف
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d YOUR-DOMAIN
```

الآن رابط الـ API هو:
```
https://YOUR-DOMAIN/api
```

## ٦) أوامر مفيدة
```bash
cd ~/winz-api
docker compose --env-file .env -f deploy/docker-compose.prod.yml ps
docker compose --env-file .env -f deploy/docker-compose.prod.yml logs -f app
docker compose --env-file .env -f deploy/docker-compose.prod.yml restart
docker compose --env-file .env -f deploy/docker-compose.prod.yml --profile tools up -d phpmyadmin  # محلي فقط عبر SSH tunnel
```

## ٧) النسخ الاحتياطي (مهم)
```bash
# cron مثال: كل يوم 03:00
0 3 * * * cd ~/winz-api && docker compose --env-file .env -f deploy/docker-compose.prod.yml exec -T db \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" winz | gzip > ~/backups/winz-$(date +\%F).sql.gz
```
احتفظ بنسخة من `.env` و`APP_KEY` وvolume `dbdata`.

## ٨) التحديث لاحقاً
```bash
cd ~/winz-api
git pull
docker compose --env-file .env -f deploy/docker-compose.prod.yml up -d --build
docker compose --env-file .env -f deploy/docker-compose.prod.yml exec -T app php artisan migrate --force
```

## تحذير أمني مهم
الـ API الحالي **بدون مصادقة** (كما في النسخة الأصلية من Git). أي شخص يعرف الرابط يستطيع استدعاء `/api/call` و`/api/restore`. قبل النشر العام إمّا:
- قصر الوصول على عناوين IP المعروفة عبر firewall، أو
- أضف token مطلوب في الرأس (سأحتاج تعديل طفيف في التطبيق والخادم).

لا تعرض phpMyAdmin على الإنترنت؛ استخدم SSH tunnel فقط.

