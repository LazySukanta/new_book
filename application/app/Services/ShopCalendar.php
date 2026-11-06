<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ShopCalendar
{
    public static function localDate(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone('Asia/Kolkata')->format('Y-m-d');
    }

    public static function financialYear(DateTimeInterface $instant): string
    {
        $date = CarbonImmutable::instance($instant)->setTimezone('Asia/Kolkata');
        $start = $date->year - ($date->month < 4 ? 1 : 0);

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }
}
