<?php

declare(strict_types=1);

namespace Hash;

use Hash\Exception\InvalidArgumentException;
use Hash\HttpClient\CurlClient;
use Hash\HttpClient\HttpClientInterface;
use Hash\HttpClient\Psr18Client;
use Hash\Service\BalanceService;
use Hash\Service\InvoiceService;
use Hash\Service\MetaService;
use Hash\Service\WebhookDeliveryService;
use Hash\Service\WebhookEndpointService;
use Psr\Http\Client\ClientInterface;

/**
 * The Hash API client.
 *
 *     $hash = new \Hash\HashClient(getenv('HASH_SECRET_KEY'));
 *     $invoice = $hash->invoices->create(['price_usd' => '48.50', 'order_id' => '7731']);
 */
final class HashClient
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.hash.bh/api/v1';

    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_TIMEOUT = 30.0;

    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    public readonly InvoiceService $invoices;

    public readonly BalanceService $balances;

    public readonly WebhookEndpointService $webhookEndpoints;

    public readonly WebhookDeliveryService $webhookDeliveries;

    public readonly MetaService $meta;

    /** Verifies webhook requests. Also available without a client: Webhook::constructEvent(). */
    public readonly Webhooks $webhooks;

    public readonly string $userAgent;

    private readonly ?string $mode;

    /**
     * @param string $apiKey a secret key: hash_sandbox_sk_... (sandbox) or hash_live_sk_... (live). Server side only.
     * @param array{
     *     base_url?: string,
     *     max_retries?: int,
     *     timeout?: float|int,
     *     connect_timeout?: float|int,
     *     http_client?: HttpClientInterface|ClientInterface,
     *     app_info?: array{name: string, version?: string, url?: string},
     *     sleep?: callable(float): void,
     * } $options
     */
    public function __construct(#[\SensitiveParameter] string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('A Hash secret key is required, such as hash_sandbox_sk_... or hash_live_sk_...');
        }
        $this->mode = match (true) {
            str_starts_with($apiKey, 'hash_live_sk_') => 'live',
            str_starts_with($apiKey, 'hash_sandbox_sk_') => 'sandbox',
            default => null,
        };

        $maxRetries = $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
        if (!\is_int($maxRetries) || $maxRetries < 0) {
            throw new InvalidArgumentException('max_retries must be a whole number, 0 or more.');
        }
        $timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $connectTimeout = (float) ($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('timeout and connect_timeout must be more than 0 seconds.');
        }

        $userAgent = 'hash-php/' . self::VERSION . ' PHP/' . PHP_VERSION;
        $app = $options['app_info'] ?? null;
        if (\is_array($app) && ($app['name'] ?? '') !== '') {
            $userAgent .= ' ' . $app['name'] . (isset($app['version']) ? '/' . $app['version'] : '');
            if (isset($app['url'])) {
                $userAgent .= ' (+' . $app['url'] . ')';
            }
        }
        $this->userAgent = $userAgent;

        $requestor = new ApiRequestor(
            $apiKey,
            rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/'),
            self::httpClient($options['http_client'] ?? null),
            $maxRetries,
            $timeout,
            $connectTimeout,
            $userAgent,
            $options['sleep'] ?? null,
        );
        $this->invoices = new InvoiceService($requestor);
        $this->balances = new BalanceService($requestor);
        $this->webhookEndpoints = new WebhookEndpointService($requestor);
        $this->webhookDeliveries = new WebhookDeliveryService($requestor);
        $this->meta = new MetaService($requestor);
        $this->webhooks = new Webhooks();
    }

    /** `live` or `sandbox`, read from the key's prefix. Null for a key with another prefix. */
    public function mode(): ?string
    {
        return $this->mode;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['userAgent' => $this->userAgent, 'mode' => $this->mode];
    }

    /**
     * The transport: the one you pass, else ext-curl, else a PSR-18 client found by php-http/discovery.
     */
    private static function httpClient(mixed $client): HttpClientInterface
    {
        if ($client instanceof HttpClientInterface) {
            return $client;
        }
        if ($client instanceof ClientInterface) {
            if (!Psr18Client::canDiscover()) {
                throw new InvalidArgumentException('To use a PSR-18 client without php-http/discovery, wrap it: new Psr18Client($client, $requestFactory, $streamFactory).');
            }

            return Psr18Client::discover($client);
        }
        if ($client !== null) {
            throw new InvalidArgumentException('http_client must be a Hash\HttpClient\HttpClientInterface or a PSR-18 client.');
        }
        if (CurlClient::isAvailable()) {
            return new CurlClient();
        }
        if (Psr18Client::canDiscover()) {
            return Psr18Client::discover();
        }
        throw new InvalidArgumentException('No HTTP transport: enable ext-curl, pass a PSR-18 client as http_client, or install php-http/discovery and a PSR-18 client.');
    }
}
