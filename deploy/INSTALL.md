# Mossfield — Manual Install Guide (Ubuntu 24.04)

Step-by-step commands to bring up the mossfield Laravel app on a fresh Ubuntu 24.04 host with Nginx, PHP 8.3, MariaDB, and Let's Encrypt TLS.

This is the manual companion to `DEPLOYMENT.md` (the production runbook) and `deploy/ansible/` (the automated playbook). For redeploys/upgrades, follow `DEPLOYMENT.md` §7 instead — this guide is for the initial stack-up.

## Prerequisites

- Fresh Ubuntu 24.04 server with sudo access.
- DNS for your domain pointing at the server's public IP **before** running the Let's Encrypt step (HTTP-01 challenge needs to reach you on port 80).
- WireGuard already set up if you're using it for SSH (see UFW section below).

---

## 1. Generate secrets

Run these on any machine, save the output — you'll paste them into commands and `.env` below.

```bash
openssl rand -base64 24       # for <DB_PASSWORD>
openssl rand -base64 24       # for <ADMIN_PASSWORD>
openssl rand -hex 32          # for <API_TOKEN>  (used in BOTH OFFICE_API_TOKEN and MOSSORDERS_API_TOKEN)
```

Replace `<DOMAIN>`, `<EMAIL>`, `<DB_PASSWORD>`, `<ADMIN_PASSWORD>`, `<API_TOKEN>`, `<REPO_URL>` throughout.

---

## 2. Base system + firewall

```bash
sudo apt update && sudo apt -y upgrade
sudo apt install -y git unzip curl ca-certificates software-properties-common ufw unattended-upgrades

sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

### UFW + WireGuard

OpenSSH is already installed (you're SSHing in to run these). The question is just what UFW does with port 22. Pick one:

**A) Leave SSH open publicly (simplest, safe with key-only auth):**

```bash
sudo ufw allow OpenSSH
```

Recommended even if you use WireGuard for day-to-day — it's your fallback if WG ever breaks.

**B) SSH only over WireGuard:**

Verify you can SSH over the WG tunnel first, then:

```bash
sudo ufw allow in on wg0 to any port 22
```

**C) Hybrid — SSH from WG subnet only:**

```bash
sudo ufw allow from 10.x.x.0/24 to any port 22    # replace with your WG subnet
```

### Open the WireGuard port itself

In all three cases:

```bash
sudo ufw allow 51820/udp     # or whatever your `ListenPort` is — check with `sudo wg show`
```

### Enable UFW

```bash
sudo ufw --force enable
```

⚠ Make sure your SSH rule (A, B, or C above) is in place **before** running this, or you'll lock yourself out.

---

## 3. PHP 8.3 + extensions

Per `DEPLOYMENT.md` §1.

```bash
sudo apt install -y \
  php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-gd php8.3-mysql php8.3-intl

sudo systemctl enable --now php8.3-fpm
```

The `php8.3-mysql` extension drives both MySQL and MariaDB — no swap needed.

---

## 4. MariaDB + database bootstrap

Ubuntu 24.04 ships MariaDB 11.4, well above the 10.6 floor `DEPLOYMENT.md` requires.

```bash
sudo apt install -y mariadb-server
sudo systemctl enable --now mariadb

sudo mariadb-secure-installation
```

When `mariadb-secure-installation` asks **"Switch to unix_socket authentication"**, answer **No** — you want a real root password. Set a root password at the next prompt; accept the rest of the defaults (drop anonymous users, disallow remote root, drop test DB, reload privileges).

Then create the app database + user (`DEPLOYMENT.md` §2):

```bash
sudo mariadb <<SQL
CREATE DATABASE mossfield CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mossfield'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
GRANT ALL PRIVILEGES ON mossfield.* TO 'mossfield'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## 5. Composer 2.x

```bash
curl -sS https://getcomposer.org/installer | sudo php -- \
  --install-dir=/usr/local/bin --filename=composer
composer --version
```

---

## 6. Node.js 20 (build-time only)

```bash
sudo install -d -m 0755 /etc/apt/keyrings
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | \
  sudo tee /etc/apt/keyrings/nodesource.asc > /dev/null
echo "deb [signed-by=/etc/apt/keyrings/nodesource.asc] https://deb.nodesource.com/node_20.x nodistro main" | \
  sudo tee /etc/apt/sources.list.d/nodesource.list
sudo apt update && sudo apt install -y nodejs
node -v && npm -v
```

---

## 7. Nginx + Certbot

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
sudo systemctl enable --now nginx
```

---

## 8. Clone and install the app

```bash
sudo mkdir -p /var/www/mossfield
sudo chown -R www-data:www-data /var/www/mossfield

sudo -u www-data git clone <REPO_URL> /var/www/mossfield
cd /var/www/mossfield

sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci
sudo -u www-data npm run build

sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

---

## 9. Edit `.env`

```bash
sudo -u www-data nano /var/www/mossfield/.env
```

Set at minimum (`DEPLOYMENT.md` §3):

```env
APP_NAME=Mossfield
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<DOMAIN>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mossfield
DB_USERNAME=mossfield
DB_PASSWORD=<DB_PASSWORD>

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict

OFFICE_API_TOKEN=<API_TOKEN>
MOSSORDERS_API_TOKEN=<API_TOKEN>
MOSSORDERS_BASE_URL=https://mossorders.example.com
OFFICE_API_TOKEN_PREVIOUS=
OFFICE_API_ALLOWED_IPS=

SYNC_SCHEDULE_ENABLED=false
SYNC_ALERT_EMAIL=<EMAIL>

MAIL_MAILER=smtp
MAIL_HOST=<SMTP_HOST>
MAIL_PORT=587
MAIL_USERNAME=<SMTP_USER>
MAIL_PASSWORD=<SMTP_PASS>
MAIL_FROM_ADDRESS=noreply@<DOMAIN>
MAIL_FROM_NAME="Mossfield Office"
```

Important:
- `DB_CONNECTION=mysql` is correct for MariaDB — Laravel's mysql driver speaks both protocols.
- `MOSSORDERS_BASE_URL` must start with `https://` and `APP_DEBUG` must be `false`. `AppServiceProvider::boot()` refuses to start otherwise.
- `OFFICE_API_TOKEN` and `MOSSORDERS_API_TOKEN` must be **the same** hex value (shared secret with the mossorders host).

---

## 10. Migrate, seed, file perms

`DEPLOYMENT.md` §2.

```bash
cd /var/www/mossfield
sudo -u www-data php artisan migrate --force

sudo -u www-data ADMIN_SEED_PASSWORD='<ADMIN_PASSWORD>' \
  php artisan db:seed --class=AdminUserSeeder --force

sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;

sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

`AdminUserSeeder` refuses to run in production unless `ADMIN_SEED_PASSWORD` is set inline — never use the dev default `admin123`.

---

## 11. Nginx vhost (HTTP-only first)

Certbot needs a working HTTP server on port 80 to do the HTTP-01 challenge.

```bash
sudo tee /etc/nginx/sites-available/mossfield.conf > /dev/null <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name <DOMAIN>;

    root /var/www/mossfield/public;
    index index.php;

    client_max_body_size 32m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

sudo sed -i 's|<DOMAIN>|your.domain.tld|' /etc/nginx/sites-available/mossfield.conf
sudo ln -sf /etc/nginx/sites-available/mossfield.conf /etc/nginx/sites-enabled/mossfield.conf
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

DNS for `<DOMAIN>` must already point at this server before the next step.

---

## 12. Let's Encrypt TLS

```bash
sudo certbot --nginx -d <DOMAIN> -m <EMAIL> --agree-tos --non-interactive --redirect
sudo systemctl status certbot.timer    # confirm renewal scheduled
```

certbot rewrites the vhost in place — adds `listen 443 ssl;` and the cert paths, and adds a 301 redirect from port 80. Renewal happens automatically via the systemd timer that ships with the apt package.

---

## 13. Cron — Laravel scheduler

`DEPLOYMENT.md` §6. Mandatory for the hourly Mossorders sync — without it, `SYNC_SCHEDULE_ENABLED=true` does nothing.

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * /usr/bin/php /var/www/mossfield/artisan schedule:run >> /var/www/mossfield/storage/logs/cron.log 2>&1
```

---

## 14. Smoke tests

`DEPLOYMENT.md` §10.

```bash
curl -sI https://<DOMAIN>/ | grep -iE 'strict-transport|x-frame|x-content|referrer|permissions'
sudo -u www-data php /var/www/mossfield/artisan schedule:list
sudo -u www-data php /var/www/mossfield/artisan mossfield:import-online-orders
sudo -u www-data tail -n 5 /var/www/mossfield/storage/logs/sync-$(date +%Y-%m-%d).log
```

Then browse to `https://<DOMAIN>/login` and sign in as `admin` with `<ADMIN_PASSWORD>`.

Verify PII encryption migration ran:

```bash
sudo mariadb mossfield -e "SELECT phone FROM customers LIMIT 1;"
# → expect ciphertext beginning eyJpdiI6 (or NULL if no rows yet)
```

---

## 15. Flip the scheduler on

Once the smoke tests pass (`DEPLOYMENT.md` §4.4):

```bash
sudo -u www-data nano /var/www/mossfield/.env
# change SYNC_SCHEDULE_ENABLED=false to SYNC_SCHEDULE_ENABLED=true

sudo -u www-data php /var/www/mossfield/artisan config:clear
sudo -u www-data php /var/www/mossfield/artisan config:cache
```

Within an hour the scheduled sync will run; check `storage/logs/sync-YYYY-MM-DD.log` for a `run summary` line.

---

## Tightening before go-live

`DEPLOYMENT.md` §4.2 — once the mossorders public IP is stable, populate the allowlist:

```bash
sudo -u www-data nano /var/www/mossfield/.env
# OFFICE_API_ALLOWED_IPS=<mossorders-public-ip>      # comma-separated, CIDR supported

sudo -u www-data php /var/www/mossfield/artisan config:clear
sudo -u www-data php /var/www/mossfield/artisan config:cache
```

Verify: a curl to `/api/products` from the mossorders host returns 200; from any other IP returns 403.

---

## Future redeploys

For upgrades, follow `DEPLOYMENT.md` §7. The short version:

```bash
cd /var/www/mossfield
sudo -u www-data git fetch --tags && sudo -u www-data git checkout <new-tag>
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo systemctl reload php8.3-fpm
```
