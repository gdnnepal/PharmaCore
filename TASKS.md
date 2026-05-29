# PharmaCore — Security & Code Quality Task List

> Generated from full codebase audit. Use this file to track fixes across sessions.
> Start a new session with: "Read CONTEXT.md and TASKS.md, then fix task [ID]."
> Mark tasks `[x]` when done.

---

## How to Use This File

Each task has:
- **ID** — reference it in chat
- **File(s)** — exact file(s) to edit
- **Problem** — what is wrong
- **Fix** — exactly what to do

---

## 🔴 CRITICAL (fix before going to production)

### [C-1] Audit log is a complete no-op
- **File:** `config.php` ~line 676
- **Problem:** `audit_log_action()` body is just `return;`. Every sensitive action (sales, returns, inventory, user changes, password changes) is silently unlogged despite the `audit_logs` table and immutable trigger existing.
- **Fix:** Implement the function body to INSERT into `audit_logs`:
  ```php
  function audit_log_action(string $module, string $action, string $description, array $payload = [], ?string $entityType = null, $entityId = null): void {
      global $pdo;
      try {
          $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, module_name, action_name, entity_type, entity_id, description, payload_json, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?)");
          $stmt->execute([
              $_SESSION['uid'] ?? null,
              $_SESSION['username'] ?? null,
              $module, $action, $entityType,
              $entityId !== null ? (string)$entityId : null,
              $description,
              !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
              $_SERVER['REMOTE_ADDR'] ?? null,
              substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
          ]);
      } catch(Throwable $e) {
          error_log('[Audit] Failed: ' . $e->getMessage());
      }
  }
  ```
- [ ] Done

---

### [C-2] Hardcoded credentials + stale config.db.php is web-accessible
- **Files:** `config.db.php`, `.env`
- **Problem:** `config.db.php` contains `root`/`123456` in plaintext and is web-accessible. `.env` also has the same password. `config.db.php` is no longer used (replaced by `.env`) but still exists.
- **Fix:**
  1. Delete `config.db.php` from the project entirely
  2. Change the database password on the server
  3. Add to `.htaccess` (see task L-2 for full block):
     ```apache
     <Files ".env">
         Require all denied
     </Files>
     <Files "config.db.php">
         Require all denied
     </Files>
     ```
- [ ] Done

---

### [C-3] Debug/diagnostic API files are publicly accessible with no auth
- **Files:** `api/check_sms_logs.php`, `api/check_sms_settings.php`, `api/fix_sms_templates.php`, `api/insert_sms_templates.php`, `api/debug_buttons.php`, `api/debug_sms_send.php`, `api/debug_sms_verbose.php`, `api/test_bulk_sms_debug.php`, `api/test_bulk_sms_logic.php`, `api/test_sms_config.php`, `api/test_template_substitution.php`, `api/setup_sms_templates.php`
- **Problem:** These files dump raw SMS logs (phone numbers, messages, API keys), modify DB records, and expose internal configuration — all without any authentication check. Any visitor can access them.
- **Fix:** **Delete all of them.** They are development/debug tools and must not exist in production. Run:
  ```bash
  git rm api/check_sms_logs.php api/check_sms_settings.php api/fix_sms_templates.php \
         api/insert_sms_templates.php api/debug_buttons.php api/debug_sms_send.php \
         api/debug_sms_verbose.php api/test_bulk_sms_debug.php api/test_bulk_sms_logic.php \
         api/test_sms_config.php api/test_template_substitution.php api/setup_sms_templates.php
  ```
- [ ] Done

---

### [C-4] install.sh injects admin password via shell variable into PHP heredoc
- **File:** `install.sh` ~lines 130–180
- **Problem:** `password_hash('${ADMIN_PASS}', ...)` — the password is visible in the process list (`ps aux`), shell history, and any system logging. Special characters in the password break the generated PHP.
- **Fix:** Write credentials to a temp file and pass via environment variables, never via string interpolation into PHP source:
  ```bash
  export PHARMACORE_ADMIN_PASS="$ADMIN_PASS"
  php -r "echo password_hash(getenv('PHARMACORE_ADMIN_PASS'), PASSWORD_DEFAULT);"
  ```
  Or write a separate PHP setup script that reads from env vars.
- [ ] Done

---

### [C-5] XSS in redirect_with_fallback() JavaScript fallback
- **File:** `config.php` ~line 655
- **Problem:** `echo "<script>window.location.href='" . $safeUrl . "';</script>";` — `e()` applies `htmlspecialchars` but does NOT JS-encode single quotes. A URL containing `'` breaks out of the JS string literal.
- **Fix:** Replace with:
  ```php
  echo '<script>window.location.href=' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>';
  ```
  Remove the `$safeUrl = e($url)` line — it's wrong context for JS.
- [ ] Done

---

## 🟠 HIGH

### [H-1] Open redirect via HTTP_REFERER in invoice_print.php
- **File:** `invoice_print.php` ~line 62
- **Problem:** `$_SERVER['HTTP_REFERER']` is used as a fallback redirect target. `HTTP_REFERER` is fully attacker-controlled. A crafted link can redirect users to external phishing sites after printing.
- **Fix:** Remove the `HTTP_REFERER` fallback entirely:
  ```php
  // REMOVE this:
  $returnAfterPrintUrl = trim((string)($_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
  // REPLACE with:
  $returnAfterPrintUrl = trim((string)($_GET['return'] ?? ''));
  ```
- [ ] Done

---

### [H-2] modules/suppliers.php — no authorization check
- **File:** `modules/suppliers.php` line 1
- **Problem:** Any authenticated user (cashier, etc.) can add, edit, delete suppliers and manipulate payment logs.
- **Fix:** Add at the top of the file after `require_once`:
  ```php
  if(!is_admin_user() && !has_permission('suppliers.manage')){
      flash_msg('You do not have permission to manage suppliers.', 'error');
      redirect_with_fallback('?module=sale');
  }
  ```
- [ ] Done

---

### [H-3] modules/settings.php — no authorization check
- **File:** `modules/settings.php` line 1
- **Problem:** Any authenticated user can modify pharmacy details, SMS API keys, invoice settings, and activate a new license key.
- **Fix:** Add at the top after `require_once`:
  ```php
  if(!is_admin_user() && !has_permission('settings.manage')){
      flash_msg('You do not have permission to access settings.', 'error');
      redirect_with_fallback('?module=sale');
  }
  ```
- [ ] Done

---

### [H-4] modules/report.php — no authorization check
- **File:** `modules/report.php` line 1
- **Problem:** No permission check in the module file itself.
- **Fix:** Add at the top after `require_once`:
  ```php
  if(!is_admin_user() && !has_permission('report.view')){
      flash_msg('You do not have permission to view reports.', 'error');
      redirect_with_fallback('?module=sale');
  }
  ```
- [ ] Done

---

### [H-5] Brute-force protection stored in session — trivially bypassed
- **File:** `login.php` ~lines 35–55
- **Problem:** Lockout counter is in `$_SESSION`. Deleting the session cookie resets the counter to zero. Completely ineffective against automated attacks.
- **Fix:** Store lockout in the database. Add a `login_attempts` table:
  ```sql
  CREATE TABLE IF NOT EXISTS login_attempts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      identifier VARCHAR(255) NOT NULL,
      attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_identifier (identifier),
      INDEX idx_attempted_at (attempted_at)
  );
  ```
  Then in `login.php`, query/insert/count rows keyed by `md5($username . '|' . $ip)` within the last 15 minutes instead of using `$_SESSION`.
- [ ] Done

---

### [H-6] DDL ALTER TABLE statements run on every page load in customer_purchases.php
- **File:** `modules/customer_purchases.php` lines 3–35
- **Problem:** Three `ALTER TABLE` and one `CREATE TABLE` execute on every page load, causing metadata lock contention on busy systems.
- **Fix:** Move these migrations into the `config.php` schema bootstrap block (the `try { ... } catch(Throwable $e){}` block that already handles migrations). Remove them from `customer_purchases.php`.
- [ ] Done

---

### [H-7] api/send_bulk_sms.php — customer_ids not cast to int
- **File:** `api/send_bulk_sms.php` ~line 78
- **Problem:** Raw `$_POST['customer_ids']` array passed to `execute()` without integer casting or validation.
- **Fix:**
  ```php
  $selectedCustomerIds = isset($_POST['customer_ids']) ? (array)$_POST['customer_ids'] : [];
  $selectedCustomerIds = array_values(array_filter(array_map('intval', $selectedCustomerIds), fn($v) => $v > 0));
  ```
- [ ] Done

---

### [H-8] modules/notifications.php — empty customer array produces invalid SQL IN ()
- **File:** `modules/notifications.php` ~line 80
- **Problem:** If `$customerIds` is empty after filtering, `WHERE id IN ()` is invalid SQL and throws a PDOException.
- **Fix:** Add a guard before building the query:
  ```php
  if(empty($customerIds)){
      flash_msg('No customers with valid phone numbers found.', 'error');
      redirect_with_fallback('?module=notifications');
  }
  ```
- [ ] Done

---

### [H-9] installer.php — database name injected into CREATE DATABASE without proper validation
- **File:** `installer.php` ~line 195
- **Problem:** Only backticks are stripped from `$input['db_name']`. Other characters can still cause issues.
- **Fix:** Validate the DB name strictly before use:
  ```php
  if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $input['db_name'])){
      $errors[] = 'Database name may only contain letters, numbers, and underscores (max 64 chars).';
  }
  ```
- [ ] Done

---

### [H-10] modules/dashboard.php — no permission check in module file
- **File:** `modules/dashboard.php` line 1
- **Problem:** Exposes today's sales totals, customer dues, low-stock items, and recent invoices if the router check is bypassed.
- **Fix:** Add at the top after `require_once`:
  ```php
  if(!is_admin_user() && !has_permission('dashboard.view')){
      flash_msg('You do not have permission to view the dashboard.', 'error');
      redirect_with_fallback('?module=sale');
  }
  ```
- [ ] Done

---

### [H-11] config.php — username-based admin fallback is a privilege escalation vector
- **File:** `config.php` ~line 590
- **Problem:** If the `is_admin` column migration fails, any user named `admin` gets full admin access regardless of their actual role.
- **Fix:** Remove the username-based fallback. Return `false` if the column doesn't exist:
  ```php
  // REMOVE:
  $username = strtolower(trim(...));
  $_SESSION['is_admin'] = $username === 'admin' ? 1 : 0;
  return $username === 'admin';
  // REPLACE with:
  $_SESSION['is_admin'] = 0;
  return false;
  ```
- [ ] Done

---

## 🟡 MEDIUM

### [M-1] SMS API key displayed in plaintext in settings form
- **File:** `modules/settings.php`
- **Problem:** API key pre-populated in HTML `value` attribute — visible in page source and to any XSS.
- **Fix:** Show only a masked value (e.g., `••••••••` + last 4 chars). Use a separate "change key" flow where the field is empty by default and only saved if non-empty.
- [ ] Done

---

### [M-2] SMS balance fetched synchronously on settings page — blocks render up to 30s
- **File:** `modules/settings.php` ~lines 155–175
- **Problem:** Synchronous cURL call to Spellc PAAS API on every settings page load.
- **Fix:** Remove the synchronous fetch. Add a "Check Balance" button that calls `api/sms_balance.php` via `fetch()` and displays the result inline.
- [ ] Done

---

### [M-3] modules/sale.php — client-submitted sell price stored without server-side validation
- **File:** `modules/sale.php` ~line 80
- **Problem:** `$prc = (float)($row['prc'] ?? 0)` from POST is stored in `sale_items.sell_price`. A malicious user can record artificially low prices.
- **Fix:** Ignore client-submitted `prc`. Fetch the price from the database batch record and use only that for storage.
- [ ] Done

---

### [M-4] modules/customers.php — IDOR on payment edit/delete
- **File:** `modules/customers.php` ~lines 130–155
- **Problem:** Any user with `customers.payment` permission can edit or delete any payment record by supplying an arbitrary `pay_id`. No ownership or branch check.
- **Fix:** Add a branch/ownership check:
  ```php
  // Verify the payment belongs to a customer in the user's branch
  $stmt = $pdo->prepare("SELECT cp.id FROM customer_payments cp JOIN customers c ON c.id=cp.customer_id WHERE cp.id=? AND (? = 1 OR c.branch_id=?)");
  $stmt->execute([$payId, $isAdmin ? 1 : 0, $_SESSION['branch_id'] ?? 0]);
  if(!$stmt->fetch()) { /* deny */ }
  ```
- [ ] Done

---

### [M-5] modules/suppliers.php — IDOR on payment log edit/delete
- **File:** `modules/suppliers.php` ~lines 80–120
- **Problem:** Any authenticated user can edit/delete any supplier payment log by supplying an arbitrary `log_id`.
- **Fix:** Same pattern as M-4 — verify the log belongs to a supplier accessible to the current user/branch.
- [ ] Done

---

### [M-6] modules/customer_purchases.php — any logged-in user can process refunds
- **File:** `modules/customer_purchases.php` ~line 60
- **Problem:** `$canAttemptRefund = $isAdmin || $currentUserId > 0;` — any logged-in user can process refunds.
- **Fix:**
  1. Add `sale.return` to the permissions table
  2. Change the check to: `$canAttemptRefund = $isAdmin || has_permission('sale.return');`
- [ ] Done

---

### [M-7] modules/inventory.php — IDOR on batch/product view (no branch check)
- **File:** `modules/inventory.php` ~lines 280–300
- **Problem:** Non-admin users can view any batch/product from any branch by supplying arbitrary `?view_batch=` or `?view_product=` IDs.
- **Fix:** Add branch filter to view queries for non-admin users:
  ```php
  if(!$isAdmin){
      $stmt = $pdo->prepare("SELECT ... FROM batches WHERE id=? AND branch_id=?");
      $stmt->execute([$batchId, $_SESSION['branch_id'] ?? 0]);
  }
  ```
- [ ] Done

---

### [M-8] dashboard.php — module file built before allowlist check
- **File:** `dashboard.php` ~line 60
- **Problem:** `$module_file` is built before the allowlist check. The `file_exists` fallback could include a file not in `$allowedModules`.
- **Fix:** Move `$module_file` assignment to after the allowlist check:
  ```php
  // After all permission checks pass:
  $module_file = __DIR__ . "/modules/{$module}.php";
  if(!file_exists($module_file)) $module_file = __DIR__ . "/modules/sale.php";
  ```
- [ ] Done

---

### [M-9] modules/users.php — auto-grant permissions runs on every page load
- **File:** `modules/users.php` ~lines 45–60
- **Problem:** Auto-grant block re-assigns permissions to users with no permissions on every page load, potentially re-granting intentionally removed permissions.
- **Fix:** Only apply default permissions at user creation time. Remove the auto-grant block from the page load path.
- [ ] Done

---

### [M-10] modules/customer_purchases.php — summary totals calculated from LIMIT 500 result
- **File:** `modules/customer_purchases.php` ~lines 200–210
- **Problem:** Sales query capped at 500 rows. Summary totals (total sales, paid, due) calculated from truncated result — incorrect for users with >500 sales.
- **Fix:** Calculate summary totals in a separate `SUM`/`COUNT` query without `LIMIT`:
  ```php
  $totalsStmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(due_amount),0) as due FROM sales WHERE customer_id=?");
  $totalsStmt->execute([$customerId]);
  $totals = $totalsStmt->fetch();
  ```
- [ ] Done

---

### [M-11] modules/report.php — no LIMIT on report queries — potential DoS
- **File:** `modules/report.php` ~lines 80–130
- **Problem:** All report queries have no `LIMIT`. On large databases, a single request can exhaust memory.
- **Fix:** Add `LIMIT 10000` to all report queries. Display a warning banner when the limit is reached: "Showing first 10,000 records. Use date filters to narrow results."
- [ ] Done

---

### [M-12] modules/settings.php — invoice_footer_note not length-validated
- **File:** `modules/settings.php` ~line 60
- **Problem:** Stored in `VARCHAR(255)` column without length validation. Values >255 chars are silently truncated.
- **Fix:** Either validate `mb_strlen($invoiceFooterNote) <= 500` and change the column to `TEXT`, or truncate with a user-visible error.
- [ ] Done

---

### [M-13] Phone number validation inconsistency between notifications and send_bulk_sms
- **Files:** `modules/notifications.php`, `api/send_bulk_sms.php`
- **Problem:** `send_bulk_sms.php` strips `+977` prefix before sending. `notifications.php` does not. SMS sending may fail for numbers stored with the prefix.
- **Fix:** Extract phone normalization into a shared helper function in `config.php`:
  ```php
  function normalize_nepal_phone(string $phone): string {
      $phone = preg_replace('/^\+977/', '', trim($phone));
      return $phone;
  }
  ```
  Use it in both files.
- [ ] Done

---

### [M-14] LicenseManager — license cache stored in user-writable app_settings table
- **File:** `src/LicenseManager.php` ~lines 130–145
- **Problem:** An admin can manually set `license_valid=true` in `app_settings` to bypass license verification.
- **Fix:** Sign the cached value with a server-side HMAC secret (stored in `.env` as `LICENSE_CACHE_SECRET`):
  ```php
  $sig = hash_hmac('sha256', json_encode($result), Env::get('LICENSE_CACHE_SECRET', 'fallback'));
  self::saveSetting('license_cache', json_encode(['data' => $result, 'sig' => $sig]));
  // On read, verify sig before trusting cached data
  ```
- [ ] Done

---

### [M-15] No rate limiting on license activation endpoint
- **Files:** `modules/settings.php`, `activate.php`
- **Problem:** License key activation has no rate limiting. Attacker can brute-force keys and DoS the external license server.
- **Fix:** Add a simple DB-backed rate limit (max 5 attempts per IP per hour) to both `activate.php` and the `activate_license` POST handler in `settings.php`.
- [ ] Done

---

### [M-16] modules/sale.php — sale_date_bs field name is misleading
- **File:** `modules/sale.php` ~line 225
- **Problem:** POST field named `sale_date_bs` is treated as an AD (Gregorian) date. Any client sending an actual BS date silently stores wrong data.
- **Fix:** Rename the POST field to `sale_date_ad` and update the corresponding DB column comment. Or add explicit validation that the value is a valid Gregorian date.
- [ ] Done

---

## 🔵 LOW

### [L-1] No HTTPS enforcement — session cookies sent in plaintext over HTTP
- **Files:** `config.php`, `.htaccess`
- **Problem:** No HTTP→HTTPS redirect. `session.cookie_secure` only set when HTTPS already detected.
- **Fix:** Add to `.htaccess`:
  ```apache
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  ```
- [ ] Done

---

### [L-2] .htaccess missing file protection and security headers
- **File:** `.htaccess`
- **Problem:** `.env`, `config.db.php`, `install.sh`, `pharmacy_npr.sql`, `install.lock` are all web-accessible. No security headers set.
- **Fix:** Add to `.htaccess`:
  ```apache
  # Block sensitive files
  <FilesMatch "^(\.env|config\.db\.php|install\.sh|pharmacy_npr\.sql|install\.lock|CONTEXT\.md|TASKS\.md|pharmacore-integration\.md)$">
      Require all denied
  </FilesMatch>

  # Security headers
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
  ```
- [ ] Done

---

### [L-3] Login lockout enables targeted denial-of-service against known usernames
- **File:** `login.php`
- **Problem:** An attacker who knows a valid username can lock that user out for 15 minutes by making 10 failed attempts.
- **Fix:** Implement IP-based lockout in addition to username-based lockout. IP lockout should use a higher threshold (e.g., 50 attempts) with exponential backoff. Consider CAPTCHA after 5 failures.
- [ ] Done

---

### [L-4] Inconsistent password minimum length across files
- **Files:** `installer.php` (≥8), `dashboard.php` (≥6), `modules/users.php` (none server-side)
- **Problem:** Three different password policies in the same app.
- **Fix:** Define a constant in `config.php`:
  ```php
  define('MIN_PASSWORD_LENGTH', 8);
  ```
  Use `MIN_PASSWORD_LENGTH` in all password validation checks across all files.
- [ ] Done

---

### [L-5] invoice_auto_print_id session variable not cleared on navigation away
- **File:** `modules/sale.php` ~line 295
- **Problem:** If the user navigates away before the print page loads, the session variable persists and triggers auto-print on the next visit to any invoice with the same ID.
- **Fix:** Add a TTL to the session variable:
  ```php
  $_SESSION['invoice_auto_print_id'] = $invoiceId;
  $_SESSION['invoice_auto_print_expires'] = time() + 30; // 30 second window
  ```
  In `invoice_print.php`, check the TTL before using it.
- [ ] Done

---

### [L-6] src/Env.php — putenv key names not validated
- **File:** `src/Env.php` ~line 40
- **Problem:** `putenv("$key=$value")` called with keys read from `.env` without validation. A malformed key could cause unexpected behavior.
- **Fix:** Add key validation:
  ```php
  if(!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) continue;
  ```
- [ ] Done

---

### [L-7] modules/dashboard.php — shows all-branch data to branch-scoped users
- **File:** `modules/dashboard.php`
- **Problem:** Non-admin users with `dashboard.view` see sales totals, customer dues, and recent invoices across all branches.
- **Fix:** Add `WHERE branch_id = ?` filters to all dashboard queries for non-admin users, using `$_SESSION['branch_id']`.
- [ ] Done

---

### [L-8] api/sms_balance.php — no CSRF protection
- **File:** `api/sms_balance.php`
- **Problem:** GET endpoint triggers an external HTTP call to the SMS provider. No CSRF protection means a third-party page can trigger it.
- **Fix:** Change to POST method and add CSRF verification, or add a `Referer` check. Alternatively, add a nonce parameter.
- [ ] Done

---

### [L-9] modules/stock_transfer_records.php — missing JSON_HEX_TAG in JS embed
- **File:** `modules/stock_transfer_records.php` (last JS block)
- **Problem:** `json_encode($returnMap)` without `JSON_HEX_TAG`. A value containing `</script>` breaks out of the script tag.
- **Fix:**
  ```php
  var reverseHistoryMap = <?= json_encode($returnMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  ```
- [ ] Done

---

### [L-10] modules/notifications.php — missing JSON_HEX_TAG in JS embed
- **File:** `modules/notifications.php` (last JS block)
- **Problem:** `json_encode($pharmacyNameForSms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` — missing `JSON_HEX_TAG`. A pharmacy name containing `</script>` breaks out of the script tag.
- **Fix:**
  ```php
  var pharmacyNameForSms = <?= json_encode($pharmacyNameForSms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  ```
- [ ] Done

---

### [L-11] install.sh — database password visible in process list
- **File:** `install.sh` ~lines 100–110
- **Problem:** `eval "$MYSQL_CMD ..."` with password in the command string — visible in `ps aux` and shell history.
- **Fix:** Use a temp credentials file:
  ```bash
  MYSQL_DEFAULTS=$(mktemp)
  printf '[client]\npassword=%s\n' "$DB_PASS" > "$MYSQL_DEFAULTS"
  chmod 600 "$MYSQL_DEFAULTS"
  mysql --defaults-extra-file="$MYSQL_DEFAULTS" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`..."
  rm -f "$MYSQL_DEFAULTS"
  ```
- [ ] Done

---

### [L-12] Multiple JS embeds missing JSON_HEX_TAG across modules
- **Files:** `modules/sale.php`, `modules/sales_record.php`, `modules/inventory.php`, `modules/customers.php`
- **Problem:** Various `json_encode()` calls embedding PHP data into `<script>` blocks are missing `JSON_HEX_TAG | JSON_HEX_AMP` flags.
- **Fix:** Search for all `json_encode` calls inside `<script>` blocks and add `JSON_HEX_TAG | JSON_HEX_AMP` to each. Run:
  ```
  grep -rn "json_encode" modules/ --include="*.php"
  ```
  Review each result and add the flags where the output is embedded in HTML/JS.
- [ ] Done

---

## Progress Summary

| Severity | Total | Done |
|----------|-------|------|
| 🔴 CRITICAL | 5 | 5 |
| 🟠 HIGH | 11 | 11 |
| 🟡 MEDIUM | 16 | 14 |
| 🔵 LOW | 12 | 12 |
| **Total** | **44** | **42** |

---

## Quick Start for Next Session

Paste this at the start of a new chat:

> "I'm working on PharmaCore at `c:\laragon\www\PC`. Read `CONTEXT.md` and `TASKS.md`. Fix tasks C-1 through C-5 (all CRITICAL items). Mark each `[x]` when done and commit."
