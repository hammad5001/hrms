<?php
/**
 * Night-shift attendance rules (shared by admin dashboard + employee portal).
 *
 * Shift date 10 = punches from 10th 4:00 PM through 11th 11:00 AM count on the 10th.
 * Official duty: 6:00 PM – 4:00 AM; attendance window deadline: 4:00 PM – next day 11:00 AM.
 * Example: in 10th 6:30 PM, out 11th 4:00 AM → shift date 10. Open duty auto-closes at 11:00 AM.
 */

if (!defined('ESS_SHIFT_CHECKIN_HOUR')) {
    define('ESS_SHIFT_CHECKIN_HOUR', 16);   // 4 PM — attendance window opens
    define('ESS_SHIFT_START_TIME', '18:00:00'); // 6 PM — official shift start
    define('ESS_SHIFT_LATE_TIME', '18:00:00'); // 6 PM — late if check-in after this
    define('ESS_SHIFT_GRACE_MINUTES', 15);
    define('ESS_SHIFT_CHECKOUT_END_HOUR', 11); // next-day checkout / auto-close at 11 AM
    define('ESS_SHIFT_CHECKOUT_END_TIME', '11:00:00');
}

/** Shift windows for a given shift date (YYYY-MM-DD). */
function ess_get_shift_windows(string $shiftDate): array
{
    $nextDate = date('Y-m-d', strtotime($shiftDate . ' +1 day'));

    return [
        'shift_date' => $shiftDate,
        'window_start' => $shiftDate . ' ' . sprintf('%02d:00:00', ESS_SHIFT_CHECKIN_HOUR),
        'window_end' => $nextDate . ' ' . ESS_SHIFT_CHECKOUT_END_TIME,
        'checkin_start' => $shiftDate . ' ' . sprintf('%02d:00:00', ESS_SHIFT_CHECKIN_HOUR),
        'checkin_end' => $shiftDate . ' 23:59:59',
        'checkout_start' => $nextDate . ' 00:00:00',
        'checkout_end' => $nextDate . ' ' . ESS_SHIFT_CHECKOUT_END_TIME,
        'auto_close_at' => $nextDate . ' ' . ESS_SHIFT_CHECKOUT_END_TIME,
    ];
}

/** Unix timestamp when an open duty for this shift date must auto-close. */
function ess_shift_auto_close_unix(string $shiftDate): int
{
    $windows = ess_get_shift_windows($shiftDate);
    return (int) strtotime($windows['auto_close_at']);
}

/**
 * Which shift date is "today" for the portal.
 * Before 11 AM = still previous night shift; 11 AM onward = today's shift date.
 */
function ess_active_shift_date(?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $hour = (int) date('G', $timestamp);

    if ($hour < ESS_SHIFT_CHECKOUT_END_HOUR) {
        return date('Y-m-d', strtotime('-1 day', $timestamp));
    }

    return date('Y-m-d', $timestamp);
}

/** Map a punch timestamp to its shift date. */
function ess_shift_date_for_timestamp(string $timestamp): string
{
    $ts = strtotime($timestamp);
    if ($ts === false) {
        return date('Y-m-d');
    }

    $hour = (int) date('G', $ts);

    if ($hour >= ESS_SHIFT_CHECKIN_HOUR) {
        return date('Y-m-d', $ts);
    }

    if ($hour < ESS_SHIFT_CHECKOUT_END_HOUR) {
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
    $lateAfter = strtotime($shiftDate . ' ' . ESS_SHIFT_LATE_TIME);
    return strtotime($checkIn) > $lateAfter;
}

/** True when the shift has not started yet (future shift date or before 4 PM on active shift). */
function ess_is_shift_upcoming(string $shiftDate, ?int $timestamp = null): bool
{
    $timestamp = $timestamp ?? time();
    $active = ess_active_shift_date($timestamp);

    if ($shiftDate > $active) {
        return true;
    }
    if ($shiftDate < $active) {
        return false;
    }

    $checkinStart = strtotime($shiftDate . ' ' . sprintf('%02d:00:00', ESS_SHIFT_CHECKIN_HOUR));

    return $timestamp < $checkinStart;
}

/**
 * Present / Absent / Late / Upcoming for one shift date — based only on fetched punches that day.
 */
function ess_attendance_status_for_shift(?string $checkIn, ?string $checkOut, string $shiftDate): array
{
    if (!$checkIn && ess_is_shift_upcoming($shiftDate)) {
        return [
            'status' => 'upcoming',
            'label' => 'Upcoming',
            'check_in' => null,
            'check_out' => $checkOut,
            'on_duty' => false,
            'is_late' => false,
        ];
    }

    if (!$checkIn) {
        return [
            'status' => 'absent',
            'label' => 'Absent',
            'check_in' => null,
            'check_out' => $checkOut,
            'on_duty' => false,
            'is_late' => false,
        ];
    }

    $isLate = ess_is_late_checkin($checkIn, $shiftDate);
    $onDuty = !$checkOut;

    return [
        'status' => $isLate ? 'late' : 'present',
        'label' => $isLate ? 'Late' : 'Present',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'on_duty' => $onDuty,
        'is_late' => $isLate,
    ];
}

/** Active shift status from raw punch list (today's shift date only). */
function ess_attendance_status_from_timestamps(array $timestamps, ?string $shiftDate = null): array
{
    $shiftDate = $shiftDate ?? ess_active_shift_date();
    $shift = ess_resolve_shift_punches($timestamps, $shiftDate);
    return ess_attendance_status_for_shift($shift['check_in'], $shift['check_out'], $shiftDate);
}

/**
 * Current duty session for the active shift.
 * Open check-in without check-out auto-closes at 11:00 AM the next calendar day.
 */
function ess_resolve_open_duty(array $timestamps, ?int $nowTs = null): array
{
    $empty = [
        'check_in' => null,
        'check_out' => null,
        'times' => [],
        'punch_count' => 0,
        'shift_date' => ess_active_shift_date($nowTs),
        'on_duty' => false,
        'auto_closed' => false,
    ];

    if (empty($timestamps)) {
        return $empty;
    }

    $nowTs = $nowTs ?? time();
    $activeDate = ess_active_shift_date($nowTs);
    $datesToScan = [$activeDate];
    $prevDate = date('Y-m-d', strtotime($activeDate . ' -1 day'));
    if ($prevDate !== $activeDate) {
        $datesToScan[] = $prevDate;
    }

    foreach ($datesToScan as $shiftDate) {
        $shift = ess_resolve_shift_punches($timestamps, $shiftDate);
        if (!$shift['check_in'] || $shift['check_out']) {
            continue;
        }

        $autoCloseAt = ess_get_shift_windows($shiftDate)['auto_close_at'];
        $autoCloseTs = strtotime($autoCloseAt);
        if ($autoCloseTs !== false && $nowTs >= $autoCloseTs) {
            return [
                'check_in' => $shift['check_in'],
                'check_out' => null,
                'auto_close_at' => $autoCloseAt,
                'times' => $shift['times'],
                'punch_count' => $shift['punch_count'],
                'shift_date' => $shiftDate,
                'on_duty' => false,
                'auto_closed' => true,
                'missing_checkout' => true,
            ];
        }

        return [
            'check_in' => $shift['check_in'],
            'check_out' => null,
            'times' => $shift['times'],
            'punch_count' => $shift['punch_count'],
            'shift_date' => $shiftDate,
            'on_duty' => true,
            'auto_closed' => false,
        ];
    }

    $shift = ess_resolve_shift_punches($timestamps, $activeDate);
    return [
        'check_in' => $shift['check_in'],
        'check_out' => $shift['check_out'],
        'times' => $shift['times'],
        'punch_count' => $shift['punch_count'],
        'shift_date' => $activeDate,
        'on_duty' => false,
        'auto_closed' => false,
    ];
}

/** Unix timestamp for a stored punch string (MySQL session timezone = Asia/Karachi). */
function ess_punch_unix(mysqli $conn, string $punchTime): int
{
    $stmt = $conn->prepare('SELECT UNIX_TIMESTAMP(?) AS ts');
    if (!$stmt) {
        return (int) strtotime($punchTime);
    }
    $stmt->bind_param('s', $punchTime);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['ts'] ?? strtotime($punchTime));
}

/**
 * Duty seconds from check-in punch → check-out punch or live now while on duty.
 * Caps at shift auto-close (11 AM next day) when still on duty past the deadline.
 */
function ess_duty_seconds(?string $checkIn, ?string $checkOut, $connOrNow = null, ?string $shiftDate = null): int
{
    if (!$checkIn) {
        return 0;
    }

    $mysqli = $connOrNow instanceof mysqli ? $connOrNow : null;
    $in = $mysqli ? ess_punch_unix($mysqli, $checkIn) : (int) strtotime($checkIn);
    if ($in <= 0) {
        return 0;
    }

    if ($checkOut) {
        $out = $mysqli ? ess_punch_unix($mysqli, $checkOut) : (int) strtotime($checkOut);
        if ($out <= 0) {
            return 0;
        }
        if ($out < $in) {
            $out += 86400;
        }
        return max(0, $out - $in);
    }

    $nowTs = time();
    if ($mysqli) {
        $clock = ess_server_clock($mysqli);
        $nowTs = (int) $clock['ts'];
    } elseif (is_int($connOrNow)) {
        $nowTs = $connOrNow;
    }

    $shiftDate = $shiftDate ?? ess_shift_date_for_timestamp($checkIn);
    $autoCloseTs = ess_shift_auto_close_unix($shiftDate);
    if ($nowTs >= $autoCloseTs) {
        $nowTs = $autoCloseTs;
    }

    if ($in > $nowTs) {
        return 0;
    }

    return max(0, $nowTs - $in);
}

/** MySQL server clock for timer sync. */
function ess_server_clock(mysqli $conn): array
{
    $res = $conn->query('SELECT UNIX_TIMESTAMP(NOW()) AS ts, NOW() AS now_str');
    $row = $res ? $res->fetch_assoc() : null;

    return [
        'ts' => (int)($row['ts'] ?? time()),
        'now_str' => $row['now_str'] ?? date('Y-m-d H:i:s'),
    ];
}

/** Elapsed on-duty seconds from check-in punch until check-out or server now. */
function ess_elapsed_seconds(?string $checkIn, ?string $checkOut, ?int $nowTs = null, ?string $shiftDate = null): int
{
    if (!$checkIn) {
        return 0;
    }

    $in = strtotime($checkIn);
    if ($in === false) {
        return 0;
    }

    $nowTs = $nowTs ?? time();

    if ($checkOut) {
        $out = strtotime($checkOut);
        if ($out === false) {
            return 0;
        }
        if ($out < $in) {
            $out += 86400;
        }
        return max(0, $out - $in);
    }

    $shiftDate = $shiftDate ?? ess_shift_date_for_timestamp($checkIn);
    $autoCloseTs = ess_shift_auto_close_unix($shiftDate);
    if ($nowTs >= $autoCloseTs) {
        $nowTs = $autoCloseTs;
    }

    if ($in > $nowTs) {
        return 0;
    }

    return max(0, $nowTs - $in);
}

function ess_working_hours(?string $checkIn, ?string $checkOut, $connOrNow = null, ?string $shiftDate = null): float
{
    $seconds = ess_duty_seconds($checkIn, $checkOut, $connOrNow, $shiftDate);
    return round($seconds / 3600, 2);
}
