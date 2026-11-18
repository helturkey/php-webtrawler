<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Transport;

use HElturkey\WebTrawler\Transport\Drivers\GuzzleTransport;
use HElturkey\WebTrawler\Transport\Drivers\AmpTransport;
use HElturkey\WebTrawler\Transport\Drivers\ImpityTransport;
use HElturkey\WebTrawler\Transport\Drivers\RnetTransport;
use HElturkey\WebTrawler\Transport\Drivers\BrowserTransport;
use Closure;
use HElturkey\WebTrawler\Contracts\Http\TransportInterface;

/**
 * Names transports so configuration (the CLI's --transport option, Laravel's config/scraper.php) can select
 * them. A package that ships its own TransportInterface, AsyncTransportInterface or BrowserDriver registers a factory
 * under its own name; nothing in this package has to change:
 *
 *     $registry->register('patchright', fn (array $config) => new BrowserTransport(new PatchrightDriver($config)));
 *
 * The factory receives the configuration array of the caller (CLI options or the Laravel config) and returns
 * the base transport. Proxy and retry layering are applied afterwards by the caller.
 */
final class TransportRegistry
{
    /** @var array<string, Closure(array<string, mixed>): TransportInterface> */
    private array $factories = [];

    /** The transports that ship with the package. Optional ones fail with an install hint when used. */
    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register('guzzle', static fn (array $config): TransportInterface => new GuzzleTransport());
        $registry->register('amp', static fn (array $config): TransportInterface => new AmpTransport());
        $registry->register('rnet', static fn (array $config): TransportInterface => new RnetTransport(is_string($config['emulation'] ?? null) ? $config['emulation'] : null));
        $registry->register('impity', static fn (array $config): TransportInterface => new ImpityTransport());

        return $registry;
    }

    /** @param callable(array<string, mixed>): TransportInterface $factory */
    public function register(string $name, callable $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('A transport name must be lowercase letters, digits, "-" or "_" and start with a letter.');
        }
        $this->factories[$name] = Closure::fromCallable($factory);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->factories);
        sort($names);

        return $names;
    }

    /** @param array<string, mixed> $config */
    public function create(string $name, array $config = []): TransportInterface
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown transport "%s". Available: %s.', $name, implode(', ', $this->names())));
        }

        return ($this->factories[$name])($config);
    }
}
