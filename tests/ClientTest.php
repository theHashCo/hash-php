<?php

declare(strict_types=1);

namespace Hash\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hash\Exception\InvalidArgumentException;
use Hash\HashClient;
use Hash\HttpClient\Psr18Client;
use Hash\Tests\Support\TestCase;

final class ClientTest extends TestCase
{
    public function testSendsTheUserAgentWithTheAppsName(): void
    {
        $this->client(['app_info' => ['name' => 'hash-woocommerce', 'version' => '1.2.0', 'url' => 'https://hash.bh']])->balances->retrieve();
        self::assertSame(
            'hash-php/' . HashClient::VERSION . ' PHP/' . PHP_VERSION . ' hash-woocommerce/1.2.0 (+https://hash.bh)',
            $this->server->requests()[0]['headers']['user-agent'],
        );
    }

    public function testVersionMatchesTheChangelog(): void
    {
        $changelog = (string) file_get_contents(\dirname(__DIR__) . '/CHANGELOG.md');
        self::assertStringContainsString('## [v' . HashClient::VERSION . ']', $changelog);
    }

    public function testReadsTheModeFromTheKey(): void
    {
        self::assertSame('live', (new HashClient('hash_live_sk_' . str_repeat('a', 40)))->mode());
        self::assertSame('sandbox', (new HashClient(self::KEY))->mode());
        self::assertNull((new HashClient('something_else'))->mode());
    }

    public function testRefusesAMissingKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashClient('');
    }

    public function testRefusesBadOptions(): void
    {
        foreach ([['max_retries' => -1], ['timeout' => 0], ['http_client' => new \stdClass()]] as $options) {
            try {
                /** @phpstan-ignore argument.type (deliberately wrong) */
                new HashClient(self::KEY, $options);
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(3);
    }

    public function testNeverShowsTheKey(): void
    {
        $hash = new HashClient(self::KEY);
        self::assertStringNotContainsString(self::KEY, print_r($hash, true));
        ob_start();
        var_dump($hash);
        self::assertStringNotContainsString(self::KEY, (string) ob_get_clean());
        self::assertStringNotContainsString(self::KEY, (string) json_encode($hash));
    }

    public function testAcceptsABaseUrlWithATrailingSlash(): void
    {
        $this->client(['base_url' => $this->server->url . '/'])->balances->retrieve();
        self::assertSame('/api/v1/balances', $this->server->requests()[0]['uri']);
    }

    public function testWorksThroughAPsr18Client(): void
    {
        $hash = $this->client(['http_client' => new Guzzle()]);
        $invoice = $hash->invoices->create(['price_usd' => '48.50']);
        self::assertStringStartsWith('inv_', $invoice->id);
        self::assertSame('/api/v1/invoices', $this->server->requests()[0]['uri']);
    }

    public function testWorksThroughAnExplicitPsr18Adapter(): void
    {
        $factory = new HttpFactory();
        $hash = $this->client(['http_client' => new Psr18Client(new Guzzle(), $factory, $factory)]);
        self::assertSame([], $hash->meta->webhookIps()->ipv6);
    }

    public function testMapsPsr18ErrorsToConnectionException(): void
    {
        $this->expectException(\Hash\Exception\ConnectionException::class);
        $this->client(['http_client' => new Guzzle(), 'base_url' => 'http://127.0.0.1:9/api/v1', 'max_retries' => 0])->balances->retrieve();
    }

    /** Plugins may load the SDK with nothing but its own files: no Composer packages at all. */
    public function testWorksWithoutAnyComposerPackage(): void
    {
        $src = \dirname(__DIR__) . '/src/';
        $script = <<<PHP
            spl_autoload_register(static function (string \$class): void {
                if (str_starts_with(\$class, 'Hash\\\\')) {
                    require '$src' . str_replace('\\\\', '/', substr(\$class, 5)) . '.php';
                }
            });
            \$hash = new Hash\\HashClient('%s', ['base_url' => '%s', 'max_retries' => 0]);
            \$invoice = \$hash->invoices->create(['price_usd' => '1.00']);
            \$ips = \$hash->meta->webhookIps();
            echo \$invoice->id, ' ', count(\$ips->ipv4);
            PHP;
        $file = (string) tempnam(sys_get_temp_dir(), 'hash-bare-');
        file_put_contents($file, '<?php ' . sprintf($script, self::KEY, $this->server->url));
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        unlink($file);
        self::assertSame('inv_01kaf3q7m2x9c4v8b6n1d5r0tz 1', trim($output));
    }

    public function testHasNoRuntimeDependencies(): void
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame(['php', 'ext-json'], array_keys($composer['require']));
    }

    public function testDefinesNoGlobalFunctionsOrConstants(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src')) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            self::assertMatchesRegularExpression('/^namespace Hash(\\\\\w+)*;$/m', $code, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/^function\s/m', $code, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\bdefine\(/', $code, $file->getPathname());
        }
    }
}
