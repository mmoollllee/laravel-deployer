<?php

declare(strict_types=1);

/**
 * `dep shell` — an interactive SSH session that opens in the remote webroot with
 * the deploy environment already in place.
 *
 * Deployer ships its own `dep ssh`, which also cd's into {{deploy_path}} — but
 * the shell it hands over is bare: `git pull` falls back to whatever
 * ~/.ssh/config offers, and `php` is the host's default CLI, which on Plesk is
 * usually not the version the site runs on. This command exports both.
 *
 * It has to be a console command rather than a task, because Deployer runs tasks
 * in a worker subprocess whose stdin is not a tty — `ssh -t` could not hand the
 * terminal over from there.
 */

namespace Mmoollllee\LaravelDeployer;

use Deployer\Deployer;
use Deployer\Host\Host;
use Deployer\Host\Localhost;
use Deployer\Task\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

use function Deployer\git_ssh_command;
use function Deployer\quote;

final class ShellCommand extends Command
{
    public function __construct(private readonly Deployer $deployer)
    {
        parent::__construct('shell');

        $this->setDescription('Open an interactive shell in the remote webroot (deploy key + PHP binary preloaded)');
    }

    protected function configure(): void
    {
        $this->addArgument('hostname', InputArgument::OPTIONAL, 'Hostname');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->selectHost($input, $output);

        if ($host === null) {
            $output->writeln('No remote hosts.');

            return 2;
        }

        // Config lookups below resolve per-host values ({{git_ssh_key}} is
        // typically set on the host, not globally), which needs a context.
        Context::push(new Context($host));

        try {
            // A multiplexed master connection would outlive the interactive
            // session; let this one own its socket.
            $host->setSshMultiplexing(false);

            $environment = $this->environment($host);

            $this->printBanner($output, $host, $environment);

            $options = implode(' ', array_map(fn ($option) => quote($option), $host->connectionOptions()));

            // -t allocates a tty on the remote so the login shell is interactive.
            passthru(sprintf(
                'ssh -t %s %s %s',
                $options,
                $host->connectionString(),
                escapeshellarg($this->remoteCommand($host, $environment)),
            ), $exitCode);
        } finally {
            Context::pop();
        }

        return $exitCode;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('hostname')) {
            $suggestions->suggestValues(array_keys($this->deployer->hosts->all()));
        }
    }

    /**
     * Picks the host from the argument, or — as Deployer's own `dep ssh` does —
     * the only remote host, or asks. Returns null when the recipe defines no
     * remote host at all.
     */
    private function selectHost(InputInterface $input, OutputInterface $output): ?Host
    {
        $hostname = $input->getArgument('hostname');

        if (! empty($hostname)) {
            return $this->deployer->hosts->get($hostname);
        }

        $aliases = [];

        foreach ($this->deployer->hosts as $host) {
            if ($host instanceof Localhost) {
                continue;
            }

            $aliases[] = $host->getAlias();
        }

        if ($aliases === []) {
            return null;
        }

        if (count($aliases) === 1) {
            return $this->deployer->hosts->get($aliases[0]);
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion('<question>Select host:</question>', $aliases);
        $question->setErrorMessage('There is no "%s" host.');

        return $this->deployer->hosts->get($helper->ask($input, $output, $question));
    }

    /**
     * Environment exported into the interactive shell.
     *
     * @return array<string, string>
     */
    private function environment(Host $host): array
    {
        $environment = [];

        $gitSsh = git_ssh_command();

        if ($gitSsh !== null) {
            $environment['GIT_SSH_COMMAND'] = $gitSsh;
        }

        $php = (string) $host->get('remote_php', 'php');

        // Only a pinned path is worth exporting — a bare `php` is already on PATH.
        if (str_contains($php, '/')) {
            // Put the pinned interpreter first on PATH so `php artisan …` and
            // anything shelling out to `php` use the site's version. Keep the
            // binary itself around too: a login profile that rewrites PATH
            // (phpenv shims, for one) can still push our entry back.
            $environment['PATH'] = dirname($php).':$PATH';
            $environment['DEP_PHP'] = $php;
        }

        foreach ((array) $host->get('shell_env', []) as $name => $value) {
            // Catch a list where a map was meant — set('shell_env', ['FOO']) would
            // otherwise emit `export 0="FOO"`, which bash rejects as an invalid
            // identifier, killing the session before it starts with no clue why.
            if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(
                    sprintf('shell_env: "%s" is not a valid environment variable name — expected a name => value map.', (string) $name),
                );
            }

            $environment[$name] = (string) $value;
        }

        return $environment;
    }

    /**
     * The command handed to `ssh -t`. The parts are joined with `;` rather than
     * `&&` so the shell still starts when the cd fails — landing in $HOME beats
     * getting no session at all.
     *
     * @param array<string, string> $environment
     */
    private function remoteCommand(Host $host, array $environment): string
    {
        $deployPath = (string) $host->get('deploy_path', '~');

        // Unquoted on purpose: deploy_path is usually written as ~/example.com,
        // and the tilde has to be expanded by the remote shell.
        $lines = [sprintf('cd %s || echo "dep shell: %s not found, staying in $PWD" >&2', $deployPath, $deployPath)];

        foreach ($environment as $name => $value) {
            // Double quotes, so `$VAR` in a shell_env value is expanded on the
            // server (that is what makes `PATH` => '…:$PATH' work).
            $lines[] = sprintf('export %s="%s"', $name, addcslashes($value, '"\\'));
        }

        // Exported variables survive the exec; the login shell is what the host
        // expects (phpenv and friends set themselves up in the login profile).
        $shell = $host->has('shell_path') ? (string) $host->get('shell_path') : '$SHELL';

        $lines[] = sprintf('exec %s -l', $shell);

        return implode('; ', $lines);
    }

    /**
     * @param array<string, string> $environment
     */
    private function printBanner(OutputInterface $output, Host $host, array $environment): void
    {
        $output->writeln(sprintf(
            '<info>→ %s</info> <comment>%s</comment>',
            $host->getAlias(),
            (string) $host->get('deploy_path', '~'),
        ));

        if (isset($environment['GIT_SSH_COMMAND'])) {
            $output->writeln(sprintf('  git  <comment>%s</comment> via $GIT_SSH_COMMAND', (string) $host->get('git_ssh_key')));
        }

        if (isset($environment['DEP_PHP'])) {
            $output->writeln(sprintf('  php  <comment>%s</comment> first on $PATH (also $DEP_PHP)', $environment['DEP_PHP']));
        }
    }
}
