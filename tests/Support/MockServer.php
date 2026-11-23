<?php

declare(strict_types=1);

namespace Hash\Tests\Support;

/** Runs tests/Support/router.php under `php -S` on a free local port, once per test run. */
final class MockServer
{
    private static ?self $instance = null;

    /** @var resource|null */
    private $process = null;

    public readonly State $state;

    public readonly string $url;

    private function __construct()
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'hash-mock-');
        if ($stateFile === false) {
            throw new \RuntimeException('Cannot create the mock state file.');
        }
        $this->state = new State($stateFile);
        $this->state->reset();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot find a free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $env = array_merge(getenv(), ['HASH_MOCK_STATE' => $stateFile, 'PHP_CLI_SERVER_WORKERS' => '1']);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-S', "127.0.0.1:$port", __DIR__ . '/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', sys_get_temp_dir() . '/hash-mock-server.log', 'a']],
            $pipes,
            null,
            $env,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot start the mock server.');
        }
        $this->process = $process;
        $this->url = "http://127.0.0.1:$port/api/v1";

        for ($i = 0; $i < 100; ++$i) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('The mock server did not start.');
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function shutdown(): void
    {
        if (self::$instance?->process !== null) {
            proc_terminate(self::$instance->process);
            proc_close(self::$instance->process);
            self::$instance->process = null;
        }
    }

    public function reset(): void
    {
        $this->state->reset();
    }

    /** @param array{status: int, body?: mixed, raw?: string, headers?: array<string, string>, delay?: float} ...$answers */
    public function enqueue(array ...$answers): void
    {
        $this->state->update(static function (array $s) use ($answers): array {
            $s['queue'] = array_merge($s['queue'], $answers);

            return $s;
        });
    }

    /** @return list<array{method: string, uri: string, path: string, query: string, headers: array<string, string>, body: string, operation: string|null}> */
    public function requests(): array
    {
        /** @var list<array{method: string, uri: string, path: string, query: string, headers: array<string, string>, body: string, operation: string|null}> */
        return $this->state->read()['requests'];
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->state->read()['problems'];
    }
}
