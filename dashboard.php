<?php
require_once 'config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function hard_logout_and_refresh(string $reason = 'logout'): void {
    if(session_status() === PHP_SESSION_ACTIVE){
        $_SESSION = [];
        if(ini_get('session.use_cookies')){
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    header('Clear-Site-Data: "cache", "storage"');
    $target = 'login.php?logged_out=1&reason=' . urlencode($reason) . '&t=' . time();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="0">
        <title>Signing Out</title>
        <link rel="icon" type="image/x-icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
        <link rel="shortcut icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
        <style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .card{background:#fff;padding:20px 24px;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 30px rgba(15,23,42,.08)}</style>
    </head>
    <body>
        <div class="card">Securing session and clearing cache...</div>
        <script>
        (async function(){
            try {
                if(window.caches && caches.keys){
                    const keys = await caches.keys();
                    await Promise.all(keys.map(function(k){ return caches.delete(k); }));
                }
                if(navigator.serviceWorker && navigator.serviceWorker.getRegistrations){
                    const regs = await navigator.serviceWorker.getRegistrations();
                    await Promise.all(regs.map(function(r){ return r.unregister(); }));
                }
            } catch (e) {}
            window.location.replace(<?= json_encode($target, JSON_UNESCAPED_SLASHES) ?>);
        })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

if(($_GET['action'] ?? '') === 'logout' || ($_GET['action'] ?? '') === 'refresh_logout'){
    hard_logout_and_refresh((string)($_GET['action'] ?? 'logout'));
}

$module = preg_replace('/[^a-zA-Z0-9_-]/','',$_GET['module'] ?? 'sale');
if($module === 'logout'){
    hard_logout_and_refresh('logout');
}
$isAdmin = is_admin_user();
$currentPermKeys = get_current_user_permissions(true);
$showPosMenu = true;
try {
    $settingRows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('show_pos_menu')")->fetchAll();
    $settingMap = [];
    foreach($settingRows as $sr){
        $settingMap[(string)$sr['setting_key']] = (string)$sr['setting_value'];
    }
    $showPosMenu = (($settingMap['show_pos_menu'] ?? '1') === '1');
} catch(Throwable $e){
    $showPosMenu = true;
}

$modulePermissions = [
    'dashboard' => 'dashboard.view',
    'sale' => 'sale.create',
    'inventory' => 'inventory.view',
    'stock_transfer_records' => null,
    'suppliers' => 'suppliers.manage',
    'customers' => 'customers.view',
    'notifications' => 'customers.view',
    'sales_record' => 'sales_record.view',
    'report' => 'report.view',
    'settings' => 'settings.manage',
    'branches' => 'branch.manage',
    'users' => 'user.manage',
    'logout' => null,
];

if($module === 'audit_logs'){
    flash_msg('Audit Logs are no longer available.', 'error');
    redirect_with_fallback('?module=sale');
}

$requiredPerm = $modulePermissions[$module] ?? null;
if(!$isAdmin && $module === 'sales_record' && !has_any_permission(['sales_record.view', 'sale.view'])){
    flash_msg('You do not have permission to access this module.', 'error');
    redirect_with_fallback('?module=sale');
}
if(!$isAdmin && $module === 'inventory' && !has_any_permission(['inventory.view', 'inventory.manage', 'inventory.transfer'])){
    flash_msg('You do not have permission to access this module.', 'error');
    redirect_with_fallback('?module=sale');
}
if(!$isAdmin && $module === 'stock_transfer_records' && !has_any_permission(['inventory.transfer_record.view', 'inventory.transfer.reverse'])){
    flash_msg('You do not have permission to access this module.', 'error');
    redirect_with_fallback('?module=sale');
}
if(!$isAdmin && $module !== 'sales_record' && $module !== 'inventory' && $requiredPerm !== null && !has_permission($requiredPerm)){
    flash_msg('You do not have permission to access this module.', 'error');
    redirect_with_fallback('?module=sale');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    $action = (string)($_POST['action'] ?? '');
    if($action === 'change_password'){
        $currentPw = (string)($_POST['current_password'] ?? '');
        $newPw = (string)($_POST['new_password'] ?? '');
        $confirmPw = (string)($_POST['confirm_password'] ?? '');

        if($currentPw === '' || $newPw === '' || $confirmPw === ''){
            flash_msg('All password fields are required.', 'error');
            redirect_with_fallback('?module=' . urlencode($module));
        }
        if(strlen($newPw) < 6){
            flash_msg('New password must be at least 6 characters.', 'error');
            redirect_with_fallback('?module=' . urlencode($module));
        }
        if($newPw !== $confirmPw){
            flash_msg('New password and confirm password do not match.', 'error');
            redirect_with_fallback('?module=' . urlencode($module));
        }

        $uid = (int)($_SESSION['uid'] ?? 0);
        if($uid <= 0){
            hard_logout_and_refresh('session_invalid');
        }

        $pwStmt = $pdo->prepare("SELECT password_hash, username FROM users WHERE id=? LIMIT 1");
        $pwStmt->execute([$uid]);
        $uRow = $pwStmt->fetch();
        if(!$uRow){
            hard_logout_and_refresh('session_invalid');
        }

        $storedHash = (string)($uRow['password_hash'] ?? '');
        $ok = false;
        if($storedHash !== '' && password_verify($currentPw, $storedHash)){
            $ok = true;
        }
        if(!$ok && preg_match('/^[a-f0-9]{32}$/i', $storedHash)){
            $ok = hash_equals(strtolower($storedHash), md5($currentPw));
        }
        if(!$ok){
            flash_msg('Current password is incorrect.', 'error');
            redirect_with_fallback('?module=' . urlencode($module));
        }

        $newHash = password_hash($newPw, PASSWORD_DEFAULT);
        $up = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
        $up->execute([$newHash, $uid]);

        audit_log_action(
            'users',
            'change_password',
            'User changed own account password and was logged out.',
            [
                'user_id' => $uid,
                'username' => (string)($uRow['username'] ?? ($_SESSION['username'] ?? '')),
            ],
            'user',
            $uid
        );

        hard_logout_and_refresh('password_changed');
    }
}

$currentUserId = (int)($_SESSION['uid'] ?? 0);
$displayUserName = trim((string)($_SESSION['username'] ?? 'User'));
$displayBranchName = 'No Branch';
if($currentUserId > 0){
    try {
        $hasFullNameCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='full_name'")->fetchColumn() > 0;
        $hasBranchIdCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='branch_id'")->fetchColumn() > 0;
        $hasBranchesTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches'")->fetchColumn() > 0;

        if($hasBranchIdCol && $hasBranchesTable){
            if($hasFullNameCol){
                $stmtUser = $pdo->prepare("SELECT COALESCE(NULLIF(u.full_name,''), u.username) AS user_name, COALESCE(NULLIF(b.name,''), 'No Branch') AS branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.id=? LIMIT 1");
            } else {
                $stmtUser = $pdo->prepare("SELECT u.username AS user_name, COALESCE(NULLIF(b.name,''), 'No Branch') AS branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.id=? LIMIT 1");
            }
            $stmtUser->execute([$currentUserId]);
            $userMeta = $stmtUser->fetch();
            if($userMeta){
                $displayUserName = trim((string)($userMeta['user_name'] ?? $displayUserName));
                $displayBranchName = trim((string)($userMeta['branch_name'] ?? $displayBranchName));
            }
        } else {
            if($hasFullNameCol){
                $stmtUser = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), username) AS user_name FROM users WHERE id=? LIMIT 1");
                $stmtUser->execute([$currentUserId]);
                $userMeta = $stmtUser->fetch();
                if($userMeta){
                    $displayUserName = trim((string)($userMeta['user_name'] ?? $displayUserName));
                }
            }
        }
    } catch(Throwable $e){
        // Keep navbar render resilient even if metadata lookup fails.
    }
}
$module_file = __DIR__ . "/modules/{$module}.php";
if(!file_exists($module_file)) $module_file = __DIR__ . "/modules/sale.php";
$moduleTitle = $module === 'stock_transfer_records' ? 'Stock Log' : ucwords(str_replace('_', ' ', $module));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PharmaCore | <?= ucfirst($module) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <link rel="shortcut icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= h(get_base_url() . '/css/style.css') ?>">
    <style>
        .print-only { display: none; }
        .sidebar-scrollbar-hidden {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .sidebar-scrollbar-hidden::-webkit-scrollbar {
            width: 0;
            height: 0;
        }
        @keyframes sidebarHintBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(3px); }
        }
        .sidebar-hint-bounce {
            animation: sidebarHintBounce 1.4s ease-in-out infinite;
        }
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm;
            }
            body.report-print-mode aside,
            body.report-print-mode header,
            body.report-print-mode .no-print {
                display: none !important;
            }
            body.report-print-mode {
                margin: 0 !important;
                padding: 0 !important;
            }
            body.report-print-mode .print-only {
                display: block !important;
            }
            body.report-print-mode main {
                padding: 0 !important;
                margin: 0 !important;
            }
            body.report-print-mode .print-report-container {
                box-shadow: none !important;
                border: none !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            body.report-print-mode table {
                font-size: 12px;
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-800 font-sans <?= $module === 'report' ? 'report-print-mode' : '' ?>">
<div class="flex h-screen overflow-hidden">
    <aside id="appSidebar" class="w-60 bg-dark text-white flex flex-col hidden md:flex h-screen overflow-hidden">
        <div class="p-5 text-xl font-bold border-b border-slate-700">PharmaCore</div>
        <nav class="flex-1 p-3 space-y-1 mt-2 overflow-y-auto min-h-0 sidebar-scrollbar-hidden">
            <?php
            if($isAdmin){
                $menuGroups = [
                    'Overview' => [
                        'dashboard' => 'Dashboard',
                    ],
                    'Inventory' => [
                        'inventory' => 'Inventory',
                        'stock_transfer_records' => 'Stock Log',
                        'suppliers' => 'Suppliers',
                    ],
                    'Sales' => [
                        'customers' => 'Customers',
                        'sales_record' => 'Sales',
                        'report' => 'Report',
                    ],
                    'Administration' => [
                        'branches' => 'Branches',
                        'users' => 'Users',
                        'settings' => 'Settings',
                    ],
                    'System' => [
                        'notifications' => 'Notifications',
                    ],
                ];

                if($showPosMenu){
                    $menuGroups['Sales'] = ['sale' => 'POS Billing'] + $menuGroups['Sales'];
                }
                // Audit Logs removed.
            } else {
                $menuGroups = [
                    'Overview' => [],
                    'Inventory' => [],
                    'Sales' => [],
                    'System' => [],
                ];
                if(has_permission('dashboard.view')) $menuGroups['Overview']['dashboard'] = 'Dashboard';
                if(has_any_permission(['inventory.view', 'inventory.manage', 'inventory.transfer'])) $menuGroups['Inventory']['inventory'] = 'Inventory';
                if(has_any_permission(['inventory.transfer_record.view', 'inventory.transfer.reverse'])) $menuGroups['Inventory']['stock_transfer_records'] = 'Stock Log';
                if(has_permission('sale.create')) $menuGroups['Sales']['sale'] = 'POS Billing';
                if(has_permission('customers.view')) $menuGroups['Sales']['customers'] = 'Customers';
                if(has_permission('customers.view')) $menuGroups['System']['notifications'] = 'Notifications';
                if(has_any_permission(['sales_record.view', 'sale.view'])) $menuGroups['Sales']['sales_record'] = 'Sales';
                if(has_permission('report.view')) $menuGroups['Sales']['report'] = 'Report';
                // Audit Logs removed.
            }
            foreach($menuGroups as $groupTitle => $menu):
                if(empty($menu)) continue;
                echo "<div class='px-3 pt-3 pb-1 text-[10px] font-semibold tracking-[0.16em] uppercase text-slate-400'>" . e($groupTitle) . "</div>";
                foreach($menu as $k => $v):
                $act = $k === $module ? 'bg-primary text-white' : 'hover:bg-slate-700 text-slate-300';
                $icon = '';
                if($k === 'dashboard'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l9-8 9 8v8a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-8z"/></svg>';
                } else if($k === 'sale'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2m0 0L7 13h10l2-8H5.4zM7 13l-1 5h12M9 20a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z"/></svg>';
                } else if($k === 'inventory'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7l8-4 8 4-8 4-8-4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 12l8 4 8-4M4 17l8 4 8-4"/></svg>';
                } else if($k === 'stock_transfer_records'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7h11M8 12h11M8 17h11"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7h.01M3 12h.01M3 17h.01"/></svg>';
                } else if($k === 'suppliers'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 21h18M5 21V7l7-4 7 4v14M9 10h6M9 14h6"/></svg>';
                } else if($k === 'customers'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0"/></svg>';
                } else if($k === 'sales_record'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5h11M9 9h11M9 13h11M9 17h11M4 6h.01M4 10h.01M4 14h.01M4 18h.01"/></svg>';
                } else if($k === 'report'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 20h16M7 16V9M12 16V5M17 16v-3"/></svg>';
                } else if($k === 'branches'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 21h16M6 21V8h12v13M9 12h2m2 0h2M9 16h2m2 0h2"/></svg>';
                } else if($k === 'users'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11a4 4 0 10-8 0 4 4 0 008 0zM3 21a9 9 0 0118 0"/></svg>';
                } else if($k === 'settings'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317a1.724 1.724 0 013.35 0l.16.696a1.724 1.724 0 002.591 1.066l.61-.356a1.724 1.724 0 012.355.632 1.724 1.724 0 01-.632 2.355l-.61.356a1.724 1.724 0 00-.82 1.49c0 .254.056.505.16.735l.356.61a1.724 1.724 0 01-.632 2.355 1.724 1.724 0 01-2.355-.632l-.356-.61a1.724 1.724 0 00-1.49-.82 1.724 1.724 0 00-.735.16l-.696.16a1.724 1.724 0 01-3.35 0l-.16-.696a1.724 1.724 0 00-2.591-1.066l-.61.356a1.724 1.724 0 11-1.723-2.987l.61-.356a1.724 1.724 0 00.82-1.49c0-.254-.056-.505-.16-.735l-.356-.61a1.724 1.724 0 112.987-1.723l.356.61a1.724 1.724 0 001.49.82c.254 0 .505-.056.735-.16l.696-.16z"/><circle cx="12" cy="12" r="2.5" stroke-width="1.8"/></svg>';
                } else if($k === 'notifications'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
                } else if($k === 'logout'){
                    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 17l5-5-5-5M15 12H3"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3h7a2 2 0 012 2v14a2 2 0 01-2 2h-7"/></svg>';
                }
                echo "<a href='?module=$k' class='flex items-center gap-3 px-4 py-2.5 rounded transition $act'>" . $icon . "<span>$v</span></a>";
                endforeach;
            endforeach;
            ?>
        </nav>
        <div id="sidebarScrollHint" class="hidden bg-gradient-to-b from-transparent to-dark/95 px-4 py-2" aria-hidden="true">
            <div class="flex items-center justify-center text-slate-400/70 sidebar-hint-bounce">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 7l6 6 6-6"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 13l6 6 6-6"/>
                </svg>
            </div>
        </div>
        <div class="p-4 text-xs text-slate-400 border-t border-slate-700">© <?= date('Y') ?> PharmaCore</div>
    </aside>

    <div class="flex-1 flex flex-col overflow-hidden">
        <header class="bg-white shadow-sm px-6 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <button id="sidebarToggleBtn" type="button" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100" title="Toggle Sidebar" aria-label="Toggle Sidebar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <h1 class="text-lg font-semibold"><?= e($moduleTitle) ?></h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="relative" id="profileMenuWrap">
                    <button id="profileMenuBtn" type="button" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-slate-100 border border-slate-200">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-200 text-slate-700 text-xs font-semibold"><?= e(strtoupper(substr($displayUserName, 0, 1))) ?></span>
                        <span class="text-sm text-slate-700 font-medium"><?= e($displayUserName) ?></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4 text-slate-500"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div id="profileMenu" class="hidden absolute right-0 top-12 w-64 bg-white border border-slate-200 shadow-xl rounded-xl z-50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-100">
                            <div class="text-sm font-medium text-slate-800"><?= e($displayUserName) ?></div>
                            <div class="text-xs text-slate-500"><?= e($displayBranchName) ?></div>
                        </div>
                        <button type="button" id="openChangePassword" class="w-full text-left px-4 py-2.5 text-sm hover:bg-slate-50 text-slate-700">Change Password</button>
                        <a href="?action=refresh_logout" class="block px-4 py-2.5 text-sm hover:bg-slate-50 text-slate-700">Refresh System</a>
                        <a href="?action=logout" class="block px-4 py-2.5 text-sm hover:bg-slate-50 text-red-700">Logout</a>
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-1 overflow-y-auto p-5">
            <?php include $module_file; ?>
        </main>
    </div>
</div>
<div id="changePasswordModal" class="hidden fixed inset-0 bg-black/45 z-[90] items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <h3 class="text-base font-semibold text-slate-800">Change Password</h3>
            <button type="button" id="closeChangePassword" class="text-slate-500 hover:text-slate-700 text-xl leading-none">x</button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="change_password">
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Current Password</label>
                <input type="password" name="current_password" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">New Password</label>
                <input type="password" name="new_password" minlength="6" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Confirm New Password</label>
                <input type="password" name="confirm_password" minlength="6" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
            </div>
            <button type="submit" class="w-full bg-primary hover:bg-teal-800 text-white py-2.5 rounded-lg text-sm font-medium">Update Password</button>
        </form>
    </div>
</div>
<script>
var profileMenuBtn = document.getElementById('profileMenuBtn');
var profileMenu = document.getElementById('profileMenu');
var profileMenuWrap = document.getElementById('profileMenuWrap');
var changePasswordModal = document.getElementById('changePasswordModal');
var openChangePassword = document.getElementById('openChangePassword');
var closeChangePassword = document.getElementById('closeChangePassword');
var appSidebar = document.getElementById('appSidebar');
var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
var sidebarMenu = document.querySelector('#appSidebar nav');
var sidebarScrollHint = document.getElementById('sidebarScrollHint');

function updateSidebarScrollHint(){
    if(!sidebarMenu || !sidebarScrollHint) return;
    var hasOverflow = sidebarMenu.scrollHeight > sidebarMenu.clientHeight + 1;
    sidebarScrollHint.classList.toggle('hidden', !hasOverflow);
}

function applySidebarState(collapsed){
    if(!appSidebar) return;
    if(collapsed){
        appSidebar.style.display = 'none';
    } else {
        appSidebar.style.display = '';
    }
}

try {
    var sidebarCollapsed = localStorage.getItem('pharmacore.sidebar.collapsed') === '1';
    applySidebarState(sidebarCollapsed);
} catch (e) {
    applySidebarState(false);
}

updateSidebarScrollHint();

if(sidebarToggleBtn){
    sidebarToggleBtn.addEventListener('click', function(){
        if(!appSidebar) return;
        var collapsed = appSidebar.style.display === 'none';
        applySidebarState(!collapsed);
        try {
            localStorage.setItem('pharmacore.sidebar.collapsed', !collapsed ? '1' : '0');
        } catch (e) {
            // Ignore storage failures.
        }
    });
}

window.addEventListener('resize', updateSidebarScrollHint);
if(sidebarMenu){
    sidebarMenu.addEventListener('scroll', updateSidebarScrollHint);
}

if(profileMenuBtn && profileMenu){
    profileMenuBtn.addEventListener('click', function(){
        profileMenu.classList.toggle('hidden');
    });
}

document.addEventListener('click', function(e){
    if(profileMenu && profileMenuWrap && !profileMenuWrap.contains(e.target)){
        profileMenu.classList.add('hidden');
    }
});

if(openChangePassword && changePasswordModal){
    openChangePassword.addEventListener('click', function(){
        profileMenu.classList.add('hidden');
        changePasswordModal.classList.remove('hidden');
        changePasswordModal.classList.add('flex');
    });
}

if(closeChangePassword && changePasswordModal){
    closeChangePassword.addEventListener('click', function(){
        changePasswordModal.classList.add('hidden');
        changePasswordModal.classList.remove('flex');
    });
}

if(changePasswordModal){
    changePasswordModal.addEventListener('click', function(e){
        if(e.target === changePasswordModal){
            changePasswordModal.classList.add('hidden');
            changePasswordModal.classList.remove('flex');
        }
    });
}
</script>
</body></html>