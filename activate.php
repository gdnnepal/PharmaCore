<?php
declare(strict_types=1);

// Harden session cookie before session_start()
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'){
    ini_set('session.cookie_secure', '1');
}
session_start();

// Bootstrap only what we need — avoid full config.php which triggers license check
require_once __DIR__ . '/src/Env.php';
Env::load(__DIR__ . '/.env');

// Minimal DB connection
$_dbConfig = [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'name' => Env::get('DB_NAME', 'pharmacy_npr'),
    'user' => Env::get('DB_USER', 'root'),
    'pass' => Env::get('DB_PASS', ''),
];

$pdo = null;
$dbError = '';
try {
    $pdo = new PDO(
        "mysql:host={$_dbConfig['host']};port={$_dbConfig['port']};dbname={$_dbConfig['name']};charset=utf8mb4",
        $_dbConfig['user'],
        $_dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch(Throwable $e){
    $dbError = 'Database connection failed. Please check your .env configuration.';
}

require_once __DIR__ . '/src/LicenseManager.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// Determine base URL
$_docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
$_appRoot = realpath(__DIR__);
$baseUrl  = '';
if($_docRoot !== false && $_appRoot !== false){
    $_docRootNorm = str_replace('\\', '/', strtolower((string)$_docRoot));
    $_appRootNorm = str_replace('\\', '/', strtolower((string)$_appRoot));
    if(str_starts_with($_appRootNorm, $_docRootNorm)){
        $baseUrl = '/' . trim(str_replace('\\', '/', substr((string)$_appRoot, strlen((string)$_docRoot))), '/');
    }
}
if($baseUrl === '' || $baseUrl === '/'){
    $baseUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
}

// CSRF
if(empty($_SESSION['activate_csrf'])){
    $_SESSION['activate_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['activate_csrf'];

$error   = (string)($_SESSION['license_error'] ?? '');
$success = '';
unset($_SESSION['license_error']);

// Handle AJAX activation (called from JS fetch)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json; charset=utf-8');

    $postCsrf = trim((string)($_POST['csrf'] ?? ''));
    if(!hash_equals($csrfToken, $postCsrf)){
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
        exit;
    }

    if($pdo === null){
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $dbError]);
        exit;
    }

    $licenseKey = trim((string)($_POST['license_key'] ?? ''));
    $result     = LicenseManager::activate($licenseKey);

    if($result['valid']){
        echo json_encode([
            'success'    => true,
            'message'    => $result['message'],
            'plan'       => $result['plan'],
            'expires_at' => $result['expires_at'],
            'redirect'   => $baseUrl . '/login.php',
        ]);
    } else {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'status'  => $result['status'],
        ]);
    }
    exit;
}

// Handle regular POST (non-JS fallback)
if($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    $postCsrf = trim((string)($_POST['csrf'] ?? ''));
    if(!hash_equals($csrfToken, $postCsrf)){
        $error = 'Invalid request token. Please try again.';
    } elseif($pdo === null){
        $error = $dbError;
    } else {
        $licenseKey = trim((string)($_POST['license_key'] ?? ''));
        $result     = LicenseManager::activate($licenseKey);

        if($result['valid']){
            $success = 'License activated successfully. Redirecting to login...';
            header('Refresh: 2; url=' . $baseUrl . '/login.php');
        } else {
            $error = $result['message'];
        }
    }
}

// If already valid, redirect to login
if($pdo !== null && $success === '' && $error === ''){
    $current = LicenseManager::check();
    if($current['valid']){
        header('Location: ' . $baseUrl . '/login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCore — License Activation</title>
    <link rel="icon" type="image/x-icon" href="<?= h($baseUrl . '/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= h($baseUrl . '/css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        .key-input { font-family: 'Courier New', monospace; letter-spacing: 0.08em; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { animation: spin 0.8s linear infinite; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-teal-50 flex items-center justify-center p-6">
    <div class="w-full max-w-lg">

        <!-- Logo / Brand -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 shadow-lg mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">License Activation</h1>
            <p class="text-slate-500 text-sm mt-1">Enter your PharmaCore license key to continue</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200/60 overflow-hidden">

            <!-- Status banner (error from previous redirect) -->
            <?php if($error !== ''): ?>
            <div class="px-6 pt-5">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <span id="errorBanner"><?= h($error) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if($success !== ''): ?>
            <div class="px-6 pt-5">
                <div class="flex items-start gap-3 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-700">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= h($success) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="p-6">
                <form id="activateForm" method="POST" novalidate>
                    <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">
                                License Key <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="licenseKeyInput"
                                name="license_key"
                                required
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"
                                class="key-input w-full px-4 py-3 border border-slate-300 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition text-sm"
                            >
                            <p class="text-xs text-slate-500 mt-1.5">Format: 8-4-4-4-12 hexadecimal characters</p>
                        </div>

                        <!-- Result area (shown after AJAX response) -->
                        <div id="resultArea" class="hidden"></div>

                        <button
                            type="submit"
                            id="activateBtn"
                            class="w-full flex items-center justify-center gap-2 bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white font-semibold py-3 px-6 rounded-xl transition shadow-sm hover:shadow-md"
                        >
                            <svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <span id="btnText">Activate License</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Footer info -->
            <div class="px-6 pb-6 border-t border-slate-100 pt-4">
                <div class="grid grid-cols-3 gap-3 text-center text-xs text-slate-500">
                    <div class="flex flex-col items-center gap-1">
                        <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <span>Secure Verification</span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                        <span>Domain Locked</span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <svg class="w-4 h-4 text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>24/7 Support</span>
                    </div>
                </div>
                <p class="text-center text-xs text-slate-400 mt-4">
                    Need a license key? Contact
                    <a href="mailto:support@gdn.com.np" class="text-teal-600 hover:underline">support@gdn.com.np</a>
                </p>
            </div>
        </div>

        <p class="text-center text-xs text-slate-400 mt-6">PharmaCore &copy; <?= date('Y') ?> GDN Nepal</p>
    </div>

    <script>
    (function(){
        var form       = document.getElementById('activateForm');
        var btn        = document.getElementById('activateBtn');
        var btnText    = document.getElementById('btnText');
        var btnIcon    = document.getElementById('btnIcon');
        var resultArea = document.getElementById('resultArea');
        var input      = document.getElementById('licenseKeyInput');

        // Auto-uppercase the key as user types
        input.addEventListener('input', function(){
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });

        function setLoading(loading){
            btn.disabled = loading;
            if(loading){
                btnIcon.outerHTML = '<svg id="btnIcon" class="w-4 h-4 spinner" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
                btnText.textContent = 'Verifying...';
            } else {
                document.getElementById('btnIcon').outerHTML = '<svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
                btnText.textContent = 'Activate License';
            }
        }

        function showResult(success, message){
            resultArea.classList.remove('hidden');
            if(success){
                resultArea.innerHTML = '<div class="flex items-start gap-3 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-700">'
                    + '<svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
                    + '<span>' + message + '</span></div>';
            } else {
                resultArea.innerHTML = '<div class="flex items-start gap-3 p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700">'
                    + '<svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>'
                    + '<span>' + message + '</span></div>';
            }
        }

        form.addEventListener('submit', function(e){
            e.preventDefault();

            var key = input.value.trim();
            if(key === ''){
                showResult(false, 'Please enter your license key.');
                return;
            }

            setLoading(true);
            resultArea.classList.add('hidden');

            var formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
            .then(function(res){ return res.json(); })
            .then(function(data){
                setLoading(false);
                if(data.success){
                    showResult(true, data.message + ' Redirecting...');
                    setTimeout(function(){ window.location.href = data.redirect; }, 1500);
                } else {
                    showResult(false, data.message || 'Activation failed. Please try again.');
                }
            })
            .catch(function(){
                setLoading(false);
                showResult(false, 'Network error. Please check your connection and try again.');
            });
        });
    })();
    </script>
</body>
</html>
