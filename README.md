# Ff Store

Single-domain key/licence store (PHP 8 + MySQL). No subdomains, no Telegram, no separate admin password.

## Setup
1. Upload the files, create an empty MySQL database.
2. Open `/install.php` → enter DB details + your admin account → done (`install.lock` is created).
   (Or import `schema.sql` manually and copy `config.sample.php` → `config.local.php`.)
3. Log in on the home page with the admin account, then open `/admin.php` (an **Admin** button shows in the header).

## Files
| File | Purpose |
|---|---|
| `index.php` | Storefront: products, login/signup, buy, add-fund, my keys |
| `admin.php` | Admin panel (same login as the store, role = admin) |
| `schema.sql` | All tables |
| `install.php` | One-time installer |
| `inc/bootstrap.php` | DB, session, settings, CSRF, helpers |
| `assets/` | `style.css`, `app.js`, `admin.css`, `admin.js` |

## Maintenance mode
* Products → **🔧 Maintenance** on any product: the store shows a red card (ribbon + button); one click opens the popup.
* Popup message: per product (in that dialog) or the default message in **Site Settings**.
* All ribbon/button/popup/close texts, support link & theme colours are editable in **Site Settings** (with live preview).

## Security notes
* Prepared statements everywhere, CSRF token on every POST, `password_hash`, login rate-limit, safe image uploads (`uploads/` blocks script execution).
* Balance/stock changes run inside DB transactions with row locks.
