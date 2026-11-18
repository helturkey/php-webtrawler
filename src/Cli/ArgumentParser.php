<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Cli;

/**
 * Minimal POSIX-style long-option parser: --name value, --name=value, flags, repeatable options, and "--" to
 * end option parsing. Unknown options are errors so typos never go unnoticed.
 *
 * @internal
 */
final class ArgumentParser
{
    /**
     * @param list<string> $arguments
     * @param list<string> $valueOptions Options taking one value (last one wins).
     * @param list<string> $multiOptions Options taking one value, repeatable.
     * @param list<string> $flags Boolean options.
     * @return array{positional: list<string>, options: array<string, string|list<string>|true>}
     */
    public static function parse(array $arguments, array $valueOptions, array $multiOptions, array $flags): array
    {
        $positional = [];
        $options = [];
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];
            if ($argument === '--') {
                array_push($positional, ...array_slice($arguments, $i + 1));
                break;
            }
            if (!str_starts_with($argument, '--')) {
                $positional[] = $argument;
                continue;
            }

            $name = substr($argument, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (in_array($name, $flags, true)) {
                if ($value !== null) {
                    throw new UsageException(sprintf('Option --%s does not take a value.', $name));
                }
                $options[$name] = true;
                continue;
            }
            if (!in_array($name, $valueOptions, true) && !in_array($name, $multiOptions, true)) {
                throw new UsageException(sprintf('Unknown option --%s.', $name));
            }
            if ($value === null) {
                if (!isset($arguments[$i + 1])) {
                    throw new UsageException(sprintf('Option --%s needs a value.', $name));
                }
                $value = $arguments[++$i];
            }

            if (in_array($name, $multiOptions, true)) {
                $existing = $options[$name] ?? [];
                $existing = is_array($existing) ? $existing : [];
                $existing[] = $value;
                $options[$name] = $existing;
            } else {
                $options[$name] = $value;
            }
        }

        return ['positional' => $positional, 'options' => $options];
    }
}
