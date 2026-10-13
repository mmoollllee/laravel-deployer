<?php

declare(strict_types=1);

/**
 * `dep pull:db <tables> [selector]` — pull only the named tables from the remote,
 * without putting them into {{db_pull_include}} first.
 *
 * The tables are a positional argument, the way Deployer's own
 * `dep run '<command>' [selector]` takes its command. A task cannot declare one:
 * Deployer reads every positional argument of a task as a host selector, so
 * `dep pull:db-refresh users` looks for a host named "users". Several tables
 * therefore go into one argument — `dep pull:db "users posts"` — and whatever
 * follows it selects the host, as everywhere else.
 *
 * The command is a front for `pull:db-refresh`: it runs that task with
 * `--tables`, so snapshot, download and local load are the same code, and the
 * hooks a site puts on `pull:db-refresh` (localizing tenant domains, say) run
 * after an ad-hoc pull too.
 */

namespace Mmoollllee\LaravelDeployer;

use Deployer\Command\MainCommand;
use Deployer\Deployer;
use Deployer\Exception\Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Deployer\parse_table_list;

final class PullDbCommand extends MainCommand
{
    /** The task this command runs, with the tables in its `--tables` option. */
    private const TASK = 'pull:db-refresh';

    public function __construct(Deployer $deployer)
    {
        parent::__construct('pull:db', 'Pull the given DB tables from remote → local (snapshot)', $deployer);
    }

    protected function configure(): void
    {
        // Ahead of the parent's host selector, which is an optional array and
        // has to come last.
        $this->addArgument(
            'tables',
            InputArgument::REQUIRED,
            'Table(s) to pull — several in one argument, space or comma separated: "users posts"',
        );

        // Extending MainCommand rather than Command gives this command the
        // task's own options (-o, --no-hooks, --plan, …), so they can be handed
        // on unchanged.
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tables = parse_table_list($input->getArgument('tables'), (string) $input->getOption('tables'));

        if ($tables === []) {
            throw new Exception('No table given: dep pull:db "users posts"');
        }

        // `dep pull:db users posts` reads "posts" as a host. Say so instead of
        // the bare "No host selected" the task would answer with.
        $selector = $input->getArgument('selector');

        if ($selector !== [] && $this->deployer->selector->select(implode(',', $selector)) === []) {
            throw new Exception(sprintf(
                "No host matches \"%s\". Several tables go into one argument:\n\n    dep pull:db \"%s\"",
                implode(' ', $selector),
                implode(' ', [...$tables, ...$selector]),
            ));
        }

        $arguments = ['selector' => $selector];

        // Unset options are left out, as on a command line: ArrayInput refuses
        // null for an option that requires a value.
        foreach ($input->getOptions() as $name => $value) {
            if ($value !== null && $value !== false && $value !== []) {
                $arguments["--{$name}"] = $value;
            }
        }

        $arguments['--tables'] = implode(',', $tables);

        $task = new ArrayInput($arguments);
        $task->setInteractive($input->isInteractive());

        return $this->getApplication()->find(self::TASK)->run($task, $output);
    }
}
