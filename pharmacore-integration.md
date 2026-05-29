# Pharmacore License Server — Full API Reference & Integration Context

This is a Laravel-based license server that manages software licenses.

## Live Server
- **Base URL:** `https://license.gdn.com.np`
- **Verify endpoint (live):** `https://license.gdn.com.np/api/verify`
- Server is confirmed live (returns 405 on GET — correct, only accepts POST)

## Reference Implementation — YummyCloud (CloudKitchen)
The YummyCloud app (`c:\laragon\www\CloudKitchen\backend`) already integrates this license server.
When implementing license integration in Pharmacore, **copy the exact same pattern** from YummyCloud.

Key files in YummyCloud to reference:
- `app/Services/LicenseService.php` — calls /api/verify, caches result
- `app/Http/Middleware/VerifyLicense.php` — blocks requests if license invalid
- `app/Http/Controllers/Api/AdminController.php` — `verifyLicense()` and `licenseStatus()` methods
- `bootstrap/app.php` — middleware alias registration
- `routes/api.php` — middleware applied as `verify.license` on admin route group

---

## Exact Implementation (copied from YummyCloud — use same in Pharmacore)

### 1. LicenseService.php
```php
<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class LicenseService
{
    private string $verifyUrl = 'https://license.gdn.com.np/api/verify';
    private string $productSlug = 'pharmacore'; // change from 'yummycloud' to 'pharmacore'

    public function verify(?string $licenseKey = null, bool $forceCheck = false): array
    {
        $licenseKey = $licenseKey ?: Setting::get('license_key');

        if (!$licenseKey) {
            return ['valid' => false, 'message' => 'No license key configured.'];
        }

        $cacheKey = 'license_status_' . md5($licenseKey);

        if (!$forceCheck && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $domain = $this->getCurrentDomain();

            $response = Http::timeout(10)->post($this->verifyUrl, [
                'license_key'  => $licenseKey,
                'product_slug' => $this->productSlug,
                'domain'       => $domain,
                'timestamp'    => time(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $isValid = ($data['status'] ?? '') === 'valid';
                $result = [
                    'valid'      => $isValid,
                    'message'    => $data['message'] ?? 'License verified.',
                    'expires_at' => $data['expires_at'] ?? null,
                    'plan'       => $data['plan'] ?? null,
                    'status'     => $data['status'] ?? 'unknown',
                ];
            } else {
                $result = ['valid' => false, 'message' => 'License server returned an error.'];
            }
        } catch (\Exception $e) {
            Log::warning('License verification failed: ' . $e->getMessage());
            // On network failure, allow grace period using cached status
            $cached = Cache::get($cacheKey);
            if ($cached && $cached['valid']) {
                return $cached;
            }
            $result = ['valid' => false, 'message' => 'Unable to reach license server.'];
        }

        // Cache for 24 hours
        Cache::put($cacheKey, $result, now()->addHours(24));

        // Store status in settings for quick access
        Setting::set('license_valid', $result['valid'] ? 'true' : 'false');
        Setting::set('license_message', $result['message']);

        return $result;
    }

    public function isValid(): bool
    {
        $licenseKey = Setting::get('license_key');
        if (!$licenseKey) return false;

        $cacheKey = 'license_valid_' . md5($licenseKey);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->verify($licenseKey, forceCheck: true);
        $valid = $result['valid'] === true;

        // Cache validity for 5 minutes
        Cache::put($cacheKey, $valid, now()->addMinutes(5));

        return $valid;
    }

    private function getCurrentDomain(): string
    {
        $appUrl = config('app.url', request()->getSchemeAndHttpHost());
        return parse_url($appUrl, PHP_URL_HOST) ?: request()->getHost();
    }

    public function clearCache(): void
    {
        $licenseKey = Setting::get('license_key');
        if ($licenseKey) {
            Cache::forget('license_status_' . md5($licenseKey));
            Cache::forget('license_valid_' . md5($licenseKey));
        }
        Setting::set('license_valid', null);
        Setting::set('license_message', null);
    }
}
```

### 2. VerifyLicense Middleware
```php
<?php
namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLicense
{
    public function __construct(private LicenseService $licenseService) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Always allow license and settings endpoints (needed to activate license)
        if ($request->is('api/admin/license/*') || $request->is('api/admin/settings') || $request->is('api/admin/settings/*')) {
            return $next($request);
        }

        if (!$this->licenseService->isValid()) {
            return response()->json([
                'message'       => 'Invalid or expired license. Please activate your license in Settings.',
                'license_error' => true,
            ], 403);
        }

        return $next($request);
    }
}
```

### 3. Register Middleware Alias in bootstrap/app.php
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'verify.license' => \App\Http\Middleware\VerifyLicense::class,
    ]);
})
```

### 4. Apply to Routes in routes/api.php
```php
// All protected admin routes require auth + valid license
Route::middleware(['auth:sanctum', 'verify.license'])->prefix('admin')->group(function () {
    // License management routes (these are EXCLUDED from license check in middleware)
    Route::post('/license/verify', [AdminController::class, 'verifyLicense']);
    Route::get('/license/status',  [AdminController::class, 'licenseStatus']);

    // All other admin routes...
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    // etc.
});
```

### 5. Admin Controller Methods (verifyLicense + licenseStatus)
```php
// Activate / change license key
public function verifyLicense(Request $request)
{
    $request->validate(['license_key' => 'required|string']);

    \App\Models\Setting::set('license_key', $request->license_key);

    $licenseService = app(\App\Services\LicenseService::class);
    $licenseService->clearCache();
    $result = $licenseService->verify($request->license_key, forceCheck: true);

    return response()->json($result);
}

// Get current license status (masked key)
public function licenseStatus()
{
    $licenseService = app(\App\Services\LicenseService::class);
    $result = $licenseService->verify();
    $key = \App\Models\Setting::get('license_key');
    $result['license_key'] = $key ? '••••••••' . substr($key, -6) : null;

    return response()->json($result);
}
```

### 6. Setting Model (required — license_key stored in DB settings table)
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function getAll(): array
    {
        return self::pluck('value', 'key')->toArray();
    }
}
```

The license_key is stored in the `settings` table with key `license_key`, NOT in .env.
This allows the admin to change the license key from the UI without server access.

---

## How License Key is Stored

| Where | Key | Value |
|-------|-----|-------|
| `settings` DB table | `license_key` | `XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX` |
| `settings` DB table | `license_valid` | `true` or `false` (cached status) |
| `settings` DB table | `license_message` | Last verification message |

---

## API Endpoints on the License Server

### Verify License — POST https://license.gdn.com.np/api/verify
No auth required. Called by Pharmacore on every admin request (with 5-min cache).

**Request:**
```json
{
    "license_key":  "A1B2C3D4-E5F6-7890-ABCD-EF1234567890",
    "domain":       "citypharmacy.com",
    "product_slug": "pharmacore",
    "timestamp":    1748505600
}
```

**Success Response:**
```json
{
    "status":     "valid",
    "message":    "License is active.",
    "timestamp":  1748505600,
    "expires_at": "2027-05-29T00:00:00.000000Z",
    "plan":       "yearly",
    "signature":  "3d9f2a1b...hmac-sha256"
}
```

**Failure Responses:**
```json
{ "status": "expired",         "message": "License has expired.",                  "timestamp": 1748505600, "signature": "..." }
{ "status": "suspended",       "message": "License has been suspended.",            "timestamp": 1748505600, "signature": "..." }
{ "status": "domain_mismatch", "message": "License is not valid for this domain.", "timestamp": 1748505600, "signature": "..." }
{ "status": "invalid",         "message": "License key not found.",                "timestamp": 1748505600 }
```

### Lookup License — POST https://license.gdn.com.np/api/lookup
```json
{ "license_key": "A1B2C3D4-..." }
```
Response: `{ found, client_name, product, domain, plan, status, expires_at, activated_at }`

### Admin Login — POST https://license.gdn.com.np/api/admin/login
```json
{ "email": "admin@example.com", "password": "password" }
```
Response: `{ user: {...}, token: "1|abc..." }`

### Create License — POST https://license.gdn.com.np/api/admin/licenses
Header: `Authorization: Bearer {token}`
```json
{
    "product_id":   1,
    "client_name":  "City Pharmacy",
    "client_email": "owner@citypharmacy.com",
    "client_phone": "9800000000",
    "domain":       "citypharmacy.com",
    "plan":         "yearly",
    "price":        5000
}
```
Plans: `monthly` (now+1mo), `yearly` (now+1yr), `lifetime` (null expiry), `custom` (uses expires_at field)

### Other Admin Endpoints (Bearer token required)
```
GET    /api/admin/licenses              list (filter: ?product_id=&status=&search=)
GET    /api/admin/licenses/{id}         detail + last 50 verification logs
PUT    /api/admin/licenses/{id}         update
POST   /api/admin/licenses/{id}/renew   { plan, expires_at }
POST   /api/admin/licenses/{id}/suspend { reason }
POST   /api/admin/licenses/{id}/email   send license details email
DELETE /api/admin/licenses/{id}
POST   /api/admin/licenses/bulk         { ids: [], action: activate|suspend|delete|email }
GET    /api/admin/products
POST   /api/admin/products              { name, slug, description } — hmac_secret auto-generated
GET    /api/admin/dashboard
GET    /api/admin/logs                  verification logs (?license_id=)
GET    /api/admin/settings
PUT    /api/admin/settings
GET    /api/admin/activity
GET    /api/admin/users                 (super_admin only)
```

---

## Domain Normalization
Server normalizes domain before comparing:
- Lowercase
- Strip `http://` or `https://`
- Strip leading `www.`
- Strip trailing `/`

`https://www.citypharmacy.com/` → `citypharmacy.com`

YummyCloud uses `parse_url(config('app.url'), PHP_URL_HOST)` to get domain from APP_URL in .env.

---

## Cache Strategy (from YummyCloud)
- `license_status_{md5(key)}` — full verify result, cached **24 hours**
- `license_valid_{md5(key)}`  — boolean valid/invalid, cached **5 minutes**
- On network failure: falls back to existing cache if previously valid (grace period)
- `clearCache()` called when license key is changed via admin UI

---

## Product Slugs
- YummyCloud: `yummycloud`
- Pharmacore: `pharmacore`
