<?php
// ====================================================================
// CONFIG.PHP — শুধু এই ফাইলটাই edit করতে হবে
// ====================================================================

// ---- 1. Database (cPanel → MySQL Databases থেকে নেওয়া) --------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// ---- 2. Timezone ----------------------------------------------------
date_default_timezone_set('Asia/Dhaka');

// ---- 3. Error display (live site এ false থাকবে) ---------------------
define('DEBUG', false);
ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(DEBUG ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Site name, logo, color, support link, maintenance text — এগুলো
// admin panel → Site Settings থেকে বদলাবেন, এখানে না।
