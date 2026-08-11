<?php

namespace App\Enums;

enum IngestionPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function queue(): string
    {
        return 'ingestion-'.$this->value;
    }

    /** @return list<string> queue names in consumption order */
    public static function queues(): array
    {
        return array_map(fn (self $case) => $case->queue(), [self::High, self::Normal, self::Low]);
    }
}
