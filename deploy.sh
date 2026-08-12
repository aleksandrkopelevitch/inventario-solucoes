#!/usr/bin/env bash
#
# Production deploy script for isol.leomadeiras.com.br
#
# Usage:
#   ./deploy.sh [command] [branch]
#
# Commands:
#   deploy        (default) pull + composer + npm + migrate + cache + queue restart
#   rollback      reset one commit back, rebuild, roll back the last migration
#   cache:clear   clear all caches (config/route/view/event/app) and rebuild icons
#   down          put the application into maintenance mode
#   up            bring the application back online
#   supervisor    reread/update/restart the queue worker program (only needed
#                 after the .conf file itself changes, not on every deploy)
#
# Examples:
#   ./deploy.sh                  # deploy main
#   ./deploy.sh deploy feature/x # deploy a specific branch
#   ./deploy.sh rollback
#   ./deploy.sh cache:clear

set -euo pipefail

APP_DIR="/var/www/isol"
QUEUE_PROGRAM="laravel-worker"
LOCK_FILE="/tmp/isol-deploy.lock"

COMMAND="${1:-deploy}"
BRANCH="${2:-main}"

exec 200>"$LOCK_FILE"
flock -n 200 || { echo "Another deploy is already running." >&2; exit 1; }

trap 'echo -e "\n!! Command failed. If the app was put into maintenance mode, run ./deploy.sh up once the issue is fixed." >&2' ERR

cd "$APP_DIR"

step() {
    printf '\n==> %s\n' "$1"
}

pull() {
    step "Fetching ${BRANCH}"
    git fetch origin "$BRANCH"

    step "Resetting to origin/${BRANCH}"
    git reset --hard "origin/${BRANCH}"
}

composer_install() {
    step "Installing PHP dependencies"
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
}

npm_build() {
    step "Installing Node dependencies"
    npm ci --prefer-offline

    step "Compiling assets for production"
    npm run build
}

rebuild_caches() {
    step "Clearing and rebuilding caches"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan icons:cache
}

restart_queue() {
    step "Restarting queue workers"
    php artisan queue:restart
}

cmd_deploy() {
    step "Putting application into maintenance mode"
    php artisan down --refresh=15 --retry=10

    pull
    composer_install
    npm_build

    step "Running migrations"
    php artisan migrate --force

    step "Linking public storage"
    # --force recreates the symlink instead of printing a misleading "ERROR ...
    # already exists" on every deploy. It only removes the path when it is
    # already a symlink, so a real public/storage directory is never touched.
    php artisan storage:link --force

    rebuild_caches
    restart_queue

    step "Bringing application back online"
    php artisan up

    step "Deployment completed!"
}

cmd_rollback() {
    step "Putting application into maintenance mode"
    php artisan down --refresh=15 --retry=10

    step "Rolling back to previous commit"
    git reset --hard HEAD~1

    composer_install
    npm_build

    step "Rolling back the last migration batch"
    php artisan migrate:rollback --force

    step "Rebuilding caches"
    php artisan optimize
    php artisan icons:cache

    restart_queue

    step "Bringing application back online"
    php artisan up
}

cmd_cache_clear() {
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan event:clear
    php artisan cache:clear
    php artisan icons:cache

    step "Caches cleared"
}

cmd_down() {
    php artisan down --refresh=15 --retry=10
}

cmd_up() {
    php artisan up
}

# Only run manually (./deploy.sh supervisor) when laravel-worker.conf changes.
cmd_supervisor() {
    step "Reloading supervisor configuration"
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart "${QUEUE_PROGRAM}:*"

    step "Supervisor status"
    sudo supervisorctl status "${QUEUE_PROGRAM}:*"
}

case "$COMMAND" in
    deploy) cmd_deploy ;;
    rollback) cmd_rollback ;;
    cache:clear) cmd_cache_clear ;;
    down) cmd_down ;;
    up) cmd_up ;;
    supervisor) cmd_supervisor ;;
    *)
        echo "Unknown command: ${COMMAND}" >&2
        echo "Usage: ./deploy.sh [deploy|rollback|cache:clear|down|up|supervisor] [branch]" >&2
        exit 1
        ;;
esac
