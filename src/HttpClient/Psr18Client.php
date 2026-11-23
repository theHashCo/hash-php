<?php

declare(strict_types=1);

namespace Hash\HttpClient;

use Hash\Exception\ConnectionException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 client, such as Guzzle or Symfony HttpClient.
 *
 * PSR-18 has no per-request timeout: configure timeouts on the client you pass.
 */
final class Psr18Client implements HttpClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /** True when php-http/discovery is installed. */
    public static function canDiscover(): bool
    {
        return class_exists(Psr18ClientDiscovery::class) && class_exists(Psr17FactoryDiscovery::class);
    }

    /** Finds an installed PSR-18 client and PSR-17 factories with php-http/discovery. */
    public static function discover(?ClientInterface $client = null): self
    {
        return new self(
            $client ?? Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new ConnectionException('Could not reach Hash: ' . $e->getMessage(), false, $e);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException('The HTTP client failed: ' . $e->getMessage(), false, $e);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return new Response($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
