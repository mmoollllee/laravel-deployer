# Changelog

## 0.5.8 — 2026-10-28

- **Docs: the Plesk examples pin PHP 8.4.** The README, `examples/deploy.app.php`
  and the `remote_php` comment in `recipe/base.php` name `/opt/plesk/php/8.4/bin/php`
  instead of 8.3. Nothing changes for a site: `remote_php` still defaults to `php`,
  and a site that pins it keeps its own path.

## 0.5.7 — 2026-10-13

- **`dep pull:db <tables>`** pulls only the tables named on the command line —
  `dep pull:db users`, `dep pull:db "users posts"` — and leaves every other local
  table as it is, without editing `db_pull_include` first. Several tables go into
  one argument, because Deployer reads every further positional argument as a host
  selector (`dep pull:db users,posts production`); `dep pull:db users posts` stops
  with that hint instead of looking for a host called `posts`. A task cannot take
  an argument of its own, so this is a console command in front of
  `pull:db-refresh`, which it runs with the new `--tables` option: the hooks a site
  puts on `pull:db-refresh` run after an ad-hoc pull too. Table names are limited
  to letters, digits, `_`, `$`, `.` and `-`, the ones from `db_pull_include`
  included — they reach the remote shell unquoted. A site with a `pull:db` task of
  its own keeps it.
- **`php artisan deploy` hands further arguments to dep** —
  `php artisan deploy pull:db "users posts"`. dep's own options go behind `--`
  (`php artisan deploy -- pull:db users --no-hooks`); in front of it they are
  artisan's, which refuses the ones it does not know.
- **Fix: local paths count from the project, wherever `dep` is started.** Deployer 8
  runs `runLocally()` in the directory of `deploy.php`, but hands the local side of
  `download()`/`upload()` to rsync relative to the working directory. Started
  anywhere else (`dep --file=…`), `pull:db*` created the dump directory in the
  project and then rsynced into the working directory, which failed; `pull:files`
  and `push:files` synced the wrong directory, `push:auth` missed the project's
  auth.json and could fall back on the global one, and `--retry-load` looked for
  the dump in the working directory. The new `local_path()` anchors all of them
  where `runLocally()` works. Sites with a download task of their own should use
  it for the local side as well.

## 0.5.6 — 2026-10-07

- **`deploy_clear_app_cache`** (default `true`, unchanged behaviour): set it to
  `false` and `optimize:clear` — before migrating and in `deploy:quick` — keeps
  the application cache (`--except=cache`) and clears only the framework caches.
  A full flush took scheduler mutexes, locks and whatever a site records in its
  cache with every deploy; on nest.kuckuck.cam that sent every public scene's
  images through PHP until a five-minute sync had recorded its bucket grants
  again. A release that changes what a cache holds then deploys once with it on,
  e.g. `dep deploy -o deploy_clear_app_cache=true`, or from a task that sets it
  before the deploy runs — a setting a task writes reaches the tasks after it.

## 0.5.5 — 2026-10-06

- **Fix: `--retry-load` says how old the dump is** instead of when it was pulled.
  Deployer's PHP runs in UTC, so the time it printed was hours off the local
  clock.

## 0.5.4 — 2026-10-06

- **`pull:db-full --retry-load`** (and `pull:db-refresh --retry-load`) loads the
  newest dump in the local `database/dumps` again, without a remote snapshot or a
  download. An import that broke off after a pull of several hundred megabytes
  used to mean pulling the whole snapshot again. It names the file, its size and
  when it was pulled before it loads, refuses when there is no dump, and tasks
  hooked onto the pull (`after('pull:db-full', …)`) run as usual.

## 0.5.3 — 2026-09-29

- **Fix: `deploy_standard()` rebuilds the framework caches right after
  `composer install`,** before the asset build, and again at the end as before.
  `git pull` puts the new code live in place, and it used to run against the
  previous release's config and route cache until the final `optimize` — through
  `npm ci` and the whole build. A config file the release added read as null in
  that window, a new route name threw. On nest.kuckuck.cam a panel provider that
  requires a new config key made every artisan boot throw for a minute, so the
  scheduler lost that minute's jobs and web requests failed with it. What is left
  is `composer install` itself (new code, old classmap), which only a release
  directory could close. A site with its own `deploy` task that composes the
  helpers should call `deploy_optimize()` after `deploy_vendors()` as well.

## 0.5.2 — 2026-09-25

- **Fix: `app:localize-tenant-domains` stays idempotent for any `--map` target.**
  A target without a `.test` suffix (`example.localhost`) got one appended on the
  next run, and a chained mapping re-mapped the previous run's result; map targets
  now stay as they are. Domains are compared in lower case, as DNS and the unique
  index compare them, so two tenants that differ in case only are refused before
  the first write, and the rewrite runs in one transaction — it changes every
  domain or none.

## 0.5.1 — 2026-09-25

- **`app:localize-tenant-domains --map=domain=local-domain`** replaces a domain
  instead of appending `.test`, repeatable. A staging subdomain
  (`vorschau.example.de`) has no `.test` twin: the site is developed under the
  app's Herd domain, and `vorschau.example.de.test` resolved to nothing. A domain
  an earlier run already suffixed is mapped as well, so adding the option heals a
  database pulled before; a mapping that would give two tenants the same domain
  aborts before the first write. The command now prints each rewrite.

## 0.4.3 — 2026-08-25

- **Fix: `pull:db-*` and `pull:files` create their local destination.** Deployer's
  `download()` is a bare `rsync -azP` with no `--mkpath`, and rsync creates at
  most the *last* segment of a destination path — a file destination whose parent
  is missing fails outright. Every path these tasks write to is git-ignored by
  design, so a fresh clone has no reason to contain it, and the first pull on a
  new machine died on `change_dir "…/database/dumps" failed: No such file or
  directory (2)`. `pull:files` failed the same way one level deeper, on nested
  targets such as `storage/app/captures/<site>/`.

  The seven apps pulling a database had papered over this three different ways —
  a committed `.gitkeep` here, a root `.gitignore` entry there, nothing at all in
  two of them — and only where someone had already hit it. The new
  `ensure_local_dir()` helper runs before each download, so none of that is load-
  bearing any more; sites with their own download task should call it too.
- README: the per-directory `.gitignore` convention for `database/dumps`,
  including why a root-level `/database/dumps` entry defeats it.

## 0.4.2 — 2026-08-23

- **`php artisan deploy`** — runs this project's Deployer task (`vendor/bin/dep`),
  passing its output and exit code straight through, with no timeout of its own.
  It exists to take the name: the consuming apps' own `deploy` command held the
  SERVER-side routine (npm build, migrate, optimize, cache warm), and running
  that from a developer machine is a silent misfire — it rebuilds the local
  caches, prints success, and never reaches the server. That happened during a
  nest.kuckuck.cam deploy; the command now answers instead, and refuses under
  `APP_ENV=production` (where it would deploy the host onto itself) unless
  `--force` is given. Missing `vendor/bin/dep` or `deploy.php` fail with a
  sentence naming the fix.

  **Consumers should delete their own `app/Console/Commands/Deploy.php`** — the
  last registration wins, and the stale copy is the dangerous one.
- `symfony/process` is now a declared dependency; the command uses it directly
  instead of relying on it arriving through the framework.

## 0.4.1 — 2026-08-21

- **Fix: `app:localize-tenant-domains` no longer assumes `primary_domain`.** It
  probes `primary_domain`, then `domain`, and `--column=` names anything else.
  0.4.0 hard-coded the column the CMS apps use, which turned the command into a
  `Column not found: 1054` everywhere it is called `domain` — nest.kuckuck.cam
  hooks it onto both DB pulls, so a freshly pulled production dump ended in a
  query exception instead of reachable `*.test` domains. A tenants table with no
  domain column at all (Filament workspaces, which have no domains) now fails
  with a sentence rather than a stack trace.
- **`--set=column=value`** sets one further column for every tenant, repeatable.
  It exists for the per-tenant debug flag nest's own copy of the command used to
  flip before it moved into the package — a locally imported dump wants it on,
  since looking inside is the point of pulling it. `true`, `false` and `null` are
  read as those values; an unknown column or a malformed pair aborts before the
  first row is written.

## 0.4.0 — 2026-08-18

- **Artisan commands.** `app:localize-tenant-domains` (rewrites tenant domains to
  `*.test` after a DB pull) and `app:send-test-mail` (SMTP diagnosis) move here
  from the consuming apps, which had grown four near-identical copies between
  them. Both keep their `app:` signature on purpose — they are named in every
  project's `deploy.php` hooks, `.env.prod` comments and server runbooks, and a
  cleaner `deploy:` prefix is not worth breaking those silently.

  **Consumers must move the package from `require-dev` to `require`**:
  `app:send-test-mail` is a production diagnostic, and a dev-only package does
  not exist after `composer install --no-dev`. To keep that cheap,
  `deployer/deployer` is no longer a hard dependency — it is a `suggest`, needed
  only where `dep` actually runs, which is often a global install. Projects that
  invoke `vendor/bin/dep` should list it in their own `require-dev`.

  Needs a current Laravel 12: on **12.28** a package registering commands from
  its service provider leaves `PackageDiscoverCommand` without its container
  (`Call to a member function make() on null`), which aborts every
  `composer install`. Fixed upstream — 12.55 and 12.67 were verified good, so
  `composer update laravel/framework` is the remedy. The constraint stays `^12.0`
  rather than guessing where in the 12.x line the fix actually landed.

## 0.3.0 — 2026-07-31

- **`shell_aliases`** — shorthand commands for `dep shell`, defaulting to
  `art` → `{{bin/php}} artisan`. Aliases cannot be exported (they are a shell
  feature, and the session's `exec $SHELL -l` reads only the host's rc files), so
  each entry is written as a small executable in `~/.cache/dep-shell/bin` and
  that directory is prepended to `PATH`. Commands are resolved before they are
  written, so `art` uses the site's PHP even when a login profile pushes the
  `remote_php` `PATH` entry back. The directory is rewritten on every session,
  so renamed entries leave nothing behind.

## 0.2.0 — 2026-07-29

- **`dep shell`** — interactive SSH into `{{deploy_path}}` with the deploy
  environment preloaded: `GIT_SSH_COMMAND` for `{{git_ssh_key}}`, the pinned
  `{{remote_php}}` first on `PATH` (and as `$DEP_PHP`), plus anything in the new
  `shell_env` config. It is a console command, not a task, because Deployer runs
  tasks in a worker without a tty. The name is `shell` rather than `ssh`:
  Deployer registers its own commands after the recipe is imported.
- **Fix: every `artisan:*` task now works.** `current_path` and
  `release_or_current_path` are pinned to `{{deploy_path}}` — an in-place deploy
  has no `current` symlink, so `{{bin/artisan}}` used to resolve to
  `{{deploy_path}}/current/artisan` and Deployer's Laravel-recipe tasks (plus
  `dep run` and `logs:app`) missed the app entirely.
- New tasks: `git:pull`, `git:fetch`, `git:status`, `git:log`, `git:reset-hard`,
  `composer:install`, `composer:dump`, `assets:build`, `deploy:quick`, `logs`,
  `logs:tail`, `logs:clear`, `app:info`. Shared `--lines=N` option for `git:log`,
  `logs` and `logs:tail`.
- `reset:hard` is now an alias of `git:reset-hard`.
- New helpers: `git_ssh_command()`, `git_env()` (for `run(…, env: …)`),
  `deploy_post_optimize()`, `log_files_glob()`, `latest_log_file()`,
  `env_value()`, `option_lines()`, `write_raw()` and `write_line()`.
  `deploy_update_code()` returns the `git pull` output and passes the key via
  `env:` instead of an inline assignment.
- Remote output is printed with `write_raw()` rather than `writeln()`. Remote
  data is read as markup twice over: `writeln()` parses it for
  `{{placeholders}}`, and the worker's *and* the master's console formatters
  both render style tags — a log line containing `<fg=…>` with an unknown
  colour aborted the whole command.
- Deployer's `push` task is removed. It rsyncs uncommitted local changes into
  `{{current_path}}`, which the pin above turns into the live webroot;
  `push:files` and `push:auth` are unaffected.

## 0.1.1 — 2026-07-24

- Declare `spatie/laravel-db-snapshots` as a Composer `suggest` — the `pull:db-*`
  tasks need the `snapshot:*` artisan commands in the consuming app (local +
  remote), not in the recipe itself.
- `pull:db-*` now assert those commands are available and fail with a clear
  message otherwise.
- Drop the `version` field from composer.json (inferred from the git tag).

## 0.1.0 — 2026-07-24

Initial release.

- In-place git deploy (`deploy`) replacing the release-based Laravel recipe.
- Step helpers: `deploy_update_code()`, `deploy_vendors()`, `deploy_assets()`,
  `deploy_clear()`, `deploy_migrate()`, `deploy_optimize()`, `deploy_standard()`.
- `long_running()` (`timeout: 0`, `forceOutput: true`) spread into
  `run()`/`runLocally()` as Deployer 8 named arguments.
- `git_pull_command()` with optional `{{git_ssh_key}}` (IdentitiesOnly=yes).
- Snapshot DB pull: `pull:db-refresh`, `pull:db-full` — local post-load is
  `config:clear` only (never `optimize`, to protect local test databases).
- Storage sync: `pull:files`, `push:files`.
- `reset:hard` (with confirmation), `setup` (non-destructive first-time setup,
  `--seed` opt-in), `push:auth` (upload local auth.json for private repos).
- Opt-in post-deploy toggles: `deploy_cache_clear`, `deploy_queue_restart`.
- Entry recipes: `recipe/app.php` (full app), `recipe/signatur.php` (no DB).
- Requires Deployer 8.
