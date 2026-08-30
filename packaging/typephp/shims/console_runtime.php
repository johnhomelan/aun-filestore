<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Cli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Argv parser + stdout writer standing in for Symfony's Input/Output in the
 * TypePHP builds, so `main-*.php` can hand a real
 * HomeLan\FileStore\Command\* class its input/output and call ->run().
 *
 * Compile-only (listed in packaging/typephp/project*.yml, never autoloaded).
 */
final class ArgvInput implements InputInterface
{
    /** Short flag -> long option name, covering every shortcut the command classes register. */
    private const SHORTCUTS = [
        'c' => 'config',
        'd' => 'daemonize',
        'p' => 'pidfile',
    ];

    /** @var array<string, string|bool> */
    private array $aOptions = [];

    /** @var list<string> */
    private array $aArguments = [];

    /** @param list<string> $aArgv tokens after argv[0] (or after a sub-command name) */
    public function __construct(array $aArgv)
    {
        $iCount = \count($aArgv);
        for ($i = 0; $i < $iCount; $i++) {
            $sToken = $aArgv[$i];

            if (\str_starts_with($sToken, '--')) {
                $sBody = \substr($sToken, 2);
                $iEq = \strpos($sBody, '=');
                if ($iEq !== false) {
                    $this->aOptions[\substr($sBody, 0, $iEq)] = \substr($sBody, $iEq + 1);
                } elseif ($i + 1 < $iCount && !\str_starts_with($aArgv[$i + 1], '-')) {
                    $this->aOptions[$sBody] = $aArgv[++$i];
                } else {
                    $this->aOptions[$sBody] = true;
                }
                continue;
            }

            if (\strlen($sToken) === 2 && $sToken[0] === '-' && $sToken !== '--') {
                $sName = self::SHORTCUTS[$sToken[1]] ?? $sToken[1];
                if ($i + 1 < $iCount && !\str_starts_with($aArgv[$i + 1], '-')) {
                    $this->aOptions[$sName] = $aArgv[++$i];
                } else {
                    $this->aOptions[$sName] = true;
                }
                continue;
            }

            $this->aArguments[] = $sToken;
        }
    }

    public function getOption(string $name): mixed
    {
        return $this->aOptions[$name] ?? null;
    }

    public function getArgument(string $name): mixed
    {
        return $this->aArguments[0] ?? null;
    }
}

/**
 * Writes command output straight to stdout, stripping the Symfony style tags
 * (`<error>`, `<comment>`, `<info>`, ...) the commands sprinkle in.
 */
final class StdoutOutput implements OutputInterface
{
    public function writeln(string|iterable $messages, int $options = 0): void
    {
        $this->write($messages, true, $options);
    }

    public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
    {
        if (\is_iterable($messages)) {
            foreach ($messages as $sLine) {
                $this->write((string) $sLine, $newline, $options);
            }
            return;
        }
        $sClean = \preg_replace('#</?[a-z][a-z0-9=;-]*>#i', '', (string) $messages);
        \fwrite(\STDOUT, ($sClean ?? '') . ($newline ? "\n" : ''));
    }
}
