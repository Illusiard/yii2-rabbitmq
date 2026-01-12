<?php

namespace illusiard\rabbitmq\consumer;

class ConsumerIdDeriver
{
    public static function derive(string $className): string
    {
        $baseName = self::baseName($className);
        $baseName = preg_replace('/Consumer$/', '', $baseName) ?? $baseName;

        if ($baseName === '') {
            return '';
        }

        $kebab = preg_replace('/(?<!^)[A-Z]/', '-$0', $baseName) ?? $baseName;

        return strtolower($kebab);
    }

    private static function baseName(string $className): string
    {
        $pos = strrpos($className, '\\');
        if ($pos === false) {
            return $className;
        }

        return substr($className, $pos + 1);
    }
}
