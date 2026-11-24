<?php
// Bootstrap PHPUnit: memuat autoload Composer, konstanta, dan fungsi yang diuji.
require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('SUPER_ADMIN_EMAIL')) {
    define('SUPER_ADMIN_EMAIL', 'admin@kontrakan.com'); // sama dengan config/db.php
}

require_once __DIR__ . '/../includes/auth.php';           // isSuperAdmin(), currentUserId()
require_once __DIR__ . '/../includes/hapus_pengguna.php'; // unit yang diuji
