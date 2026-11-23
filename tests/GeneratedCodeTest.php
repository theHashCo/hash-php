<?php

declare(strict_types=1);

namespace Hash\Tests;

use Hash\Dev\Generator;
use Hash\Model\Balances;
use Hash\Model\Invoice;
use Hash\Model\WebhookDelivery;
use Hash\Model\WebhookEndpoint;
use Hash\Model\WebhookEndpointWithSecret;
use Hash\Model\WebhookSourceIps;
use Hash\Service\Operations;
use Hash\Tests\Support\Spec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedCodeTest extends TestCase
{
    public function testMatchesAFreshRunOfTheGenerator(): void
    {
        $root = \dirname(__DIR__);
        $files = (new Generator(Spec::get()))->generate();
        foreach ($files as $rel => $content) {
            self::assertFileExists("$root/$rel", 'Run composer generate.');
            self::assertSame($content, file_get_contents("$root/$rel"), "$rel is out of date: run composer generate.");
        }
        foreach (['src/Enum', 'src/Model', 'src/Service'] as $dir) {
            foreach (glob("$root/$dir/*.php") ?: [] as $abs) {
                $rel = substr($abs, \strlen($root) + 1);
                if (str_contains((string) file_get_contents($abs), Generator::MARKER)) {
                    self::assertArrayHasKey($rel, $files, "$rel is no longer generated: run composer generate.");
                }
            }
        }
    }

    public function testKnowsEveryOperationWithTheSpecsMethodAndPath(): void
    {
        foreach (Spec::operations() as $o) {
            self::assertArrayHasKey($o['id'], Operations::ALL);
            self::assertSame($o['method'], Operations::ALL[$o['id']]['method']);
            self::assertSame($o['path'], Operations::ALL[$o['id']]['path']);
        }
        self::assertCount(\count(Spec::operations()), Operations::ALL);
    }

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function examples(): array
    {
        $out = [];
        foreach (Spec::operations() as $o) {
            foreach ($o['op']['responses'] as $code => $r) {
                $c = $r['content']['application/json'] ?? null;
                if ($c !== null && \array_key_exists('example', $c)) {
                    $out["{$o['id']} $code"] = [$o['id'], $c['schema'], ['paths', $o['path'], strtolower($o['method']), 'responses', (string) $code, 'content', 'application/json', 'example']];
                }
            }
            $rb = $o['op']['requestBody']['content']['application/json'] ?? null;
            if ($rb !== null && \array_key_exists('example', $rb)) {
                $out["{$o['id']} request"] = [$o['id'], $rb['schema'], ['paths', $o['path'], strtolower($o['method']), 'requestBody', 'content', 'application/json', 'example']];
            }
        }
        foreach (Spec::get()['webhooks'] as $type => $item) {
            $c = $item['post']['requestBody']['content']['application/json'];
            if (\array_key_exists('example', $c)) {
                $out["webhook $type"] = [(string) $type, $c['schema'], ['webhooks', (string) $type, 'post', 'requestBody', 'content', 'application/json', 'example']];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $examplePath
     */
    #[DataProvider('examples')]
    public function testTheSpecsExamplesMatchTheirSchemas(string $name, array $schema, array $examplePath): void
    {
        self::assertSame([], Spec::validate($schema, Spec::rawValue($examplePath)), $name);
    }

    public function testEveryResponseExampleHydratesIntoItsModel(): void
    {
        $models = [
            'Invoice' => Invoice::class,
            'Balances' => Balances::class,
            'WebhookEndpoint' => WebhookEndpoint::class,
            'WebhookEndpointWithSecret' => WebhookEndpointWithSecret::class,
            'WebhookDelivery' => WebhookDelivery::class,
            'WebhookSourceIps' => WebhookSourceIps::class,
        ];
        $count = 0;
        foreach (Spec::operations() as $o) {
            foreach ($o['op']['responses'] as $code => $r) {
                $c = $r['content']['application/json'] ?? null;
                if ($c === null || !\array_key_exists('example', $c) || !preg_match('/^2/', (string) $code)) {
                    continue;
                }
                $ref = basename((string) ($c['schema']['$ref'] ?? ''));
                if (isset($models[$ref])) {
                    $model = $models[$ref]::fromArray($c['example']);
                    self::assertSame($c['example'], $model->toArray());
                    ++$count;
                }
            }
        }
        self::assertGreaterThan(10, $count);
    }
}
