<?php
declare(strict_types=1);
// One-time web installer. Creates config.local.php, imports schema.sql, creates the admin account.
// After success it writes install.lock and refuses to run again.
$root = __DIR__;
if (is_file($root . '/install.lock')) { http_response_code(403); exit('Already installed. Delete install.lock only if you really want to reinstall.'); }
session_start();
if (empty($_SESSION['ic'])) $_SESSION['ic'] = bin2hex(random_bytes(16));

$err = ''; $ok = false;
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'admin_user' => 'admin', 'admin_email' => '', 'site_name' => 'Ff Store', 'demo' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = trim((string)$_POST[$k]);
    $v['demo'] = isset($_POST['demo']) ? '1' : '0';
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    try {
        if (!hash_equals($_SESSION['ic'], (string)($_POST['ic'] ?? ''))) throw new Exception('Session expired, reload the page.');
        if ($v['db_name'] === '' || $v['db_user'] === '') throw new Exception('Database name and user are required.');
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $v['admin_user'])) throw new Exception('Admin username: 3-32 letters/numbers/underscore.');
        if (!filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Valid admin email required.');
        if (strlen($adminPass) < 8) throw new Exception('Admin password must be at least 8 characters.');

        $pdo = new PDO("mysql:host={$v['db_host']};dbname={$v['db_name']};charset=utf8mb4", $v['db_user'], $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        // schema
        $sql = (string)file_get_contents($root . '/schema.sql');
        $sql = preg_replace('~^\s*--.*$~m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);

        // admin
        $st = $pdo->prepare("INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?, 'admin')
                             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin'");
        $st->execute([$v['admin_user'], $v['admin_email'], password_hash($adminPass, PASSWORD_DEFAULT)]);

        $set = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $set->execute(['site_name', $v['site_name'] ?: 'Ff Store']);
        $set->execute(['footer_text', '© ' . date('Y') . ' ' . ($v['site_name'] ?: 'Ff Store') . '. All rights reserved.']);

        if ($v['demo'] === '1' && !(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
            $pdo->exec("INSERT INTO categories (name, sort_order) VALUES ('Android',1),('iOS',2),('Root',3)");
            $pdo->exec("INSERT INTO products (category_id,name,subtitle,tag,discount_percent,is_active,is_maintenance,maintenance_msg,sort_order) VALUES
                (1,'Demo Product Android','🔥 Instant Delivery ✅','ANDROID',5,1,0,NULL,1),
                (3,'Demo Product Root','🔥 Instant Delivery ✅','ROOT',0,1,0,NULL,2),
                (2,'Demo Product iOS','🔥 Instant Delivery ✅','IOS',0,1,1,'এই প্রোডাক্টটির সার্ভার আপগ্রেড চলছে। সাময়িক অসুবিধার জন্য আমরা দুঃখিত। খুব শীঘ্রই সার্ভিস চালু করা হবে।',3)");
            $pdo->exec("INSERT INTO product_plans (product_id,title,duration_days,price,sort_order) VALUES
                (1,'1 Day',1,50,1),(1,'7 Days',7,149,2),(1,'30 Days',30,399,3),(2,'7 Days',7,149,1),(2,'30 Days',30,449,2),(3,'1 Day',1,60,1)");
            $pdo->exec("INSERT INTO payment_methods (name,account_no,account_type,instructions,sort_order) VALUES
                ('bKash','01XXXXXXXXX','Personal','Send Money to this number, then submit the Transaction ID.',1),
                ('Nagad','01XXXXXXXXX','Personal','Send Money to this number, then submit the Transaction ID.',2)");
            $pdo->exec("INSERT INTO announcements (message) VALUES ('Welcome! Add funds and get your key instantly.')");
        }

        $cfg = "<?php\nreturn " . var_export([
            'db_host' => $v['db_host'], 'db_name' => $v['db_name'], 'db_user' => $v['db_user'], 'db_pass' => $dbPass,
        ], true) . ";\n";
        if (file_put_contents($root . '/config.local.php', $cfg, LOCK_EX) === false) throw new Exception('Could not write config.local.php (check folder permissions).');
        @chmod($root . '/config.local.php', 0640);
        file_put_contents($root . '/install.lock', date('c'));
        $ok = true;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — Store</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{--p:#2563eb;--a:#f43f5e;--bg:#eef2ff;--tx:#0f172a;--mu:#64748b}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font-family:Outfit,system-ui,sans-serif;background:radial-gradient(900px 500px at 10% -10%,#c7d2fe,transparent),radial-gradient(800px 500px at 100% 110%,#fecdd3,transparent),var(--bg);color:var(--tx)}
.card{width:100%;max-width:520px;background:#fff;border-radius:24px;padding:30px;box-shadow:0 25px 60px -20px rgba(37,99,235,.35)}
h1{margin:0 0 4px;font-size:26px}p.s{margin:0 0 20px;color:var(--mu)}
label{display:block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mu);margin:14px 0 6px}
input[type=text],input[type=email],input[type=password]{width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font:inherit;outline:0}
input:focus{border-color:var(--p);box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}h2{font-size:14px;margin:22px 0 0;color:var(--p);text-transform:uppercase;letter-spacing:.08em}
button{margin-top:22px;width:100%;padding:14px;border:0;border-radius:14px;font:inherit;font-weight:800;color:#fff;cursor:pointer;background:linear-gradient(135deg,var(--p),#7c3aed)}
.err{background:#fff1f2;color:#be123c;border:1px solid #fecdd3;padding:12px;border-radius:12px;margin-bottom:10px;font-size:14px}
.ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;padding:16px;border-radius:14px}
a.b{display:inline-block;margin-top:10px;padding:10px 16px;border-radius:10px;background:var(--p);color:#fff;text-decoration:none;font-weight:700}
.chk{display:flex;gap:8px;align-items:center;margin-top:16px;font-size:14px}
</style></head><body><div class="card">
<h1>⚡ Store Installer</h1><p class="s">One-time setup — database, admin account &amp; sample data.</p>
<?php if ($ok): ?>
  <div class="ok"><b>Installation complete 🎉</b><br>Log in on the home page with your admin account, then open <b>/admin.php</b>.<br>
  <a class="b" href="index.php">Open store</a> <a class="b" style="background:#0f172a" href="admin.php">Open admin</a></div>
<?php else: ?>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="ic" value="<?= htmlspecialchars($_SESSION['ic']) ?>">
    <h2>Database</h2>
    <div class="row"><div><label>Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($v['db_host']) ?>"></div>
    <div><label>Database name</label><input type="text" name="db_name" value="<?= htmlspecialchars($v['db_name']) ?>" required></div></div>
    <div class="row"><div><label>DB user</label><input type="text" name="db_user" value="<?= htmlspecialchars($v['db_user']) ?>" required></div>
    <div><label>DB password</label><input type="password" name="db_pass"></div></div>
    <h2>Admin account</h2>
    <div class="row"><div><label>Username</label><input type="text" name="admin_user" value="<?= htmlspecialchars($v['admin_user']) ?>" required></div>
    <div><label>Email</label><input type="email" name="admin_email" value="<?= htmlspecialchars($v['admin_email']) ?>" required></div></div>
    <label>Password (min 8)</label><input type="password" name="admin_pass" required minlength="8">
    <label>Site name</label><input type="text" name="site_name" value="<?= htmlspecialchars($v['site_name']) ?>">
    <label class="chk"><input type="checkbox" name="demo" <?= $v['demo'] === '1' ? 'checked' : '' ?>> Add demo products (incl. one in maintenance)</label>
    <button>Install now</button>
  </form>
<?php endif; ?>
</div></body></html>
