<?php
declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_DIR . '/uploads');

// ---------------------------------------------------------------- config
$cfgFile = ROOT_DIR . '/config.local.php';
$CFG = is_file($cfgFile) ? (array)require $cfgFile : [];
$CFG += [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_name' => getenv('DB_NAME') ?: '',
    'db_user' => getenv('DB_USER') ?: '',
    'db_pass' => getenv('DB_PASS') ?: '',
];

// ---------------------------------------------------------------- session
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------- database
function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            "mysql:host={$CFG['db_host']};dbname={$CFG['db_name']};charset=utf8mb4",
            $CFG['db_user'], $CFG['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        if (is_file(ROOT_DIR . '/install.php') && !is_file(ROOT_DIR . '/install.lock')) {
            header('Location: install.php'); exit;
        }
        exit('Database connection failed. Check config.local.php');
    }
    return $pdo;
}

// ---------------------------------------------------------------- settings
function default_settings(): array {
    return [
        'site_name'          => 'Ff Store',
        'site_tagline'       => 'Instant delivery • Trusted • 24/7 support',
        'site_logo'          => '',
        'currency_symbol'    => '৳',
        'theme_primary'      => '#2563eb',
        'theme_accent'       => '#f43f5e',
        'theme_mode'         => 'light',
        'support_link'       => '',
        'support_label'      => 'Contact Admin Support',
        'footer_text'        => '© Ff Store. All rights reserved.',
        'min_deposit'        => '50',
        'max_deposit'        => '50000',
        // Store-wide maintenance
        'site_maintenance'   => '0',
        'site_maintenance_msg' => 'Store upgrade in progress. We will be back shortly.',
        // Product maintenance card + popup (all editable from admin → Site Settings)
        'maint_ribbon_text'  => 'MAINTENANCE',
        'maint_button_text'  => 'MAINTENANCE',
        'maint_popup_chip'   => 'TEMPORARILY UNAVAILABLE',
        'maint_popup_rules_title' => 'RULES & INFORMATION:',
        'maint_default_msg'  => "⚠️ Rules & Conditions:\nThe server for this product is being upgraded. We apologise for the inconvenience. Service will be back soon.",
        'maint_close_text'   => 'CLOSE',
    ];
}

function settings(bool $reload = false): array {
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = default_settings();
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = (string)$r['setting_value'];
            }
        } catch (Throwable $e) {}
    }
    return $cache;
}
function setting(string $key, string $default = ''): string {
    return settings()[$key] ?? $default;
}
function save_setting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    settings(true);
}

// ---------------------------------------------------------------- helpers
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($n): string { return setting('currency_symbol', '৳') . number_format((float)$n, 2); }
function money0($n): string { return setting('currency_symbol', '৳') . rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t);
}
function json_out(array $data, int $code = 200): never {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function log_activity(?int $uid, string $action, string $details = ''): void {
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)')
            ->execute([$uid, $action, mb_substr($details, 0, 255), client_ip()]);
    } catch (Throwable $e) {}
}

// Simple per-session rate limit (login/register brute-force guard)
function rate_limited(string $bucket, int $max, int $windowSec): bool {
    $now = time();
    $hits = array_filter($_SESSION['rl'][$bucket] ?? [], fn($t) => $t > $now - $windowSec);
    if (count($hits) >= $max) { $_SESSION['rl'][$bucket] = $hits; return true; }
    $hits[] = $now;
    $_SESSION['rl'][$bucket] = $hits;
    return false;
}

// ---------------------------------------------------------------- auth
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['uid']]);
        $row = $st->fetch();
        if ($row && $row['status'] === 'active') $u = $row;
        else unset($_SESSION['uid']);
    }
    return $u;
}
function login_user(array $row): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$row['id']]);
}

// ---------------------------------------------------------------- uploads
/** Saves an uploaded image safely. Returns relative URL (uploads/xxx.ext) or null. */
function save_image(?array $file, string $prefix = 'img'): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 3 * 1024 * 1024) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext || !@getimagesize($file['tmp_name'])) return null;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) return null;
    return 'uploads/' . $name;
}
function safe_url(string $u): string {
    $u = trim($u);
    return preg_match('#^(https?://|/|uploads/)[^\s"\'<>]*$#i', $u) ? $u : '';
}
