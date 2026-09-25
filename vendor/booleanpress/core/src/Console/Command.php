<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Console;

/**
 * Base Command
 *
 * Abstract base class for WP-CLI commands.
 * Provides helper methods for input/output and common operations.
 */
abstract class Command
{
    /**
     * The command name.
     */
    protected string $name = '';

    /**
     * The command description.
     */
    protected string $description = '';

    /**
     * The application instance.
     */
    protected ?\BooleanSmtp\Core\Foundation\Application $app = null;

    /**
     * Set the application instance.
     */
    public function setApplication(\BooleanSmtp\Core\Foundation\Application $app): void
    {
        $this->app = $app;
    }

    /**
     * The command synopsis (arguments and options).
     *
     * @var array<array<string, mixed>>
     */
    protected array $synopsis = [];

    /**
     * Handle the command execution.
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assocArgs Associative arguments (options)
     */
    abstract public function handle(array $args, array $assocArgs): void;

    /**
     * Register the command with WP-CLI.
     */
    public function register(string $parentCommand = ''): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $command = $parentCommand
            ? "{$parentCommand} {$this->name}"
            : $this->name;

        \WP_CLI::add_command($command, [$this, 'execute'], [
            'shortdesc' => $this->description,
            'synopsis' => $this->synopsis,
        ]);
    }

    /**
     * Execute the command (WP-CLI callback).
     *
     * @param array<string> $args Positional arguments
     * @param array<string, mixed> $assocArgs Associative arguments
     */
    public function execute(array $args, array $assocArgs): void
    {
        try {
            $this->handle($args, $assocArgs);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Get the application instance.
     */
    public function getApp(): \BooleanSmtp\Core\Foundation\Application
    {
        if ($this->app === null) {
            throw new \RuntimeException('Application instance not set. Call setApplication() first.');
        }
        return $this->app;
    }

    /**
     * Get the command name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Write a success message.
     */
    protected function success(string $message): void
    {
        \WP_CLI::success($message);
    }

    /**
     * Write an error message and exit.
     */
    protected function error(string $message): void
    {
        \WP_CLI::error($message);
    }

    /**
     * Write a warning message.
     */
    protected function warning(string $message): void
    {
        \WP_CLI::warning($message);
    }

    /**
     * Write an info line.
     */
    protected function info(string $message): void
    {
        \WP_CLI::log($message);
    }

    /**
     * Write a line of output.
     */
    protected function line(string $message = ''): void
    {
        \WP_CLI::log($message);
    }

    /**
     * Write a comment (muted text).
     */
    protected function comment(string $message): void
    {
        \WP_CLI::log(\WP_CLI::colorize("%9{$message}%n"));
    }

    /**
     * Write colored output.
     */
    protected function colorize(string $message): string
    {
        return \WP_CLI::colorize($message);
    }

    /**
     * Prompt for user input with optional validation.
     */
    protected function ask(string $question, string $default = '', ?callable $validator = null): string
    {
        do {
            $input = \cli\prompt($question, $default);

            if ($validator && !$validator($input)) {
                $this->error("Invalid input. Please try again.");
                continue;
            }

            return $input;
        } while (true);
    }

    /**
     * Prompt for confirmation.
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        $defaultText = $default ? 'Y/n' : 'y/N';
        $response = $this->ask("{$question} [{$defaultText}]");

        if ($response === '') {
            return $default;
        }

        return strtolower($response[0]) === 'y';
    }

    /**
     * Prompt the user to choose from a list of options.
     *
     * @param string $question
     * @param array<string> $choices
     * @param string|null $default
     * @return string
     */
    protected function choice(string $question, array $choices, ?string $default = null): string
    {
        $choicesMap = array_values($choices);
        $defaultIndex = $default ? array_search($default, $choicesMap, true) : null;

        $this->line($question);
        foreach ($choicesMap as $index => $choice) {
            $this->line("  [{$index}] {$choice}");
        }

        do {
            $selection = $this->ask("Selection >");

            if ($selection === '' && $defaultIndex !== null && $defaultIndex !== false) {
                return $choicesMap[$defaultIndex];
            }

            if (isset($choicesMap[$selection])) {
                return $choicesMap[$selection];
            }

            // Check if they typed the value directly
            if (in_array($selection, $choicesMap, true)) {
                return $selection;
            }

            $this->error("Invalid selection.");
        } while (true);
    }

    /**
     * Prompt for secret input (password).
     */
    protected function secret(string $question): string
    {
        return \cli\prompt($question, '', ' ', true);
    }

    /**
     * Write a table.
     *
     * Rows may be lists in header order or arrays already keyed by header; WP-CLI needs the
     * latter, so list rows are combined with the headers first.
     *
     * @param array<string> $headers
     * @param array<array<int|string, mixed>> $rows
     */
    protected function table(array $headers, array $rows): void
    {
        $items = array_map(
            static fn (array $row): array => array_is_list($row) ? array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), '')) : $row,
            array_values($rows)
        );

        \WP_CLI\Utils\format_items('table', $items, $headers);
    }

    /**
     * Show a progress bar.
     *
     * @param string $message Progress bar label
     * @param int $count Total number of items
     * @return object The progress bar instance
     */
    protected function progress(string $message, int $count): object
    {
        return \WP_CLI\Utils\make_progress_bar($message, $count);
    }

    /**
     * Execute a WP-CLI command.
     *
     * @param string $command The command to run
     * @param array<string, mixed> $options Options for the command
     * @return mixed
     */
    protected function call(string $command, array $options = []): mixed
    {
        return \WP_CLI::runcommand($command, $options);
    }

    /**
     * Get an argument value.
     *
     * @param array<string> $args
     */
    protected function argument(array $args, int $index, mixed $default = null): mixed
    {
        return $args[$index] ?? $default;
    }

    /**
     * Get an option value.
     *
     * @param array<string, mixed> $assocArgs
     */
    protected function option(array $assocArgs, string $key, mixed $default = null): mixed
    {
        return $assocArgs[$key] ?? $default;
    }

    /**
     * Check if an option flag is set.
     *
     * @param array<string, mixed> $assocArgs
     */
    protected function hasOption(array $assocArgs, string $key): bool
    {
        return isset($assocArgs[$key]);
    }

    /**
     * Print debug information.
     */
    protected function debug(string $message): void
    {
        \WP_CLI::debug($message);
    }

    /**
     * Get the synopsis for registration.
     *
     * @return array<array<string, mixed>>
     */
    protected function getSynopsis(): array
    {
        return $this->synopsis;
    }

    /**
     * Add an argument to the synopsis.
     *
     * @return array<string, mixed>
     */
    protected function addArgument(
        string $name,
        string $description = '',
        bool $optional = false,
        mixed $default = null
    ): array {
        $arg = [
            'type' => 'positional',
            'name' => $name,
            'description' => $description,
            'optional' => $optional,
        ];

        if ($default !== null) {
            $arg['default'] = $default;
        }

        return $arg;
    }

    /**
     * Add an option to the synopsis.
     *
     * @return array<string, mixed>
     */
    protected function addOption(
        string $name,
        string $description = '',
        bool $optional = true,
        mixed $default = null
    ): array {
        $opt = [
            'type' => 'assoc',
            'name' => $name,
            'description' => $description,
            'optional' => $optional,
        ];

        if ($default !== null) {
            $opt['default'] = $default;
        }

        return $opt;
    }

    /**
     * Add a flag to the synopsis.
     *
     * @return array<string, mixed>
     */
    protected function addFlag(string $name, string $description = ''): array
    {
        return [
            'type' => 'flag',
            'name' => $name,
            'description' => $description,
            'optional' => true,
        ];
    }
}
