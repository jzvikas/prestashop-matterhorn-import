<?php
namespace Lp\MatterhornImport\Util;

final class CommandInput
{
    public static function positiveInt(mixed $value, string $name, int $max = PHP_INT_MAX): int
    {
        return self::parseInt($value, $name, 1, $max);
    }
    public static function nonNegativeInt(mixed $value, string $name, int $max = PHP_INT_MAX): int
    {
        return self::parseInt($value, $name, 0, $max);
    }
    public static function optionalPositiveInt(mixed $value, string $name, int $max = PHP_INT_MAX): ?int
    {
        return $value === null ? null : self::positiveInt($value, $name, $max);
    }
    private static function parseInt(mixed $value, string $name, int $min, int $max): int
    {
        if (is_int($value)) { $parsed = $value; }
        else {
            if (!is_string($value) || $value === '' || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
                throw new \InvalidArgumentException($name . ' must be an integer between ' . $min . ' and ' . $max);
            }
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($parsed === false) {
                throw new \InvalidArgumentException($name . ' must be an integer between ' . $min . ' and ' . $max);
            }
        }
        if ($parsed < $min || $parsed > $max) {
            throw new \InvalidArgumentException($name . ' must be an integer between ' . $min . ' and ' . $max);
        }
        return (int) $parsed;
    }
}
