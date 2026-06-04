<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/company_branches.php';

$list = [];
foreach (COMPANY_BRANCHES as $key => $meta) {
    $list[] = [
        'key'   => $key,
        'label' => $meta['label'],
        'short' => $meta['short'],
        'color' => $meta['color'],
    ];
}

echo json_encode(['success' => true, 'branches' => $list]);
