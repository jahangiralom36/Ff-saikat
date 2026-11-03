# Ff Store

Single-domain key/licence store (PHP 8 + MySQL). No subdomains, no Telegram, no separate admin password.

## Setup
1. cPanel-এ একটা MySQL database + user বানান।
2. phpMyAdmin → database → **Import** → `database.sql`
3. `config.php` খুলে `DB_NAME / DB_USER / DB_PASS` বসান।
4. Site-এ login: `admin` / `Admin@12345` → **Admin** button → password বদলান।

## Files
| File | Purpose |
|---|---|
| `index.php` | Storefront: products, login/signup, buy, add-fund, my keys |
| `admin.php` | Admin panel (same login as the store, role = admin) |
| `database.sql` | All tables + default admin + default settings |
| `config.php` | Database credentials |
| `inc/bootstrap.php` | DB, session, settings, CSRF, helpers |
| `assets/` | `style.css`, `app.js`, `admin.css`, `admin.js` |

## Maintenance mode
* Products → **🔧 Maintenance** on any product: the store shows a red card (ribbon + button); one click opens the popup.
* Popup message: per product (in that dialog) or the default message in **Site Settings**.
* All ribbon/button/popup/close texts, support link & theme colours are editable in **Site Settings** (with live preview).

## Security notes
* Prepared statements everywhere, CSRF token on every POST, `password_hash`, login rate-limit, safe image uploads (`uploads/` blocks script execution).
* Balance/stock changes run inside DB transactions with row locks.
