<?php
// Copy to config.php and fill with the SAME database used by PHPNuxBill.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'phpnuxbill',
        'user' => 'USERNAME_DB',
        'pass' => 'PASSWORD_DB',
        'charset' => 'utf8mb4',
    ],
    // Base URL of the existing PHPNuxBill installation, no trailing slash.
    // The callback stays in PHPNuxBill so its existing webhook/recharge code remains authoritative.
    'billing_base_url' => 'LINK_BILLING',
    'app_name' => 'VIA QRIS',
    'timezone' => 'Asia/Jakarta',
    'session_name' => 'VIAQRISSESSID',
];
