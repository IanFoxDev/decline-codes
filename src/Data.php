<?php

declare(strict_types=1);

namespace IanFoxDev\DeclineCodes;

/**
 * Typed reads from decoded JSON, with errors that name the field.
 *
 * @internal
 */
final class Data
{
    /**
     * @return array<mixed>
     */
    public static function file(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException(\sprintf('Cannot read %s.', $path));
        }
        $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException(\sprintf('%s is not a JSON object.', $path));
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \UnexpectedValueException(\sprintf('Field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function optionalString(array $data, string $key): ?string
    {
        return isset($data[$key]) ? self::string($data, $key) : null;
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('Field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(\sprintf('Field "%s" must be a list.', $key));
        }
        $items = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException(\sprintf('Every item of "%s" must be an object.', $key));
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('Field "%s" must be an object.', $key));
        }

        return $value;
    }
}
