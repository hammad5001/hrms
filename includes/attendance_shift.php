<?php
/**
 * Night-shift attendance rules (shared by admin dashboard + employee portal).
 *
 * Shift date 8 = check-in 8th 2:00 PM – 11:59 PM + check-out 9th 12:00 AM – 11:59 AM.
 * Example: in 8th 5:00 PM, out 9th 4:00 AM → counted on shift date 8.
 */

if (!defined('ESS_SHIFT_CHECKIN_HOUR')) {
    define('ESS_SHIFT_CHECKIN_HOUR', 14);   // 2 PM — check-in window opens
    define('ESS_SHIFT_START_TIME', '19:00:00'); // 7 PM — late after grace
    define('ESS_SHIFT_GRACE_MINUTES', 15);
    define('ESS_SHIFT_CHECKOUT_NOON_HOUR', 12); // checkout window ends noon next day
}

/** Shift windows for a given shift date (YYYY-MM-DD). */
function ess_get_shift_windows(string $shiftDate): array
{
    $nextDate = date('Y-m-d', strtotime($shiftDate . ' +1 day'));

    return [
        'shift_date' => $shiftDate,
        'checkin_start' => $shiftDate . ' ' . sprintf('%02d:00:00', ESS_SHIFT_CHECKIN_HOUR),
        'checkin_end' => $shiftDate . ' 23:59:59',
        'checkout_start' => $nextDate . ' 00:00:00',
        'checkout_end' => $nextDate . ' ' . sprintf('%02d:59:59', ESS_SHIFT_CHECKOUT_NOON_HOUR - 1),
    ];
}

/**
 * Which shift date is "today" for the portal.
 * Before noon = still previous night shift; noon onward = today's shift date.
 */
function ess_active_shift_date(?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $hour = (int)date('G', $timestamp);

    if ($hour < ESS_SHIFT_CHECKOUT_NOON_HOUR) {
        return date('Y-m-d', strtotime('-1 day', $timestamp));
    }

    return date('Y-m-d', $timestamp);
}

/** Map a punch timestamp to its shift date (same rules as attendance_collector.py). */
function ess_shift_date_for_timestamp(string $timestamp): string
{
    $ts = strtotime($timestamp);
    if ($ts === false) {
        return date('Y-m-d');
    }

    $hour = (int)date('G', $ts);

    if ($hour >= ESS_SHIFT_CHECKIN_HOUR) {
        return date('Y-m-d', $ts);
    }

    if ($hour < ESS_SHIFT_CHECKOUT_NOON_HOUR) {
        return date('Y-m-d', strtotime('-1 day', $ts));
    }

    return date('Y-m-d', $ts);
}

/** First check-in and last check-out for a shift date from raw punch list. */
function ess_resolve_shift_punches(array $timestamps, string $shiftDate): array
{
    $windows = ess_get_shift_windows($shiftDate);
    $checkins = [];
    $checkouts = [];

    foreach ($timestamps as $ts) {
        if ($ts >= $windows['checkin_start'] && $ts <= $windows['checkin_end']) {
            $checkins[] = $ts;
        } elseif ($ts >= $windows['checkout_start'] && $ts <= $windows['checkout_end']) {
            $checkouts[] = $ts;
        }
    }

    sort($checkins);
    sort($checkouts);

    $checkIn = $checkins[0] ?? null;
    $checkOut = !empty($checkouts) ? $checkouts[count($checkouts) - 1] : null;
    $all = array_merge($checkins, $checkouts);
    sort($all);

    return [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'times' => $all,
        'punch_count' => count($all),
    ];
}

function ess_is_late_checkin(?string $checkIn, string $shiftDate): bool
{
    if (!$checkIn) {
        return false;
    }
    $shiftStart = strtotime($shiftDate . ' ' . ESS_SHIFT_START_TIME);
    $graceEnd = $shiftStart + (ESS_SHIFT_GRACE_MINUTES * 60);
    return strtotime($checkIn) > $graceEnd;
}

function ess_working_hours(?string $checkIn, ?string $checkOut): float
{
    if (!$checkIn || !$checkOut) {
        return 0.0;
    }
    $in = strtotime($checkIn);
    $out = strtotime($checkOut);
    if ($out < $in) {
        $out += 86400;
    }
    return round(max(0, ($out - $in) / 3600), 2);
}
