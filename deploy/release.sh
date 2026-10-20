#!/bin/bash
# Makes one release live on the API host (ADR 0015). Run as root by .github/workflows/deploy.yml
# through Systems Manager, after it has unpacked the release into releases/<sha>:
#
#   release.sh <sha>
#
# Order matters. Everything that can fail runs first, while the previous release is still serving:
# the .env, the shared storage, migrations (additive only, so the old code keeps working against
# the new schema), and the caches. Only then does `current` switch to the new release, in one rename,
# so a request sees either the old release or the new one, never a half-made one. A failure before
# the switch leaves the previous release live; a failure after it (a service that will not reload,
# or no answer from /up) switches back to it.
set -eEuo pipefail

SHA="$1"
BASE=/var/www/connected
RELEASE="$BASE/releases/$SHA"
SHARED="$BASE/shared"
PREFIX=/connected/production/
AWS=/snap/bin/aws

test -d "$RELEASE/api" || { echo "no release at $RELEASE" >&2; exit 1; }

# 1. Settings: every parameter under /connected/production/ becomes a line of .env, apart from the
#    two only this script needs; then the database password from Secrets Manager.
params=$($AWS ssm get-parameters-by-path --path "$PREFIX" --with-decryption --recursive --output json)
setting() { echo "$params" | jq -r --arg n "$PREFIX$1" '.Parameters[] | select(.Name == $n) | .Value'; }
db_secret=$($AWS secretsmanager get-secret-value --secret-id "$(setting DB_SECRET_ARN)" --query SecretString --output text)
origin_secret=$(setting ORIGIN_SECRET)
app_host=$(setting APP_URL | sed -E 's#^https?://##; s#/.*$##')

# Every value is double-quoted with \, ", and $ escaped, so phpdotenv reads it back exactly: an
# RDS-generated password may contain any of them.
ESC='def esc: gsub("\\\\"; "\\\\") | gsub("\""; "\\\"") | gsub("\\$"; "\\$");'

umask 027
{
    echo "APP_NAME=ConnectED"
    echo "APP_ENV=production"
    echo "APP_DEBUG=false"
    echo "LOG_CHANNEL=daily"
    echo "DB_CONNECTION=pgsql"
    echo "DB_SSLMODE=require"
    echo "SESSION_DRIVER=database"
    echo "SESSION_SECURE_COOKIE=true"
    echo "CACHE_STORE=database"
    echo "QUEUE_CONNECTION=database"
    echo "$params" | jq -r "$ESC"'.Parameters[]
        | select(.Name | endswith("/DB_SECRET_ARN") or endswith("/ORIGIN_SECRET") | not)
        | "\(.Name | split("/") | last)=\"\(.Value | esc)\""'
    echo "$db_secret" | jq -r "$ESC"'"DB_PASSWORD=\"\(.password | esc)\""'
} > "$SHARED/.env.new"
mv "$SHARED/.env.new" "$SHARED/.env"
chown root:www-data "$SHARED/.env"
chmod 640 "$SHARED/.env"
umask 022

# 2. Shared storage, kept across releases: logs, sessions' files, and anything the app writes.
install -d -o www-data -g www-data \
    "$SHARED/storage/app/private" "$SHARED/storage/app/public" "$SHARED/storage/logs" \
    "$SHARED/storage/framework/cache/data" "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views"
rm -rf "$RELEASE/api/storage"
ln -sfn "$SHARED/storage" "$RELEASE/api/storage"
ln -sfn "$SHARED/.env" "$RELEASE/api/.env"
install -d -o www-data -g www-data "$RELEASE/api/bootstrap/cache"
chown -R www-data:www-data "$RELEASE"

# 3. The database, then the caches, as the web server's user, against the new code.
artisan() { sudo -u www-data php "$RELEASE/api/artisan" "$@"; }
artisan migrate --force --no-interaction
artisan config:cache
artisan route:cache
artisan view:cache
artisan filament:optimize

# 4. nginx and the queue worker, from this release's own copies.
sed -e "s#{ORIGIN_SECRET}#$origin_secret#" -e "s#{APP_HOST}#$app_host#" \
    "$RELEASE/deploy/nginx.conf" > /etc/nginx/sites-available/connected
ln -sfn /etc/nginx/sites-available/connected /etc/nginx/sites-enabled/connected
rm -f /etc/nginx/sites-enabled/default
nginx -t
cp "$RELEASE/deploy/connected-queue.service" /etc/systemd/system/connected-queue.service
systemctl daemon-reload
systemctl enable --quiet connected-queue

# 5. The switch: one atomic rename. If anything after it fails, `current` goes back to the release
#    that was serving before (none on the very first deploy), which still works against the
#    migrated schema because migrations are additive.
previous=$(readlink "$BASE/current" || true)
activate() {
    ln -sfn "$1" "$BASE/current.next"
    mv -Tf "$BASE/current.next" "$BASE/current"
    systemctl reload php8.4-fpm
    systemctl reload nginx
    systemctl restart connected-queue
}
restore() {
    if [ -n "$previous" ]; then
        echo "release $SHA failed after the switch; restoring $previous" >&2
        activate "$previous" || true
    fi
}
trap restore ERR
activate "$RELEASE"

# 6. Is it answering? Through nginx, with the header CloudFront sends; a few tries, since the
#    PHP workers have only just reloaded.
curl -fsS -o /dev/null --retry 5 --retry-delay 2 --retry-all-errors \
    -H "X-Origin-Verify: $origin_secret" http://127.0.0.1/up
trap - ERR
echo "release $SHA is live"

# 7. Keep the five newest releases, for a quick rollback by pointing current at an older one.
ls -1dt "$BASE"/releases/*/ | tail -n +6 | xargs -r rm -rf
