<?php

declare(strict_types=1);

namespace Hash;

use Hash\Exception\InvalidArgumentException;
use Hash\Exception\SignatureVerificationException;
use Hash\Exception\UnexpectedValueException;

/**
 * Verifies webhook requests and turns them into events, as https://docs.hash.bh/webhooks/verify describes.
 * Available as $hash->webhooks, or statically through Webhook::constructEvent().
 */
final class Webhooks
{
    /** Requests whose `t` is further than this from your clock are refused: 5 minutes. */
    public const DEFAULT_TOLERANCE = 300;

    public const SIGNATURE_HEADER = 'Hash-Signature';

    /**
     * Verifies a webhook request and returns its event.
     *
     * @param string                                          $rawBody   the body exactly as received: file_get_contents('php://input')
     * @param array<string, string|list<string>|null>|string $headers   the request headers (getallheaders(), $_SERVER or a PSR-7 getHeaders()), or the Hash-Signature value
     * @param string|list<string>                             $secret    the endpoint's signing secret (whsec_...); several to accept any of them
     * @param int                                             $tolerance seconds `t` may differ from your clock
     * @param int|null                                        $now       your clock in unix seconds; defaults to time()
     *
     * @throws SignatureVerificationException when the signature is missing, malformed, wrong or too old
     * @throws UnexpectedValueException       when the signed body is not a Hash event
     */
    public function constructEvent(string $rawBody, array|string $headers, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        $this->verifySignature($rawBody, $headers, $secret, $tolerance, $now);
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UnexpectedValueException('The webhook body is signed but is not valid JSON.', null, null, $rawBody, null, [], $e);
        }
        if (!\is_array($data)) {
            throw new UnexpectedValueException('The webhook body is signed but is not a Hash event.');
        }

        /** @var array<string, mixed> $data */
        return Event::fromArray($data);
    }

    /**
     * Like constructEvent(), without parsing the body. Returns when the request is genuine.
     *
     * @param array<string, string|list<string>|null>|string $headers
     * @param string|list<string>                             $secret
     *
     * @throws SignatureVerificationException
     */
    public function verifySignature(string $rawBody, array|string $headers, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        $secrets = array_values(array_filter((array) $secret, static fn (string $s): bool => $s !== ''));
        if ($secrets === []) {
            throw new SignatureVerificationException('No webhook signing secret was given.');
        }
        if ($tolerance < 0) {
            throw new InvalidArgumentException('tolerance must be 0 or more seconds.');
        }

        $header = \is_string($headers) ? $headers : self::signatureHeader($headers);
        if ($header === null || trim($header) === '') {
            throw new SignatureVerificationException('The request has no Hash-Signature header.');
        }
        $parsed = self::parseSignatureHeader($header);
        if ($parsed === null) {
            throw new SignatureVerificationException('The Hash-Signature header has no timestamp (t) or no signature (v1).');
        }
        [$timestamp, $signatures] = $parsed;

        $matched = false;
        foreach ($secrets as $s) {
            $expected = self::computeSignature($s, $timestamp, $rawBody);
            foreach ($signatures as $candidate) {
                if (hash_equals($expected, strtolower($candidate))) {
                    $matched = true;
                }
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException('No signature in Hash-Signature matches the body. Check the secret, and that you pass the raw body exactly as received.');
        }

        $clock = $now ?? time();
        if (abs($clock - $timestamp) > $tolerance) {
            throw new SignatureVerificationException("The Hash-Signature timestamp is more than $tolerance seconds from your clock. Check your server's clock is set by NTP.");
        }
    }

    /** HMAC-SHA256 of "<t>.<raw body>" keyed with the whole secret (whsec_ included), in lowercase hex. */
    public static function computeSignature(#[\SensitiveParameter] string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /** Makes a Hash-Signature value for a body, as Hash does. Use it to test your webhook handler. */
    public function generateTestHeader(string $rawBody, #[\SensitiveParameter] string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t=$timestamp,v1=" . self::computeSignature($secret, $timestamp, $rawBody);
    }

    /**
     * Reads `t` and every `v1` from a Hash-Signature header. Null when either is missing.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    public static function parseSignatureHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pieces = explode('=', trim($part), 2);
            if (\count($pieces) !== 2) {
                continue;
            }
            [$key, $value] = $pieces;
            if ($key === 't' && ctype_digit($value) && \strlen($value) <= 18) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return $timestamp === null || $signatures === [] ? null : [$timestamp, $signatures];
    }

    /**
     * Finds Hash-Signature in any common shape of headers: getallheaders() ("Hash-Signature"),
     * PSR-7 or Laravel (lists of values), or $_SERVER ("HTTP_HASH_SIGNATURE").
     *
     * @param array<string, string|list<string>|null> $headers
     */
    private static function signatureHeader(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);
            if (str_starts_with($normalized, 'http_')) {
                $normalized = str_replace('_', '-', substr($normalized, 5));
            }
            if ($normalized !== 'hash-signature') {
                continue;
            }
            if (\is_array($value)) {
                $value = $value[0] ?? null;
            }

            return \is_string($value) ? $value : null;
        }

        return null;
    }
}
