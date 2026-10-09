<?php

declare(strict_types=1);

return [
    'times' => array_map(mb_trim(...), explode(',', (string) env('POSTING_TIMES', '08:00,10:00,14:00,17:00,20:00,21:30'))),
    'per_day' => (int) env('POSTING_PER_DAY', 6),
    'min_gap_minutes' => (int) env('POSTING_MIN_GAP_MINUTES', 60),
    'grace_minutes' => (int) env('POSTING_GRACE_MINUTES', 30),
    'stuck_minutes' => (int) env('POSTING_STUCK_MINUTES', 60),
];
