<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Redmine\RubyYaml;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Text column holding structured data. Reads both legacy Redmine (Ruby YAML) and
 * Symine (JSON) encodings, always writes JSON. Plain scalars are kept as-is.
 */
final class RedmineSerializedType extends Type
{
    public const string NAME = 'redmine_serialized';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return self::decode($value);
    }

    public static function decode(mixed $value): mixed
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }
        $value = (string) $value;
        if (RubyYaml::isYaml($value)) {
            try {
                return RubyYaml::decode($value);
            } catch (\Throwable) {
                return $value;
            }
        }
        $first = $value[0];
        if ('[' === $first || '{' === $first) {
            try {
                return json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $value;
            }
        }

        return $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return self::encode($value);
    }

    public static function encode(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_array($value)) {
            return json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
