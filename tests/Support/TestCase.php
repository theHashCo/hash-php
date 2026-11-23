<?php

declare(strict_types=1);

namespace Hash\Tests\Support;

use Hash\HashClient;

/** A test against the mock server. Fails a test that sent a request the spec does not allow. */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const KEY = 'hash_sandbox_sk_a1B2c3D4e5F6g7H8i9J0a1B2c3D4e5F6g7H8i9J0';

    protected MockServer $server;

    /** @var list<float> */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->server = MockServer::instance();
        $this->server->reset();
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        $problems = $this->server->problems();
        $this->server->reset();
        self::assertSame([], $problems, 'The mock server saw requests the spec does not allow.');
    }

    /** @param array<string, mixed> $options */
    protected function client(array $options = []): HashClient
    {
        return new HashClient(self::KEY, $options + [
            'base_url' => $this->server->url,
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    /** @return array<string, mixed> */
    protected static function example(string $operationId): array
    {
        foreach (Spec::operations() as $o) {
            if ($o['id'] === $operationId) {
                $body = Spec::successExample($o['op'])['body'];
                if (!\is_array($body)) {
                    throw new \RuntimeException('Example is not an object.');
                }

                /** @var array<string, mixed> $body */
                return $body;
            }
        }
        throw new \RuntimeException("No operation $operationId");
    }
}
