<?php

declare(strict_types=1);

namespace Hash\Model;

use Hash\Exception\UnexpectedValueException;
use JsonSerializable;

/**
 * The base of every model. A model is read only: its typed properties mirror the API's fields
 * (with the API's own names), and toArray() returns the object exactly as Hash sent it,
 * including fields this version of the SDK does not know yet.
 */
abstract class AbstractModel implements JsonSerializable
{
    /** @param array<string, mixed> $raw */
    protected function __construct(private readonly array $raw)
    {
    }

    /** @param array<string, mixed> $data */
    abstract public static function fromArray(array $data): static;

    /** @return array<string, mixed> the object as Hash sent it */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** @param array<string, mixed> $data */
    private static function required(array $data, string $key): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new UnexpectedValueException(sprintf('%s: Hash sent no "%s".', static::shortName(), $key));
        }

        return $data[$key];
    }

    private static function wrongType(string $key, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('%s: "%s" should be %s, got %s.', static::shortName(), $key, $expected, get_debug_type($value)));
    }

    protected static function shortName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    /** @param array<string, mixed> $data */
    protected static function string(array $data, string $key): string
    {
        $v = self::required($data, $key);
        if (!is_string($v)) {
            throw self::wrongType($key, 'a string', $v);
        }

        return $v;
    }

    /** @param array<string, mixed> $data */
    protected static function nullableString(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;

        return $v === null ? null : self::string($data, $key);
    }

    /** @param array<string, mixed> $data */
    protected static function int(array $data, string $key): int
    {
        $v = self::required($data, $key);
        if (!is_int($v)) {
            throw self::wrongType($key, 'an integer', $v);
        }

        return $v;
    }

    /** @param array<string, mixed> $data */
    protected static function nullableInt(array $data, string $key): ?int
    {
        return ($data[$key] ?? null) === null ? null : self::int($data, $key);
    }

    /** @param array<string, mixed> $data */
    protected static function float(array $data, string $key): float
    {
        $v = self::required($data, $key);
        if (!is_int($v) && !is_float($v)) {
            throw self::wrongType($key, 'a number', $v);
        }

        return (float) $v;
    }

    /** @param array<string, mixed> $data */
    protected static function nullableFloat(array $data, string $key): ?float
    {
        return ($data[$key] ?? null) === null ? null : self::float($data, $key);
    }

    /** @param array<string, mixed> $data */
    protected static function bool(array $data, string $key): bool
    {
        $v = self::required($data, $key);
        if (!is_bool($v)) {
            throw self::wrongType($key, 'a boolean', $v);
        }

        return $v;
    }

    /** @param array<string, mixed> $data */
    protected static function nullableBool(array $data, string $key): ?bool
    {
        return ($data[$key] ?? null) === null ? null : self::bool($data, $key);
    }

    /** @param array<string, mixed> $data */
    protected static function scalar(array $data, string $key): string|int|float|bool
    {
        $v = self::required($data, $key);
        if (!is_scalar($v)) {
            throw self::wrongType($key, 'a string, number or boolean', $v);
        }

        return $v;
    }

    /** @param array<string, mixed> $data */
    protected static function nullableScalar(array $data, string $key): string|int|float|bool|null
    {
        return ($data[$key] ?? null) === null ? null : self::scalar($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected static function map(array $data, string $key): array
    {
        $v = self::required($data, $key);
        if (!is_array($v)) {
            throw self::wrongType($key, 'an object', $v);
        }
        /** @var array<string, mixed> $v */
        return $v;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    protected static function nullableMap(array $data, string $key): ?array
    {
        return ($data[$key] ?? null) === null ? null : self::map($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    protected static function stringList(array $data, string $key): array
    {
        $v = self::required($data, $key);
        if (!is_array($v) || !array_is_list($v)) {
            throw self::wrongType($key, 'a list', $v);
        }
        foreach ($v as $item) {
            if (!is_string($item)) {
                throw self::wrongType($key, 'a list of strings', $v);
            }
        }
        /** @var list<string> $v */
        return $v;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>|null
     */
    protected static function nullableStringList(array $data, string $key): ?array
    {
        return ($data[$key] ?? null) === null ? null : self::stringList($data, $key);
    }

    /**
     * @template T of AbstractModel
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $class
     *
     * @return T
     */
    protected static function model(array $data, string $key, string $class): AbstractModel
    {
        $v = self::required($data, $key);
        if (!is_array($v)) {
            throw self::wrongType($key, 'an object', $v);
        }

        /** @var array<string, mixed> $v */
        return $class::fromArray($v);
    }

    /**
     * @template T of AbstractModel
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $class
     *
     * @return T|null
     */
    protected static function nullableModel(array $data, string $key, string $class): ?AbstractModel
    {
        return ($data[$key] ?? null) === null ? null : self::model($data, $key, $class);
    }

    /**
     * @template T of AbstractModel
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $class
     *
     * @return list<T>
     */
    protected static function modelList(array $data, string $key, string $class): array
    {
        $v = self::required($data, $key);
        if (!is_array($v) || !array_is_list($v)) {
            throw self::wrongType($key, 'a list', $v);
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_array($item)) {
                throw self::wrongType($key, 'a list of objects', $v);
            }
            /** @var array<string, mixed> $item */
            $out[] = $class::fromArray($item);
        }

        return $out;
    }

    /**
     * @template T of AbstractModel
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $class
     *
     * @return list<T>|null
     */
    protected static function nullableModelList(array $data, string $key, string $class): ?array
    {
        return ($data[$key] ?? null) === null ? null : self::modelList($data, $key, $class);
    }
}
