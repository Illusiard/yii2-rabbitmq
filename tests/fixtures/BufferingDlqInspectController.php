<?php

namespace illusiard\rabbitmq\tests\fixtures;

use illusiard\rabbitmq\console\DlqInspectController;

class BufferingDlqInspectController extends DlqInspectController
{
    public string $buffer = '';

    public function stdout($string): int
    {
        $this->buffer .= $string;
        return strlen($string);
    }
}
