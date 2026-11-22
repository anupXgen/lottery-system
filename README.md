# Diwali Lottery Draw Management System

A simple, reliable web app for running a live Diwali lottery: upload tickets, configure 50 prizes, draw winners live from **Prize 50 down to Prize 1** on a big LED screen, and download the final winners PDF.

- Core PHP (7.4+ / 8.x — production target **PHP-FPM 8.3 + nginx**), MySQL 8 / MariaDB 10.4+
- Bootstrap 5 (served locally — works without internet), vanilla JS
- Dompdf 3.1 (PDF), PhpSpreadsheet 1.30 (Excel import)

---

## 1. Requirements

| Item | Notes |
|---|---|
| PHP 8.3 (FPM) | also runs on 7.4+ |
| PHP extensions | `pdo_mysql`, `mbstring`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `zip`, `gd`, `fileinfo`, `iconv`, `ctype`, `zlib` |
| MySQL 8.0+ or MariaDB 10.4+ | InnoDB |
| nginx (or Apache) | nginx config in `deploy/nginx/` |

Ubuntu/Debian example: `apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd`

## 2. Installation

> **Production (lottery.myappstore.org.in):** follow [`deploy/DEPLOY.md`](deploy/DEPLOY.md) – atomic releases via GitHub Actions, `shared/.env`, nginx + PHP-FPM 8.3. The steps below are the manual/simple install.

1. **Copy the project** to the server, e.g. `/var/www/diwali-lottery`. The `vendor/` folder is included; if it is missing run `composer install --no-dev` in the project folder.
2. **Create the database** (creates `diwali_lottery`, all tables, 50 prizes and the admin user):
   ```bash
   mysql -u root -p < database.sql
   ```
   > ⚠️ `database.sql` drops and recreates the tables. Do not run it again on a live database.
3. **Create a dedicated DB user** (recommended):
   ```sql
   CREATE USER 'lottery'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON diwali_lottery.* TO 'lottery'@'localhost';
   ```
4. **Configure** – copy `.env.example` to `.env` in the project root and set `LOTTERY_DB_*` (host, port, name, user, password). Real environment variables with the same names take precedence. Keep `LOTTERY_APP_DEBUG=false` in production. Never commit `.env`.
   Check `config/config.php` for the timezone (default `Asia/Kolkata`).
5. **Writable folders** for the PHP-FPM user: `uploads/` and `exports/`
   ```bash
   chown -R www-data:www-data uploads exports && chmod 750 uploads exports
   ```
6. **Web server**
   - **nginx:** use `deploy/nginx/` (`common.inc` + the HTTP or HTTPS server block; adjust `server_name`/`root`). nginx ignores `.htaccess`, so the deny rules in `common.inc` are **required** to keep `.env`, `config/`, `includes/`, `vendor/`, `bin/`, `uploads/`, `exports/` and `tests/` private.
   - **Apache:** the included `.htaccess` files already protect those folders.
   - Upload limits for FPM are in `.user.ini` (8 MB); nginx uses `client_max_body_size 10m`.
7. Open `https://your-domain/` and log in.

### Default admin

| Username | Password |
|---|---|
| `admin` | `Admin@123` |

**Change it before the site is public** with `bin/set-admin-password.php` on the server (see `deploy/DEPLOY.md` step 9; the password is read from stdin, not the command line), or in *Settings › Change Password* after login. Ten failed logins from one IP lock logins from that IP for 15 minutes.

## 3. Running the event

1. **Tickets** – *Upload* a CSV/Excel file (download the template from the Upload page, or use `sample-tickets.csv`) or add tickets manually. Columns: `Lottery Number, Sold By, Sold To` (Sold To optional). The whole file is rejected if any row is invalid (duplicate number, missing Sold By, bad characters…) unless you tick *skip invalid rows*. A full validation report is shown.
2. **Prizes** – name all 50 prizes. Prize 1 is the grand prize and is drawn last.
3. **Settings** – event name, date and venue (shown on the LED screen and PDF).
4. **LED screen** – on the computer connected to the LED wall open `/live-display.php` and press **F** (or the *Full screen* button). It needs no login and updates itself every second; refreshing it is always safe.
5. **Live Draw** (`/admin/live-draw.php`) on the admin laptop:
   - **START DRAW** is the only click needed per prize: the LED rolls numbers for 2 seconds (`AUTO_ROLL_MS`), then the server picks a random eligible ticket and **saves it automatically**. The LED slows down onto the number (`LED_REVEAL_MS`), shows it as confirmed with a celebration, and adds it to the winners list. START unlocks again once the LED has landed.
   - If the admin page is closed or loses connection mid-draw, the draw is left unfinished: **DRAW WINNER NOW** picks and saves it, **CONFIRM WINNER** saves an already revealed winner, **CANCEL DRAW** discards it (nothing saved; the same prize is drawn again).
   - **END LOTTERY** shows the closing screen on the LED (can be resumed if pressed early).
6. **Reports** – download the PDF (*DIWALI LOTTERY – FINAL WINNERS LIST*, prizes 50 → 1, page numbers) or CSV. Before all 50 are drawn the PDF is marked *provisional*. A copy of each PDF is archived in `exports/`.

Once the first winner is confirmed, tickets and prizes are **locked**. To rehearse and then start over use *Settings › Reset Lottery* (needs your password and typing `RESET`; the previous winners are archived as CSV in `exports/` and recorded in the audit log).

## 4. How fairness and safety are guaranteed

- **Server-side randomness:** the winner is chosen in PHP with `random_int()` (cryptographically secure) uniformly from all tickets with status `AVAILABLE`. The browser animation is purely visual; the number is not sent to the LED screen until it is revealed.
- **One draw at a time:** every draw action locks the single `draw_session` row (`SELECT … FOR UPDATE`) inside a transaction, so double-clicks, multiple admin tabs or simultaneous requests are serialised and validated against the state machine `IDLE → ROLLING → REVEALED → IDLE`.
- **Nothing is saved until CONFIRM.** Confirmation is one transaction that re-checks the prize is still pending and is the next in order, that the ticket is still available, and that it matches what the admin saw.
- **Database constraints:** `UNIQUE(prize_id)` and `UNIQUE(lottery_ticket_id)` on `lottery_draws` make a second winner for a prize, or a ticket winning twice, impossible even if application code were bypassed. Lottery numbers are `UNIQUE`; prize numbers are constrained to 1–50.
- **Descending order** is enforced on the server: the next prize is always the highest pending prize number.
- **Recovery:** all state lives in the database. Reloading the admin page or the LED screen (or restarting the server) resumes exactly where it was, including a revealed-but-unconfirmed winner.
- **Security:** PDO prepared statements everywhere, output escaping, CSRF tokens on all admin actions, `password_hash()`, session ID regeneration on login, HttpOnly/SameSite cookies, idle timeout, login throttling, audit log of every draw/reset/import.

## 5. Project structure

```
admin/          dashboard, tickets, upload-tickets, prizes, live-draw, reports, settings
api/            draw-start, draw-reveal, draw-confirm, draw-cancel, draw-end (POST, admin + CSRF)
                live-status, winners (GET, public read-only)
assets/         css/, js/ (app, live-draw, live-display, draw-animation), vendor/ (Bootstrap)
config/         config.php (app), database.php (DB), env.php (.env loader)
bin/            healthcheck.php, set-admin-password.php (CLI only)
includes/       bootstrap, auth, functions, draw (draw engine), tickets (validation/import), header/footer
deploy/         DEPLOY.md (production runbook), remote-deploy.sh, nginx/
.github/        workflows/deploy.yml (CI: lint, audit, tests, atomic deploy)
tests/          run-tests.php, http-tests.php, worker.php
live-display.php  LED screen (1920×1080)
database.sql      schema + seed data
sample-tickets.csv 120 sample tickets
```

Tables: `users`, `lottery_tickets`, `prizes`, `lottery_draws` (confirmed winners only), `draw_session` (live state), `settings`, `audit_logs`.

## 6. Tests

The test-suite creates and uses a **separate** database `diwali_lottery_test` (never your live data):

```bash
php tests/run-tests.php          # core: validation, draw rules, concurrency, full 50-prize event, fairness
php tests/run-tests.php --http   # also starts PHP's built-in server and tests pages, security, APIs, PDF/CSV
```

The PDF text checks use `pdftotext` (poppler-utils) when available. Run tests on a staging copy, not on the event server during the event.

## 7. Known limitations

- Single event at a time (one set of 50 prizes); use *Reset Lottery* between rehearsal and the real event.
- The LED screen polls once per second (no WebSockets), which is plenty for a live draw and works on any shared host.
- Confirmed winners cannot be edited from the draw screen by design; correcting one requires a full reset.
- Lottery numbers are stored as text (leading zeros are kept for CSV files; Excel may already have removed them if the column was numeric).
- The LED page loads the Cinzel/Montserrat fonts from Google Fonts; without internet it falls back to system fonts (everything else is local).

## 8. Housie / Tambola module

An independent second module, reached from the **Housie / Tambola** menu. It uses the same admin login, and shares no tables, draw logic, screens or reports with the lottery.

**Install (once, additive):** take a backup, then apply the migration to the existing database:

```bash
mysqldump diwali_lottery > backup-before-housie.sql
sudo mysql diwali_lottery < database/migrations/add_housie_module.sql
```

It only creates `housie_*` tables (`CREATE TABLE IF NOT EXISTS`, safe to re-run) and never touches lottery tables. Until it is applied, the Housie pages show an "not installed" notice and the lottery works as before.

| Page | Purpose |
|---|---|
| `housie/index.php` | Dashboard: create a game, switch the active game, checklist |
| `housie/tickets.php` | Generate tickets in bulk (valid 3×9, 15 numbers, unique), search by Ticket ID / name, assign participants (before the game starts), print or PDF (10 per A4 page) |
| `housie/prizes.php` | Enable categories, prize names, descriptions, winners allowed, order; status Pending / Claimed / Completed |
| `housie/live-draw.php` | START, CALL NEXT NUMBER, PAUSE, RESUME, END |
| `housie/claims.php` | Verify a claim (category + Ticket ID) against the numbers called, then confirm or reject |
| `housie/reports.php` | Winners, number calling history, ticket report; PDF, CSV, print |
| `housie/live-display.php` | **Housie LED screen** (public, 1920×1080, press F) |

How it stays fair: the 1–90 order is shuffled on the server at START (Fisher-Yates with `random_int`) and never sent to browsers; each call runs in a transaction that locks the game row, and `UNIQUE(game_id, number)` makes repeats impossible. Claims are never auto-confirmed: the admin checks, then confirms, and confirmation re-checks everything with the game and prize rows locked. Duplicate confirmations, exceeding the winners allowed, claims from another game and claims on uncalled numbers are all refused. Second / Third Full House require the previous Full House to be completed, and a ticket can win only one Full House. Generated tickets always have numbers in the four corner cells, so every ticket can win Four Corners.

Tests: `php tests/run-tests.php` now also runs the Housie suite (sections H1–H8, plus H9 with `--http`).

**Physical (pre-printed) tickets:** generating tickets in the system is optional. A game can be started with no system tickets; call numbers as usual, check claimed tickets by hand in the room (the Claim Verification page shows the 1–90 board of called numbers), then use **Manual winner entry** (category, ticket number as printed, winner name). The winner appears on the Housie LED screen and in the reports immediately. Winner limits, duplicate tickets per category and the Full House order rules are still enforced; the ticket's numbers are not checked by the system in this mode.
