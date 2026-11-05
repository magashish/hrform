# Deployment

The hosting provider is not yet chosen (OPEN_QUESTIONS #12). These steps are host-neutral, and **no Node.js is required**.

## 1. Requirements

- PHP 8.2+ (8.3 recommended) with `pdo_pgsql` (or `pdo_mysql`), `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `gd`, `ctype`, `tokenizer`, `xml`.
- PostgreSQL 14+ (recommended; it enables the database-level audit trigger) or MySQL 8.
- Composer 2.
- A web server (nginx or Apache) serving `public/` over HTTPS.
- A process supervisor (systemd or supervisord) for the queue worker, and cron for the scheduler.
- An SMTP or API mail provider with SPF, DKIM and DMARC.
- Optional: ClamAV `clamd`. Optional: S3-compatible private bucket.

## 2. First deployment

```bash
git clone <repo> /var/www/onboarding && cd /var/www/onboarding
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
cp .env.example .env
```

Edit `.env` (see §3), then:

```bash
php artisan key:generate
```

```bash
php artisan migrate --force
```

```bash
php artisan db:seed --class=DefaultTemplatesSeeder --force
```

```bash
php artisan portal:hr-user:create first.hr@example.com --name="First HR User"
```

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Set permissions so the web and queue user can write only to `storage/` and `bootstrap/cache/`:

```bash
chown -R www-data:www-data storage bootstrap/cache && chmod -R o-rwx storage
```

Then, signed in as HR:
1. Enrol 2FA.
2. **Disclosure** → create the client-approved version and activate it. Applicants cannot proceed until one is active.
3. **Documents** → upload the current official blank PDFs for I-9 and SSA-89, and add the other client forms.
4. **Settings** → confirm the filename pattern, reminders, notification recipients and retention.
5. Check the **System** page.

## 3. Production `.env` essentials

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://onboarding.example.com
APP_DISPLAY_TIMEZONE=America/New_York   # confirm with client
DB_CONNECTION=pgsql
SESSION_SECURE_COOKIE=true
SECURITY_HSTS=true                      # once HTTPS is permanent
TRUSTED_PROXIES=10.0.0.0/8              # only if TLS terminates at a load balancer
MAIL_MAILER=smtp                        # never "log" in production
QUEUE_CONNECTION=database               # or redis
DOCUMENTS_DRIVER=local                  # or s3 with a PRIVATE, encrypted bucket
MALWARE_SCANNER=clamav                  # strongly recommended
RETENTION_AUTO_PURGE=false              # enable after reviewing dry-run reports
ESIGN_PROVIDER=disabled
```

## 4. Web server (nginx example)

```nginx
server {
    listen 443 ssl http2;
    server_name onboarding.example.com;
    root /var/www/onboarding/public;
    index index.php;
    client_max_body_size 30m;           # >= UPLOAD_HARD_MAX_KB
    server_tokens off;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
    access_log /var/log/nginx/onboarding.access.log;   # never logs the invitation code (URL fragment)
}
server { listen 80; server_name onboarding.example.com; return 301 https://$host$request_uri; }
```

Match PHP's `upload_max_filesize` and `post_max_size` to `UPLOAD_HARD_MAX_KB`.

## 5. Queue worker and scheduler

Queue (emails, ZIP exports), using systemd:

```ini
[Service]
User=www-data
WorkingDirectory=/var/www/onboarding
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=600 --max-time=3600
Restart=always
```

Scheduler, via the web user's crontab:

```
* * * * * cd /var/www/onboarding && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks: heartbeat (5 min), reminders (hourly), export and OTP cleanup (15 min), retention purge or dry run (daily 02:15), audit pruning (daily 02:45). The HR System page warns if the heartbeat is older than 15 minutes.

## 6. Updating

```bash
php artisan down
```

```bash
git pull && composer install --no-dev --optimize-autoloader && composer audit
```

```bash
php artisan migrate --force && php artisan optimize && php artisan queue:restart
```

```bash
php artisan up
```

## 7. Go-live checklist

- [ ] HTTPS everywhere and HSTS enabled; `APP_DEBUG=false`; System page shows no warnings
- [ ] Final disclosure text active (not the DRAFT placeholder)
- [ ] Official blank PDFs uploaded; signature modes match `docs/signature-compliance-decisions.md`
- [ ] Malware scanner configured, or risk formally accepted
- [ ] Backups configured with agreed retention; restore test performed
- [ ] Mail deliverability tested (invitation, OTP, reminder, HR alert)
- [ ] Dry-run retention reports reviewed before enabling auto-purge
- [ ] Every HR user has enrolled 2FA and stored their recovery codes
- [ ] UAT completed with the client's sample forms
