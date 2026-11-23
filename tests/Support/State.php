<?php

declare(strict_types=1);

namespace Hash\Tests\Support;

/**
 * The mock server's state, shared between the test process and the server process through a
 * JSON file: queued answers, recorded requests and spec problems.
 */
final class State
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array{queue: list<array<string, mixed>>, requests: list<array<string, mixed>>, problems: list<string>} */
    public function read(): array
    {
        $raw = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        $data = $raw === '' ? null : json_decode($raw, true);

        /** @var array{queue: list<array<string, mixed>>, requests: list<array<string, mixed>>, problems: list<string>} */
        return \is_array($data) ? $data : ['queue' => [], 'requests' => [], 'problems' => []];
    }

    /** @param callable(array{queue: list<array<string, mixed>>, requests: list<array<string, mixed>>, problems: list<string>}): array<string, mixed> $change */
    public function update(callable $change): void
    {
        $fh = fopen($this->file, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$this->file}");
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = $raw === '' || $raw === false ? null : json_decode($raw, true);
        $state = \is_array($data) ? $data : ['queue' => [], 'requests' => [], 'problems' => []];
        /** @var array{queue: list<array<string, mixed>>, requests: list<array<string, mixed>>, problems: list<string>} $state */
        $new = $change($state);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($new, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    public function reset(): void
    {
        $this->update(static fn (): array => ['queue' => [], 'requests' => [], 'problems' => []]);
    }
}
