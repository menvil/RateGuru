<?php

namespace App\Enums;

/** Where a postback stands in the outbox. */
enum TrafficPostbackStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Sending => 'Sending',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Expired => 'Out of time',
            self::Cancelled => 'Cancelled',
        };
    }
}
