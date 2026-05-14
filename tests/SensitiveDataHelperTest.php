<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\helpers\SensitiveDataHelper;
use PHPUnit\Framework\TestCase;

class SensitiveDataHelperTest extends TestCase
{
    public function testRedactsCommonSecretFormats(): void
    {
        $message = SensitiveDataHelper::redact(
            'password=secret token:abc api_key=key-1 amqp://guest:dsn-secret@localhost/?access_token=query-secret'
        );

        $this->assertStringContainsString('[redacted]', $message);
        $this->assertStringNotContainsString('secret', $message);
        $this->assertStringNotContainsString('abc', $message);
        $this->assertStringNotContainsString('key-1', $message);
        $this->assertStringNotContainsString('dsn-secret', $message);
        $this->assertStringNotContainsString('query-secret', $message);
    }
}
