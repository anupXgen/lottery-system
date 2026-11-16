# Production deployment – lottery.myappstore.org.in

Site-specific version of the ByteStream Production Deployment SOP v1.0 for the Diwali Lottery app.
Server-level setup (nginx, PHP 8.3-FPM, UFW, fail2ban, `deploy` user) is assumed done; this covers only the per-site steps.

```bash
DOMAIN=lottery.myappstore.org.in
APP_NAME=lottery
SERVER_IP=13.126.155.242          # Mumbai Lightsail – confirm this is the target server
DEPLOY_PATH=/var/www/apps/lottery.myappstore.org.in
PHP_VERSION=8.3
```

### Where this app differs from the generic SOP

| SOP item | This app |
|---|---|
| `WEB_ROOT=/var/www/sites/$DOMAIN` | `DEPLOY_PATH=/var/www/apps/$DOMAIN` (`releases/`, `shared/`, `current`) |
| `shared/.env` only | `shared/.env` **plus** `shared/uploads/` and `shared/exports/` (uploaded ticket files, PDF/CSV archives) |
| Clean URLs (`@clean_urls` rewrite) | **Not used.** The app uses real `.php` URLs only; nginx has no rewrites and no `$uri.php` fallback. SOP steps 32/33 (`.php` → clean redirects, sitemap scan) do not apply. |
| `www.` host | None – single host `lottery.myappstore.org.in` |
| Deployment ZIP | `.tgz` (tar is always on the server; no `unzip` needed) |
| Contact form test (step 39) | Replaced by the event rehearsal (step 16 below) |
| — | **MySQL database** (step 5) and **admin password** (step 9) are app-specific |

Health checks used by CI: `php bin/healthcheck.php` (before the switch) and `GET /api/live-status.php` → `200` + `"ok":true` (after the switch, via nginx on 127.0.0.1).

---

## 1. Security audit (done 2026-11-16, before first deploy)

- `php -l` on all app PHP files: **clean** (PHP 8.2 and 7.4)
- `composer audit`: **no advisories** (dompdf 3.1.6, phpspreadsheet 1.30.8)
- Test-suite: **113/113** (PHP 8.2, incl. HTTP/security tests), **72/72** core (PHP 7.4)
- Secrets: no credentials in code. DB credentials moved to `.env` (git-ignored); `config/database.php` contains only defaults with an empty password.
- See the findings table in the deployment hand-over notes for remaining items (default admin password, CSP).

CI re-runs lint, `composer audit`, a tracked-secrets check and the full test-suite on every deploy; a failure blocks the deploy.

## 2. Deployment SSH key (Mac)

```bash
ssh-keygen -t ed25519 -f ~/.ssh/github-actions-lottery-production -C "github-actions-lottery-production"
cat ~/.ssh/github-actions-lottery-production.pub
```

On the server (mumadmin), append to `/home/deploy/.ssh/authorized_keys`:

```
no-agent-forwarding,no-port-forwarding,no-X11-forwarding ssh-ed25519 AAAA... github-actions-lottery-production
```

```bash
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 700 /home/deploy/.ssh && sudo chmod 600 /home/deploy/.ssh/authorized_keys
```

Test from the Mac: `ssh -i ~/.ssh/github-actions-lottery-production deploy@$SERVER_IP whoami` → `deploy`.

Known hosts (compare fingerprints first, SOP step 6):

```bash
ssh-keyscan -H -t ed25519 $SERVER_IP 2>/dev/null     # → GitHub secret PROD_KNOWN_HOSTS
```

## 3. Directory structure, group, shared files (server, mumadmin)

```bash
DOMAIN=lottery.myappstore.org.in; APP_NAME=lottery; DEPLOY_PATH=/var/www/apps/$DOMAIN

sudo mkdir -p $DEPLOY_PATH/{releases,shared/uploads,shared/exports}
sudo groupadd --system $APP_NAME 2>/dev/null || true
sudo usermod -aG $APP_NAME deploy
sudo usermod -aG $APP_NAME www-data
sudo systemctl restart php8.3-fpm          # FPM workers pick up the new group membership

sudo chown -R deploy:deploy $DEPLOY_PATH
sudo chmod 755 $DEPLOY_PATH $DEPLOY_PATH/releases

# shared/: readable by PHP via the group, not by others
sudo chown deploy:$APP_NAME $DEPLOY_PATH/shared
sudo chmod 750 $DEPLOY_PATH/shared

# uploads/exports: written by PHP-FPM (www-data) - group-writable, setgid keeps the group
sudo chown deploy:$APP_NAME $DEPLOY_PATH/shared/uploads $DEPLOY_PATH/shared/exports
sudo chmod 2770 $DEPLOY_PATH/shared/uploads $DEPLOY_PATH/shared/exports
```

> `/var/www/apps` must be traversable by www-data (`sudo chmod 755 /var/www/apps` if it was just created).

## 4. PHP extensions (server, once)

```bash
sudo apt install php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd
sudo systemctl reload php8.3-fpm
php -m | grep -Ei 'pdo_mysql|mbstring|dom|xmlreader|zip|gd|fileinfo|iconv'
```

## 5. Database (server, once)

MySQL 8 is not in the server baseline – install it if absent (`sudo apt install mysql-server`). Keep port 3306 closed in Lightsail and UFW (bind-address 127.0.0.1 is the Ubuntu default).

```bash
# From the Mac: copy the schema to the server
scp database.sql mumadmin@$SERVER_IP:/tmp/lottery-database.sql

# On the server
sudo mysql < /tmp/lottery-database.sql && rm /tmp/lottery-database.sql
sudo mysql <<'SQL'
CREATE USER 'lottery'@'localhost' IDENTIFIED BY 'GENERATE-A-LONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON diwali_lottery.* TO 'lottery'@'localhost';
SQL
```

> ⚠️ `database.sql` drops and recreates all tables. **Run it once only** – never on a live event database.
> Generate the password with `openssl rand -base64 24` and type it straight into the next step; don't paste it into chat/tickets.

## 6. Production `.env` (server)

```bash
sudo -u deploy touch $DEPLOY_PATH/shared/.env
sudo chown deploy:$APP_NAME $DEPLOY_PATH/shared/.env
sudo chmod 640 $DEPLOY_PATH/shared/.env
sudo -u deploy nano $DEPLOY_PATH/shared/.env
```

Contents (see `.env.example`):

```
LOTTERY_DB_HOST=127.0.0.1
LOTTERY_DB_PORT=3306
LOTTERY_DB_NAME=diwali_lottery
LOTTERY_DB_USER=lottery
LOTTERY_DB_PASS=<the password from step 5>
LOTTERY_APP_DEBUG=false
```

```bash
sudo -u www-data test -r $DEPLOY_PATH/shared/.env && echo "WWW-DATA ENV OK" || echo "WWW-DATA ENV FAILED"
sudo -u www-data test -w $DEPLOY_PATH/shared/uploads && echo "UPLOADS OK" || echo "UPLOADS FAILED"
```

## 7. GitHub environment `production`

Repository → Settings → Environments → `production` (add required reviewers if you want a manual approval gate).

| Name | Type | Value |
|---|---|---|
| `PROD_HOST` | secret | `13.126.155.242` |
| `PROD_PORT` | secret | `22` |
| `PROD_USER` | secret | `deploy` |
| `PROD_DEPLOY_PATH` | secret | `/var/www/apps/lottery.myappstore.org.in` |
| `PROD_SSH_KEY` | secret | contents of `~/.ssh/github-actions-lottery-production` |
| `PROD_KNOWN_HOSTS` | secret | output of `ssh-keyscan -H -t ed25519 13.126.155.242` |
| `PROD_SSH_PASSPHRASE` | secret | key passphrase (empty if none) |
| `PROD_DOMAIN` | variable | `lottery.myappstore.org.in` |
| `PROD_SCHEME` | variable | `http` → change to `https` after step 12 |
| `PROD_DEPLOY_ENABLED` | **repository** variable | `true` – deploys are skipped (tests still run) until this is set. Set it at repository level (Settings → Secrets and variables → Actions → Variables), not on the environment: the job's `if:` is evaluated before the environment is loaded. |

## 8. First deployment

Push to `main` (or run the workflow manually). `.github/workflows/deploy.yml`:

```
lint + composer audit + secrets check + full test-suite (MySQL service)
→ composer install --no-dev → package releases/<UTC timestamp>-<sha>.tgz → scp
→ deploy/remote-deploy.sh on the server:
   extract → link shared/.env, uploads, exports → chmod 755/644
   → php bin/healthcheck.php (DB, tables, 50 prizes, writable dirs, APP_DEBUG off)   ✗ = delete release, live untouched
   → atomic switch (ln -sfn + mv -T)
   → HTTP check via nginx on 127.0.0.1 (live-status ok, login no PHP source, .env/config/vendor/sql = 404)   ✗ = roll back to previous
   → prune (keep 5, never current/previous)
→ if PROD_SCHEME=https: public HTTPS check + HTTP→HTTPS redirect check
```

On the first run nginx isn't configured yet, so the HTTP check is skipped with a warning (the CLI check still runs).

```bash
readlink -f $DEPLOY_PATH/current               # → .../releases/<release>
ls -l $DEPLOY_PATH/current/.env                # → .../shared/.env
find $DEPLOY_PATH/current/ -maxdepth 1 -printf '%M %u %g %p\n'
sudo -u deploy php $DEPLOY_PATH/current/bin/healthcheck.php
```

## 9. Replace the default admin password (BEFORE DNS)

`database.sql` creates `admin` / `Admin@123` – a published password. Set a real one from the shell before the site is reachable:

```bash
read -rs -p "New admin password: " P && echo && printf '%s' "$P" | sudo -u deploy php $DEPLOY_PATH/current/bin/set-admin-password.php admin; unset P
```

## 10. nginx – HTTP first

```bash
cd $DEPLOY_PATH/current/deploy/nginx
sudo cp common.inc /etc/nginx/snippets/lottery.myappstore.org.in.inc
sudo cp lottery.myappstore.org.in.http.conf /etc/nginx/sites-available/lottery.myappstore.org.in
sudo ln -s /etc/nginx/sites-available/lottery.myappstore.org.in /etc/nginx/sites-enabled/lottery.myappstore.org.in
sudo mkdir -p /var/www/letsencrypt
sudo nginx -t && sudo systemctl reload nginx
```

## 11. Pre-DNS security tests (server)

```bash
H="Host: lottery.myappstore.org.in"

# Pages execute (no PHP source)
for p in "" login.php live-display.php admin/ api/live-status.php api/winners.php; do
  curl -sS -H "$H" -o /tmp/lt -w "HTTP=%{http_code} TYPE=%{content_type}  /$p\n" "http://127.0.0.1/$p"
  grep -q '<?php' /tmp/lt && echo "  CRITICAL: PHP SOURCE EXPOSED" || echo "  PHP source: OK"
done; rm -f /tmp/lt
# Expected: / 302 → login.php · login.php 200 · live-display.php 200 · admin/ 302 · api/* 200 application/json

# Private files: must all be 404
for p in .env .env.example .git/config .htaccess .user.ini composer.json composer.lock database.sql README.md \
         REVISION config/database.php config/env.php includes/auth.php vendor/autoload.php bin/healthcheck.php \
         tests/run-tests.php deploy/remote-deploy.sh deploy/nginx/common.inc uploads/ exports/; do
  curl -sS -H "$H" -o /dev/null -w "%{http_code}  /$p\n" "http://127.0.0.1/$p"
done

sudo systemctl is-active php8.3-fpm
sudo tail -n 50 /var/log/nginx/lottery_error.log
```

## 12. DNS, firewall, SSL

```bash
# DNS: A  lottery  13.126.155.242   (in the myappstore.org.in zone)
dig +short lottery.myappstore.org.in A          # → 13.126.155.242
# Lightsail + UFW: only 22, 80, 443 open (never 3306)

sudo certbot certonly --webroot -w /var/www/letsencrypt -d lottery.myappstore.org.in
sudo cp $DEPLOY_PATH/current/deploy/nginx/lottery.myappstore.org.in.conf /etc/nginx/sites-available/lottery.myappstore.org.in
sudo nginx -t && sudo systemctl reload nginx
sudo certbot renew --dry-run
```

Verify (from the Mac):

```bash
curl -4 -I https://lottery.myappstore.org.in/login.php                 # 200 + Strict-Transport-Security
curl -IL http://lottery.myappstore.org.in/                             # 301 → https, no loops
echo | openssl s_client -connect lottery.myappstore.org.in:443 -servername lottery.myappstore.org.in 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates -ext subjectAltName
```

Repeat the step-11 loops over `https://lottery.myappstore.org.in/...`. Then set GitHub variable `PROD_SCHEME=https` and re-run the workflow – it must pass the HTTPS checks.

The session cookie automatically gets the `Secure` flag over HTTPS (`fastcgi_params` passes `HTTPS=on`).

## 13. Browser regression (manual)

- `/login.php` – log in with the new password; wrong password shows an error.
- Dashboard, Tickets (add / search / edit / delete), Upload (template download, `sample-tickets.csv`), Prizes, Settings.
- `/live-display.php` on the LED computer – press **F** for full screen; fonts load (Google Fonts), no console errors.
- Live Draw – START → REVEAL → CONFIRM; LED screen follows within ~1 s.
- Reports – PDF opens and looks right; CSV downloads.
- Logs: `sudo tail -n 100 /var/log/nginx/lottery_error.log` and `sudo journalctl -u php8.3-fpm --since "30 minutes ago" --no-pager` – no 500/502, no "Primary script unknown", no permission denied.

## 14. Event rehearsal, then reset

Upload real tickets, name the 50 prizes, set event details, run a few draws end-to-end on the real LED screen and admin laptop, download the PDF, then **Settings › Reset Lottery** (keep tickets or delete them).

**Freeze deploys on event day.** A deploy is safe for draw state (it's all in the database), but there is no reason to risk it during a live draw.

## 15. Rollback

Automatic on a failed HTTP check. Manual:

```bash
ls -1dt $DEPLOY_PATH/releases/*                         # newest first
sudo -u deploy ln -sfn $DEPLOY_PATH/releases/<good-release> $DEPLOY_PATH/.current.tmp
sudo -u deploy mv -Tf $DEPLOY_PATH/.current.tmp $DEPLOY_PATH/current
```

No nginx/FPM reload needed (`$realpath_root`).

## 16. Backups and sign-off

- After the event: download the final PDF + CSV and `mysqldump diwali_lottery` (the winners list is the record of the draw).
- Lightsail snapshot after go-live: `india-mum-prod-YYYY-MM-DD-lottery`.
- Record the SOP step-45 sign-off table (domain, commit SHA, release dir, test results, rollback available).
