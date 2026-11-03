<?php
// ====================================================================
// CONFIG.PHP — শুধু এই ফাইলটাই edit করতে হবে (index.php / admin.php ছুঁতে হবে না)
// ====================================================================
declare(strict_types=1);

// ====================================================================
// 1. GENERAL
// ====================================================================
date_default_timezone_set('Asia/Dhaka');

define('DEBUG_MODE', false);          // true দিলে error দেখাবে (শুধু সমস্যা খোঁজার সময়), live সাইটে false রাখুন
ini_set('display_errors', DEBUG_MODE ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(DEBUG_MODE ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE));

// কোনো error হলে সাদা ৫০০ পেজের বদলে পরিষ্কার মেসেজ। DEBUG_MODE = true করলে আসল error দেখাবে।
set_exception_handler(function (Throwable $e) {
    error_log('[store] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    while (ob_get_level()) { @ob_end_clean(); }
    echo DEBUG_MODE
        ? '<pre style="white-space:pre-wrap;padding:16px;font:13px monospace">' . htmlspecialchars((string)$e) . '</pre>'
        : '<div style="font-family:sans-serif;text-align:center;padding:60px 16px"><h2>Something went wrong</h2><p>Please try again in a moment.</p></div>';
    exit;
});

// ====================================================================
// 2. STORE IDENTITY
// ====================================================================
define('SITE_NAME',   'VAI PANEL');          // স্টোরের নাম (admin → Site Settings থেকেও বদলানো যায়)
define('MAIN_DOMAIN', 'yourdomain.com');     // আপনার ডোমেইন (https:// ছাড়া)

$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$__host  = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? MAIN_DOMAIN)));
define('SITE_URL', ($__https ? 'https://' : 'http://') . $__host . '/');

// ====================================================================
// 3. DATABASE  (cPanel → MySQL Databases থেকে নেওয়া)
// ====================================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// ====================================================================
// 4. ADMIN PANEL LOGIN  →  yourdomain.com/admin.php
// ====================================================================
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'ChangeThisPassword@123');   // ⚠️ অবশ্যই বদলে নিন
// 🔒 আরও নিরাপদ: পাসওয়ার্ড hash করে বসাতে পারেন। নিচের লাইনটি একটা php ফাইলে চালিয়ে hash নিন:
//    echo password_hash('আপনার-পাসওয়ার্ড', PASSWORD_DEFAULT);
//    তারপর ADMIN_PASSWORD এর জায়গায় সেই '$2y$10$...' লেখাটা বসান (admin.php নিজেই চিনে নেবে)।
define('ADMIN_ALLOWED_IPS', []);      // চাইলে শুধু নির্দিষ্ট IP থেকে অ্যাডমিন খুলবে, যেমন: ['103.12.34.56']  ([] = সব IP)

// ====================================================================
// 5. SUPPORT & PAYMENT NUMBERS (প্রথমবার ডাটাবেজ খালি থাকলে এগুলো দিয়ে সেটআপ হবে;
//    পরে admin → Payment Gateways থেকে বদলানো যায়)
// ====================================================================
define('SUPPORT_LINK',  '');                     // কাস্টমার সাপোর্ট লিংক (Telegram / WhatsApp / Messenger — যেকোনো URL)
define('BKASH_NUMBER',  '01700000000');
define('NAGAD_NUMBER',  '01800000000');
define('ROCKET_NUMBER', '01900000000');

// ====================================================================
// 6. KEY MANAGER / RESET API (admin → Key Manager API থেকেও সেট করা যায়)
// ====================================================================
define('KM_DEFAULT_API_URL', '');
define('KM_DEFAULT_API_KEY', '');
define('RESET_API_URL',      'https://keymanager.shop/api.php');
define('RESET_API_KEY',      '');

// ====================================================================
// 7. OPTIONAL
// ====================================================================
define('FIREBASE_URL', '');           // Firebase Realtime DB URL (লাগলে)

// ====================================================================
// 8. SESSION (এক ডোমেইন — ৩০ দিন লগইন থাকবে)
// ====================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => $__https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ====================================================================
// 9. DATABASE CONNECTION (PDO) — টেবিল তৈরি হয় database.sql ইম্পোর্ট করলে
// ====================================================================
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // কিছু হোস্টে MySQL strict mode (ONLY_FULL_GROUP_BY ইত্যাদি) চালু থাকে — এতে ৫০০ error আসে। এটা বন্ধ রাখা হলো।
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'",
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die(DEBUG_MODE ? 'Database error: ' . htmlspecialchars($e->getMessage())
                   : 'Database connection failed. config.php এ DB_NAME / DB_USER / DB_PASS ঠিক আছে কিনা দেখুন।');
}
