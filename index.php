<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$S = settings();
$user = current_user();
$siteMaint = $S['site_maintenance'] === '1';

/* ====================================================================
   AJAX ACTIONS (JSON)
   ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if (!csrf_ok()) json_out(['ok' => false, 'msg' => 'Session expired. Please reload the page.'], 419);
    $pdo = db();

    // ---------------------------------------------------------- register
    if ($act === 'register') {
        if (rate_limited('reg', 6, 600)) json_out(['ok' => false, 'msg' => 'Too many attempts. Try again later.']);
        $un = trim((string)($_POST['username'] ?? ''));
        $em = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $un)) json_out(['ok' => false, 'msg' => 'Username: 3-30 letters, numbers or underscore.']);
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'msg' => 'Enter a valid email address.']);
        if (strlen($pw) < 6) json_out(['ok' => false, 'msg' => 'Password must be at least 6 characters.']);
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$un, $em]);
        if ($st->fetchColumn()) json_out(['ok' => false, 'msg' => 'Username or email is already registered.']);
        $pdo->prepare('INSERT INTO users (username,email,password_hash) VALUES (?,?,?)')
            ->execute([$un, $em, password_hash($pw, PASSWORD_DEFAULT)]);
        $row = $pdo->query('SELECT * FROM users WHERE id = ' . (int)$pdo->lastInsertId())->fetch();
        login_user($row);
        log_activity((int)$row['id'], 'register', $un);
        json_out(['ok' => true, 'msg' => 'Welcome, ' . $un . '!']);
    }

    // ------------------------------------------------------------- login
    if ($act === 'login') {
        if (rate_limited('login', 8, 600)) json_out(['ok' => false, 'msg' => 'Too many attempts. Try again in a few minutes.']);
        $id = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        $st = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$id, strtolower($id)]);
        $row = $st->fetch();
        if (!$row || !password_verify($pw, $row['password_hash'])) json_out(['ok' => false, 'msg' => 'Wrong username or password.']);
        if ($row['status'] !== 'active') json_out(['ok' => false, 'msg' => 'Your account is suspended.']);
        login_user($row);
        log_activity((int)$row['id'], 'login', '');
        json_out(['ok' => true, 'msg' => 'Welcome back, ' . $row['username'] . '!', 'admin' => $row['role'] === 'admin']);
    }

    if ($act === 'logout') {
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
    }

    // everything below needs a logged-in user
    if (!$user) json_out(['ok' => false, 'msg' => 'Please login first.', 'auth' => true], 401);

    // ----------------------------------------------------- coupon preview
    if ($act === 'coupon') {
        $r = coupon_check($pdo, (string)($_POST['code'] ?? ''), (float)($_POST['total'] ?? 0));
        json_out($r);
    }

    // ----------------------------------------------------------- deposit
    if ($act === 'deposit') {
        if (rate_limited('dep', 10, 3600)) json_out(['ok' => false, 'msg' => 'Too many requests. Please wait.']);
        $mid    = (int)($_POST['method_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $sender = mb_substr(trim((string)($_POST['sender_no'] ?? '')), 0, 60);
        $trx    = strtoupper(trim((string)($_POST['trx_id'] ?? '')));
        $min = (float)$S['min_deposit']; $max = (float)$S['max_deposit'];
        $st = $pdo->prepare('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1');
        $st->execute([$mid]);
        $pm = $st->fetch();
        if (!$pm) json_out(['ok' => false, 'msg' => 'Choose a payment method.']);
        if ($amount < $min || ($max > 0 && $amount > $max)) json_out(['ok' => false, 'msg' => "Amount must be between " . money0($min) . " and " . money0($max) . '.']);
        if (!preg_match('/^[A-Z0-9]{6,40}$/', $trx)) json_out(['ok' => false, 'msg' => 'Enter a valid Transaction ID (letters & numbers).']);
        try {
            $pdo->prepare('INSERT INTO deposits (user_id,method_name,amount,sender_no,trx_id) VALUES (?,?,?,?,?)')
                ->execute([$user['id'], $pm['name'], $amount, $sender, $trx]);
        } catch (PDOException $e) {
            json_out(['ok' => false, 'msg' => 'This Transaction ID was already submitted.']);
        }
        log_activity((int)$user['id'], 'deposit_request', "$trx / $amount");
        json_out(['ok' => true, 'msg' => 'Request submitted! Balance will be added after admin approval.']);
    }

    // --------------------------------------------------------------- buy
    if ($act === 'buy') {
        if ($siteMaint) json_out(['ok' => false, 'msg' => $S['site_maintenance_msg']]);
        if (rate_limited('buy', 30, 600)) json_out(['ok' => false, 'msg' => 'Too many requests. Please wait.']);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $qty    = max(1, min(10, (int)($_POST['qty'] ?? 1)));
        $code   = strtoupper(trim((string)($_POST['coupon'] ?? '')));

        try {
            $pdo->beginTransaction();
            $u = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $u->execute([$user['id']]);
            $balance = (float)$u->fetchColumn();

            $st = $pdo->prepare('SELECT pl.*, p.name AS pname, p.discount_percent, p.is_active, p.is_maintenance
                                 FROM product_plans pl JOIN products p ON p.id = pl.product_id WHERE pl.id = ?');
            $st->execute([$planId]);
            $pl = $st->fetch();
            if (!$pl || !(int)$pl['is_active']) throw new RuntimeException('This plan is not available.');
            if ((int)$pl['is_maintenance']) throw new RuntimeException('This product is under maintenance.');

            $unit  = round((float)$pl['price'] * (1 - (float)$pl['discount_percent'] / 100), 2);
            $total = round($unit * $qty, 2);
            $couponUsed = null;
            if ($code !== '') {
                $c = coupon_check($pdo, $code, $total, true);
                if (!$c['ok']) throw new RuntimeException($c['msg']);
                $total = round($total - $c['discount'], 2);
                $couponUsed = $c['code'];
            }
            if ($balance < $total) throw new RuntimeException('Insufficient balance. Please add funds.');

            $keys = $pdo->query('SELECT id, license_key FROM stock_keys WHERE plan_id = ' . $planId .
                                " AND status = 'available' ORDER BY id LIMIT " . $qty . ' FOR UPDATE')->fetchAll();
            if (count($keys) < $qty) throw new RuntimeException('Out of stock. Only ' . count($keys) . ' key(s) left.');

            $each = round($total / $qty, 2);
            $mark = $pdo->prepare("UPDATE stock_keys SET status = 'used' WHERE id = ?");
            $ins  = $pdo->prepare('INSERT INTO orders (user_id,product_id,plan_id,product_name,plan_title,license_key,price,coupon_code) VALUES (?,?,?,?,?,?,?,?)');
            $out = [];
            foreach ($keys as $k) {
                $mark->execute([$k['id']]);
                $ins->execute([$user['id'], $pl['product_id'], $planId, $pl['pname'], $pl['title'], $k['license_key'], $each, $couponUsed]);
                $out[] = $k['license_key'];
            }
            if ($couponUsed) $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?')->execute([$couponUsed]);
            $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')->execute([$total, $user['id']]);
            $pdo->commit();
            log_activity((int)$user['id'], 'purchase', $pl['pname'] . ' x' . $qty);
            json_out(['ok' => true, 'keys' => $out, 'product' => $pl['pname'], 'plan' => $pl['title'],
                      'balance' => money($balance - $total), 'download' => ''], 200);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'msg' => 'Something went wrong. Please try again.'], 500);
        }
    }
    json_out(['ok' => false, 'msg' => 'Unknown action.'], 400);
}

function coupon_check(PDO $pdo, string $code, float $total, bool $lock = false): array {
    $code = strtoupper(trim($code));
    $st = $pdo->prepare('SELECT * FROM coupons WHERE code = ? AND is_active = 1' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) return ['ok' => false, 'msg' => 'Invalid coupon code.'];
    if ($c['expires_at'] && strtotime($c['expires_at']) < time()) return ['ok' => false, 'msg' => 'This coupon has expired.'];
    if ((int)$c['max_uses'] > 0 && (int)$c['used_count'] >= (int)$c['max_uses']) return ['ok' => false, 'msg' => 'This coupon has reached its usage limit.'];
    $d = $c['type'] === 'percent' ? $total * (float)$c['value'] / 100 : (float)$c['value'];
    $d = round(min($d, $total), 2);
    return ['ok' => true, 'code' => $c['code'], 'discount' => $d, 'msg' => 'Coupon applied: -' . money0($d)];
}

/* ====================================================================
   PAGE DATA
   ==================================================================== */
$pdo = db();
$cats = $pdo->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$products = $pdo->query("SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
                         WHERE p.is_active = 1 OR p.is_maintenance = 1
                         ORDER BY p.is_pinned DESC, p.sort_order, p.id DESC")->fetchAll();
$plansBy = [];
$stockRows = $pdo->query("SELECT plan_id, COUNT(*) c FROM stock_keys WHERE status='available' GROUP BY plan_id")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($pdo->query('SELECT * FROM product_plans ORDER BY sort_order, price') as $pl) {
    $pl['stock'] = (int)($stockRows[$pl['id']] ?? 0);
    $plansBy[$pl['product_id']][] = $pl;
}
$banners = $pdo->query('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
$ann = $pdo->query('SELECT message FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_COLUMN);
$methods = $pdo->query('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();

$orders = $deps = [];
if ($user) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $st->execute([$user['id']]); $orders = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 30'); $st->execute([$user['id']]); $deps = $st->fetchAll();
}

// Client data (everything user-visible is escaped by the JS via textContent)
$js = ['products' => [], 'methods' => [], 'logged' => (bool)$user, 'csrf' => csrf_token(), 'siteMaint' => $siteMaint,
       'siteMaintMsg' => $S['site_maintenance_msg'], 'cur' => $S['currency_symbol'],
       'maint' => [
           'chip' => $S['maint_popup_chip'], 'rules' => $S['maint_popup_rules_title'], 'defMsg' => $S['maint_default_msg'],
           'close' => $S['maint_close_text'], 'support' => safe_url($S['support_link']), 'supportLabel' => $S['support_label'],
       ]];
foreach ($products as $p) {
    $disc = (float)$p['discount_percent'];
    $plans = [];
    foreach ($plansBy[$p['id']] ?? [] as $pl) {
        $plans[] = ['id' => (int)$pl['id'], 'title' => $pl['title'], 'days' => (int)$pl['duration_days'],
                    'old' => (float)$pl['price'], 'price' => round((float)$pl['price'] * (1 - $disc / 100), 2), 'stock' => $pl['stock']];
    }
    $js['products'][$p['id']] = ['id' => (int)$p['id'], 'name' => $p['name'], 'desc' => (string)$p['description'],
        'img' => safe_url((string)$p['image_url']), 'maint' => (int)$p['is_maintenance'] === 1, 'msg' => (string)$p['maintenance_msg'],
        'dl' => safe_url((string)$p['download_link']), 'plans' => $plans];
}
foreach ($methods as $m) $js['methods'][] = ['id' => (int)$m['id'], 'name' => $m['name'], 'no' => $m['account_no'], 'type' => (string)$m['account_type'], 'info' => (string)$m['instructions']];
$js['min'] = (float)$S['min_deposit']; $js['max'] = (float)$S['max_deposit'];

$logo = safe_url($S['site_logo']);
$theme = in_array($S['theme_mode'], ['light', 'dark', 'auto'], true) ? $S['theme_mode'] : 'light';
$hex = fn(string $c, string $d) => preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $d;
?><!DOCTYPE html>
<html lang="en" data-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($S['site_name']) ?> — <?= e($S['site_tagline']) ?></title>
<meta name="description" content="<?= e($S['site_tagline']) ?>">
<meta name="theme-color" content="<?= e($hex($S['theme_primary'], '#2563eb')) ?>">
<?php if ($logo): ?><link rel="icon" href="<?= e($logo) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@500;600;700&family=JetBrains+Mono:wght@700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=2">
<style>:root{--primary:<?= e($hex($S['theme_primary'], '#2563eb')) ?>;--accent:<?= e($hex($S['theme_accent'], '#f43f5e')) ?>}</style>
<?php if ($theme === 'auto'): ?><script>if(matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.dataset.theme='dark'</script><?php endif; ?>
</head>
<body>

<header class="topbar"><div class="wrap">
  <a class="brand" href="./">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><span class="dot">⚡</span><?php endif; ?>
    <span><?= e($S['site_name']) ?></span>
  </a>
  <div class="spacer"></div>
  <?php if ($user): ?>
    <?php if ($user['role'] === 'admin'): ?><a class="btn btn-white" style="padding:9px 14px" href="admin.php">Admin</a><?php endif; ?>
    <div class="chip-bal" onclick="App.open('accountModal','fund')"><i>💰</i><span id="topBal"><?= e(money($user['balance'])) ?></span></div>
    <button class="btn btn-white" style="padding:9px 14px" onclick="App.open('accountModal','orders')">👤 <?= e($user['username']) ?></button>
  <?php else: ?>
    <button class="btn btn-white" onclick="App.open('authModal')">Login / Sign up</button>
  <?php endif; ?>
</div></header>

<main class="wrap">

<?php if ($banners): ?>
  <section class="hero"><div class="slider" id="slider">
    <?php foreach ($banners as $i => $b): $img = safe_url($b['image_url']); $lnk = safe_url((string)$b['link_url']); ?>
      <div class="slide<?= $i === 0 ? ' on' : '' ?>"><?php if ($lnk): ?><a href="<?= e($lnk) ?>" target="_blank" rel="noopener"><?php endif; ?>
        <img src="<?= e($img) ?>" alt="" loading="<?= $i ? 'lazy' : 'eager' ?>"><?php if ($lnk): ?></a><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if (count($banners) > 1): ?><div class="dots"><?php foreach ($banners as $i => $_): ?><b class="<?= $i === 0 ? 'on' : '' ?>"></b><?php endforeach; ?></div><?php endif; ?>
  </div></section>
<?php else: ?>
  <section class="hero hero-plain"><h1><?= e($S['site_name']) ?></h1><p><?= e($S['site_tagline']) ?></p></section>
<?php endif; ?>

<?php if ($siteMaint): ?><div class="notice">🔧 <?= e($S['site_maintenance_msg']) ?></div><?php endif; ?>
<?php if ($ann): ?>
  <div class="ticker"><span class="ic">📢</span><div class="mq"><span><?= e(implode('   •   ', $ann)) ?></span></div></div>
<?php endif; ?>

<div class="toolbar">
  <label class="search"><span>🔎</span><input id="q" type="search" placeholder="Search products…" autocomplete="off"></label>
  <?php if ($cats): ?>
  <div class="cats">
    <button class="cat on" data-cat="all">All</button>
    <?php foreach ($cats as $c): ?><button class="cat" data-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<h2 class="sec-title">🛒 Products</h2>
<section class="grid" id="grid">
<?php foreach ($products as $p):
    $isM = (int)$p['is_maintenance'] === 1;
    $pls = $plansBy[$p['id']] ?? [];
    $min = $pls ? min(array_column($pls, 'price')) : 0;
    $disc = (float)$p['discount_percent'];
    $minNow = round($min * (1 - $disc / 100), 2);
    $img = safe_url((string)$p['image_url']);
?>
  <article class="pcard<?= $isM ? ' maint' : '' ?>" data-id="<?= (int)$p['id'] ?>" data-cat="<?= (int)$p['category_id'] ?>" data-name="<?= e(mb_strtolower($p['name'])) ?>" tabindex="0" role="button" aria-label="<?= e($p['name']) ?>">
    <?php if ($isM): ?><div class="ribbon"><i>🔧</i><?= e($S['maint_ribbon_text']) ?></div><?php endif; ?>
    <div class="thumb">
      <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" loading="lazy"><?php else: ?><div class="ph"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></div><?php endif; ?>
      <?php if ($disc > 0): ?><span class="badge off">-<?= e(rtrim(rtrim(number_format($disc, 2), '0'), '.')) ?>%</span><?php endif; ?>
      <?php if ($p['tag']): ?><span class="badge tag"><?= e($p['tag']) ?></span><?php endif; ?>
      <?php if ((int)$p['is_pinned'] && !$isM): ?><span class="badge pin">★ HOT</span><?php endif; ?>
    </div>
    <h3><?= e($p['name']) ?></h3>
    <?php if ($p['subtitle']): ?><p class="sub"><?= e($p['subtitle']) ?></p><?php endif; ?>
    <div class="pricepill"><?php if ($disc > 0 && $min): ?><s><?= e(money0($min)) ?></s><?php endif; ?><?= $pls ? e(money0($minNow)) : '—' ?></div>
    <?php if ($isM): ?><button class="btn btn-maint" type="button"><?= e($S['maint_button_text']) ?></button>
    <?php else: ?><button class="btn btn-primary" type="button">BUY NOW</button><?php endif; ?>
  </article>
<?php endforeach; ?>
  <div class="empty<?= $products ? ' hidden' : '' ?>" id="empty">😕 No products found.</div>
</section>

<footer class="footer"><?= e($S['footer_text']) ?></footer>
</main>

<?php $sup = safe_url($S['support_link']); if ($sup): ?><a class="fab" href="<?= e($sup) ?>" target="_blank" rel="noopener" title="<?= e($S['support_label']) ?>">💬</a><?php endif; ?>

<!-- ============ Maintenance popup (texts editable in admin → Site Settings) ============ -->
<div class="modal" id="maintModal"><div class="sheet mp">
  <button class="x" data-close>✕</button>
  <div class="mp-ic" id="mpIc">⚠️</div>
  <span class="mp-chip" id="mpChip"></span>
  <h2 id="mpName"></h2>
  <p class="mp-rt" id="mpRt"></p>
  <div class="mp-msg" id="mpMsg"></div>
  <a class="btn btn-primary hidden" id="mpSupport" target="_blank" rel="noopener"></a>
  <button class="btn btn-dark" data-close id="mpClose"></button>
</div></div>

<!-- ============ Product / buy modal ============ -->
<div class="modal" id="buyModal"><div class="sheet">
  <button class="x" data-close>✕</button>
  <h2 id="bName"></h2><p class="lead" id="bLead"></p>
  <p class="pdesc hidden" id="bDesc"></p>
  <div class="links"><a class="btn btn-ghost hidden" id="bDl" target="_blank" rel="noopener">⬇ Download</a></div>
  <div class="plans" id="bPlans"></div>
  <div class="field"><label>Quantity</label><div class="qty"><button type="button" id="qMinus">−</button><span id="qVal">1</span><button type="button" id="qPlus">+</button></div></div>
  <div class="field"><label>Coupon (optional)</label><div class="coupon"><input class="in" id="bCoupon" placeholder="CODE"><button class="btn btn-ghost" type="button" id="bCouponBtn">Apply</button></div><div id="bCouponMsg"></div></div>
  <div class="sum"><span>Total</span><span id="bTotal">—</span></div>
  <button class="btn btn-primary btn-block" id="bBuy">BUY NOW</button>
  <div id="bMsg"></div>
</div></div>

<!-- ============ Key success ============ -->
<div class="modal" id="keyModal"><div class="sheet succ">
  <div class="big">🎉</div><h2>Purchase successful</h2><p class="lead" id="kInfo"></p>
  <div class="list" id="kList" style="text-align:left"></div>
  <button class="btn btn-primary btn-block" style="margin-top:16px" onclick="location.reload()">Done</button>
</div></div>

<!-- ============ Auth ============ -->
<div class="modal" id="authModal"><div class="sheet">
  <button class="x" data-close>✕</button>
  <h2>Welcome 👋</h2><p class="lead">Login or create an account to buy instantly.</p>
  <div class="tabs"><button class="tab on" data-tab="login">Login</button><button class="tab" data-tab="register">Sign up</button></div>
  <form id="loginForm" data-tabpane="login">
    <div class="field"><label>Username or email</label><input class="in" name="username" required autocomplete="username"></div>
    <div class="field"><label>Password</label><input class="in" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary btn-block">Login</button><div class="amsg"></div>
  </form>
  <form id="regForm" class="hidden" data-tabpane="register">
    <div class="field"><label>Username</label><input class="in" name="username" required minlength="3" maxlength="30" autocomplete="username"></div>
    <div class="field"><label>Email</label><input class="in" type="email" name="email" required autocomplete="email"></div>
    <div class="field"><label>Password</label><input class="in" type="password" name="password" required minlength="6" autocomplete="new-password"></div>
    <button class="btn btn-primary btn-block">Create account</button><div class="amsg"></div>
  </form>
</div></div>

<!-- ============ Account ============ -->
<?php if ($user): ?>
<div class="modal" id="accountModal"><div class="sheet wide">
  <button class="x" data-close>✕</button>
  <div class="ac-head"><div class="avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
    <div><b><?= e($user['username']) ?></b><div class="lead" style="margin:0"><?= e($user['email']) ?></div></div>
    <div class="bal"><small>Balance</small><?= e(money($user['balance'])) ?></div></div>
  <div class="tabs"><button class="tab on" data-tab="orders">My Keys</button><button class="tab" data-tab="fund">Add Fund</button><button class="tab" data-tab="deps">Deposits</button></div>

  <div data-tabpane="orders" class="list">
    <?php foreach ($orders as $o): ?>
      <div class="li"><div class="t"><span><?= e($o['product_name']) ?> <small style="color:var(--mu)">· <?= e($o['plan_title']) ?></small></span><span><?= e(money0($o['price'])) ?></span></div>
        <div class="m"><?= e($o['created_at']) ?></div>
        <div class="key"><code><?= e($o['license_key']) ?></code><button type="button" data-copy="<?= e($o['license_key']) ?>">COPY</button></div></div>
    <?php endforeach; if (!$orders): ?><div class="empty" style="padding:30px">No purchases yet.</div><?php endif; ?>
  </div>

  <div data-tabpane="fund" class="hidden">
    <?php if (!$methods): ?><div class="empty" style="padding:30px">No payment method is available right now.</div><?php else: ?>
    <div class="pm" id="pmList"><?php foreach ($methods as $i => $m): ?><div class="pm-i<?= $i === 0 ? ' on' : '' ?>" data-pm="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></div><?php endforeach; ?></div>
    <div class="pm-box" id="pmBox"></div>
    <form id="depForm">
      <input type="hidden" name="method_id" value="<?= (int)$methods[0]['id'] ?>">
      <div class="field"><label>Amount (<?= e($S['currency_symbol']) ?>)</label><input class="in" name="amount" type="number" step="1" min="<?= e($S['min_deposit']) ?>" required placeholder="Min <?= e($S['min_deposit']) ?>"></div>
      <div class="field"><label>Your sender number</label><input class="in" name="sender_no" placeholder="01XXXXXXXXX"></div>
      <div class="field"><label>Transaction ID</label><input class="in" name="trx_id" required placeholder="e.g. 9AB7XK2LMQ" style="text-transform:uppercase"></div>
      <button class="btn btn-primary btn-block">Submit payment</button><div class="amsg"></div>
    </form><?php endif; ?>
  </div>

  <div data-tabpane="deps" class="list hidden">
    <?php foreach ($deps as $d): ?>
      <div class="li"><div class="t"><span><?= e($d['method_name']) ?> · <?= e(money0($d['amount'])) ?></span><span class="st <?= e($d['status']) ?>"><?= e($d['status']) ?></span></div>
        <div class="m">TRX <?= e($d['trx_id']) ?> · <?= e($d['created_at']) ?><?= $d['admin_note'] ? ' · ' . e($d['admin_note']) : '' ?></div></div>
    <?php endforeach; if (!$deps): ?><div class="empty" style="padding:30px">No deposits yet.</div><?php endif; ?>
  </div>
  <button class="btn btn-ghost btn-block" style="margin-top:18px" id="logoutBtn">Logout</button>
</div></div>
<?php endif; ?>

<div class="toast" id="toast"></div>
<script>window.APP = <?= json_encode($js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/app.js?v=2"></script>
</body></html>
