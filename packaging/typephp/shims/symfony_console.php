<?php

declare(strict_types=1);

/*
 * Reusable compile-only stand-ins for the small slice of Symfony Console that
 * the HomeLan\FileStore\Command\* classes extend / reference, so the TypePHP
 * builds can compile and run those command classes directly instead of each
 * `main-*.php` re-implementing the command's body.
 *
 * `symfony/console` is large and heavily dynamic and is not on the compile
 * path. The command classes only ever use:
 *   - `extends Command` + the `SUCCESS` / `FAILURE` / `INVALID` constants
 *   - `#[AsCommand(name: '…')]`
 *   - `configure()` with `->addOption()` / `->addArgument()` / `->setHelp()`
 *     (fluent, return $this) and the `InputOption::VALUE_*` /
 *     `InputArgument::*` constants
 *   - `execute(InputInterface, OutputInterface): int`, reading options with
 *     `$input->getOption()` / `$input->getArgument()` and writing with
 *     `$output->writeln()`
 * so that is all this provides. `run()` is a thin public entry that just calls
 * the (protected) `execute()`, so a `main()` can do `(new Foo($deps))->run($in,
 * $out)` with no per-command wrapper.
 *
 * Real argv parsing / output is HomeLan\FileStore\Cli\ArgvInput / StdoutOutput
 * in shims/console_runtime.php.
 *
 * Multi-namespace bracketed file (TypePHP supports this). Listed only in
 * packaging/typephp/project*.yml `sources`, never autoloaded, so a normal
 * (interpreted) run uses the real Symfony Console and this file is never in
 * scope - same model as shims/ldap_classes.php.
 */

namespace Symfony\Component\Console\Command {

    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;

    class Command
    {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public const INVALID = 2;

        public function __construct(?string $name = null)
        {
        }

        /**
         * Not part of Symfony's real signature - a deliberately minimal entry
         * that runs the command body without Symfony's Application / input
         * binding machinery.
         */
        public function run(InputInterface $input, OutputInterface $output): int
        {
            $this->configure();
            return $this->execute($input, $output);
        }

        protected function configure(): void
        {
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            return self::SUCCESS;
        }

        public function addOption(
            string $name,
            string|array|null $shortcut = null,
            ?int $mode = null,
            string $description = '',
            mixed $default = null
        ): static {
            return $this;
        }

        public function addArgument(
            string $name,
            ?int $mode = null,
            string $description = '',
            mixed $default = null
        ): static {
            return $this;
        }

        public function setHelp(string $help): static
        {
            return $this;
        }

        public function setDescription(string $description): static
        {
            return $this;
        }

        public function setName(string $name): static
        {
            return $this;
        }

        public function setAliases(array $aliases): static
        {
            return $this;
        }

        public function setHidden(bool $hidden = true): static
        {
            return $this;
        }
    }
}

namespace Symfony\Component\Console\Command\Attribute {

    #[\Attribute(\Attribute::TARGET_CLASS)]
    class AsCommand
    {
        public function __construct(
            public string $name,
            public ?string $description = null,
            public array $aliases = [],
            public bool $hidden = false,
        ) {
        }
    }
}

namespace Symfony\Component\Console\Input {

    class InputOption
    {
        public const VALUE_NONE = 1;
        public const VALUE_REQUIRED = 2;
        public const VALUE_OPTIONAL = 4;
        public const VALUE_IS_ARRAY = 8;
        public const VALUE_NEGATABLE = 16;
    }

    class InputArgument
    {
        public const REQUIRED = 1;
        public const OPTIONAL = 2;
        public const IS_ARRAY = 4;
    }

    interface InputInterface
    {
        public function getOption(string $name): mixed;

        public function getArgument(string $name): mixed;
    }
}

namespace Symfony\Component\Console\Output {

    interface OutputInterface
    {
        public function writeln(string|iterable $messages, int $options = 0): void;

        public function write(string|iterable $messages, bool $newline = false, int $options = 0): void;
    }
}
