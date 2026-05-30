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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0f172a;
            --mint: #0d9488;
            --mint-deep: #0f766e;
            --sand: #f8fafc;
        }
        body { font-family: 'Manrope', system-ui, sans-serif; }
        .key-input { font-family: 'Courier New', monospace; letter-spacing: 0.06em; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { animation: spin 0.8s linear infinite; }
    </style>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#ccfbf1,_transparent_45%),radial-gradient(circle_at_bottom_right,_#e0f2fe,_transparent_55%),linear-gradient(135deg,#f8fafc_0%,#ecfeff_40%,#f8fafc_100%)] text-slate-800">
    <div class="min-h-screen grid lg:grid-cols-2">
        <!-- Left panel — same as login page -->
        <section class="hidden lg:flex relative overflow-hidden p-12 xl:p-16 bg-[linear-gradient(145deg,#0f766e_0%,#134e4a_45%,#0f172a_100%)] text-white">
            <div class="absolute -top-24 -left-16 w-72 h-72 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute -bottom-24 -right-16 w-80 h-80 rounded-full bg-teal-300/20 blur-2xl"></div>
            <div class="relative z-10 max-w-md self-end space-y-6">
                <p class="text-xs tracking-[0.35em] uppercase text-teal-100">License Activation</p>
                <h1 class="text-4xl font-extrabold leading-tight">Activate your PharmaCore license to get started.</h1>
                <p class="text-teal-50/90 text-sm leading-relaxed">Enter your license key to unlock all features. Your license is domain-locked and verified securely.</p>
            </div>
        </section>

        <!-- Right panel — activation form -->
        <section class="flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-md bg-white/90 backdrop-blur rounded-3xl border border-white shadow-[0_24px_80px_rgba(15,23,42,0.14)] p-7 sm:p-9">
                <div class="mb-8">
                    <p class="text-xs font-bold tracking-[0.25em] uppercase text-teal-700">PharmaCore</p>
                    <h2 class="mt-2 text-3xl font-extrabold text-slate-900">Activate License</h2>
                    <p class="mt-1 text-sm text-slate-500">Enter your license key to continue.</p>
                </div>

                <?php if($error !== ''): ?>
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($error) ?></div>
                <?php endif; ?>
                <?php if($success !== ''): ?>
                    <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800"><?= h($success) ?></div>
                <?php endif; ?>

                <form id="activateForm" method="POST" novalidate class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">License Key</label>
                        <input
                            type="text"
                            id="licenseKeyInput"
                            name="license_key"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"
                            class="key-input w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-teal-600 focus:ring-4 focus:ring-teal-100 text-sm"
                        >
                        <p class="text-xs text-slate-400 mt-1.5">UUID format: 8-4-4-4-12 characters</p>
                    </div>

                    <!-- Result area -->
                    <div id="resultArea" class="hidden"></div>

                    <button
                        type="submit"
                        id="activateBtn"
                        class="w-full rounded-xl bg-[linear-gradient(135deg,var(--mint)_0%,var(--mint-deep)_100%)] px-4 py-3 text-sm font-bold text-white shadow-lg shadow-teal-900/20 transition hover:translate-y-[-1px] flex items-center justify-center gap-2"
                    >
                        <svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <span id="btnText">Activate License</span>
                    </button>
                </form>

                <p class="text-center text-xs text-slate-400 mt-6">
                    Need a license? Contact <a href="mailto:support@gdn.com.np" class="text-teal-600 hover:underline font-medium">support@gdn.com.np</a>
                </p>
            </div>
        </section>
    </div>

    <script>
    (function(){
        var form       = document.getElementById('activateForm');
        var btn        = document.getElementById('activateBtn');
        var btnText    = document.getElementById('btnText');
        var btnIcon    = document.getElementById('btnIcon');
        var resultArea = document.getElementById('resultArea');
        var input      = document.getElementById('licenseKeyInput');

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
                resultArea.innerHTML = '<div class="rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">' + message + '</div>';
            } else {
                resultArea.innerHTML = '<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">' + message + '</div>';
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
                    showResult(false, data.message || 'Activation failed.');
                }
            })
            .catch(function(){
                setLoading(false);
                showResult(false, 'Network error. Check your connection.');
            });
        });
    })();
    </script>
</body>
</html>
