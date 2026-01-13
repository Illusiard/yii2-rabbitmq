<?php

namespace illusiard\rabbitmq\definitions;

class DefinitionIdDeriver
{
    public static function derive(string $className, string $suffix): string
    {
        $baseName = self::baseName($className);
        if ($suffix !== '') {
            $baseName = preg_replace('/' . preg_quote($suffix, '/') . '$/', '', $baseName) ?? $baseName;
        }

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
