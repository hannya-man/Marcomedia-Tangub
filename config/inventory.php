<?php

return [

    // Who gets an email when a new stock alert opens. Leave empty to show alerts in the app only.
    'alert_email' => env('STOCK_ALERT_EMAIL'),

    // Month part of a batch number, e.g. SEPT-2026-CB-1.
    'month_labels' => [
        1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAY', 6 => 'JUN',
        7 => 'JUL', 8 => 'AUG', 9 => 'SEPT', 10 => 'OCT', 11 => 'NOV', 12 => 'DEC',
    ],

    // Suggested reorder point = average daily use x (supplier lead time + safety_days).
    'safety_days' => 3,

    // How many past days of usage to average for that suggestion.
    'usage_window_days' => 30,

];
