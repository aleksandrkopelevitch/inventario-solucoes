@servers(['web' => 'akop'])

{{--
    THE deploy path for this project (`envoy run deploy`). The live app is a
    DigitalOcean VM, reached through the `akop` alias in ~/.ssh/config.

    A parallel `deploy.sh` used to sit beside this file and was deleted on
    2026-08-28 as an older version of the same thing — it deployed to
    /var/www/isol rather than the real /var/www/isol/public_html, so the two
    disagreed about where the app even lives. `storage:link --force` came over
    from it (with its reasoning, below).

    That file also named the supervisor program `laravel-worker` while this one
    restarts the `isol:` group. `supervisorctl status` on the droplet settled it
    on 2026-08-28: NEITHER existed — the box ran akop-pro's two programs,
    laravel-horizon and laravel-newsletter, and nothing whatsoever for isol, so
    this app had no queue worker in production. deploy/supervisor/isol-queue.conf
    now exists and defines group `isol` + program `isol-queue`, which is what
    makes the 'supervisor' task below correct as written. Run it once
    (`envoy run supervisor`) to publish that program; it is not part of the
    deploy story, so a normal deploy leaves it alone.

    Note inherited from akop.pro's version of this file: no dedicated migration
    DB connection exists in config/database.php here (unlike akop.pro's
    'pgsql_migrations'), so `migrate` runs against the default connection.
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

    {{--
        Passport's signing keys are NOT in the repository (storage/*.key is
        gitignored) and cannot be, so a deploy can only check that the one-off
        `envoy run passport` has been run. A missing key is not a broken deploy —
        the whole app works without it except the MCP OAuth handshake — so this
        warns rather than aborts. Nothing regenerates them here: a new key pair
        invalidates every access token already issued, which would sign every
        connected person out on a deploy that only meant to ship a view.
    --}}
    if [ ! -f storage/oauth-private.key ]; then
        echo "==> WARNING: Passport keys missing — run 'envoy run passport'. MCP OAuth is down until then."
    fi

    {{--
        ONLY the flowSpec example corpus, and deliberately not `db:seed`.

        That corpus is derived data: database/data/digibee_flowspec_examples/
        plus its manifest are the truth, FlowspecExampleSeeder upserts them by
        slug, and no in-app editor touches a manifest-seeded example — so the
        git tree and the table can only agree if this runs every deploy. It is
        also where drift bites hardest: a connector in the catalog whose
        example is missing tells the model the name is legal while leaving it
        free to invent params, which then pass validation.

        `--force` is required, not stylistic — SeedCommand calls
        confirmToProceed(), which aborts in production, and there is no TTY
        here to answer it.

        Do NOT widen this to `php artisan db:seed --force`, and do NOT add the
        other three seeders:
          - DatabaseSeeder creates admin@leomadeiras.com.br with the literal
            password `password` (the model's `hashed` cast makes it a working
            login).
          - SolutionSeeder/DiagramSeeder/AttributeOptionSeeder were the INITIAL
            import from inventory_seed.json. The app owns those records now —
            upsertSolution() overwrites 11 columns by slug, including every
            field behind the solution header's inline-edit badges — so
            re-running them reverts real edits on every deploy.
    --}}
    echo "==> Re-seeding the flowSpec example corpus"
    php artisan db:seed --force --class=FlowspecExampleSeeder

    {{--
        --force recreates the symlink instead of printing a misleading
        "ERROR ... already exists" on every deploy. It only removes the path
        when it is already a symlink, so a real public/storage directory is
        never touched. Solution and company logos are plain files on the public
        disk (logo_path, not MediaLibrary), so without this link they 404.
    --}}
    echo "==> Linking public storage"
    php artisan storage:link --force

    echo "==> Clearing and rebuilding caches"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan icons:cache

    {{--
        Last, because everything above WRITES: the deploy connects as root
        (the `akop` alias is User root), so every compiled view, cache file and
        built asset it just produced is owned by root, while the app runs as
        www-data under php-fpm. This makes the end state the same whoever ran
        the deploy.

        Most of that is survivable on its own — the parent directories are
        www-data's and it is a directory's write bit that governs creating and
        replacing files — but the failure mode when it is NOT survivable is
        expensive and silent, so it is not worth leaving to chance. The real
        incident (2026-09-25) was `gitbook:import` run as root: media on the
        private disk (`MEDIA_DISK=local`) gets `0700` directories from
        Flysystem, those came out `root:root`, and www-data could not traverse
        into them — 300 assets downloaded, on disk, in the database, and every
        `/files/{id}` answering permission denied. See README § Comandos.

        chown, NEVER chmod -R. The `0700` on a private media directory is
        correct and deliberate; a recursive chmod would make every protected
        document on the box world-readable, which is the opposite of the fix.

        `|| echo` rather than letting it abort: this sits before `artisan up`,
        and a deploy run by a user who cannot chown must not leave the app in
        maintenance mode over a step that is preventative.
    --}}
    echo "==> Normalising ownership to www-data"
    chown -R www-data:www-data storage bootstrap/cache public/build || echo "==> WARNING: chown failed — see README § Comandos."

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

    {{-- Same reason as in the deploy task above, and needed for the same
         reason: a rollback rebuilds all of it as root too. --}}
    echo "==> Normalising ownership to www-data"
    chown -R www-data:www-data storage bootstrap/cache public/build || echo "==> WARNING: chown failed — see README § Comandos."

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
    Outside the 'deploy' story: run manually (envoy run supervisor) when the
    .conf changes — and ONCE up front, since this app had no worker on the
    droplet at all until deploy/supervisor/isol-queue.conf was added. That file
    defines [group:isol] + [program:isol-queue], matching the names below.

    Read its header before the first run: two fields (the php binary path and
    `user`) are written from the standard Laravel pattern rather than from this
    host, and want checking against akop-pro.conf, which is known to work here.
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

{{--
    Outside the 'deploy' story, like 'supervisor': run ONCE, by hand, when this
    app first gains its OAuth server or if the keys are ever lost.

    `passport:keys` writes storage/oauth-private.key (0600) and its public half,
    and WHO writes them is the whole difficulty: Envoy connects as root here,
    php-fpm serves as www-data, and a 0600 key owned by root is a key the app
    cannot read. Hence `sudo -u www-data` below rather than a plain artisan call
    — generating as root and chowning afterwards works too, and is one more step
    to forget.

    This was learned the slow way on 2026-09-16: the keys were never generated
    after the first deploy, league/oauth2-server threw `Invalid key supplied`
    while merely looking at a request, and every anonymous probe answered 500.
    Claude Desktop reads a 500 as "not an MCP server" and stops at "não foi
    possível verificar o servidor". The middleware degrades to a 401 now, so the
    same mistake is legible rather than fatal — but the connector still cannot
    work until this task has run.

    Regenerating invalidates every access token already issued: everybody who
    connected a chat client has to authorize again. That is the intended way to
    cut all connections at once, and a bad accident otherwise.
--}}
@task('passport', ['on' => 'web'])
    cd {{ $appDir }}
    sudo -u www-data php artisan passport:keys
    ls -l storage/oauth-private.key storage/oauth-public.key
@endtask
