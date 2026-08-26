@servers(['web' => 'akop'])

{{--
    Adapted from akop.pro's Envoy.blade.php for this repo (leomadeiras/inventario-solucoes):
    - No dedicated migration DB connection exists in config/database.php here
      (unlike akop.pro's 'pgsql_migrations'), so `migrate` runs against the
      default connection.
    - The 'supervisor' task assumes deploy/supervisor/isol-queue.conf, which
      does not exist in this repo yet — create it before running that task.
--}}

@setup
    $branch = $branch ?? 'main';
    $appDir = '/var/www/isol/public_html';
@endsetup

@story('deploy')
    pull
    composer
    npm
    artisan
@endstory

@task('pull', ['on' => 'web'])
    echo "==> Accessing {{ $appDir }}"
    cd {{ $appDir }}

    echo "==> Putting application into maintenance mode"
    php artisan down --refresh=15 --retry=10

    echo "==> Pulling {{ $branch }}"
    git fetch origin {{ $branch }}
    git reset --hard origin/{{ $branch }}
@endtask

@task('composer', ['on' => 'web'])
    cd {{ $appDir }}

    echo "==> Installing PHP dependencies"
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
@endtask

@task('npm', ['on' => 'web'])
    cd {{ $appDir }}

    echo "==> Installing Node dependencies"
    npm ci --prefer-offline

    echo "==> Compiling assets for production"
    npm run build
@endtask

@task('artisan', ['on' => 'web'])
    cd {{ $appDir }}

    echo "==> Running migrations"
    php artisan migrate --force

    echo "==> Clearing and rebuilding caches"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan icons:cache

    echo "==> Restarting queue workers"
    php artisan queue:restart

    echo "==> Bringing application back online"
    php artisan up

    echo "==> Deployment completed!"
@endtask

{{--
    Rolls back both code and schema.

    Unlike akop.pro (whose migrations mostly have no down()), this project's
    migrations are almost entirely reversible: 74 of 75 files under
    database/migrations/ implement down(). The one exception is
    2026_03_25_022643_create_media_table.php (Spatie's stock media-table
    migration ships with no down() method at all) — if that migration is in
    the batch being rolled back, its table is silently left in place while
    its row is removed from the migrations table, same failure mode akop.pro
    hits on every migration. Everything else in a rolled-back batch reverts
    cleanly.
--}}
@task('rollback', ['on' => 'web'])
    cd {{ $appDir }}

    echo "==> Putting application into maintenance mode"
    php artisan down --refresh=15 --retry=10

    echo "==> Rolling back the last migration batch"
    php artisan migrate:rollback --force --step=1

    echo "==> Rolling back to previous commit"
    git reset --hard HEAD~1

    echo "==> Recompiling assets"
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
    npm ci --prefer-offline && npm run build

    echo "==> Rebuilding caches"
    php artisan optimize
    php artisan icons:cache

    echo "==> Restarting queue workers"
    php artisan queue:restart

    echo "==> Bringing application back online"
    php artisan up
@endtask

@task('cache:clear', ['on' => 'web'])
    cd {{ $appDir }}

    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan event:clear
    php artisan cache:clear
    php artisan icons:cache

    echo "==> Caches cleared"
@endtask

@task('down', ['on' => 'web'])
    cd {{ $appDir }}
    php artisan down --refresh=15 --retry=10
@endtask

@task('up', ['on' => 'web'])
    cd {{ $appDir }}
    php artisan up
@endtask

{{--
    Outside the 'deploy' story: run manually (envoy run supervisor) only when
    the .conf changes. Requires deploy/supervisor/isol-queue.conf to exist —
    it doesn't yet in this repo, so this task will fail until that file (and
    a matching [group:isol] section) is created.
--}}
@task('supervisor', ['on' => 'web'])
    echo "==> Publishing supervisor configuration"
    sudo cp {{ $appDir }}/deploy/supervisor/isol-queue.conf /etc/supervisor/conf.d/isol-queue.conf

    echo "==> Reloading supervisor"
    sudo supervisorctl reread
    sudo supervisorctl update

    sudo supervisorctl restart isol:

    echo "==> Supervisor updated"
    sudo supervisorctl status isol:*
@endtask
