# Mossfield — First Deploy Notes

Working notes for resuming the initial deploy of mossfield to production. Pair with `deploy/INSTALL.md` (the canonical step-by-step) and `DEPLOYMENT.md` (the operator runbook). These notes capture the state, decisions, and gotchas specific to *this* deploy.

Not intended to be permanent — feel free to delete or gitignore once the box is live.

---

## Target host

- **Box**: `ubuntu-4gb-nbg1-1` (Hetzner, Ubuntu 24.04)
- **SSH access**: public (option A — port 22 open via UFW)
- **No WireGuard** on this server
- **Domain**: _(fill in)_  — must have DNS pointing at the box before Let's Encrypt step

## Stack decisions

| Component | Choice |
|---|---|
| Database | **MariaDB 11.4** (Ubuntu 24.04 default — drop-in MySQL replacement) |
| PHP | 8.3 |
| Node | 20.x (NodeSource) |
| Webserver | Nginx + Let's Encrypt |
| SSH access | Public (no WG), key-only auth |
| Repo auth | SSH deploy key (read-only) at `/var/www/.ssh/id_ed25519_mossfield` |

## Progress so far (mapped to INSTALL.md sections)

- [x] §1 Generate secrets — `DB_PASSWORD`, `ADMIN_PASSWORD`, `API_TOKEN` _(stored in: ___ )_
- [x] §2 Base packages + UFW — `OpenSSH`, 80/tcp, 443/tcp open; UFW enabled
- [x] §3 PHP 8.3 + extensions
- [x] §4 MariaDB + DB bootstrap (CREATE DATABASE / USER / GRANT)
- [x] §5 Composer 2.9.7 installed at `/usr/local/bin/composer`
- [x] §6 Node.js 20 installed
- [x] §7 Nginx + certbot installed
- [x] §8 partial — `composer install --no-dev` succeeded; `npm ci` **failed** on first try (see Gotchas), retry pending
- [ ] §9 Edit `.env`
- [ ] §10 Migrate, seed, perms, framework caches — **see seeder caveat below**
- [ ] §11 Nginx vhost (HTTP-only first)
- [ ] §12 Let's Encrypt — `sudo certbot --nginx -d <DOMAIN> -m <EMAIL> --agree-tos --non-interactive --redirect`
- [ ] §13 Cron entry for `schedule:run` (as `www-data`)
- [ ] §14 Smoke tests
- [ ] §15 Flip `SYNC_SCHEDULE_ENABLED=true` after smoke tests pass

## Resume here tomorrow

Currently mid-§8, npm install failed on cache permissions. Pick up with:

```bash
# Fix the npm cache dir so www-data can write to it
sudo mkdir -p /var/www/.npm
sudo chown -R www-data:www-data /var/www/.npm

# Wipe the half-extracted node_modules from the failed run
sudo rm -rf /var/www/mossfield/node_modules

cd /var/www/mossfield
sudo -u www-data npm ci
sudo -u www-data npm run build

# Verify build output landed
ls /var/www/mossfield/public/build/
```

Quick sanity check the composer step really did work:

```bash
ls /var/www/mossfield/vendor/autoload.php && echo "composer ok"
```

Then continue from §9 in `deploy/INSTALL.md`.

## ⚠ AdminUserSeeder doesn't work in production (mismatch between docs and code)

`DEPLOYMENT.md` §2 says the seeder runs in production "unless `ADMIN_SEED_PASSWORD` is set" — but the actual code (`database/seeders/AdminUserSeeder.php:14-18`) refuses to run in production *unconditionally*. So the §10 seed command in `INSTALL.md` is wrong and will be a no-op on the prod box.

Pick one before §10:

1. **Tinker (what the seeder error suggests):**
   ```bash
   sudo -u www-data php artisan tinker
   >>> \App\Models\User::create([
   ...   'name' => 'Admin', 'username' => 'admin',
   ...   'email' => 'admin@osmanager.local',
   ...   'password' => bcrypt('<ADMIN_PASSWORD>'),
   ...   'email_verified_at' => now(),
   ...   'role' => \App\Enums\UserRole::Admin->value,
   ...   'is_active' => true,
   ... ]);
   ```
2. **Patch the seeder** so it matches what `DEPLOYMENT.md` claims — allow production runs when `ADMIN_SEED_PASSWORD` is set, refuse otherwise. Cleanest fix; commit it before deploy.

**Default seeded password** (dev only): all four seeded users share `admin123` unless `ADMIN_SEED_PASSWORD` overrides it. Users: `admin`, `office_test`, `factory_test`, `driver_test`.

## Gotchas hit (so we don't re-hit)

### `/var/www/.ssh` didn't exist for the deploy-key generation
www-data's home is `/var/www` but the dir isn't writable by www-data and `.ssh` doesn't exist by default. Had to create it as root first:
```bash
mkdir -p /var/www/.ssh && chown www-data:www-data /var/www/.ssh && chmod 700 /var/www/.ssh
```
Same applies to any per-user dir under `/var/www/` that www-data needs.

### `npm` choked on `/var/www/.npm` cache permissions
Same root cause as above — www-data's home isn't writable. npm's cache dir has to be pre-created with `chown www-data:www-data /var/www/.npm`. Once made, `npm ci` runs cleanly.

### Composer "Continue as root/super user [yes]?" prompt
Only triggers when running composer as root (e.g. `composer --version` directly). All real installs use `sudo -u www-data composer install ...` and don't hit this. Harmless.

## Local dev box (separate machine — `/var/www/html/mossfield`)

- Switched git remote from HTTPS-with-embedded-PAT to SSH (`git@github.com:jonathanHas/mossfield.git`). The `ghp_…` token that was in `.git/config` is now gone.
- Made 2 new commits not yet pushed:
  - `ce72cd0` Add operator documentation and deployment guide (8 files)
  - `6989847` Checkpoint months of unpushed feature work (129 files)
- **Need to**: `git push origin main` from the dev box before the production clone is current. Without this, prod will clone an outdated `main` (last pushed commit was `b0515c2`).
- `deploy/ansible/` is gitignored (WIP playbook, not yet finished).

## Reference values that need to match across hosts

- `OFFICE_API_TOKEN` (mossfield) === `MOSSORDERS_API_TOKEN` (mossfield) === `OFFICE_API_TOKEN` on the **mossorders** host. All three hold the same hex value (`openssl rand -hex 32`). Generated once, copied to all three places.
- `MOSSORDERS_BASE_URL` must be `https://...` — `AppServiceProvider::boot()` refuses to start otherwise.
- `APP_DEBUG=false` in production — same boot guard refuses to start otherwise.

## Production `.env` — items that must be set before §10

(Copy block from `INSTALL.md` §9; this is the short reminder list of values you can't fake.)

- `APP_URL=https://<DOMAIN>`
- `DB_PASSWORD=<DB_PASSWORD>` (the one generated in §1)
- `OFFICE_API_TOKEN=<API_TOKEN>` and `MOSSORDERS_API_TOKEN=<API_TOKEN>` (same value)
- `MOSSORDERS_BASE_URL=https://<mossorders-host>` (https or boot fails)
- `MAIL_*` — real SMTP creds; `MAIL_FROM_ADDRESS` must match a real domain or alerts will bounce
- Session hardening block (5 vars from `INSTALL.md` §9) — can be copy-pasted verbatim
- `SYNC_SCHEDULE_ENABLED=false` initially — flip to true at §15

## After deploy is healthy

- §4.2 of `DEPLOYMENT.md` — populate `OFFICE_API_ALLOWED_IPS` with the mossorders public IP, `php artisan config:clear && php artisan config:cache`. Verify a curl from another host gets 403.
- §15 of `INSTALL.md` — flip `SYNC_SCHEDULE_ENABLED=true`, re-cache config. Within an hour `storage/logs/sync-YYYY-MM-DD.log` should show a `run summary` line.
- Backup plan for the DB — `mysqldump` cron, off-box destination. Not in `INSTALL.md` (out of scope) but worth doing before real customer data lands.
