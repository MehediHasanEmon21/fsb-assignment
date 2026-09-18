<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use DomainException;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function endingAt(CarbonImmutable $startsAt): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $startsAt->addMonthNoOverflow(),
            self::Yearly => $startsAt->addYearNoOverflow(),
        };
    }

    public static function endingAtFor(string $interval, CarbonImmutable $startsAt): CarbonImmutable
    {
        return self::tryFrom($interval)?->endingAt($startsAt)
            ?? throw new DomainException('The selected plan has an unsupported billing interval.');
    }
}
