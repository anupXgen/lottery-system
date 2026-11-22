#!/usr/bin/env bash
# Atomic release activation. Runs ON THE SERVER as the `deploy` user; the GitHub
# workflow pipes it over SSH:  ssh deploy@host 'bash -s' -- <args> < deploy/remote-deploy.sh
#
#   $1 DEPLOY_PATH  e.g. /var/www/apps/lottery.myappstore.org.in
#   $2 RELEASE      e.g. 20261116120000-abcdef123456  (tarball already uploaded as releases/$RELEASE.tgz)
#   $3 DOMAIN       e.g. lottery.myappstore.org.in
#   $4 SCHEME       http | https   (health check scheme)
#   $5 KEEP         number of releases to keep (default 5)
#
# Flow: extract -> link shared (.env, uploads, exports) -> permissions -> CLI health check
#       -> atomic `current` switch -> HTTP health check via nginx -> rollback on failure -> prune.
set -Eeuo pipefail

DEPLOY_PATH="${1:?deploy path}"
RELEASE="${2:?release name}"
DOMAIN="${3:?domain}"
SCHEME="${4:-http}"
KEEP="${5:-5}"

RELEASES="$DEPLOY_PATH/releases"
SHARED="$DEPLOY_PATH/shared"
CURRENT="$DEPLOY_PATH/current"
REL="$RELEASES/$RELEASE"
TARBALL="$RELEASES/$RELEASE.tgz"

log()  { echo "==> $*"; }
fail() { echo "!! DEPLOY FAILED: $*" >&2; exit 1; }

[[ "$RELEASE" =~ ^[0-9]{14}-[0-9a-f]{7,40}$ ]] || fail "invalid release name '$RELEASE'"
[[ "$SCHEME" == "http" || "$SCHEME" == "https" ]] || fail "invalid scheme '$SCHEME'"
[ -f "$TARBALL" ]        || fail "missing $TARBALL"
[ ! -e "$REL" ]          || fail "release $REL already exists"
[ -r "$SHARED/.env" ]    || fail "missing $SHARED/.env (create it first - see deploy/DEPLOY.md)"
for d in uploads exports; do
    [ -d "$SHARED/$d" ] || fail "missing $SHARED/$d (create it first - see deploy/DEPLOY.md)"
done
if [ -e "$CURRENT" ] && [ ! -L "$CURRENT" ]; then
    fail "$CURRENT exists and is not a symlink"
fi

log "Extracting $RELEASE"
mkdir -m 755 "$REL"
tar -xzf "$TARBALL" -C "$REL" --no-same-owner
rm -f "$TARBALL"

log "Linking shared files"
ln -sfn "$SHARED/.env" "$REL/.env"
for d in uploads exports; do
    rm -rf -- "${REL:?}/$d"
    ln -sfn "$SHARED/$d" "$REL/$d"
done

log "Setting permissions (dirs 755, files 644)"
find "$REL" -type d -exec chmod 755 {} +
find "$REL" -type f -exec chmod 644 {} +

# Additive, idempotent migrations (database/migrations/*.sql) run against the app's database
# (local MySQL or RDS) BEFORE the switch, so the new code never runs without its tables.
# The current release keeps serving meanwhile; it simply ignores tables it does not know.
if [ -f "$REL/bin/migrate.php" ]; then
    log "Database migrations"
    if ! php "$REL/bin/migrate.php"; then
        rm -rf -- "${REL:?}"
        fail "database migration failed - live site untouched (see message above)"
    fi
fi

log "Pre-switch health check (PHP CLI against the new release)"
if ! php "$REL/bin/healthcheck.php"; then
    rm -rf -- "${REL:?}"
    fail "health check failed - live site untouched"
fi

PREV=""
if [ -L "$CURRENT" ]; then
    PREV="$(readlink -f "$CURRENT")"
fi

switch_to() {
    ln -sfn "$1" "$DEPLOY_PATH/.current.tmp"
    mv -Tf "$DEPLOY_PATH/.current.tmp" "$CURRENT"
}

log "Switching current -> releases/$RELEASE (previous: ${PREV:-none})"
switch_to "$REL"

http_check() {
    local port=80 base fails=0 code body
    [ "$SCHEME" = "https" ] && port=443
    base="$SCHEME://$DOMAIN"
    local CURL=(curl -sS --max-time 10 --retry 3 --retry-delay 2 --resolve "$DOMAIN:$port:127.0.0.1")

    body="$("${CURL[@]}" -w $'\n%{http_code}' "$base/api/live-status.php")" || body=$'\n000'
    code="${body##*$'\n'}"
    if [ "$code" = "200" ] && grep -q '"ok":true' <<<"$body"; then
        echo "   [ OK ] /api/live-status.php 200 + ok:true"
    else
        echo "   [FAIL] /api/live-status.php -> $code"; fails=$((fails + 1))
    fi

    body="$("${CURL[@]}" -w $'\n%{http_code}' "$base/login.php")" || body=$'\n000'
    code="${body##*$'\n'}"
    if [ "$code" = "200" ] && ! grep -q '<?php' <<<"$body"; then
        echo "   [ OK ] /login.php 200, no PHP source"
    else
        echo "   [FAIL] /login.php -> $code (or PHP source exposed)"; fails=$((fails + 1))
    fi

    for p in .env config/database.php composer.json database.sql bin/healthcheck.php vendor/autoload.php \
             database/migrations/add_housie_module.sql includes/housie/housie.php; do
        code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$base/$p" || echo 000)"
        if [ "$code" = "404" ] || [ "$code" = "403" ]; then
            echo "   [ OK ] /$p -> $code"
        else
            echo "   [FAIL] /$p -> $code (must be 403/404)"; fails=$((fails + 1))
        fi
    done
    return "$fails"
}

if [ -e "/etc/nginx/sites-enabled/$DOMAIN" ]; then
    # Once SSL is set up nginx redirects http -> https. If PROD_SCHEME is still "http",
    # follow that and check over https instead of failing on the 301.
    if [ "$SCHEME" = "http" ]; then
        probe="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code} %{redirect_url}' \
                 --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/login.php" || true)"
        case "$probe" in
            30[1278]\ https://"$DOMAIN"/*)
                echo "   note: nginx redirects http -> https; checking over https (set PROD_SCHEME=https in GitHub)"
                SCHEME=https
                ;;
        esac
    fi
    log "Post-switch HTTP health check ($SCHEME via nginx on 127.0.0.1)"
    if ! http_check; then
        if [ -n "$PREV" ] && [ -d "$PREV" ] && [ "$PREV" != "$REL" ]; then
            switch_to "$PREV"
            fail "HTTP health check failed - ROLLED BACK to $(basename "$PREV")"
        fi
        fail "HTTP health check failed - no previous release to roll back to"
    fi
else
    echo "WARNING: /etc/nginx/sites-enabled/$DOMAIN not found - HTTP health check skipped (first deploy before nginx setup)."
fi

log "Pruning old releases (keeping $KEEP, never current or previous)"
i=0
for r in $(ls -1d "$RELEASES"/*/ 2>/dev/null | sort -r); do
    r="${r%/}"
    i=$((i + 1))
    [ "$i" -le "$KEEP" ] && continue
    if [ "$r" = "$REL" ] || [ "$r" = "$PREV" ]; then
        continue
    fi
    rm -rf -- "$r"
    echo "   removed $(basename "$r")"
done
rm -f "$RELEASES"/*.tgz 2>/dev/null || true

log "Deployed $RELEASE  (current -> $(readlink -f "$CURRENT"))"
