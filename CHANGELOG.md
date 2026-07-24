# Changelog

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
