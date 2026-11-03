<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/* Single admin area. No separate admin password: you sign in on the store with an
   account whose role is "admin" and this page simply opens. */
$me = current_user();
if (!$me || $me['role'] !== 'admin') { header('Location: index.php?login=1'); exit; }

$pdo = db();
$S = settings();
$pages = [
    'dashboard' => ['📊', 'Dashboard'], 'products' => ['🛍️', 'Products'], 'stock' => ['🔑', 'Stock Keys'],
    'orders' => ['🧾', 'Orders'], 'deposits' => ['💳', 'Deposits'], 'users' => ['👥', 'Users'],
    'payments' => ['🏦', 'Payment Methods'], 'coupons' => ['🎟️', 'Coupons'], 'banners' => ['🖼️', 'Banners'],
    'announce' => ['📢', 'Announcements'], 'settings' => ['⚙️', 'Site Settings'],
];
$p = (string)($_GET['p'] ?? 'dashboard');
if (!isset($pages[$p])) $p = 'dashboard';

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function back(string $p, string $extra = ''): never { header('Location: admin.php?p=' . $p . $extra); exit; }
function pstr(string $k, int $max = 255): string { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); }
function pint(string $k): int { return (int)($_POST[$k] ?? 0); }
function pflt(string $k): float { return (float)($_POST[$k] ?? 0); }
function pchk(string $k): int { return isset($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; }
function hexc(string $c, string $d): string { return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $d; }

/* ====================================================================
   ACTIONS
   ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('err', 'Session expired. Please try again.'); back($p); }
    $act = (string)($_POST['act'] ?? '');
    try {
        switch ($act) {

        /* ---------------- products ---------------- */
        case 'product_save':
            $id = pint('id'); $name = pstr('name', 150);
            if ($name === '') throw new Exception('Product name is required.');
            $cat = pint('category_id') ?: null;
            if ($newCat = pstr('new_category', 60)) {
                $pdo->prepare('INSERT IGNORE INTO categories (name) VALUES (?)')->execute([$newCat]);
                $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?'); $st->execute([$newCat]); $cat = (int)$st->fetchColumn();
            }
            $img = save_image($_FILES['image'] ?? null, 'prod');
            $maint = pchk('is_maintenance');
            $vals = [$cat, $name, pstr('subtitle', 150) ?: null, strtoupper(pstr('tag', 30)) ?: null, pstr('description', 4000) ?: null,
                     safe_url(pstr('download_link')) ?: null, max(0, min(100, pflt('discount_percent'))), pint('sort_order'),
                     pchk('is_pinned'), pchk('is_active'), $maint, pstr('maintenance_msg', 2000) ?: null];
            if ($id) {
                $pdo->prepare('UPDATE products SET category_id=?,name=?,subtitle=?,tag=?,description=?,download_link=?,discount_percent=?,sort_order=?,is_pinned=?,is_active=?,is_maintenance=?,maintenance_msg=? WHERE id=?')
                    ->execute([...$vals, $id]);
                if ($img) $pdo->prepare('UPDATE products SET image_url=? WHERE id=?')->execute([$img, $id]);
                flash('ok', 'Product updated.');
            } else {
                $pdo->prepare('INSERT INTO products (category_id,name,subtitle,tag,description,download_link,discount_percent,sort_order,is_pinned,is_active,is_maintenance,maintenance_msg,image_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals, $img]);
                flash('ok', 'Product created. Now add plans & stock.');
            }
            back('products');

        case 'product_delete':
            $pdo->prepare('DELETE FROM products WHERE id=?')->execute([pint('id')]);
            flash('ok', 'Product deleted.'); back('products');

        case 'product_maint':          // quick maintenance toggle / message edit
            $on = pchk('state'); $msg = pstr('maintenance_msg', 2000) ?: null;
            $pdo->prepare('UPDATE products SET is_maintenance=?, maintenance_msg=? WHERE id=?')->execute([$on, $msg, pint('id')]);
            flash('ok', $on ? '🔧 Product is now in MAINTENANCE.' : '✅ Product is back online.'); back('products');

        case 'plan_save':
            $pid = pint('product_id'); $title = pstr('title', 100);
            if (!$pid || $title === '') throw new Exception('Plan title is required.');
            if (pint('id')) $pdo->prepare('UPDATE product_plans SET title=?,duration_days=?,price=?,sort_order=? WHERE id=?')->execute([$title, max(1, pint('duration_days')), max(0, pflt('price')), pint('sort_order'), pint('id')]);
            else $pdo->prepare('INSERT INTO product_plans (product_id,title,duration_days,price,sort_order) VALUES (?,?,?,?,?)')->execute([$pid, $title, max(1, pint('duration_days')), max(0, pflt('price')), pint('sort_order')]);
            flash('ok', 'Plan saved.'); back('products', '#p' . $pid);

        case 'plan_delete':
            $pdo->prepare('DELETE FROM product_plans WHERE id=?')->execute([pint('id')]);
            flash('ok', 'Plan deleted.'); back('products');

        /* ---------------- stock ---------------- */
        case 'stock_add':
            $plan = pint('plan_id');
            $st = $pdo->prepare('SELECT product_id FROM product_plans WHERE id=?'); $st->execute([$plan]);
            $prod = (int)$st->fetchColumn();
            if (!$prod) throw new Exception('Choose a plan.');
            $lines = array_unique(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 255), preg_split('/\R/', (string)($_POST['keys'] ?? '')))));
            if (!$lines) throw new Exception('Paste at least one key.');
            $ins = $pdo->prepare('INSERT INTO stock_keys (product_id,plan_id,license_key) VALUES (?,?,?)');
            $pdo->beginTransaction();
            foreach ($lines as $k) $ins->execute([$prod, $plan, $k]);
            $pdo->commit();
            flash('ok', count($lines) . ' key(s) added.'); back('stock');

        case 'stock_delete':
            $pdo->prepare("DELETE FROM stock_keys WHERE id=? AND status='available'")->execute([pint('id')]);
            flash('ok', 'Key removed.'); back('stock', '&plan=' . pint('plan'));

        case 'stock_clear':
            $pdo->prepare("DELETE FROM stock_keys WHERE plan_id=? AND status='available'")->execute([pint('plan_id')]);
            flash('ok', 'All unused keys of that plan were deleted.'); back('stock');

        /* ---------------- deposits ---------------- */
        case 'deposit_approve':
        case 'deposit_reject':
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND status='pending' FOR UPDATE"); $st->execute([pint('id')]);
            $d = $st->fetch();
            if (!$d) { $pdo->rollBack(); throw new Exception('Already processed.'); }
            $ok = $act === 'deposit_approve';
            $pdo->prepare('UPDATE deposits SET status=?, admin_note=?, processed_at=NOW() WHERE id=?')->execute([$ok ? 'approved' : 'rejected', pstr('note') ?: null, $d['id']]);
            if ($ok) $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id=?')->execute([$d['amount'], $d['user_id']]);
            $pdo->commit();
            log_activity((int)$me['id'], $act, "#{$d['id']} {$d['amount']}");
            flash('ok', $ok ? 'Deposit approved & balance added.' : 'Deposit rejected.'); back('deposits');

        /* ---------------- users ---------------- */
        case 'user_balance':
            $amt = round(pflt('amount'), 2); $uid = pint('id');
            if ($amt == 0.0) throw new Exception('Enter an amount.');
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE'); $st->execute([$uid]);
            $bal = $st->fetchColumn();
            if ($bal === false) { $pdo->rollBack(); throw new Exception('User not found.'); }
            if ((float)$bal + $amt < 0) { $pdo->rollBack(); throw new Exception('Balance cannot go below zero.'); }
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id=?')->execute([$amt, $uid]);
            $pdo->commit();
            log_activity((int)$me['id'], 'balance_adjust', "user #$uid $amt");
            flash('ok', 'Balance updated.'); back('users');

        case 'user_status':
            if (pint('id') === (int)$me['id']) throw new Exception('You cannot change your own status.');
            $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([pstr('status') === 'suspended' ? 'suspended' : 'active', pint('id')]);
            flash('ok', 'User status updated.'); back('users');

        case 'user_role':
            if (pint('id') === (int)$me['id']) throw new Exception('You cannot change your own role.');
            $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([pstr('role') === 'admin' ? 'admin' : 'user', pint('id')]);
            flash('ok', 'Role updated.'); back('users');

        case 'user_password':
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 6) throw new Exception('Password must be at least 6 characters.');
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), pint('id')]);
            flash('ok', 'Password changed.'); back('users');

        /* ---------------- payment methods ---------------- */
        case 'pm_save':
            if (pstr('name', 60) === '' || pstr('account_no', 120) === '') throw new Exception('Name and account number are required.');
            $v = [pstr('name', 60), pstr('account_no', 120), pstr('account_type', 40) ?: null, pstr('instructions', 1000) ?: null, pchk('is_active'), pint('sort_order')];
            if (pint('id')) $pdo->prepare('UPDATE payment_methods SET name=?,account_no=?,account_type=?,instructions=?,is_active=?,sort_order=? WHERE id=?')->execute([...$v, pint('id')]);
            else $pdo->prepare('INSERT INTO payment_methods (name,account_no,account_type,instructions,is_active,sort_order) VALUES (?,?,?,?,?,?)')->execute($v);
            flash('ok', 'Payment method saved.'); back('payments');
        case 'pm_delete':
            $pdo->prepare('DELETE FROM payment_methods WHERE id=?')->execute([pint('id')]); flash('ok', 'Deleted.'); back('payments');

        /* ---------------- coupons ---------------- */
        case 'coupon_save':
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', pstr('code', 40)));
            if ($code === '' || pflt('value') <= 0) throw new Exception('Code and value are required.');
            $type = pstr('type') === 'fixed' ? 'fixed' : 'percent';
            $exp = pstr('expires_at') ? date('Y-m-d H:i:s', strtotime(pstr('expires_at'))) : null;
            $v = [$code, $type, $type === 'percent' ? min(100, pflt('value')) : pflt('value'), max(0, pint('max_uses')), $exp, pchk('is_active')];
            if (pint('id')) $pdo->prepare('UPDATE coupons SET code=?,type=?,value=?,max_uses=?,expires_at=?,is_active=? WHERE id=?')->execute([...$v, pint('id')]);
            else $pdo->prepare('INSERT INTO coupons (code,type,value,max_uses,expires_at,is_active) VALUES (?,?,?,?,?,?)')->execute($v);
            flash('ok', 'Coupon saved.'); back('coupons');
        case 'coupon_delete':
            $pdo->prepare('DELETE FROM coupons WHERE id=?')->execute([pint('id')]); flash('ok', 'Deleted.'); back('coupons');

        /* ---------------- banners / announcements ---------------- */
        case 'banner_save':
            $img = save_image($_FILES['image'] ?? null, 'banner');
            if (pint('id')) {
                $pdo->prepare('UPDATE banners SET link_url=?,is_active=?,sort_order=? WHERE id=?')->execute([safe_url(pstr('link_url')) ?: null, pchk('is_active'), pint('sort_order'), pint('id')]);
                if ($img) $pdo->prepare('UPDATE banners SET image_url=? WHERE id=?')->execute([$img, pint('id')]);
            } else {
                if (!$img) throw new Exception('Upload a valid image (JPG/PNG/WebP, max 3MB).');
                $pdo->prepare('INSERT INTO banners (image_url,link_url,is_active,sort_order) VALUES (?,?,?,?)')->execute([$img, safe_url(pstr('link_url')) ?: null, pchk('is_active'), pint('sort_order')]);
            }
            flash('ok', 'Banner saved.'); back('banners');
        case 'banner_delete':
            $pdo->prepare('DELETE FROM banners WHERE id=?')->execute([pint('id')]); flash('ok', 'Deleted.'); back('banners');
        case 'announce_save':
            if (pstr('message') === '') throw new Exception('Message is required.');
            if (pint('id')) $pdo->prepare('UPDATE announcements SET message=?,is_active=? WHERE id=?')->execute([pstr('message'), pchk('is_active'), pint('id')]);
            else $pdo->prepare('INSERT INTO announcements (message,is_active) VALUES (?,?)')->execute([pstr('message'), pchk('is_active')]);
            flash('ok', 'Saved.'); back('announce');
        case 'announce_delete':
            $pdo->prepare('DELETE FROM announcements WHERE id=?')->execute([pint('id')]); flash('ok', 'Deleted.'); back('announce');

        /* ---------------- site settings ---------------- */
        case 'settings_save':
            $text = ['site_name' => 80, 'site_tagline' => 160, 'footer_text' => 200, 'currency_symbol' => 5, 'support_label' => 60,
                     'site_maintenance_msg' => 500, 'maint_ribbon_text' => 30, 'maint_button_text' => 30, 'maint_popup_chip' => 40,
                     'maint_popup_rules_title' => 60, 'maint_default_msg' => 2000, 'maint_close_text' => 30];
            foreach ($text as $k => $max) if (isset($_POST[$k])) save_setting($k, pstr($k, $max));
            save_setting('support_link', safe_url(pstr('support_link')));
            save_setting('theme_primary', hexc(pstr('theme_primary', 7), '#2563eb'));
            save_setting('theme_accent', hexc(pstr('theme_accent', 7), '#f43f5e'));
            save_setting('theme_mode', in_array(pstr('theme_mode'), ['light', 'dark', 'auto'], true) ? pstr('theme_mode') : 'light');
            save_setting('min_deposit', (string)max(1, pflt('min_deposit')));
            save_setting('max_deposit', (string)max(0, pflt('max_deposit')));
            save_setting('site_maintenance', pchk('site_maintenance') ? '1' : '0');
            if ($logo = save_image($_FILES['site_logo'] ?? null, 'logo')) save_setting('site_logo', $logo);
            if (pchk('remove_logo')) save_setting('site_logo', '');
            flash('ok', 'Settings saved.'); back('settings');

        default: throw new Exception('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $m = $e instanceof PDOException ? 'Database error (duplicate or invalid data).' : $e->getMessage();
        flash('err', $m); back($p);
    }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$csrf = csrf_token();
function csrf_field(string $act, array $extra = []): string {
    $h = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="act" value="' . e($act) . '">';
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    return $h;
}
function confirm_attr(string $m): string { return ' onsubmit="return confirm(\'' . e(addslashes($m)) . '\')"'; }
function checked($v): string { return $v ? ' checked' : ''; }
function sel($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
$q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s; })();
$pendingDeps = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
$themeMode = $S['theme_mode'] === 'dark' ? 'dark' : 'light';
?><!DOCTYPE html>
<html lang="en" data-theme="<?= $themeMode ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin — <?= e($S['site_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@500;600;700&family=JetBrains+Mono:wght@700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=2"><link rel="stylesheet" href="assets/admin.css?v=2">
<style>:root{--primary:<?= e(hexc($S['theme_primary'], '#2563eb')) ?>;--accent:<?= e(hexc($S['theme_accent'], '#f43f5e')) ?>}</style>
</head>
<body class="adm">
<aside class="side" id="side">
  <div class="side-h"><span class="dot">⚡</span><div><b><?= e($S['site_name']) ?></b><small>Admin panel</small></div></div>
  <nav>
    <?php foreach ($pages as $k => [$ic, $label]): ?>
      <a href="admin.php?p=<?= $k ?>" class="<?= $k === $p ? 'on' : '' ?>"><span><?= $ic ?></span><?= e($label) ?>
        <?php if ($k === 'deposits' && $pendingDeps): ?><em><?= $pendingDeps ?></em><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="side-f"><a href="index.php" target="_blank">↗ View store</a><a href="#" id="logout">Logout (<?= e($me['username']) ?>)</a></div>
</aside>
<div class="scrim" id="scrim"></div>

<main class="main">
  <header class="bar"><button class="burger" id="burger">☰</button><h1><?= $pages[$p][0] ?> <?= e($pages[$p][1]) ?></h1></header>
  <div class="content">
  <?php if ($flash): ?><div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>

<?php /* ============================ DASHBOARD ============================ */ if ($p === 'dashboard'):
    $stats = [
      ['👥', 'Users', (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'b'],
      ['🧾', 'Orders', (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'g'],
      ['💰', 'Revenue', money0((float)$pdo->query('SELECT COALESCE(SUM(price),0) FROM orders')->fetchColumn()), 'p'],
      ['💳', 'Pending deposits', $pendingDeps, 'o'],
      ['🔑', 'Keys in stock', (int)$pdo->query("SELECT COUNT(*) FROM stock_keys WHERE status='available'")->fetchColumn(), 'c'],
      ['🔧', 'In maintenance', (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_maintenance=1')->fetchColumn(), 'r'],
    ];
    $recent = $pdo->query('SELECT o.*, u.username FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 8')->fetchAll();
    $low = $pdo->query("SELECT p.name, pl.title, (SELECT COUNT(*) FROM stock_keys k WHERE k.plan_id=pl.id AND k.status='available') s
                        FROM product_plans pl JOIN products p ON p.id=pl.product_id HAVING s < 3 ORDER BY s LIMIT 8")->fetchAll(); ?>
  <?php if ($S['site_maintenance'] === '1'): ?><div class="alert err">🔧 Store-wide maintenance is ON — customers cannot buy. <a href="admin.php?p=settings">Change</a></div><?php endif; ?>
  <div class="stats"><?php foreach ($stats as [$ic, $l, $v, $c]): ?><div class="stat <?= $c ?>"><span><?= $ic ?></span><div><small><?= e($l) ?></small><b><?= e((string)$v) ?></b></div></div><?php endforeach; ?></div>
  <div class="two">
    <section class="box"><h3>Recent orders</h3><div class="tbl"><table><tr><th>User</th><th>Product</th><th>Price</th><th>Date</th></tr>
      <?php foreach ($recent as $r): ?><tr><td><?= e($r['username']) ?></td><td><?= e($r['product_name']) ?> · <?= e($r['plan_title']) ?></td><td><?= e(money0($r['price'])) ?></td><td><?= e($r['created_at']) ?></td></tr><?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="4" class="mu">No orders yet.</td></tr><?php endif; ?></table></div></section>
    <section class="box"><h3>⚠️ Low stock</h3><div class="tbl"><table><tr><th>Product</th><th>Plan</th><th>Left</th></tr>
      <?php foreach ($low as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['title']) ?></td><td><span class="st <?= (int)$r['s'] === 0 ? 'rejected' : 'pending' ?>"><?= (int)$r['s'] ?></span></td></tr><?php endforeach; ?>
      <?php if (!$low): ?><tr><td colspan="3" class="mu">All plans are well stocked 👍</td></tr><?php endif; ?></table></div></section>
  </div>

<?php /* ============================ PRODUCTS ============================ */ elseif ($p === 'products'):
    $cats = $pdo->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll();
    $prods = $pdo->query('SELECT p.*, c.name cat FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.is_pinned DESC,p.sort_order,p.id DESC')->fetchAll();
    $plansBy = []; foreach ($pdo->query("SELECT pl.*, (SELECT COUNT(*) FROM stock_keys k WHERE k.plan_id=pl.id AND k.status='available') stock FROM product_plans pl ORDER BY pl.sort_order,pl.price") as $r) $plansBy[$r['product_id']][] = $r; ?>
  <div class="toolrow"><button class="btn btn-primary" onclick="ProdForm()">＋ Add product</button></div>
  <div class="pgrid">
  <?php foreach ($prods as $pr): $m = (int)$pr['is_maintenance'] === 1; $img = safe_url((string)$pr['image_url']); ?>
    <article class="box prod<?= $m ? ' is-maint' : '' ?>" id="p<?= (int)$pr['id'] ?>">
      <div class="ph"><?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr($pr['name'], 0, 1))) ?></span><?php endif; ?></div>
      <div class="pi">
        <b><?= e($pr['name']) ?></b>
        <div class="tags">
          <?php if ($pr['cat']): ?><span class="tg"><?= e($pr['cat']) ?></span><?php endif; ?>
          <?php if ($m): ?><span class="st rejected">Maintenance</span><?php elseif ((int)$pr['is_active']): ?><span class="st approved">Live</span><?php else: ?><span class="st pending">Hidden</span><?php endif; ?>
          <?php if ((float)$pr['discount_percent'] > 0): ?><span class="tg">-<?= e(rtrim(rtrim((string)$pr['discount_percent'], '0'), '.')) ?>%</span><?php endif; ?>
        </div>
      </div>
      <div class="acts">
        <button class="btn <?= $m ? 'btn-primary' : 'btn-warn' ?>" onclick='MaintForm(<?= json_encode(['id' => (int)$pr['id'], 'name' => $pr['name'], 'on' => $m, 'msg' => (string)$pr['maintenance_msg']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><?= $m ? '✅ Go online' : '🔧 Maintenance' ?></button>
        <button class="btn btn-ghost" onclick='ProdForm(<?= json_encode($pr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>✏️ Edit</button>
        <form method="post"<?= confirm_attr('Delete this product with all plans and stock?') ?>><?= csrf_field('product_delete', ['id' => $pr['id']]) ?><button class="btn btn-danger">🗑</button></form>
      </div>
      <details class="plans-d"><summary>Plans (<?= count($plansBy[$pr['id']] ?? []) ?>)</summary>
        <?php foreach ($plansBy[$pr['id']] ?? [] as $pl): ?>
          <form method="post" class="planrow"><?= csrf_field('plan_save', ['id' => $pl['id'], 'product_id' => $pr['id']]) ?>
            <input class="in" name="title" value="<?= e($pl['title']) ?>" placeholder="Title"><input class="in s" name="duration_days" type="number" value="<?= (int)$pl['duration_days'] ?>" title="Days">
            <input class="in s" name="price" type="number" step="0.01" value="<?= e($pl['price']) ?>" title="Price"><input type="hidden" name="sort_order" value="<?= (int)$pl['sort_order'] ?>">
            <span class="st <?= (int)$pl['stock'] ? 'approved' : 'rejected' ?>" title="Stock"><?= (int)$pl['stock'] ?></span>
            <button class="btn btn-ghost sm">Save</button>
            <button class="btn btn-danger sm" formaction="admin.php?p=products" name="act" value="plan_delete" onclick="return confirm('Delete plan and its keys?')">✕</button>
          </form>
        <?php endforeach; ?>
        <form method="post" class="planrow add"><?= csrf_field('plan_save', ['product_id' => $pr['id']]) ?>
          <input class="in" name="title" placeholder="New plan e.g. 7 Days" required><input class="in s" name="duration_days" type="number" value="7" title="Days">
          <input class="in s" name="price" type="number" step="0.01" placeholder="Price" required><button class="btn btn-primary sm">＋ Add</button>
        </form>
      </details>
    </article>
  <?php endforeach; if (!$prods): ?><div class="box mu">No products yet — click “Add product”.</div><?php endif; ?>
  </div>

  <!-- product form modal -->
  <div class="modal" id="prodModal"><div class="sheet wide"><button class="x" data-close>✕</button>
    <h2 id="pfTitle">Add product</h2>
    <form method="post" enctype="multipart/form-data" class="fgrid"><?= csrf_field('product_save') ?><input type="hidden" name="id" id="pf_id">
      <div class="field full"><label>Name *</label><input class="in" name="name" id="pf_name" required maxlength="150"></div>
      <div class="field"><label>Subtitle</label><input class="in" name="subtitle" id="pf_subtitle" placeholder="🔥 Instant Delivery ✅"></div>
      <div class="field"><label>Tag badge</label><input class="in" name="tag" id="pf_tag" placeholder="ROOT"></div>
      <div class="field"><label>Category</label><select class="in" name="category_id" id="pf_cat"><option value="">— none —</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>…or new category</label><input class="in" name="new_category" maxlength="60"></div>
      <div class="field"><label>Discount %</label><input class="in" type="number" step="0.01" min="0" max="100" name="discount_percent" id="pf_disc" value="0"></div>
      <div class="field"><label>Sort order</label><input class="in" type="number" name="sort_order" id="pf_sort" value="0"></div>
      <div class="field full"><label>Image (JPG/PNG/WebP, max 3MB)</label><input class="in" type="file" name="image" accept="image/*"></div>
      <div class="field full"><label>Download link</label><input class="in" name="download_link" id="pf_dl" placeholder="https://"></div>
      <div class="field full"><label>Description</label><textarea class="in" name="description" id="pf_desc" rows="3"></textarea></div>
      <div class="field full maintbox"><label class="chk"><input type="checkbox" name="is_maintenance" value="1" id="pf_maint"> 🔧 Maintenance mode (red card + popup on store)</label>
        <textarea class="in" name="maintenance_msg" id="pf_mmsg" rows="3" placeholder="Popup message (empty = default message from Site Settings)"></textarea></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1" id="pf_active" checked> Visible on store</label>
      <label class="chk"><input type="checkbox" name="is_pinned" value="1" id="pf_pin"> ★ Pin to top</label>
      <div class="full"><button class="btn btn-primary btn-block">Save product</button></div>
    </form></div></div>

  <!-- quick maintenance modal -->
  <div class="modal" id="maintModal"><div class="sheet"><button class="x" data-close>✕</button>
    <h2 id="mtTitle">Maintenance</h2><p class="lead">Customers see a red MAINTENANCE card. Tapping it opens a popup with your message.</p>
    <form method="post"><?= csrf_field('product_maint') ?><input type="hidden" name="id" id="mt_id">
      <label class="chk big"><input type="checkbox" name="state" value="1" id="mt_state"> Enable maintenance</label>
      <div class="field"><label>Popup message</label><textarea class="in" name="maintenance_msg" id="mt_msg" rows="5" placeholder="Leave empty to use the default message from Site Settings"></textarea></div>
      <button class="btn btn-primary btn-block">Save</button></form></div></div>

<?php /* ============================ STOCK ============================ */ elseif ($p === 'stock'):
    $plans = $pdo->query("SELECT pl.id, pl.title, p.name,
        (SELECT COUNT(*) FROM stock_keys k WHERE k.plan_id=pl.id AND k.status='available') av,
        (SELECT COUNT(*) FROM stock_keys k WHERE k.plan_id=pl.id AND k.status='used') us
        FROM product_plans pl JOIN products p ON p.id=pl.product_id ORDER BY p.name, pl.sort_order")->fetchAll();
    $viewPlan = (int)($_GET['plan'] ?? 0);
    $keys = $viewPlan ? $q("SELECT * FROM stock_keys WHERE plan_id=? AND status='available' ORDER BY id DESC LIMIT 200", [$viewPlan])->fetchAll() : []; ?>
  <div class="two">
    <section class="box"><h3>Add keys</h3>
      <form method="post"><?= csrf_field('stock_add') ?>
        <div class="field"><label>Product / plan</label><select class="in" name="plan_id" required><option value="">Select…</option>
          <?php foreach ($plans as $r): ?><option value="<?= (int)$r['id'] ?>"<?= sel($viewPlan, $r['id']) ?>><?= e($r['name']) ?> — <?= e($r['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Keys (one per line)</label><textarea class="in mono" name="keys" rows="9" required placeholder="XXXX-XXXX-XXXX&#10;YYYY-YYYY-YYYY"></textarea></div>
        <button class="btn btn-primary btn-block">Add to stock</button></form></section>
    <section class="box"><h3>Stock overview</h3><div class="tbl"><table><tr><th>Product</th><th>Plan</th><th>Free</th><th>Sold</th><th></th></tr>
      <?php foreach ($plans as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['title']) ?></td>
        <td><span class="st <?= (int)$r['av'] ? 'approved' : 'rejected' ?>"><?= (int)$r['av'] ?></span></td><td><?= (int)$r['us'] ?></td>
        <td class="r"><a class="btn btn-ghost sm" href="admin.php?p=stock&plan=<?= (int)$r['id'] ?>">View</a>
          <?php if ((int)$r['av']): ?><form method="post" style="display:inline"<?= confirm_attr('Delete ALL unused keys of this plan?') ?>><?= csrf_field('stock_clear', ['plan_id' => $r['id']]) ?><button class="btn btn-danger sm">Clear</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$plans): ?><tr><td colspan="5" class="mu">Create a product with plans first.</td></tr><?php endif; ?></table></div></section>
  </div>
  <?php if ($viewPlan): ?><section class="box"><h3>Unused keys (latest 200)</h3><div class="tbl"><table>
    <?php foreach ($keys as $k): ?><tr><td class="mono"><?= e($k['license_key']) ?></td><td><?= e($k['created_at']) ?></td>
      <td class="r"><form method="post"><?= csrf_field('stock_delete', ['id' => $k['id'], 'plan' => $viewPlan]) ?><button class="btn btn-danger sm">Delete</button></form></td></tr><?php endforeach; ?>
    <?php if (!$keys): ?><tr><td class="mu">No unused keys.</td></tr><?php endif; ?></table></div></section><?php endif; ?>

<?php /* ============================ ORDERS ============================ */ elseif ($p === 'orders'):
    $kw = trim((string)($_GET['s'] ?? ''));
    $rows = $q('SELECT o.*, u.username FROM orders o JOIN users u ON u.id=o.user_id WHERE (? = "" OR u.username LIKE ? OR o.license_key LIKE ? OR o.product_name LIKE ?) ORDER BY o.id DESC LIMIT 300',
               [$kw, "%$kw%", "%$kw%", "%$kw%"])->fetchAll(); ?>
  <form class="toolrow" method="get"><input type="hidden" name="p" value="orders"><input class="in" name="s" value="<?= e($kw) ?>" placeholder="Search user, product or key…"><button class="btn btn-primary">Search</button></form>
  <section class="box"><div class="tbl"><table><tr><th>#</th><th>User</th><th>Product</th><th>Key</th><th>Price</th><th>Coupon</th><th>Date</th></tr>
    <?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['username']) ?></td><td><?= e($r['product_name']) ?> · <?= e($r['plan_title']) ?></td><td class="mono"><?= e($r['license_key']) ?></td><td><?= e(money0($r['price'])) ?></td><td><?= e($r['coupon_code'] ?? '') ?></td><td><?= e($r['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="mu">Nothing found.</td></tr><?php endif; ?></table></div></section>

<?php /* ============================ DEPOSITS ============================ */ elseif ($p === 'deposits'):
    $f = in_array($_GET['f'] ?? 'pending', ['pending', 'approved', 'rejected', 'all'], true) ? ($_GET['f'] ?? 'pending') : 'pending';
    $rows = $q("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id=d.user_id WHERE (? = 'all' OR d.status = ?) ORDER BY d.id DESC LIMIT 300", [$f, $f])->fetchAll(); ?>
  <div class="chips"><?php foreach (['pending', 'approved', 'rejected', 'all'] as $x): ?><a class="cat<?= $f === $x ? ' on' : '' ?>" href="admin.php?p=deposits&f=<?= $x ?>"><?= ucfirst($x) ?></a><?php endforeach; ?></div>
  <section class="box"><div class="tbl"><table><tr><th>User</th><th>Method</th><th>Amount</th><th>Sender</th><th>TRX ID</th><th>Status</th><th>Date</th><th></th></tr>
    <?php foreach ($rows as $r): ?><tr><td><?= e($r['username']) ?></td><td><?= e($r['method_name']) ?></td><td><b><?= e(money0($r['amount'])) ?></b></td><td><?= e($r['sender_no']) ?></td><td class="mono"><?= e($r['trx_id']) ?></td>
      <td><span class="st <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td><td><?= e($r['created_at']) ?></td>
      <td class="r"><?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="inl"><?= csrf_field('deposit_approve', ['id' => $r['id']]) ?><button class="btn btn-primary sm">Approve</button></form>
        <form method="post" class="inl"><?= csrf_field('deposit_reject', ['id' => $r['id']]) ?><input class="in s2" name="note" placeholder="Reason"><button class="btn btn-danger sm">Reject</button></form>
      <?php else: ?><span class="mu"><?= e($r['admin_note'] ?? '') ?></span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="mu">No deposits.</td></tr><?php endif; ?></table></div></section>

<?php /* ============================ USERS ============================ */ elseif ($p === 'users'):
    $kw = trim((string)($_GET['s'] ?? ''));
    $rows = $q('SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) oc FROM users u WHERE (? = "" OR u.username LIKE ? OR u.email LIKE ?) ORDER BY u.id DESC LIMIT 300', [$kw, "%$kw%", "%$kw%"])->fetchAll(); ?>
  <form class="toolrow" method="get"><input type="hidden" name="p" value="users"><input class="in" name="s" value="<?= e($kw) ?>" placeholder="Search username or email…"><button class="btn btn-primary">Search</button></form>
  <section class="box"><div class="tbl"><table><tr><th>User</th><th>Role</th><th>Balance</th><th>Orders</th><th>Status</th><th>Joined</th><th></th></tr>
    <?php foreach ($rows as $r): $self = (int)$r['id'] === (int)$me['id']; ?><tr>
      <td><b><?= e($r['username']) ?></b><div class="mu"><?= e($r['email']) ?></div></td><td><span class="tg"><?= e($r['role']) ?></span></td>
      <td><b><?= e(money($r['balance'])) ?></b></td><td><?= (int)$r['oc'] ?></td><td><span class="st <?= $r['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($r['status']) ?></span></td><td><?= e(substr((string)$r['created_at'], 0, 10)) ?></td>
      <td class="r"><button class="btn btn-ghost sm" onclick='UserForm(<?= json_encode(['id' => (int)$r['id'], 'u' => $r['username'], 'b' => (float)$r['balance'], 'role' => $r['role'], 'status' => $r['status'], 'self' => $self], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Manage</button></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="mu">No users.</td></tr><?php endif; ?></table></div></section>
  <div class="modal" id="userModal"><div class="sheet"><button class="x" data-close>✕</button><h2 id="umTitle"></h2><p class="lead" id="umBal"></p>
    <form method="post" class="inlf"><?= csrf_field('user_balance') ?><input type="hidden" name="id" class="um_id"><div class="field"><label>Add / subtract balance (use − to subtract)</label><div class="coupon"><input class="in" type="number" step="0.01" name="amount" placeholder="e.g. 100 or -50" required><button class="btn btn-primary">Apply</button></div></div></form>
    <form method="post" class="inlf"><?= csrf_field('user_password') ?><input type="hidden" name="id" class="um_id"><div class="field"><label>New password</label><div class="coupon"><input class="in" name="password" minlength="6" placeholder="min 6 characters" required><button class="btn btn-ghost">Change</button></div></div></form>
    <div class="coupon" id="umSelf"><form method="post"><?= csrf_field('user_status') ?><input type="hidden" name="id" class="um_id"><input type="hidden" name="status" id="um_status"><button class="btn btn-warn" id="um_sbtn"></button></form>
      <form method="post"><?= csrf_field('user_role') ?><input type="hidden" name="id" class="um_id"><input type="hidden" name="role" id="um_role"><button class="btn btn-ghost" id="um_rbtn"></button></form></div>
  </div></div>

<?php /* ============================ PAYMENTS ============================ */ elseif ($p === 'payments'):
    $rows = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order,id')->fetchAll(); ?>
  <div class="toolrow"><button class="btn btn-primary" onclick="PmForm()">＋ Add method</button></div>
  <section class="box"><div class="tbl"><table><tr><th>Name</th><th>Account</th><th>Type</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['name']) ?></b></td><td class="mono"><?= e($r['account_no']) ?></td><td><?= e($r['account_type']) ?></td>
      <td><span class="st <?= (int)$r['is_active'] ? 'approved' : 'pending' ?>"><?= (int)$r['is_active'] ? 'Active' : 'Off' ?></span></td>
      <td class="r"><button class="btn btn-ghost sm" onclick='PmForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
        <form method="post" class="inl"<?= confirm_attr('Delete?') ?>><?= csrf_field('pm_delete', ['id' => $r['id']]) ?><button class="btn btn-danger sm">✕</button></form></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="mu">No payment methods.</td></tr><?php endif; ?></table></div></section>
  <div class="modal" id="pmModal"><div class="sheet"><button class="x" data-close>✕</button><h2 id="pmTitle">Payment method</h2>
    <form method="post"><?= csrf_field('pm_save') ?><input type="hidden" name="id" id="pm_id">
      <div class="field"><label>Name</label><input class="in" name="name" id="pm_name" required placeholder="bKash"></div>
      <div class="field"><label>Account number / address</label><input class="in" name="account_no" id="pm_no" required></div>
      <div class="field"><label>Account type</label><input class="in" name="account_type" id="pm_type" placeholder="Personal / Agent / Merchant"></div>
      <div class="field"><label>Instructions</label><textarea class="in" name="instructions" id="pm_info" rows="3"></textarea></div>
      <div class="field"><label>Sort</label><input class="in" type="number" name="sort_order" id="pm_sort" value="0"></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1" id="pm_active" checked> Active</label>
      <button class="btn btn-primary btn-block" style="margin-top:14px">Save</button></form></div></div>

<?php /* ============================ COUPONS ============================ */ elseif ($p === 'coupons'):
    $rows = $pdo->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll(); ?>
  <div class="toolrow"><button class="btn btn-primary" onclick="CpForm()">＋ Add coupon</button></div>
  <section class="box"><div class="tbl"><table><tr><th>Code</th><th>Discount</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?><tr><td class="mono"><b><?= e($r['code']) ?></b></td><td><?= $r['type'] === 'percent' ? e(rtrim(rtrim((string)$r['value'], '0'), '.')) . '%' : e(money0($r['value'])) ?></td>
      <td><?= (int)$r['used_count'] ?><?= (int)$r['max_uses'] ? ' / ' . (int)$r['max_uses'] : '' ?></td><td><?= e($r['expires_at'] ?? '—') ?></td>
      <td><span class="st <?= (int)$r['is_active'] ? 'approved' : 'pending' ?>"><?= (int)$r['is_active'] ? 'Active' : 'Off' ?></span></td>
      <td class="r"><button class="btn btn-ghost sm" onclick='CpForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
        <form method="post" class="inl"<?= confirm_attr('Delete?') ?>><?= csrf_field('coupon_delete', ['id' => $r['id']]) ?><button class="btn btn-danger sm">✕</button></form></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="mu">No coupons.</td></tr><?php endif; ?></table></div></section>
  <div class="modal" id="cpModal"><div class="sheet"><button class="x" data-close>✕</button><h2>Coupon</h2>
    <form method="post"><?= csrf_field('coupon_save') ?><input type="hidden" name="id" id="cp_id">
      <div class="field"><label>Code</label><input class="in" name="code" id="cp_code" required style="text-transform:uppercase"></div>
      <div class="two"><div class="field"><label>Type</label><select class="in" name="type" id="cp_type"><option value="percent">Percent %</option><option value="fixed">Fixed amount</option></select></div>
      <div class="field"><label>Value</label><input class="in" type="number" step="0.01" name="value" id="cp_val" required></div></div>
      <div class="two"><div class="field"><label>Max uses (0 = ∞)</label><input class="in" type="number" name="max_uses" id="cp_max" value="0"></div>
      <div class="field"><label>Expires</label><input class="in" type="datetime-local" name="expires_at" id="cp_exp"></div></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1" id="cp_active" checked> Active</label>
      <button class="btn btn-primary btn-block" style="margin-top:14px">Save</button></form></div></div>

<?php /* ============================ BANNERS ============================ */ elseif ($p === 'banners'):
    $rows = $pdo->query('SELECT * FROM banners ORDER BY sort_order,id')->fetchAll(); ?>
  <section class="box"><h3>Add banner <small class="mu">(recommended 1600×600)</small></h3>
    <form method="post" enctype="multipart/form-data" class="fgrid"><?= csrf_field('banner_save') ?>
      <div class="field"><label>Image</label><input class="in" type="file" name="image" accept="image/*" required></div>
      <div class="field"><label>Link (optional)</label><input class="in" name="link_url" placeholder="https://"></div>
      <div class="field"><label>Sort</label><input class="in" type="number" name="sort_order" value="0"></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1" checked> Active</label>
      <div class="full"><button class="btn btn-primary">Upload</button></div></form></section>
  <div class="pgrid"><?php foreach ($rows as $r): ?><div class="box"><img class="bn" src="<?= e(safe_url($r['image_url'])) ?>" alt="">
    <form method="post" class="planrow" style="margin-top:10px"><?= csrf_field('banner_save', ['id' => $r['id']]) ?><input class="in" name="link_url" value="<?= e($r['link_url'] ?? '') ?>" placeholder="link"><input class="in s" type="number" name="sort_order" value="<?= (int)$r['sort_order'] ?>">
      <label class="chk"><input type="checkbox" name="is_active" value="1"<?= checked($r['is_active']) ?>> On</label><button class="btn btn-ghost sm">Save</button>
      <button class="btn btn-danger sm" name="act" value="banner_delete" onclick="return confirm('Delete banner?')">✕</button></form></div><?php endforeach; ?></div>

<?php /* ============================ ANNOUNCEMENTS ============================ */ elseif ($p === 'announce'):
    $rows = $pdo->query('SELECT * FROM announcements ORDER BY id DESC')->fetchAll(); ?>
  <section class="box"><h3>New announcement</h3><form method="post" class="coupon"><?= csrf_field('announce_save') ?><input class="in" name="message" maxlength="255" required placeholder="Shown in the scrolling ticker on the store"><input type="hidden" name="is_active" value="1"><button class="btn btn-primary">Add</button></form></section>
  <section class="box"><div class="tbl"><table><?php foreach ($rows as $r): ?><tr><td><form method="post" class="coupon"><?= csrf_field('announce_save', ['id' => $r['id']]) ?><input class="in" name="message" value="<?= e($r['message']) ?>">
    <label class="chk"><input type="checkbox" name="is_active" value="1"<?= checked($r['is_active']) ?>> On</label><button class="btn btn-ghost sm">Save</button>
    <button class="btn btn-danger sm" name="act" value="announce_delete" onclick="return confirm('Delete?')">✕</button></form></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td class="mu">No announcements.</td></tr><?php endif; ?></table></div></section>

<?php /* ============================ SETTINGS ============================ */ elseif ($p === 'settings'): ?>
  <form method="post" enctype="multipart/form-data" class="setform"><?= csrf_field('settings_save') ?>
    <section class="box"><h3>🏷️ General</h3><div class="fgrid">
      <div class="field"><label>Site name</label><input class="in" name="site_name" value="<?= e($S['site_name']) ?>" maxlength="80"></div>
      <div class="field"><label>Tagline</label><input class="in" name="site_tagline" value="<?= e($S['site_tagline']) ?>" maxlength="160"></div>
      <div class="field"><label>Logo</label><input class="in" type="file" name="site_logo" accept="image/*"><?php if ($S['site_logo']): ?><label class="chk"><input type="checkbox" name="remove_logo" value="1"> remove current logo</label><?php endif; ?></div>
      <div class="field"><label>Currency symbol</label><input class="in" name="currency_symbol" value="<?= e($S['currency_symbol']) ?>" maxlength="5"></div>
      <div class="field full"><label>Footer text</label><input class="in" name="footer_text" value="<?= e($S['footer_text']) ?>"></div></div></section>

    <section class="box"><h3>🎨 Theme</h3><div class="fgrid">
      <div class="field"><label>Primary colour</label><input class="in color" type="color" name="theme_primary" id="t_p" value="<?= e(hexc($S['theme_primary'], '#2563eb')) ?>"></div>
      <div class="field"><label>Accent colour</label><input class="in color" type="color" name="theme_accent" id="t_a" value="<?= e(hexc($S['theme_accent'], '#f43f5e')) ?>"></div>
      <div class="field"><label>Mode</label><select class="in" name="theme_mode"><?php foreach (['light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto (device)'] as $k => $l): ?><option value="<?= $k ?>"<?= sel($S['theme_mode'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field full"><label>Presets</label><div class="presets">
        <?php foreach ([['Royal','#2563eb','#f43f5e'],['Violet','#7c3aed','#f59e0b'],['Emerald','#059669','#f43f5e'],['Sunset','#ea580c','#7c3aed'],['Ocean','#0891b2','#e11d48'],['Rose','#e11d48','#2563eb'],['Midnight','#4338ca','#06b6d4']] as [$n,$a,$b]): ?>
          <button type="button" class="pre" data-p="<?= $a ?>" data-a="<?= $b ?>"><i style="background:linear-gradient(135deg,<?= $a ?>,<?= $b ?>)"></i><?= $n ?></button><?php endforeach; ?></div></div></div></section>

    <section class="box"><h3>💬 Support &amp; deposits</h3><div class="fgrid">
      <div class="field"><label>Support link (any URL: WhatsApp, Messenger, page…)</label><input class="in" name="support_link" value="<?= e($S['support_link']) ?>" placeholder="https://"></div>
      <div class="field"><label>Support button text</label><input class="in" name="support_label" value="<?= e($S['support_label']) ?>"></div>
      <div class="field"><label>Min deposit</label><input class="in" type="number" name="min_deposit" value="<?= e($S['min_deposit']) ?>"></div>
      <div class="field"><label>Max deposit (0 = no limit)</label><input class="in" type="number" name="max_deposit" value="<?= e($S['max_deposit']) ?>"></div></div></section>

    <section class="box"><h3>🔧 Maintenance — card &amp; popup</h3>
      <p class="mu" style="margin-top:0">Turn a product into maintenance from <b>Products → 🔧 Maintenance</b>. These texts control how every maintenance card and popup looks.</p>
      <div class="fgrid">
        <div class="field"><label>Card ribbon text</label><input class="in" name="maint_ribbon_text" id="m_rb" value="<?= e($S['maint_ribbon_text']) ?>" maxlength="30"></div>
        <div class="field"><label>Card button text</label><input class="in" name="maint_button_text" id="m_bt" value="<?= e($S['maint_button_text']) ?>" maxlength="30"></div>
        <div class="field"><label>Popup status chip</label><input class="in" name="maint_popup_chip" id="m_ch" value="<?= e($S['maint_popup_chip']) ?>" maxlength="40"></div>
        <div class="field"><label>Popup “rules” heading</label><input class="in" name="maint_popup_rules_title" id="m_rt" value="<?= e($S['maint_popup_rules_title']) ?>" maxlength="60"></div>
        <div class="field"><label>Close button text</label><input class="in" name="maint_close_text" id="m_cl" value="<?= e($S['maint_close_text']) ?>" maxlength="30"></div>
        <div class="field full"><label>Default popup message (used when a product has no own message)</label><textarea class="in" name="maint_default_msg" id="m_msg" rows="4"><?= e($S['maint_default_msg']) ?></textarea></div>
      </div>
      <div class="preview"><div class="pcard maint"><div class="ribbon"><i>🔧</i><span id="pv_rb"></span></div><div class="thumb"><div class="ph">H</div><span class="badge off">-5%</span><span class="badge tag">ROOT</span></div><h3>HEX BLADE ROOT</h3><p class="sub">🔥 Instant Delivery ✅</p><div class="pricepill">৳149</div><button type="button" class="btn btn-maint" id="pv_bt"></button></div>
        <div class="sheet mp pv-pop"><div class="mp-ic">⚠️</div><span class="mp-chip" id="pv_ch"></span><h2>HEX BLADE ROOT</h2><p class="mp-rt" id="pv_rt"></p><div class="mp-msg" id="pv_msg"></div>
          <span class="btn btn-primary">💬 <?= e($S['support_label']) ?></span><span class="btn btn-dark" id="pv_cl"></span></div></div>

      <hr class="sep"><label class="chk big"><input type="checkbox" name="site_maintenance" value="1"<?= checked($S['site_maintenance'] === '1') ?>> 🚧 Store-wide maintenance (pause ALL purchases)</label>
      <div class="field"><label>Store-wide message</label><input class="in" name="site_maintenance_msg" value="<?= e($S['site_maintenance_msg']) ?>"></div></section>
    <div class="savebar"><button class="btn btn-primary">💾 Save all settings</button></div>
  </form>
<?php endif; ?>
  </div>
</main>

<script>window.CSRF = <?= json_encode($csrf) ?>;</script>
<script src="assets/admin.js?v=2"></script>
</body></html>
