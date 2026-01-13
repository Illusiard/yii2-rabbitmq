<?php

namespace illusiard\rabbitmq\profile;

class OptionsMerger
{
    public static function merge(array $defaults, array $overrides): array
    {
        if (self::isList($defaults) && self::isList($overrides)) {
            return self::mergeLists($defaults, $overrides);
        }

        $result = $defaults;

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($result[$key]);
                continue;
            }

            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
                continue;
            }

            $current = $result[$key];
            if (is_array($current) && is_array($value)) {
                if (self::isList($current) && self::isList($value)) {
                    $result[$key] = self::mergeLists($current, $value);
                } else {
                    $result[$key] = self::merge($current, $value);
                }
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private static function mergeLists(array $defaults, array $overrides): array
    {
        $result = $defaults;

        foreach ($overrides as $value) {
            if (!in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
