<?php

namespace illusiard\rabbitmq\helpers;

class SensitiveDataHelper
{
    private const REDACTED = '[redacted]';

    public static function redact(string $value): string
    {
        $replacements = [
            '/([?&](?:password|passwd|pwd|secret|token|access_token|api_key)=)[^&\s]+/i' => '$1' . self::REDACTED,
            '/((?:password|passwd|pwd|secret|token|access_token|api_key)\s*[=:]\s*)[^\s,;]+/i' => '$1' . self::REDACTED,
            '#(amqps?://[^:\s/@]+:)[^@\s/]+(@)#i' => '$1' . self::REDACTED . '$2',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }
}
