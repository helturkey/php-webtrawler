<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests\Support;

/**
 * Starts PHP's built-in web server with tests/Fixtures/router.php on a free loopback port.
 * Returns null (tests skip) when it cannot start, e.g. proc_open is disabled or on Windows.
 */
final class LocalServer
{
    /** @var resource */
    private $process;

    private function __construct(mixed $process, public readonly string $baseUrl)
    {
        $this->process = $process;
    }

    public static function start(): ?self
    {
        if (!function_exists('proc_open') || DIRECTORY_SEPARATOR !== '/') {
            return null;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            return null;
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $process = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Fixtures/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '16'] + getenv(),
        );
        if (!is_resource($process)) {
            return null;
        }

        for ($i = 0; $i < 60; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return new self($process, 'http://127.0.0.1:' . $port);
            }
            usleep(50_000);
        }
        proc_terminate($process);
        proc_close($process);

        return null;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
