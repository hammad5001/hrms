<?php
// Only for Dialer/QA imported reports.
// Do NOT change global PHP/MySQL/server timezone.

function get_dialer_usa_range($period = 'today') {
    $tz = new DateTimeZone('America/New_York');
    $now = new DateTime('now', $tz);

    $period = strtolower(trim((string)$period));

    if ($period === 'week' || $period === 'this_week') {
        $start = clone $now;
        $start->modify('monday this week')->setTime(0, 0, 0);

        $end = clone $start;
        $end->modify('sunday this week')->setTime(23, 59, 59);
    } elseif ($period === 'month' || $period === 'this_month') {
        $start = new DateTime($now->format('Y-m-01 00:00:00'), $tz);
        $end = new DateTime($now->format('Y-m-t 23:59:59'), $tz);
    } else {
        $start = clone $now;
        $start->setTime(0, 0, 0);

        $end = clone $now;
        $end->setTime(23, 59, 59);
    }

    return [
        'from' => $start->format('Y-m-d H:i:s'),
        'to' => $end->format('Y-m-d H:i:s'),
        'timezone' => 'America/New_York',
        'usa_today' => $now->format('Y-m-d')
    ];
}
