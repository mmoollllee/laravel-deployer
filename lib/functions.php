<?php

/**
 * Shared helpers for the mmoollllee/laravel-deployer recipes.
 *
 * Everything lives in the Deployer namespace so the helpers can call Deployer's
 * own functions (get, run, cd, …) without imports and be called bare from the
 * recipes.
 */

namespace Deployer;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

// This file only declares functions (no side effects). The recipes pull it in
// via require_once, so it loads exactly once per run — a runtime guard would not
// help here anyway, because top-level function declarations are early-bound at
// compile time, before any guard could run.

/**
 * Named-argument options for long-running commands, spread into run()/runLocally()
 * as `run($cmd, ...long_running())`. Disables the command timeout (0 → Symfony
 * Process treats it as "no timeout", so composer/npm/migrate survive past
 * Deployer's 300 s default) and streams output live.
 *
 * Deployer 8 replaced v7's options-array with named scalar parameters, so this
 * MUST be spread (`...`) — passing it positionally would bind the array to
 * run()'s `$cwd` and throw a TypeError.
 *
 * @return array{timeout: int, forceOutput: bool}
 */
function long_running(): array
{
    return [
        'timeout' => 0,
        'forceOutput' => true,
    ];
}

/**
 * The GIT_SSH_COMMAND value that pins {{git_ssh_key}} — always with
 * IdentitiesOnly=yes, so no other agent key is offered. Returns null when no key
 * is configured, in which case git falls back to ~/.ssh/config.
 */
function git_ssh_command(): ?string
{
    $key = get('git_ssh_key');

    if (empty($key)) {
        return null;
    }

    // Double-quote the key path so a path with spaces still parses as a single
    // -i argument. The tilde in `~/.ssh/…` survives it: OpenSSH expands it for
    // identity files itself.
    return sprintf('ssh -i "%s" -o IdentitiesOnly=yes', $key);
}

/**
 * Environment for remote git commands, spread into run() as
 * `run('git …', env: git_env())`.
 *
 * Deliberately not `set('env', …)`: a global GIT_SSH_COMMAND would also apply to
 * `composer install`, and IdentitiesOnly=yes would then block every other key
 * when Composer clones a private dependency over SSH.
 *
 * @return array<string, string>
 */
function git_env(): array
{
    $command = git_ssh_command();

    return $command === null ? [] : ['GIT_SSH_COMMAND' => $command];
}

/**
 * Builds the `git pull` command with {{git_ssh_key}} inlined as a variable
 * assignment. The recipes pass the key via run()'s `env:` argument instead; this
 * helper stays for site recipes that compose the command string themselves.
 */
function git_pull_command(): string
{
    $command = git_ssh_command();

    return $command === null ? 'git pull' : sprintf("GIT_SSH_COMMAND='%s' git pull", $command);
}

/**
 * Print output that came back from the remote — a commit message, a file path,
 * a log line. All of it is *data*, and both layers that would otherwise read it
 * as markup have to be defused:
 *
 * - Deployer's writeln() parses `{{placeholders}}`, so `{{ app_name }}` in a log
 *   line would abort the task with "config option does not exist". Hence
 *   output() rather than writeln().
 * - Console style tags are read twice: the task runs in a worker subprocess and
 *   Master::runTask re-emits the worker's stdout through the master's formatter.
 *   One escape is consumed by the worker's formatter, so the text is escaped
 *   *and* written raw — the escape then survives to the master, which renders it
 *   back to a literal. Without that, `<fg=…>` with an unknown colour in a log
 *   line kills the whole command with an InvalidArgumentException.
 */
function write_raw(string $text): void
{
    $text = rtrim($text);

    if ($text !== '') {
        write_line(OutputFormatter::escape($text));
    }
}

/**
 * Write one line that is rendered by the master's formatter, not the worker's.
 *
 * Style tags in $line stay intact for the master to render; anything in it that
 * came from the remote must already be escaped (see write_raw()).
 */
function write_line(string $line): void
{
    output()->writeln($line, OutputInterface::OUTPUT_RAW);
}

/**
 * Reads a single key out of the remote .env, for display. Returns an empty
 * string when the file or the key is missing.
 */
function env_value(string $key): string
{
    // quote() the whole pattern: this is a public helper, so $key is not
    // guaranteed to be a literal at the call site.
    $pattern = quote("^{$key}=");

    return trim(run("grep -m1 {$pattern} .env 2>/dev/null | cut -d= -f2- || true", nothrow: true));
}

/**
 * Reads the shared `--lines=N` option (see recipe/tasks.php) and falls back to
 * $default when it is absent or not a positive integer.
 */
function option_lines(int $default): int
{
    $lines = input()->hasOption('lines') ? input()->getOption('lines') : null;

    return (is_string($lines) && ctype_digit($lines) && (int) $lines > 0) ? (int) $lines : $default;
}

/**
 * Resolves the local auth.json to upload so the remote `composer install` can
 * authenticate against private repositories (e.g. filament-media-library-pro on
 * satis.ralphjsmit.com). Checks, in order: the {{auth_json}} override, a
 * project-local auth.json, then Composer's global auth.json. Returns null when
 * none exists.
 */
function local_auth_json(): ?string
{
    $home = getenv('HOME') ?: '';

    $candidates = array_filter([
        get('auth_json'),
        'auth.json',
        $home !== '' ? "{$home}/.composer/auth.json" : null,
        $home !== '' ? "{$home}/.config/composer/auth.json" : null,
    ]);

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Create a local directory that a pull is about to rsync into.
 *
 * Deployer's download() is a bare `rsync -azP` with no `--mkpath`, and rsync
 * only ever creates the *last* segment of a destination path: a file
 * destination whose parent is missing fails outright, a directory destination
 * two levels deep fails the same way. Every path the pull tasks write to is
 * git-ignored by design — database dumps, uploaded media — so a fresh clone has
 * no reason to contain it, and the first pull on a new machine died on
 *
 *     rsync: [Receiver] change_dir#3 "…/database/dumps" failed:
 *            No such file or directory (2)
 *
 * Consumers used to paper over this with a committed `.gitkeep`, inconsistently
 * and only where someone had already hit it. Creating the directory here means a
 * pull works on a clone that has never seen one.
 */
function ensure_local_dir(string $dir): void
{
    $dir = rtrim($dir, '/');

    // dirname() of a bare filename; nothing to create.
    if ($dir === '' || $dir === '.') {
        return;
    }

    if (is_dir($dir)) {
        return;
    }

    runLocally('mkdir -p ' . escapeshellarg($dir));
}

/**
 * Remote: pull the latest code via git.
 *
 * @return string the `git pull` output, so a task can show it
 */
function deploy_update_code(): string
{
    cd('{{deploy_path}}');

    return run('git pull', env: git_env());
}

/**
 * The {{log_files}} glob for the current host, or null when the site has none.
 *
 * Resolved through get()'s default rather than referenced as a `{{log_files}}`
 * placeholder, because every other way of asking throws: get() without a default
 * throws for a missing option, and has() disagrees with it for
 * `set('log_files', null)` — has() is array_key_exists(), while get() still
 * treats a null value as missing. A default answers both cases with ''.
 */
function log_files_glob(): ?string
{
    $glob = trim((string) get('log_files', ''));

    return $glob === '' ? null : $glob;
}

/**
 * Remote: the newest file matching {{log_files}}, relative to {{deploy_path}}.
 * Laravel's daily channel leaves a file per day behind, so the glob is sorted by
 * mtime instead of dumping all of them. Returns null when the site defines no
 * glob, or when nothing matches.
 *
 * Also cd's into {{deploy_path}}, so the caller can use the returned relative
 * path directly.
 */
function latest_log_file(): ?string
{
    $glob = log_files_glob();

    if ($glob === null) {
        return null;
    }

    cd('{{deploy_path}}');

    // The glob goes in unquoted — the remote shell has to expand it.
    $file = trim(run(sprintf('ls -1t %s 2>/dev/null | head -n1', $glob), nothrow: true));

    return $file === '' ? null : $file;
}

/**
 * Whether a shebang line hands the file to a PHP interpreter.
 *
 * Only the interpreter itself counts, never the rest of the line — the phpenv
 * shim that motivated this lives under `~/.phpenv/shims/`, so a substring match
 * on "php" would misread `#!/usr/bin/env bash` scripts sitting in a php-ish
 * path. `env` is unwrapped so `#!/usr/bin/env php` resolves to `php`.
 */
function shebang_runs_php(string $shebang): bool
{
    if (! preg_match('/^#!\s*(\S+)(?:\s+(\S+))?/', $shebang, $matches)) {
        return false;
    }

    $interpreter = basename($matches[1]);

    if ($interpreter === 'env') {
        $interpreter = basename($matches[2] ?? '');
    }

    // php, php8, php8.3, php-cgi — but not `bash`, `sh`, `python`.
    return (bool) preg_match('/^php[\d.\-]*$/', $interpreter);
}

/**
 * Remote: install Composer dependencies for production.
 *
 * {{bin/composer}} resolves how Composer must be invoked on this host (see
 * recipe/base.php). The version probe in front is the guard that made this
 * necessary: a misresolved binary can "succeed" with exit code 0 and install
 * nothing, which leaves vendor/ silently stale for release after release.
 */
function deploy_vendors(): void
{
    cd('{{deploy_path}}');
    assert_composer_runnable();
    run('{{bin/composer}} install --no-dev --optimize-autoloader --no-interaction', ...long_running());
}

/**
 * Fail loudly when {{bin/composer}} does not actually execute Composer.
 *
 * @throws \RuntimeException when the command produces no Composer version banner
 */
function assert_composer_runnable(): void
{
    $output = trim(run('{{bin/composer}} --version 2>&1 || true'));

    if (! str_contains($output, 'Composer version')) {
        throw new \RuntimeException(
            "`{{bin/composer}}` did not run Composer on the remote — vendor/ would be left untouched.\n".
            "Got: ".(($output === '') ? '(no output)' : mb_substr($output, 0, 300))."\n".
            "Set the correct command per host, e.g. ->set('bin/composer', '/usr/local/bin/composer')."
        );
    }
}

/** Remote: install locked npm dependencies and build front-end assets. */
function deploy_assets(): void
{
    cd('{{deploy_path}}');
    run('npm ci', ...long_running());
    run('npm run build', ...long_running());
}

/** Remote: drop stale config/route/view caches before migrating. */
function deploy_clear(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan optimize:clear');
}

/** Remote: run database migrations. */
function deploy_migrate(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan migrate --force', ...long_running());
}

/** Remote: rebuild the framework caches. */
function deploy_optimize(): void
{
    cd('{{deploy_path}}');
    run('{{bin/php}} artisan optimize');
}

/**
 * Remote: the opt-in steps that follow every cache rebuild.
 *
 * Shared by deploy_standard() and the `deploy:quick` task — a queue worker holds
 * the route and config cache it loaded at start-up, so skipping the restart
 * after publishing new code leaves it serving the old one.
 */
function deploy_post_optimize(): void
{
    cd('{{deploy_path}}');

    if (get('deploy_cache_clear')) {
        run('{{bin/php}} artisan cache:clear');
    }

    if (get('deploy_queue_restart')) {
        run('{{bin/php}} artisan queue:restart');
    }
}

/**
 * The standard in-place deploy sequence. Honours {{deploy_assets}} and
 * {{deploy_migrate}} so sites without a front-end build or a database can reuse
 * it unchanged. Sites with extra steps define their own `deploy` task and call
 * these helpers in whatever order they need.
 */
function deploy_standard(): void
{
    deploy_update_code();
    deploy_vendors();

    if (get('deploy_assets')) {
        deploy_assets();
    }

    if (get('deploy_migrate')) {
        deploy_clear();
        deploy_migrate();
    }

    deploy_optimize();
    deploy_post_optimize();
}
