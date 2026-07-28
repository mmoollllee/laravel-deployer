# mmoollllee/laravel-deployer

Shared [Deployer](https://deployer.org) recipes for the way these Laravel sites
are hosted: a plain **in-place `git pull` deploy** on Plesk / managed hosting
(no atomic releases), a **snapshot-based database pull** for local development,
and **storage sync**.

Requires **Deployer 8** (see [Deployer 8](#deployer-8)).

## Install

Add the VCS repository and require the package. In each project's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mmoollllee/laravel-deployer" }
    ],
    "require-dev": {
        "mmoollllee/laravel-deployer": "^0.1.0",
        "deployer/deployer": "^8.0"
    }
}
```

```bash
composer update mmoollllee/laravel-deployer deployer/deployer --with-dependencies
```

## Usage

Two entry recipes. Pick one in the project's `deploy.php`:

```php
<?php
namespace Deployer;

require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';       // full app: DB + assets + migrations
// require 'vendor/mmoollllee/laravel-deployer/recipe/signatur.php'; // no DB, no migrations
```

A minimal full-app `deploy.php` is then just host + config:

```php
<?php
namespace Deployer;

require 'vendor/mmoollllee/laravel-deployer/recipe/app.php';

host('example.com')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '~/example.com')
    ->set('remote_php', '/opt/plesk/php/8.3/bin/php')
    ->set('git_ssh_key', '~/.ssh/example_ed25519');

set('db_pull_include', ['migrations', 'users']);
set('files', ['storage/app/public/']);
```

See [`examples/`](examples) for full templates.

## Configuration

| Key                 | Default | Purpose |
|---------------------|---------|---------|
| `remote_php`        | `php`   | PHP binary for remote artisan/composer. Pin on Plesk, e.g. `/opt/plesk/php/8.3/bin/php`. |
| `bin/composer`      | auto    | How to invoke Composer remotely. Auto-detected; override with a full path when detection fails. |
| `git_ssh_key`       | `null`  | Deploy key for `git pull` (IdentitiesOnly=yes). `null` → bare `git pull`. |
| `deploy_assets`     | `true`  | Run `npm ci && npm run build`. |
| `deploy_migrate`    | `true`  | Run `artisan migrate --force`. |
| `deploy_cache_clear` | `false` | Run `artisan cache:clear` after optimize. |
| `deploy_queue_restart` | `false` | Run `artisan queue:restart` after optimize. |
| `files`             | `[]`    | Storage folders for `pull:files` / `push:files`. |
| `files_pull_delete` | `true`  | Mirror on pull (`rsync --delete`). |
| `db_pull_include`   | `[]`    | Tables for `pull:db-refresh`. |
| `auth_json`         | `null`  | Local auth.json uploaded by `setup`/`push:auth`. `null` → auto-detect (`./auth.json`, then `~/.composer/auth.json`). |

`bin/php` is derived from `remote_php`, so Deployer's own Laravel-recipe tasks
use the pinned binary too.

`bin/composer` is resolved per host from `command -v composer`. Composer is
normally a PHAR, so it gets prefixed with `{{bin/php}}` to run on the same
interpreter as the rest of the deploy. Hosts that ship Composer as a *shell*
wrapper instead — phpenv/Plesk installs `~/.phpenv/shims/composer` with a
`#!/usr/bin/env bash` shebang — are detected and invoked directly, because
handing a shell script to `php` prints it and exits 0, silently skipping the
install. `deploy_vendors()` probes `composer --version` first and aborts the
deploy if no version banner comes back, so a misresolved binary can never leave
`vendor/` quietly stale.

## Tasks

| Task              | Description |
|-------------------|-------------|
| `deploy`          | In-place git deploy: pull, composer, assets, (clear + migrate), optimize. |
| `pull:db-refresh` | Snapshot only the `db_pull_include` tables → local (keeps other local tables). |
| `pull:db-full`    | Snapshot the whole DB → local. |
| `pull:files`      | Download `files` from the server (mirror). |
| `push:files`      | Upload `files` to the server (never deletes remotely). |
| `reset:hard`      | `git reset --hard` on the server (asks first). |
| `setup`           | Non-destructive first-time setup: uploads auth.json, `.env` (if missing), deps, key (if missing), storage-link, `migrate --force` (`--seed` opt-in), optimize. |
| `push:auth`       | Upload the local auth.json (Composer credentials for private repos like filament-media-library-pro). |

`pull:db-*` load the dump locally and then run **`config:clear` only** — never
`optimize`. Caching config locally would bake the dev-DB credentials into
`bootstrap/cache/config.php`, after which a `RefreshDatabase` test run could hit
and wipe the real local database.

They also need the `snapshot:*` artisan commands (`spatie/laravel-db-snapshots`
or a compatible fork) installed in the app **both locally and on the remote** —
the recipe only shells out to them, so it is a Composer `suggest` rather than a
hard dependency, and asserts their presence before running.

## First-time setup

On a fresh remote (repo already cloned — e.g. by Plesk git or by hand), bootstrap
it once, non-destructively:

```bash
dep setup           # existing DB: migrate only
dep setup --seed    # brand-new DB: also run seeders
```

`setup` uploads your local `auth.json` first (so `composer install` can pull
private packages like **filament-media-library-pro** from satis.ralphjsmit.com),
creates `.env` from `.env.prod` only if absent, generates an app key only if none
exists, links storage, runs `migrate --force` (additive) and optimizes. It never
overwrites an existing `.env`, key or database, so re-running it is safe. Refresh
just the credentials later with `dep push:auth`.

## Customising a site

Toggle common post-deploy steps, override `deploy` for bespoke logic, and add
local fixups via hooks:

```php
// Common post-deploy steps are opt-in toggles — no override needed:
set('deploy_cache_clear', true);   // artisan cache:clear after optimize
set('deploy_queue_restart', true); // artisan queue:restart after optimize

// For bespoke steps, override deploy and compose the helpers:
desc('Publish code on the remote');
task('deploy', function () {
    deploy_standard();               // update-code, vendors, assets, clear, migrate, optimize (+ toggles)
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan storage:link');
});

// rewrite tenant domains to *.test after every DB pull
task('db:localize', fn () => runLocally('php artisan app:localize-tenant-domains'));
after('pull:db-refresh', 'db:localize');
after('pull:db-full', 'db:localize');
```

Step helpers (all in the `Deployer` namespace):
`deploy_update_code()`, `deploy_vendors()`, `deploy_assets()`, `deploy_clear()`,
`deploy_migrate()`, `deploy_optimize()`, `deploy_standard()`,
`long_running()`, `git_pull_command()`, `local_auth_json()`.

## Deployer 8

Deployer 8 replaced v7's options-array API for `run()`/`runLocally()` with named
scalar parameters, so `long_running()` returns a named-argument array that is
**spread** into the call — `run($cmd, ...long_running())` — setting `timeout: 0`
(no timeout) and `forceOutput: true`. The package `require`s `^8.0`. Make sure the
globally invoked `dep` is v8 too:

```bash
composer global require deployer/deployer:^8.0
```

## Layout

```
lib/functions.php     step helpers + long_running() + git_pull_command()
recipe/base.php       config defaults, deploy, pull/push:files, push:auth, reset:hard, setup
recipe/database.php   pull:db-refresh, pull:db-full
recipe/app.php        entry: Deployer laravel + rsync + base + database
recipe/signatur.php   entry: Deployer laravel + rsync + base (no DB, no migrate)
examples/             copy-paste deploy.php templates
```
