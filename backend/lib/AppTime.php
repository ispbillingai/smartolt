<?php
/** Existing database timestamps use Nairobi time. User preferences affect display and input only. */
final class AppTime
{
    public const STORAGE_ZONE = 'Africa/Nairobi';
    private static string $displayZone = self::STORAGE_ZONE;

    public static function setDisplayZone(string $zone): void
    {
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Choose a valid time zone.');
        self::$displayZone = $zone;
    }

    public static function zone(): string { return self::$displayZone; }

    public static function format(string $format, $value = null): string
    {
        if ($value === null) $value = time();
        $date = is_int($value) ? new DateTimeImmutable('@' . $value)
            : new DateTimeImmutable((string)$value, new DateTimeZone(self::STORAGE_ZONE));
        return $date->setTimezone(new DateTimeZone(self::$displayZone))->format($format);
    }

    public static function timestamp(string $stored): int
    {
        return (new DateTimeImmutable($stored, new DateTimeZone(self::STORAGE_ZONE)))->getTimestamp();
    }

    public static function toStorage(string $local): string
    {
        $format = strlen($local) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat($format, $local, new DateTimeZone(self::$displayZone));
        if (!$date || $date->format(substr($format, 1)) !== $local) throw new InvalidArgumentException('Enter a valid date and time.');
        return $date->setTimezone(new DateTimeZone(self::STORAGE_ZONE))->format('Y-m-d H:i:s');
    }

    public static function dayBoundary(string $day, bool $nextDay = false): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone(self::$displayZone));
        if (!$date || $date->format('Y-m-d') !== $day) throw new InvalidArgumentException('Enter a valid date.');
        if ($nextDay) $date = $date->modify('+1 day');
        return $date->setTimezone(new DateTimeZone(self::STORAGE_ZONE))->format('Y-m-d H:i:s');
    }
}
