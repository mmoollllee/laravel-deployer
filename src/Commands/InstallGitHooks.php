<?php

namespace Mmoollllee\LaravelDeployer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Installs the repository's git hooks, which today means one: a pre-commit guard
 * against committing database dumps.
 *
 * This lives with the deployer because the deployer is what creates the hazard —
 * `dep pull:db-full` drops a production snapshot into the working tree, and the
 * only thing standing between that file and the repository's permanent history
 * is somebody noticing it in `git status`.
 *
 * The hook is COPIED into the app rather than pointed at from `vendor/`: hooks
 * have to work in a fresh clone before `composer install` has run, and
 * `core.hooksPath` takes a single directory, so aiming it at the package would
 * make app-owned hooks impossible. The copy carries a header saying where it
 * came from and how to refresh it.
 *
 * `core.hooksPath` is per-clone git config and cannot be committed, so this
 * command has to be run once per checkout. It is idempotent.
 */
class InstallGitHooks extends Command
{
    // Declared as properties rather than #[Signature]/#[Description]: those
    // attributes are Laravel 13, and this package supports 12 as well.
    protected $signature = 'app:install-git-hooks
        {--force : Overwrite an existing hook that differs from the shipped one}';

    protected $description = "Install the project's git hooks (pre-commit: refuses to commit database dumps) and point core.hooksPath at them";

    /**
     * Where the app keeps its hooks. A directory in the repository rather than
     * `.git/hooks`, so the hook is versioned and reviewable like any other file.
     */
    private const HOOKS_DIRECTORY = '.githooks';

    public function handle(): int
    {
        $root = base_path();

        // `.git` is a FILE in a worktree and in every submodule, and absent
        // entirely when base_path() is a subdirectory of the repository, so
        // testing for a directory rejects perfectly valid checkouts. Ask git.
        if (! $this->isGitRepository($root)) {
            $this->error($root.' is not a git repository.');

            return self::FAILURE;
        }

        $stub = $this->stub();

        if ($stub === null) {
            $this->error('The shipped hook template is missing from the package.');

            return self::FAILURE;
        }

        $hooksDirectory = $root.'/'.self::HOOKS_DIRECTORY;
        $target = $hooksDirectory.'/pre-commit';
        // Interpolated into `dump_dir='…'` in the hook: a quote in the path
        // would otherwise produce an unterminated string, and every commit in
        // the repository would fail with a shell parse error.
        $contents = str_replace('__DUMP_DIR__', $this->shellEscape($this->dumpDirectory()), $stub);

        if (is_file($target) && ! $this->option('force') && file_get_contents($target) !== $contents) {
            $this->warn(self::HOOKS_DIRECTORY.'/pre-commit exists and differs from the shipped hook — left untouched. Re-run with --force to replace it.');
        } else {
            if (! is_dir($hooksDirectory) && ! mkdir($hooksDirectory, 0o755, recursive: true) && ! is_dir($hooksDirectory)) {
                $this->error('Could not create '.self::HOOKS_DIRECTORY.'.');

                return self::FAILURE;
            }

            // Unchecked writes are how a developer ends up being told the guard
            // is installed while every commit goes through unguarded.
            if (file_put_contents($target, $contents) === false) {
                $this->error('Could not write '.self::HOOKS_DIRECTORY.'/pre-commit.');

                return self::FAILURE;
            }

            chmod($target, 0o755);

            $this->info(self::HOOKS_DIRECTORY.'/pre-commit installed.');
        }

        return $this->pointGitAtHooksDirectory($root);
    }

    /**
     * Git only consults one hooks directory, so an existing `core.hooksPath`
     * pointing somewhere else is somebody's deliberate setup — say so rather
     * than quietly taking it over.
     */
    private function pointGitAtHooksDirectory(string $root): int
    {
        $current = $this->gitConfig($root, 'core.hooksPath');

        if ($current === self::HOOKS_DIRECTORY) {
            $this->line('core.hooksPath already points at '.self::HOOKS_DIRECTORY.'.');

            return self::SUCCESS;
        }

        // A value spelling out the repository's own `.git/hooks` is where git
        // looks anyway — set by a tool, not a decision worth protecting.
        if ($this->isDefaultHooksPath($current, $root)) {
            $current = '';
        }

        if ($current !== '') {
            $this->warn("core.hooksPath is set to [{$current}] — leaving it alone. Point it at ".self::HOOKS_DIRECTORY.' yourself, or move the hook there.');

            return self::SUCCESS;
        }

        shell_exec('git -C '.escapeshellarg($root).' config core.hooksPath '.escapeshellarg(self::HOOKS_DIRECTORY).' 2>/dev/null');

        // Read it back rather than trusting the write. `git` missing from PATH,
        // `shell_exec` disabled, or an unwritable .git/config all fail silently,
        // and reporting success there would leave a setup script green while
        // git never executes the hook.
        if ($this->gitConfig($root, 'core.hooksPath') !== self::HOOKS_DIRECTORY) {
            $this->error('Could not set core.hooksPath — the hook will NOT run. Set it yourself: git config core.hooksPath '.self::HOOKS_DIRECTORY);

            return self::FAILURE;
        }

        $this->info('core.hooksPath set to '.self::HOOKS_DIRECTORY.'.');

        return self::SUCCESS;
    }

    private function isGitRepository(string $root): bool
    {
        $output = shell_exec('git -C '.escapeshellarg($root).' rev-parse --git-dir 2>/dev/null');

        return filled($output);
    }

    private function gitConfig(string $root, string $key): string
    {
        return trim((string) shell_exec('git -C '.escapeshellarg($root).' config '.escapeshellarg($key).' 2>/dev/null'));
    }

    /**
     * Quote a value for a single-quoted POSIX shell string.
     */
    private function shellEscape(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }

    /**
     * Whether `core.hooksPath` merely restates git's own default location for
     * this repository, absolute or relative.
     */
    private function isDefaultHooksPath(string $current, string $root): bool
    {
        $candidate = Str::startsWith($current, '/')
            ? $current
            : rtrim($root, '/').'/'.$current;

        return rtrim($candidate, '/') === rtrim($root, '/').'/.git/hooks';
    }

    /**
     * The snapshot disk's directory, relative to the repository root, so the
     * hook can name it without asking Laravel on every commit. Empty when the
     * app has no db-snapshots config or its disk is not local — the hook then
     * falls back to matching dump file extensions alone.
     */
    private function dumpDirectory(): string
    {
        $disk = config('db-snapshots.disk');

        if (! is_string($disk) || $disk === '') {
            return '';
        }

        try {
            $path = Storage::disk($disk)->path('');
        } catch (Throwable) {
            return '';
        }

        $path = rtrim($path, '/');
        $root = rtrim(base_path(), '/');

        return Str::startsWith($path, $root.'/')
            ? Str::after($path, $root.'/')
            : '';
    }

    private function stub(): ?string
    {
        $path = __DIR__.'/../../hooks/pre-commit';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
