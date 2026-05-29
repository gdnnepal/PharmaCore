# PharmaCore — Project Context & Next Session Guide

> Keep this file updated. It is the single source of truth for any new chat session.

---

## What Is This Project?

**PharmaCore** is a self-hosted PHP pharmacy management system.
- Multi-branch, role-based access control
- POS billing, inventory, suppliers, customers, sales records, reports
- SMS notifications via Spellc PAAS
- Tailwind CSS frontend (no JS framework)
- Pure PHP 8.0+, PDO/MySQL, no Composer

**GitHub:** https://github.com/gdnnepal/PharmaCore  
**Local path:** `c:\laragon\www\PC`  
**Stack:** PHP 8.0+, MySQL, Apache (Laragon), Tailwind CSS v3

---

## Project File Structure

```
PharmaCore/
├── .env                    # DB credentials — gitignored, written by installer
├── .env.example            # Template for .env — committed to git
├── .gitignore
├── .htaccess
├── config.php              # Bootstrap: loads .env, PDO, schema migrations, all helper functions
├── dashboard.php           # Main app shell — sidebar, auth, module loader
├── login.php               # Login page with brute-force protection
├── installer.php           # Web-based setup wizard (4 steps incl. license)
├── install.sh              # One-click bash installer for Linux/Mac servers
├── install.lock            # Created after install — blocks re-installation
├── invoice_print.php       # Invoice print/PDF page
├── index.php               # Redirects to dashboard or login
├── pharmacy_npr.sql        # Base SQL schema dump
├── tailwind.config.js
├── package.json            # npm scripts: build:css, watch:css
├── src/
│   ├── input.css           # Tailwind source
│   ├── Env.php             # Lightweight .env parser (no Composer needed)
│   ├── SmsHelper.php       # SMS gateway class (Spellc PAAS)
│   └── SmsLogInitializer.php  # Creates sms_logs table on boot
├── css/
│   └── style.css           # Compiled Tailwind output — committed to git
├── modules/                # Each file = one dashboard module
│   ├── dashboard.php
│   ├── sale.php            # POS billing
│   ├── sales_record.php
│   ├── inventory.php
│   ├── customers.php
│   ├── customer_purchases.php
│   ├── suppliers.php
│   ├── branches.php
│   ├── users.php
│   ├── settings.php
│   ├── report.php
│   ├── notifications.php
│   └── stock_transfer_records.php
└── api/                    # JSON API endpoints (AJAX calls from modules)
    ├── send_bulk_sms.php
    ├── sms_balance.php
    ├── check_sms_logs.php
    ├── check_sms_settings.php
    └── ... (debug/setup helpers)
```

---

## Environment Variables (`.env`)

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pharmacore
DB_USER=root
DB_PASS=yourpassword

# Added when license feature is implemented:
LICENSE_KEY=XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX
LICENSE_SERVER_URL=https://license.gdnnepal.com
LICENSE_HMAC_SECRET=64-char-secret-from-license-server
```

Loaded by `src/Env.php` via `Env::load(__DIR__ . '/.env')` at the top of `config.php`.

---

## Key Helper Functions (defined in `config.php`)

| Function | Purpose |
|---|---|
| `get_base_url()` | Dynamic base URL — works in subdirectory installs |
| `get_app_setting(key, default)` | Read from `app_settings` DB table |
| `is_admin_user()` | Check if current session user is admin |
| `has_permission(key)` | Check single permission for current user |
| `has_any_permission([keys])` | Check any of multiple permissions |
| `require_auth()` | Redirect to login if not authenticated |
| `require_admin()` | Redirect if not admin |
| `csrf_token()` | Generate/return CSRF token |
| `verify_csrf()` | Validate POST CSRF token |
| `flash_msg(msg, type)` | Set/get one-time session flash message |
| `redirect_with_fallback(url)` | Header redirect with JS fallback |
| `send_sms_notification(phone, msg)` | Send SMS via SmsHelper |
| `get_sms_balance()` | Get SMS credit balance |
| `is_sms_configured()` | Check if SMS provider is set up |
| `e(string)` / `h(string)` | `htmlspecialchars` shorthand |
| `npr(float)` | Format currency with symbol |
| `audit_log_action(...)` | **Currently a no-op — needs implementation** |

---

## Database Tables

| Table | Purpose |
|---|---|
| `users` | User accounts with roles |
| `branches` | Physical pharmacy branches |
| `permissions` | Permission definitions |
| `user_permissions` | User ↔ permission mapping |
| `app_settings` | Key-value app configuration |
| `pharmacy_details` | Pharmacy name, address, PAN, DDA |
| `products` | Medicine/product catalog |
| `batches` | Stock batches per product/branch |
| `sales` | Sale transactions |
| `sale_items` | Line items per sale |
| `customers` | Customer records |
| `suppliers` | Supplier records |
| `supplier_payments` | Payments to/from suppliers |
| `stock_transfers` | Inter-branch stock transfers |
| `stock_transfer_returns` | Partial/full transfer reversals |
| `sale_return_logs` | Returned sale items |
| `sms_logs` | SMS send history |
| `audit_logs` | Immutable audit trail (trigger blocks DELETE) |

---

## Security — What Has Been Done

- [x] Session hardening: `httponly`, `samesite=Lax`, `use_strict_mode`, `secure` on HTTPS
- [x] Session fixation fix: `session_regenerate_id(true)` on login
- [x] Brute-force protection on login: 10 attempts → 15-min lockout per username+IP
- [x] CSRF tokens on all forms
- [x] All DB queries use PDO prepared statements
- [x] All output escaped with `e()` / `h()`
- [x] Module allowlist in `dashboard.php` (prevents arbitrary file inclusion)
- [x] Open redirect fix in `invoice_print.php` (blocks `//evil.com` protocol-relative URLs)
- [x] `json_encode` in JS contexts uses `JSON_HEX_TAG | JSON_HEX_AMP`
- [x] Bulk SMS API requires `settings.manage` permission
- [x] Fatal error handler no longer leaks file paths
- [x] SSL verification in SmsHelper uses system CA store (no longer silently disabled)
- [x] `.env` replaces `config.db.php` — credentials never hardcoded

## Security — What Still Needs Doing

- [ ] `audit_log_action()` is a **no-op** — the function exists but does nothing. The `audit_logs` table and immutable trigger are in place. Needs actual implementation.
- [ ] No rate limiting on API endpoints (only login has it)
- [ ] Password minimum length is 6 chars in `dashboard.php` change-password — should be 8 to match installer
- [ ] `config.php` still has `error_reporting(0)` — fine for production but should log to file in dev

---

## SMS System

- **Provider:** Spellc PAAS (`https://spellcpaas.com`)
- **Config:** Stored in `app_settings` table — keys: `sms_provider`, `sms_api_key`, `sms_template_due`, `sms_template_custom`
- **Phone format:** Nepal numbers only — 10 digits starting with `97` or `98`
- **Template variables:** `{firstname}`, `{fullname}`, `{dueamt}`, `{phone}`
- **Bulk SMS types:** `due` (auto-targets customers with outstanding balance), `custom` (selected customers)
- **Logs:** Every send (success/fail) written to `sms_logs` table

---

## License Verification — PENDING IMPLEMENTATION

This is the **main pending feature**. The license server already exists (Laravel-based).

### License Server API (already built)

**Base URL:** `https://license.gdnnepal.com` (or wherever it's hosted)

**Verify endpoint:**
```
POST /api/verify
Content-Type: application/json

{
  "license_key": "XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX",
  "domain": "pharmacy.example.com",
  "product_slug": "pharmacore",
  "timestamp": 1748000000
}

Response:
{
  "status": "valid",           // valid | expired | suspended | domain_mismatch | invalid
  "message": "License is valid",
  "timestamp": 1748000000,
  "signature": "hmac_sha256_hex",
  "expires_at": "2027-01-01",  // null for lifetime
  "plan": "yearly"             // monthly | yearly | lifetime | custom
}
```

**HMAC verification formula:**
```
hash_hmac('sha256', status + '|' + license_key + '|' + domain + '|' + timestamp, hmac_secret)
```
Client MUST verify this signature on every response to prevent spoofing.

**Domain normalization** (must match server-side):
- Strip `http://`, `https://`
- Strip `www.`
- Strip trailing `/`

### What Needs to Be Built in PharmaCore

#### 1. `src/LicenseManager.php`
A class that:
- Reads `LICENSE_KEY`, `LICENSE_SERVER_URL`, `LICENSE_HMAC_SECRET` from `.env`
- Calls `POST /api/verify` with key + normalized domain + timestamp
- Verifies HMAC signature on response
- Caches result in `app_settings` table (key: `license_cache`, value: JSON) with TTL (e.g. 24h)
- Returns status: `valid` / `expired` / `suspended` / `domain_mismatch` / `invalid` / `unreachable`
- On `unreachable` (no internet): use cached result if < 72h old (grace period)

#### 2. License check in `config.php`
After DB connection is established:
```php
require_once __DIR__ . '/src/LicenseManager.php';
LicenseManager::boot(); // throws or redirects if invalid
```
Should NOT block the installer (`installer.php`) or a dedicated `activate.php` page.

#### 3. `activate.php` — License activation page
Shown when:
- No `LICENSE_KEY` in `.env`
- License status is `invalid`, `expired`, or `suspended`

UI should:
- Show a form to enter the license key
- Call `/api/verify` via AJAX or form POST
- On success: write `LICENSE_KEY`, `LICENSE_SERVER_URL`, `LICENSE_HMAC_SECRET` to `.env`
- Redirect to login

#### 4. Installer step 0 — License key entry
Add a **Step 0** (before DB config) to `installer.php`:
- Input: License Key
- Validates against license server before proceeding
- Writes key to `.env` on success

#### 5. `install.sh` update
Add license key prompt before DB config section:
```bash
read -rp "License Key: " LICENSE_KEY
# Validate via curl POST to license server
# Write to .env
```

#### 6. `.env.example` update
Add:
```dotenv
LICENSE_KEY=
LICENSE_SERVER_URL=https://license.gdnnepal.com
LICENSE_HMAC_SECRET=
```

### Decisions Still Needed

| Question | Options |
|---|---|
| Check frequency | Install-time only / Every boot / Periodic (daily/weekly) |
| Grace period when server unreachable | 24h / 72h / 7 days |
| What happens on invalid license | Hard block (redirect to activate.php) / Soft warning banner |
| HMAC secret delivery | Hardcoded in app / Returned by server on first activation / In .env |
| Multi-domain support | One key = one domain / One key = multiple domains |

**Recommended defaults:**
- Check on every boot, cache result for 24h in DB
- 72h grace period if license server unreachable
- Hard block — redirect to `activate.php`
- HMAC secret returned by server on first activation, stored in `.env`

---

## One-Click Installer

### Web installer
```
http://yourserver/PharmaCore/installer.php
```
Steps: License Key → Database → Admin Account → Pharmacy Info

### Bash installer (Linux/Mac)
```bash
bash <(curl -fsSL https://raw.githubusercontent.com/gdnnepal/PharmaCore/main/install.sh)
```
Or inside the cloned repo:
```bash
bash install.sh
```

### Build CSS (after cloning, if needed)
```bash
npm install
npm run build:css
```
Pre-built `css/style.css` is committed to git so this is optional.

---

## Git & Deployment

**Remote:** `https://github.com/gdnnepal/PharmaCore.git`  
**Branch:** `main`

**Gitignored files** (never committed):
- `.env` — DB + license credentials
- `config.db.php` — legacy, replaced by `.env`
- `install.lock` — install state
- `node_modules/`
- `*.zip`

**To deploy on a new server:**
1. `git clone https://github.com/gdnnepal/PharmaCore.git`
2. Run `installer.php` in browser OR `bash install.sh` in terminal
3. Point web server document root to the cloned folder

---

## Pending / TODO

### High Priority
- [ ] **License verification system** — see full spec above
- [ ] **`audit_log_action()` implementation** — currently a no-op, infrastructure exists

### Medium Priority
- [ ] Password min-length consistency — change-password in `dashboard.php` uses 6, installer uses 8 → standardize to 8
- [ ] Rate limiting on all API endpoints (not just login)
- [ ] `install.sh` — add license key prompt and validation step

### Low Priority / Nice to Have
- [ ] `README.md` — public-facing project readme for GitHub
- [ ] Expiry alerts — notify admin when license is about to expire
- [ ] Multi-language support groundwork
- [ ] Dark mode

---

## How to Start a New Chat Session

Paste this at the start:

> "I'm working on PharmaCore — a PHP pharmacy management system at `c:\laragon\www\PC`. Read `CONTEXT.md` for full project context. Today I want to work on: [your task]"

Then reference the relevant section of this file for the specific feature.
