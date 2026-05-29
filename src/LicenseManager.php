<?php
declare(strict_types=1);

/**
 * LicenseManager
 *
 * Verifies PharmaCore licenses against https://license.gdn.com.np/api/verify
 * - License key stored in app_settings DB table (key: license_key)
 * - Verification result cached in app_settings (key: license_cache) for 24 hours
 * - 72-hour grace period when license server is unreachable
 * - Hard-blocks the app on invalid/expired/suspended license
 */
class LicenseManager
{
    private const VERIFY_URL   = 'https://license.gdn.com.np/api/verify';
    private const PRODUCT_SLUG = 'pharmacore';
    private const CACHE_TTL    = 86400;      // 24 hours in seconds
    private const GRACE_TTL    = 259200;     // 72 hours in seconds

    // Pages that must never be blocked (needed to activate the license)
    private const PUBLIC_SCRIPTS = ['installer.php', 'activate.php', 'login.php'];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Called once from config.php after DB is ready.
     * Redirects to activate.php if license is not valid.
     */
    public static function boot(): void
    {
        $entry = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if(in_array($entry, self::PUBLIC_SCRIPTS, true)){
            return;
        }

        $result = self::check();

        if(!$result['valid']){
            // Store the reason so activate.php can display it
            $_SESSION['license_error'] = $result['message'] ?? 'License is not valid.';
            $base = self::getBaseUrl();
            if(!headers_sent()){
                header('Location: ' . $base . '/activate.php');
            } else {
                echo '<script>window.location.href=' . json_encode($base . '/activate.php', JSON_HEX_TAG) . ';</script>';
            }
            exit;
        }
    }

    /**
     * Verify the license. Uses cache; falls back to grace period on network failure.
     *
     * @param bool $force  Skip cache and call the server directly
     * @return array{valid: bool, status: string, message: string, expires_at: string|null, plan: string|null, cached: bool}
     */
    public static function check(bool $force = false): array
    {
        global $pdo;

        $licenseKey = self::getLicenseKey();

        if($licenseKey === ''){
            return self::result(false, 'no_key', 'No license key configured.');
        }

        // Return cached result unless forced
        if(!$force){
            $cached = self::getCache();
            if($cached !== null){
                $cached['cached'] = true;
                return $cached;
            }
        }

        // Call the license server
        $response = self::callServer($licenseKey);

        if($response === null){
            // Network failure — use grace period
            $stale = self::getCache(ignoreExpiry: true);
            if($stale !== null){
                $cachedAt = (int)($stale['cached_at'] ?? 0);
                if((time() - $cachedAt) < self::GRACE_TTL){
                    $stale['cached']  = true;
                    $stale['message'] = 'License server unreachable. Using cached status (grace period).';
                    return $stale;
                }
            }
            return self::result(false, 'unreachable', 'Unable to reach license server. Grace period expired.');
        }

        $status  = (string)($response['status']  ?? 'invalid');
        $valid   = ($status === 'valid');
        $message = (string)($response['message'] ?? ucfirst($status));

        $result = self::result(
            $valid,
            $status,
            $message,
            (string)($response['expires_at'] ?? ''),
            (string)($response['plan']       ?? '')
        );

        // Persist to cache
        self::setCache($result);

        // Also persist plain flags for quick reads
        self::saveSetting('license_valid',   $valid ? 'true' : 'false');
        self::saveSetting('license_status',  $status);
        self::saveSetting('license_message', $message);

        return $result;
    }

    /**
     * Activate a new license key: saves it, clears cache, re-verifies.
     *
     * @return array{valid: bool, status: string, message: string, ...}
     */
    public static function activate(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);

        if($licenseKey === ''){
            return self::result(false, 'invalid', 'License key cannot be empty.');
        }

        // Basic UUID format check
        if(!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $licenseKey)){
            return self::result(false, 'invalid', 'Invalid license key format. Expected: XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX');
        }

        self::saveSetting('license_key', $licenseKey);
        self::clearCache();

        return self::check(force: true);
    }

    /**
     * Clear the verification cache (call after changing the license key).
     */
    public static function clearCache(): void
    {
        self::saveSetting('license_cache', '');
    }

    /**
     * Return the stored license key (masked for display).
     * e.g. "A1B2C3D4-••••-••••-••••-EF1234567890"
     */
    public static function getMaskedKey(): string
    {
        $key = self::getLicenseKey();
        if($key === '') return '';
        $parts = explode('-', $key);
        if(count($parts) === 5){
            return $parts[0] . '-••••-••••-••••-' . $parts[4];
        }
        return '••••••••' . substr($key, -6);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function getLicenseKey(): string
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='license_key' LIMIT 1");
            $stmt->execute();
            return trim((string)($stmt->fetchColumn() ?: ''));
        } catch(Throwable $e){
            return '';
        }
    }

    /**
     * @param bool $ignoreExpiry  If true, return cache even if expired (for grace period)
     */
    private static function getCache(bool $ignoreExpiry = false): ?array
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='license_cache' LIMIT 1");
            $stmt->execute();
            $raw = (string)($stmt->fetchColumn() ?: '');
            if($raw === '') return null;

            // M-14: Verify HMAC signature to prevent manual DB tampering
            $sigStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='license_cache_sig' LIMIT 1");
            $sigStmt->execute();
            $storedSig = (string)($sigStmt->fetchColumn() ?: '');
            $expectedSig = hash_hmac('sha256', $raw, self::getCacheSecret());
            if($storedSig === '' || !hash_equals($expectedSig, $storedSig)){
                return null; // tampered or missing signature
            }

            $data = json_decode($raw, true);
            if(!is_array($data)) return null;

            $cachedAt = (int)($data['cached_at'] ?? 0);
            if(!$ignoreExpiry && (time() - $cachedAt) > self::CACHE_TTL){
                return null; // expired
            }

            return $data;
        } catch(Throwable $e){
            return null;
        }
    }

    private static function setCache(array $result): void
    {
        $result['cached_at'] = time();
        $json = (string)json_encode($result);
        // M-14: Sign cache with HMAC
        $sig = hash_hmac('sha256', $json, self::getCacheSecret());
        self::saveSetting('license_cache', $json);
        self::saveSetting('license_cache_sig', $sig);
    }

    /**
     * M-14: Cache signing secret — unique per installation path
     */
    private static function getCacheSecret(): string
    {
        return hash('sha256', __DIR__ . '|pharmacore_license_cache_v1');
    }

    /**
     * POST to the license server. Returns decoded JSON array or null on failure.
     */
    private static function callServer(string $licenseKey): ?array
    {
        if(!function_exists('curl_init')) return null;

        $domain    = self::normalizeDomain();
        $timestamp = time();

        $payload = json_encode([
            'license_key'  => $licenseKey,
            'product_slug' => self::PRODUCT_SLUG,
            'domain'       => $domain,
            'timestamp'    => $timestamp,
        ]);

        $ch = curl_init(self::VERIFY_URL);
        if($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if($curlError !== '' || $response === false){
            error_log('[LicenseManager] cURL error: ' . $curlError);
            return null;
        }

        $data = json_decode((string)$response, true);
        if(!is_array($data)){
            error_log('[LicenseManager] Invalid JSON from license server (HTTP ' . $httpCode . ')');
            return null;
        }

        return $data;
    }

    /**
     * Normalize the current domain to match server-side normalization:
     * lowercase, strip http(s)://, strip www., strip trailing /
     */
    private static function normalizeDomain(): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        // Strip port
        $host = (string)preg_replace('/:\d+$/', '', $host);
        // Strip www.
        if(str_starts_with($host, 'www.')){
            $host = substr($host, 4);
        }
        return $host;
    }

    private static function getBaseUrl(): string
    {
        if(function_exists('get_base_url')){
            return get_base_url();
        }
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        return rtrim(str_replace('\\', '/', dirname($script)), '/');
    }

    private static function saveSetting(string $key, string $value): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            );
            $stmt->execute([$key, $value]);
        } catch(Throwable $e){
            error_log('[LicenseManager] Failed to save setting ' . $key . ': ' . $e->getMessage());
        }
    }

    /**
     * Build a consistent result array.
     */
    private static function result(
        bool   $valid,
        string $status,
        string $message,
        string $expiresAt = '',
        string $plan      = ''
    ): array {
        return [
            'valid'      => $valid,
            'status'     => $status,
            'message'    => $message,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'plan'       => $plan      !== '' ? $plan      : null,
            'cached'     => false,
            'cached_at'  => null,
        ];
    }
}
