<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Port of Flutter lib/src/utils/timezone.dart (MarketTimezone).
 *
 * New York wall-clock times are converted to real UTC epochs using the US DST
 * rules (second Sunday of March -> first Sunday of November). Unknown zones
 * keep the legacy "device local" behaviour (the server's default timezone
 * stands in for the device timezone).
 */
class TimezoneService
{
    public const DEFAULT_TIMEZONE = 'America/New_York';

    public static function defaultTimezone(): string
    {
        return self::DEFAULT_TIMEZONE;
    }

    public static function isNewYork(?string $tz): bool
    {
        $t = strtolower(trim((string) $tz));

        return $t === ''
            || in_array($t, ['america/new_york', 'us/eastern', 'est', 'edt'], true);
    }

    private static function nthSunday(int $year, int $month, int $nth): DateTimeImmutable
    {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $weekday = (int) $d->format('N'); // Mon=1 .. Sun=7
        $daysUntilSunday = (7 - $weekday) % 7;

        return $d->modify('+' . ($daysUntilSunday + ($nth - 1) * 7) . ' days')->setTime(0, 0);
    }

    /** UTC offset in hours for a New York wall-clock date (-5 EST, -4 EDT). */
    public static function newYorkOffsetHours(DateTimeImmutable $wallDate): int
    {
        $y = (int) $wallDate->format('Y');
        $m = (int) $wallDate->format('m');
        $dstStart = self::nthSunday($y, 3, 2);
        $dstEnd = self::nthSunday($y, 11, 1);
        $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, (int) $wallDate->format('d')));

        return ($d >= $dstStart && $d < $dstEnd) ? -4 : -5;
    }

    /** Convert a wall-clock date/time in $tz to an absolute epoch (milliseconds). */
    public static function epochForWallTime(DateTimeImmutable $date, int $hour, int $minute, ?string $tz): int
    {
        $y = (int) $date->format('Y');
        $m = (int) $date->format('m');
        $d = (int) $date->format('d');

        if (! self::isNewYork($tz)) {
            $local = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $m, $d, $hour, $minute));

            return (int) ($local->getTimestamp() * 1000);
        }

        $offset = self::newYorkOffsetHours($date);
        // Wall time = UTC + offset, therefore UTC = wall time - offset.
        $utc = gmmktime($hour - $offset, $minute, 0, $m, $d, $y);

        return (int) ($utc * 1000);
    }

    /**
     * Convert an epoch back into wall-clock fields for $tz.
     * The returned DateTime's Y/m/d/H/i are the wall-clock values in the
     * configured timezone (its absolute instant is meaningless for display).
     */
    public static function wallTimeFromEpoch(int $ms, ?string $tz): DateTimeImmutable
    {
        $utc = (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('UTC'));

        if (! self::isNewYork($tz)) {
            return $utc->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        $offset = self::newYorkOffsetHours($utc);
        $modifier = ($offset >= 0 ? '+' : '') . $offset . ' hours';

        return $utc->modify($modifier);
    }

    public static function format12FromEpoch(int $ms, ?string $tz): string
    {
        $wall = self::wallTimeFromEpoch($ms, $tz);
        $h = (int) $wall->format('H');
        $m = $wall->format('i');
        $h12 = $h % 12 === 0 ? 12 : $h % 12;

        return sprintf('%02d:%s %s', $h12, $m, $h < 12 ? 'AM' : 'PM');
    }

    public static function parseYmd(string $date): DateTimeImmutable
    {
        $parts = preg_split('/-/', $date);
        if (count($parts) < 3) {
            return new DateTimeImmutable($date);
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2]));
    }
}
