<?php

namespace illusiard\rabbitmq\helpers;

class ArrayHelper
{
    public static function normalizeItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        if ($items === []) {
            return [];
        }

        if (!array_is_list($items)) {
            return [$items];
        }

        return $items;
    }
}
