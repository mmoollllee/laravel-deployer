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
    "require": {
        "mmoollllee/laravel-deployer": "^0.4.0"
    },
    "require-dev": {
        "deployer/deployer": "^8.0"
    }
}
```

```bash
composer update mmoollllee/laravel-deployer deployer/deployer --with-dependencies
```

`require`, not `require-dev`: the package ships artisan commands (below) and one
of them is a production diagnostic, which a dev-only package cannot be. It stays
cheap there because `deployer/deployer` is only a `suggest` — list it in your own
`require-dev` if you invoke `vendor/bin/dep` rather than a global Deployer 8.

## Artisan commands

Registered automatically by the package's service provider.

| Command | Purpose |
|---------|---------|
| `app:localize-tenant-domains` | Appends `.test` to every tenant domain (or maps it with `--map`), so a production dump is reachable under Herd. Idempotent, and it refuses to run outside `local`/`testing` — it rewrites every domain there is, which on a server is the whole site. Multi-tenant apps only; it fails cleanly without a `tenants` table or a domain column in it. |
| `app:send-test-mail [recipient]` | Sends one mail through the configured mailer and prints the transport's own error on failure (an SMTP 535 is the point of it). Defaults to `MAIL_FROM_ADDRESS`. Meant for the server: `dep shell`, then `art app:send-test-mail`. |
| `app:install-git-hooks [--force]` | Installs `.githooks/pre-commit` and points `core.hooksPath` at it. The hook refuses to commit database dumps — see below. Idempotent; a hand-edited hook is left alone unless `--force`. |

### Keeping production dumps out of git

`pull:db-full` and `pull:db-refresh` drop a **production** snapshot into the working
tree. History is forever and a pushed dump cannot be taken back, so run this once per
clone:

```bash
php artisan app:install-git-hooks
```

There are two ways to keep the dumps out, and the hook backs up **both**:

- **Gitignore the directory** (below) — the dump never appears in `git status` and cannot
  be added. Nothing to remember, but nothing reminds you to delete it either.
- **Leave it visible** — the dump shows up in `git status` until you delete it. That
  visibility is what makes `git add -A` dangerous, which is why the hook exists.

The hook refuses any staged file inside the snapshot disk's directory (resolved from
`db-snapshots.disk` at install time; the directory's own `.gitkeep`/`.gitignore` are
allowed through) as well as `*.sql`, `*.dump`, `*.sqlite` and their `.gz`/`.bz2`/`.xz`/
`.zst`/`.zip` forms anywhere in the tree, matched case-insensitively. Renames count too,
so moving a dump does not get it past.

It **fails closed**: if git errors, `awk` is missing, or a file name is one git has to
quote and the hook cannot compare reliably, the commit is refused rather than allowed
through unchecked.

`core.hooksPath` is per-clone git config and cannot be committed, which is why this is a
command rather than something the package can simply ship. `git commit --no-verify`
bypasses the hook: it is a guard rail, not a lock.

Hook the first one onto both DB pulls in the project's `deploy.php`:

```php
task('pull:localize-domains', fn () => runLocally('php artisan app:localize-tenant-domains'));
after('pull:db-refresh', 'pull:localize-domains');
after('pull:db-full', 'pull:localize-domains');
```

The domain column is found rather than assumed: `primary_domain` first, then
`domain` — the apps built on this package disagree on the name, and neither of
them should have to configure the common case. `--column=vanity_domain` picks a
third name, and an unknown one aborts before a row is touched instead of
surfacing as a query exception.

`--set=column=value` forces one more column for **every** tenant, repeatable.
The case it exists for is a per-tenant debug flag that a locally imported dump
wants switched on — looking inside is the point of pulling it:

```php
runLocally('php artisan app:localize-tenant-domains --set=app_debug=1');
```

`true`, `false` and `null` are read as those values rather than as strings.

`--map=domain=local-domain` replaces one domain instead of suffixing it,
repeatable. A staging subdomain has no `.test` twin of its own — the site is
developed under the app's Herd domain:

```php
runLocally('php artisan app:localize-tenant-domains --map=vorschau.example.de=example.de.test');
```

A domain an earlier run already suffixed (`vorschau.example.de.test`) is mapped
too, so adding a mapping heals an existing local copy. A mapping that would give
two tenants the same domain aborts before a row is touched.

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

### Running a deploy

```bash
php artisan deploy            # the deploy task from this project's deploy.php
php artisan deploy pull:db    # any other task, same as `dep pull:db`
vendor/bin/dep deploy         # Deployer directly — identical, minus the guards
```

`artisan deploy` is a thin wrapper around `vendor/bin/dep` and exists for one
reason: apps used to ship a **local** `deploy` command that held the SERVER-side
routine (npm build, migrate, optimize, cache warm). Run on a laptop it rebuilds
the developer's own caches, reports success, and never touches the server. The
package takes the name so that habit lands on the real deploy, and refuses to
run with `APP_ENV=production` — where it would point the deploy at the host it
is running on — unless you pass `--force`.

**Delete the app's own `app/Console/Commands/Deploy.php`** when adopting this:
whichever command registers last wins, and the stale one is the dangerous half.

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
| `shell_env`         | `[]`    | Extra environment exported by `dep shell`. |
| `shell_aliases`     | `['art' => '{{bin/php}} artisan']` | Shorthand commands available in `dep shell`. |

`bin/php` is derived from `remote_php`, so Deployer's own Laravel-recipe tasks
use the pinned binary too.

`current_path` and `release_or_current_path` are pinned to `deploy_path`. An
in-place deploy has no `releases/` directory and no `current` symlink, and
without the pin everything Deployer resolves through them — `{{bin/artisan}}`,
and with it every `artisan:*` task, plus `dep run` and `logs:app` — would look
for the app in `{{deploy_path}}/current` and fail.

Deployer's `push` task is removed for the same reason: it rsyncs the local
working tree's uncommitted changes into `current_path`, which is now the live
webroot. `push:files` and `push:auth` are unaffected.

`bin/composer` is resolved per host from `command -v composer`. Composer is
normally a PHAR, so it gets prefixed with `{{bin/php}}` to run on the same
interpreter as the rest of the deploy. Hosts that ship Composer as a *shell*
wrapper instead — phpenv/Plesk installs `~/.phpenv/shims/composer` with a
`#!/usr/bin/env bash` shebang — are detected and invoked directly, because
handing a shell script to `php` prints it and exits 0, silently skipping the
install. `deploy_vendors()` probes `composer --version` first and aborts the
deploy if no version banner comes back, so a misresolved binary can never leave
`vendor/` quietly stale.

## `dep shell`

Opens an interactive SSH session **in the webroot, with the deploy environment
already in place**:

```bash
dep shell
```

```
→ example.com ~/example.com
  git  ~/.ssh/example_ed25519 via $GIT_SSH_COMMAND
  php  /opt/plesk/php/8.3/bin/php first on $PATH (also $DEP_PHP)
  art  → /opt/plesk/php/8.3/bin/php artisan
```

So a manual `git pull` on the server uses the deploy key without touching
`~/.ssh/config`, and `php artisan …` runs on the version the site runs on
instead of whatever the host's default CLI happens to be. Add per-site variables
with `shell_env`:

```php
set('shell_env', ['COMPOSER_MEMORY_LIMIT' => '-1']);
```

Values are exported inside double quotes, so `$VAR` in a value is expanded on
the server (that is how the `PATH` entry works).

### Shorthands

`art` stands in for `php artisan`, so `art migrate --force` and `art tinker`
work straight from the prompt. Add your own — or drop the default with
`set('shell_aliases', [])`:

```php
set('shell_aliases', [
    'art' => '{{bin/php}} artisan',
    'pest' => '{{bin/php}} vendor/bin/pest',
]);
```

These are not shell aliases: an alias is a shell feature, not part of the
environment, so there is nothing to export it in, and the interactive shell
`dep shell` hands over reads only the host's own rc files. Each entry is instead
written as a small executable in `~/.cache/dep-shell/bin`, which is prepended to
`PATH` — same thing at the prompt, whatever login shell the host uses. The
directory is rewritten on every `dep shell`, so a renamed entry leaves nothing
behind. `{{placeholders}}` in a command are resolved before the file is written,
which is why `art` keeps using the site's PHP even when a login profile pushes
the `PATH` entry for `remote_php` back.

Deployer's built-in `dep ssh` also cd's into the webroot, but hands over a bare
shell — no deploy key, no pinned PHP. The name `ssh` cannot be taken over from a
recipe: Deployer registers its own commands in `Deployer::init()`, which runs
after `deploy.php` is imported, so anything we put there would be overwritten.
Hence `shell`.

## Tasks

Everyday tasks — all of them run in `deploy_path`:

| Task              | Description |
|-------------------|-------------|
| `deploy`          | In-place git deploy: pull, composer, optimize, assets, (clear + migrate), optimize. |
| `deploy:quick`    | Code-only deploy: `git pull` + `optimize:clear` + `optimize`. For blade/config changes. |
| `app:info`        | What is deployed: branch, commit, working-copy state, PHP, Laravel, `APP_ENV`/`APP_DEBUG`/`APP_URL`, pending migrations. |
| `git:pull`        | `git pull` with `git_ssh_key`, nothing else. |
| `git:fetch`       | `git fetch --prune`, then `git status`. |
| `git:status`      | `git status --short --branch`. |
| `git:log`         | Last commits (`--lines=N`, default 10). |
| `git:reset-hard`  | `git reset --hard` (asks first). `reset:hard` is kept as an alias. |
| `composer:install`| `composer install --no-dev --optimize-autoloader`. |
| `composer:dump`   | `composer dump-autoload --optimize --no-dev`. |
| `assets:build`    | `npm ci && npm run build`. |
| `logs`            | Tail of the newest file matching `log_files` (`--lines=N`, default 100). |
| `logs:tail`       | Follow it live (`--lines=N`, default 20; Ctrl-C to stop). |
| `logs:clear`      | Truncate the log files (asks first). |

Deploy and sync:

| Task              | Description |
|-------------------|-------------|
| `pull:db-refresh` | Snapshot only the `db_pull_include` tables → local (keeps other local tables). |
| `pull:db-full`    | Snapshot the whole DB → local. |
| `pull:files`      | Download `files` from the server (mirror). |
| `push:files`      | Upload `files` to the server (never deletes remotely). |
| `setup`           | Non-destructive first-time setup: uploads auth.json, `.env` (if missing), deps, key (if missing), storage-link, `migrate --force` (`--seed` opt-in), optimize. |
| `push:auth`       | Upload the local auth.json (Composer credentials for private repos like filament-media-library-pro). |

On top of these, Deployer's own Laravel recipe is loaded, so every `artisan:*`
task comes along and works against the webroot — `dep artisan:optimize`,
`artisan:migrate:status`, `artisan:route:list`, `artisan:down` / `artisan:up`,
`artisan:queue:restart`, `artisan:horizon:*`, … (`dep list` shows them all).
Anything else is a `dep run`:

```bash
dep run 'php artisan about'
```

`pull:db-*` load the dump locally and then run **`config:clear` only** — never
`optimize`. Caching config locally would bake the dev-DB credentials into
`bootstrap/cache/config.php`, after which a `RefreshDatabase` test run could hit
and wipe the real local database.

They also need the `snapshot:*` artisan commands (`spatie/laravel-db-snapshots`
or a compatible fork) installed in the app **both locally and on the remote** —
the recipe only shells out to them, so it is a Composer `suggest` rather than a
hard dependency, and asserts their presence before running.

### Where a pull lands locally

The pull tasks create their local destination before rsyncing into it, so a
clone that has never pulled works the same as one that has. Nothing to set up.

What the app still owns is keeping the pulled data **out of git**. Both targets
are content, not code — a production database dump and the uploaded media — and
neither belongs in the repository. One option is Laravel's own per-directory idiom
(`bootstrap/cache/.gitignore` is the same pattern) rather than a root entry — the other
is to leave the dumps visible and rely on the pre-commit hook above:

```
# database/dumps/.gitignore
*
!.gitignore
```

A root-level `/database/dumps` looks equivalent and is not: git does not descend
into a directory excluded at the parent level, so a `.gitignore` or `.gitkeep`
placed inside one can never be committed. If a project has that line, drop it
when adding the file above.

Sites with their own download task — an October CMS theme pull, say — should
call `ensure_local_dir()` the same way `pull:files` does.

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
    deploy_standard();               // update-code, vendors, optimize, assets, clear, migrate, optimize (+ toggles)
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
`deploy_migrate()`, `deploy_optimize()`, `deploy_post_optimize()`,
`deploy_standard()`, `long_running()`, `git_ssh_command()`, `git_env()`,
`git_pull_command()`, `local_auth_json()`, `log_files_glob()`,
`latest_log_file()`, `env_value()`, `option_lines()`, `write_raw()`,
`write_line()`.

`git_env()` returns `['GIT_SSH_COMMAND' => …]` for `run('git …', env: git_env())`.
It is passed per command rather than through a global `set('env', …)` on
purpose: a global `GIT_SSH_COMMAND` would also apply to `composer install`, and
its `IdentitiesOnly=yes` would then block every other key when Composer clones a
private dependency over SSH.

Print remote output with `write_raw()`, never `writeln()`. Remote data passes
two layers that read it as markup, and both bite:

- `writeln()` parses `{{placeholders}}`, so a commit message or log line
  containing `{{ … }}` — Blade source in a stack trace — aborts the task with
  "config option does not exist".
- Console style tags are rendered **twice**: the task runs in a worker
  subprocess and Deployer's master re-emits the worker's stdout through its own
  formatter. `<fg=…>` with an unknown colour in a log line kills the whole
  command. `write_raw()` escapes *and* writes raw, so the escape survives the
  worker and the master turns it back into a literal.

`write_line()` is the same channel for lines you style yourself — the tags stay
intact for the master, so any remote value in them must be escaped first.

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
lib/functions.php     step helpers + long_running() + git env helpers
lib/ShellCommand.php  the `dep shell` console command
recipe/base.php       config defaults, deploy, pull/push:files, push:auth, setup
recipe/tasks.php      git:*, composer:*, assets:build, logs*, app:info, deploy:quick
recipe/database.php   pull:db-refresh, pull:db-full
recipe/app.php        entry: Deployer laravel + rsync + base + database
recipe/signatur.php   entry: Deployer laravel + rsync + base (no DB, no migrate)
examples/             copy-paste deploy.php templates
```
