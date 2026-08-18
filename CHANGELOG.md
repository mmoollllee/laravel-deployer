# Changelog

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
