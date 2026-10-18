<?php
declare(strict_types=1);

namespace App\Core;

/** Computes free booking slots from opening hours, slot length, chair count and existing appointments. */
final class Availability
{
    public const MIN_LEAD_MINUTES = 120;
    public const MAX_DAYS_AHEAD = 60;

    public static function defaultHours(): array
    {
        $week = [['09:00', '13:00'], ['14:00', '18:00']];
        return ['1' => $week, '2' => $week, '3' => $week, '4' => $week, '5' => $week, '6' => [['09:00', '13:00']], '7' => []];
    }

    /** @return string[] list of "HH:MM" */
    public static function slots(array $clinic, string $date): array
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return [];
        }
        $today = new \DateTimeImmutable('today');
        if ($day < $today || $day > $today->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            return [];
        }
        $hours = json_decode((string) $clinic['opening_hours'], true) ?: [];
        $ranges = $hours[$day->format('N')] ?? [];
        $step = max(10, (int) $clinic['slot_minutes']);
        $chairs = max(1, (int) $clinic['chairs']);
        $earliest = new \DateTimeImmutable('+' . self::MIN_LEAD_MINUTES . ' minutes');

        $busy = Database::all(
            "SELECT starts_at, ends_at FROM appointments
              WHERE clinic_id = ? AND status IN ('pending','confirmed') AND starts_at < ? AND ends_at > ?",
            [$clinic['id'], $day->format('Y-m-d 23:59:59'), $day->format('Y-m-d 00:00:00')]
        );

        $slots = [];
        foreach ($ranges as [$open, $close]) {
            $t = new \DateTimeImmutable("$date $open");
            $end = new \DateTimeImmutable("$date $close");
            while ($t->modify("+$step minutes") <= $end) {
                $slotEnd = $t->modify("+$step minutes");
                if ($t >= $earliest) {
                    $overlaps = 0;
                    foreach ($busy as $b) {
                        if (strtotime($b['starts_at']) < $slotEnd->getTimestamp() && strtotime($b['ends_at']) > $t->getTimestamp()) {
                            $overlaps++;
                        }
                    }
                    if ($overlaps < $chairs) {
                        $slots[] = $t->format('H:i');
                    }
                }
                $t = $slotEnd;
            }
        }
        return $slots;
    }
}
