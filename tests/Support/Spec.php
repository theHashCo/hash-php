<?php

declare(strict_types=1);

namespace Hash\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** The spec, its operations, its examples, and JSON Schema validation against it. */
final class Spec
{
    private const ID = 'https://hash.test/openapi.json';

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    private static ?Validator $validator = null;

    /** @return array<string, mixed> */
    public static function get(): array
    {
        if (self::$spec === null) {
            /** @var array<string, mixed> $spec */
            $spec = json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
            self::$spec = $spec;
        }

        return self::$spec;
    }

    public static function path(): string
    {
        return \dirname(__DIR__, 2) . '/spec/openapi.json';
    }

    /**
     * @return list<array{id: string, method: string, path: string, regex: string, op: array<string, mixed>, params: list<array<string, mixed>>}>
     */
    public static function operations(): array
    {
        $spec = self::get();
        $out = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (['get', 'post', 'patch', 'put', 'delete'] as $method) {
                if (!isset($item[$method])) {
                    continue;
                }
                $op = $item[$method];
                $params = [];
                foreach (array_merge($item['parameters'] ?? [], $op['parameters'] ?? []) as $p) {
                    $params[] = isset($p['$ref']) ? $spec['components']['parameters'][basename($p['$ref'])] : $p;
                }
                $out[] = [
                    'id' => $op['operationId'],
                    'method' => strtoupper($method),
                    'path' => (string) $path,
                    'regex' => '#^' . preg_replace('/\\\\\{\w+\\\\\}/', '([^/]+)', preg_quote((string) $path, '#')) . '$#',
                    'op' => $op,
                    'params' => $params,
                ];
            }
        }

        return $out;
    }

    /**
     * The example of the operation's 2xx answer. An operation without one borrows another example of the same schema.
     *
     * @param array<string, mixed> $op
     *
     * @return array{status: int, body: mixed}
     */
    public static function successExample(array $op): array
    {
        $code = null;
        foreach (array_keys($op['responses']) as $c) {
            if (preg_match('/^2\d\d$/', (string) $c) === 1) {
                $code = (string) $c;
                break;
            }
        }
        if ($code === null) {
            throw new \RuntimeException('No 2xx response.');
        }
        $content = $op['responses'][$code]['content']['application/json'] ?? [];
        if (\array_key_exists('example', $content)) {
            return ['status' => (int) $code, 'body' => $content['example']];
        }
        $ref = $content['schema']['$ref'] ?? null;
        foreach (self::operations() as $other) {
            foreach ($other['op']['responses'] as $r) {
                $c = $r['content']['application/json'] ?? null;
                if ($c !== null && ($c['schema']['$ref'] ?? null) === $ref && \array_key_exists('example', $c)) {
                    return ['status' => (int) $code, 'body' => $c['example']];
                }
            }
        }
        throw new \RuntimeException('No example for ' . $op['operationId']);
    }

    /**
     * Validates a decoded JSON value (objects as stdClass) against a schema of the spec.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string> the problems
     */
    public static function validate(array $schema, mixed $value): array
    {
        if (self::$validator === null) {
            self::$validator = new Validator();
            self::$validator->setMaxErrors(10);
            self::$validator->parser()->setDefaultDraftVersion('2020-12');
            $resolver = self::$validator->resolver();
            if ($resolver === null) {
                throw new \RuntimeException('No resolver');
            }
            $resolver->registerRaw((string) file_get_contents(self::path()), self::ID);
        }
        $result = self::$validator->validate($value, (string) json_encode(self::absolutize($schema)));
        if ($result->isValid()) {
            return [];
        }
        $error = $result->error();
        if ($error === null) {
            return ['invalid'];
        }
        $out = [];
        foreach ((new ErrorFormatter())->format($error, true) as $path => $messages) {
            foreach ((array) $messages as $m) {
                $out[] = $path . ' ' . (\is_string($m) ? $m : (string) json_encode($m));
            }
        }

        return $out;
    }

    /**
     * A value of the spec decoded with JSON objects kept as objects (so `{}` stays an object), for validation.
     *
     * @param list<string> $path keys from the root, such as ['webhooks', 'invoice.paid', 'post', ...]
     */
    public static function rawValue(array $path): mixed
    {
        $value = json_decode((string) file_get_contents(self::path()), false, 512, JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            $value = \is_object($value) ? $value->{$key} : (\is_array($value) ? $value[(int) $key] : null);
        }

        return $value;
    }

    private static function absolutize(mixed $schema): mixed
    {
        if (!\is_array($schema)) {
            return $schema;
        }
        $out = [];
        foreach ($schema as $k => $v) {
            $out[$k] = $k === '$ref' && \is_string($v) && str_starts_with($v, '#') ? self::ID . $v : self::absolutize($v);
        }
        if ($out === []) {
            return new \stdClass();
        }

        return $out;
    }
}
