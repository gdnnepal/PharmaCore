<?php
declare(strict_types=1);
session_start();

$lockFile = __DIR__ . '/install.lock';
$envFile  = __DIR__ . '/.env';
$sqlFile  = __DIR__ . '/pharmacy_npr.sql';

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
$appRoot = realpath(__DIR__);
$baseUrl = '';
if($docRoot !== false && $appRoot !== false){
    $docRootNorm = str_replace('\\', '/', strtolower($docRoot));
    $appRootNorm = str_replace('\\', '/', strtolower($appRoot));
    if(str_starts_with($appRootNorm, $docRootNorm)){
        $relative = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        $baseUrl = '/' . trim($relative, '/');
    }
}
if($baseUrl === '' || $baseUrl === '/'){
    $baseUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if($baseUrl === '/' || $baseUrl === '.'){
        $baseUrl = '';
    }
}
$loginUrl = $baseUrl . '/login.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function installer_token(): string {
    if(empty($_SESSION['installer_csrf'])){
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['installer_csrf'];
}

function installer_verify_token(): bool {
    return isset($_POST['csrf']) && hash_equals((string)($_SESSION['installer_csrf'] ?? ''), (string)$_POST['csrf']);
}

function execute_sql_dump(PDO $pdo, string $filePath): void {
    if(!is_file($filePath)){
        throw new RuntimeException('SQL file not found: ' . $filePath);
    }

    $sql = (string)file_get_contents($filePath);
    if(trim($sql) === ''){
        throw new RuntimeException('SQL file is empty.');
    }

    $pdo->exec('DROP TRIGGER IF EXISTS trg_audit_logs_no_delete');

    $delimiter = ';';
    $buffer = '';
    $lines = preg_split('/\R/', $sql) ?: [];

    foreach($lines as $line){
        $trim = trim($line);

        if($trim === ''){
            continue;
        }

        if(str_starts_with($trim, '--')){
            continue;
        }

        if($buffer === '' && preg_match('/^\(\s*\d+\s*,.*\)\s*,?$/', $trim) === 1){
            continue;
        }

        if(preg_match('/^\/\*!\d+/', $trim) === 1){
            continue;
        }

        if(str_starts_with($trim, '/*') && str_ends_with($trim, '*/')){
            continue;
        }

        if(strncasecmp($trim, 'DELIMITER ', 10) === 0){
            $delimiter = trim(substr($trim, 10));
            if($delimiter === ''){
                $delimiter = ';';
            }
            continue;
        }

        if(strncasecmp($trim, 'SET ', 4) === 0 && 
           (str_contains($trim, '@@') || str_contains($trim, '@OLD_'))){
            continue;
        }

        $buffer .= $line . "\n";

        if($delimiter === ';'){
            if(preg_match('/;\s*$/', $trim) === 1){
                $stmt = trim($buffer);
                $buffer = '';
                if($stmt !== '' && !str_starts_with($stmt, 'SET')){
                    try {
                        $pdo->exec($stmt);
                    } catch(PDOException $e){
                        error_log('SQL Warning in installer: ' . $e->getMessage());
                    }
                }
            }
        } else {
            $bufferTrimmed = rtrim($buffer);
            if(str_ends_with($bufferTrimmed, $delimiter)){
                $stmt = trim(substr($bufferTrimmed, 0, -strlen($delimiter)));
                $buffer = '';
                if($stmt !== ''){
                    try {
                        $pdo->exec($stmt);
                    } catch(PDOException $e){
                        error_log('SQL Warning in installer: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    $tail = trim($buffer);
    if($tail !== '' && !str_starts_with($tail, 'SET')){
        try {
            $pdo->exec($tail);
        } catch(PDOException $e){
            error_log('SQL Warning in installer: ' . $e->getMessage());
        }
    }
}

if(is_file($lockFile)){
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installation Locked</title>
        <link rel="icon" type="image/x-icon" href="<?php echo h($baseUrl . '/favicon.ico'); ?>">
        <link rel="stylesheet" href="<?php echo h($baseUrl . '/css/style.css'); ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
            .lock-icon { width: 64px; height: 64px; background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto; }
            .lock-icon::before { content: '🔒'; font-size: 32px; }
        </style>
    </head>
    <body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100 flex items-center justify-center p-6">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-xl border border-slate-200/60 overflow-hidden">
                <div class="p-8 text-center">
                    <div class="lock-icon mb-6"></div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Installation Locked</h1>
                    <p class="text-slate-600 text-sm leading-relaxed">This application has already been installed. Remove <code class="px-2 py-0.5 bg-slate-100 rounded text-xs font-mono text-slate-800">install.lock</code> only if you intentionally want to reinstall.</p>
                </div>
                <div class="px-8 pb-8">
                    <a href="<?php echo h($loginUrl); ?>" class="block w-full text-center bg-teal-600 hover:bg-teal-700 text-white font-semibold py-3 rounded-xl transition-colors">
                        Go to Login
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$errors = [];
$success = false;

$input = [
    'db_host' => trim((string)($_POST['db_host'] ?? '')),
    'db_port' => trim((string)($_POST['db_port'] ?? '')),
    'db_name' => trim((string)($_POST['db_name'] ?? '')),
    'db_user' => trim((string)($_POST['db_user'] ?? '')),
    'db_pass' => (string)($_POST['db_pass'] ?? ''),
    'admin_username' => trim((string)($_POST['admin_username'] ?? 'admin')),
    'admin_full_name' => trim((string)($_POST['admin_full_name'] ?? 'Administrator')),
    'pharmacy_name' => trim((string)($_POST['pharmacy_name'] ?? '')),
    'pharmacy_address' => trim((string)($_POST['pharmacy_address'] ?? '')),
    'pharmacy_phone' => trim((string)($_POST['pharmacy_phone'] ?? '')),
    'pharmacy_email' => trim((string)($_POST['pharmacy_email'] ?? '')),
    'pan_vat' => trim((string)($_POST['pan_vat'] ?? '')),
    'dda_no' => trim((string)($_POST['dda_no'] ?? '')),
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!installer_verify_token()){
        $errors[] = 'Invalid request token. Please reload the installer page.';
    }

    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminConfirm = (string)($_POST['admin_confirm_password'] ?? '');

    if($input['db_host'] === '' || $input['db_name'] === '' || $input['db_user'] === ''){
        $errors[] = 'Database host, name, and user are required.';
    }
    // H-9: Strict DB name validation before use in CREATE DATABASE
    if($input['db_name'] !== '' && !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $input['db_name'])){
        $errors[] = 'Database name may only contain letters, numbers, and underscores (max 64 chars).';
    }
    if(!ctype_digit($input['db_port']) || (int)$input['db_port'] <= 0){
        $errors[] = 'Database port must be a valid positive number.';
    }
    if($input['admin_username'] === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $input['admin_username'])){
        $errors[] = 'Admin username must be 3-50 characters and can include letters, numbers, ., _, -';
    }
    if(strlen($adminPassword) < 8){
        $errors[] = 'Admin password must be at least 8 characters.';
    }    if($adminPassword !== $adminConfirm){
        $errors[] = 'Admin passwords do not match.';
    }
    if($input['pharmacy_name'] === '' || $input['pharmacy_address'] === '' || $input['pharmacy_phone'] === '' || $input['pan_vat'] === '' || $input['dda_no'] === ''){
        $errors[] = 'Pharmacy name, address, phone, PAN/VAT and DDA No are required.';
    }

    if(empty($errors)){
        try {
            $rootDsn = 'mysql:host=' . $input['db_host'] . ';port=' . (int)$input['db_port'] . ';charset=utf8mb4';
            $rootPdo = new PDO($rootDsn, $input['db_user'], $input['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $dbNameSafe = str_replace('`', '', $input['db_name']);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $dbDsn = 'mysql:host=' . $input['db_host'] . ';port=' . (int)$input['db_port'] . ';dbname=' . $input['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dbDsn, $input['db_user'], $input['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            execute_sql_dump($pdo, $sqlFile);

            $permRows = [
                ['dashboard.view', 'View Dashboard', 'dashboard'],
                ['sale.create', 'Create Sale', 'sales'],
                ['sale.credit', 'Allow Credit Sale', 'sales'],
                ['sale.view', 'View Sales (Legacy)', 'sales'],
                ['sales_record.view', 'View Sales Record', 'sales'],
                ['inventory.view', 'View Inventory', 'inventory'],
                ['inventory.manage', 'Manage Inventory', 'inventory'],
                ['inventory.transfer', 'Transfer Inventory Stock', 'inventory'],
                ['inventory.transfer_record.view', 'View Stock Transfer Records', 'inventory'],
                ['inventory.transfer.reverse', 'Reverse Stock Transfer (Partial/Full)', 'inventory'],
                ['customers.manage', 'Manage Customers', 'customers'],
                ['customers.view', 'View Customers', 'customers'],
                ['customers.create', 'Add Customers', 'customers'],
                ['customers.edit', 'Edit Customers', 'customers'],
                ['customers.delete', 'Delete Customers', 'customers'],
                ['customers.payment', 'Manage Customer Payments', 'customers'],
                ['suppliers.manage', 'Manage Suppliers', 'suppliers'],
                ['report.view', 'View Reports', 'reports'],
                ['settings.manage', 'Manage Settings', 'settings'],
                ['branch.manage', 'Manage Branches', 'admin'],
                ['user.manage', 'Manage Users', 'admin'],
            ];
            $permIns = $pdo->prepare('INSERT INTO permissions(permission_key, label, category) VALUES(?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label), category=VALUES(category)');
            foreach($permRows as $row){
                $permIns->execute([$row[0], $row[1], $row[2]]);
            }

            $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $userIns = $pdo->prepare('INSERT INTO users(username, password_hash, full_name, branch_id, is_admin, is_active) VALUES(?,?,?,?,1,1) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), full_name=VALUES(full_name), is_admin=1, is_active=1');
            $userIns->execute([
                $input['admin_username'],
                $adminHash,
                $input['admin_full_name'] !== '' ? $input['admin_full_name'] : 'Administrator',
                null,
            ]);

            $adminIdStmt = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
            $adminIdStmt->execute([$input['admin_username']]);
            $adminId = (int)($adminIdStmt->fetchColumn() ?: 0);
            if($adminId <= 0){
                throw new RuntimeException('Admin account creation failed.');
            }

            $allPermIds = $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
            $assignPerm = $pdo->prepare('INSERT IGNORE INTO user_permissions(user_id, permission_id) VALUES(?,?)');
            foreach($allPermIds as $permId){
                $assignPerm->execute([$adminId, (int)$permId]);
            }

            $settingIns = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $settingIns->execute(['show_pos_menu', '1']);

            $pharmaStmt = $pdo->prepare('INSERT INTO pharmacy_details(id, pharmacy_name, address, phone_number, email, pan_vat, dda_no) VALUES(1,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pharmacy_name=VALUES(pharmacy_name), address=VALUES(address), phone_number=VALUES(phone_number), email=VALUES(email), pan_vat=VALUES(pan_vat), dda_no=VALUES(dda_no)');
            $pharmaStmt->execute([
                $input['pharmacy_name'],
                $input['pharmacy_address'],
                $input['pharmacy_phone'],
                $input['pharmacy_email'] !== '' ? $input['pharmacy_email'] : null,
                $input['pan_vat'],
                $input['dda_no'],
            ]);

            $envContent = "# PharmaCore Environment — generated by installer on " . date('c') . "\n"
                . "\n"
                . "# Database\n"
                . "DB_HOST=" . $input['db_host'] . "\n"
                . "DB_PORT=" . (int)$input['db_port'] . "\n"
                . "DB_NAME=" . $input['db_name'] . "\n"
                . "DB_USER=" . $input['db_user'] . "\n"
                . "DB_PASS=" . $input['db_pass'] . "\n";

            if(file_put_contents($envFile, $envContent, LOCK_EX) === false){
                throw new RuntimeException('Could not write .env file. Check file permissions.');
            }
            if(file_put_contents($lockFile, 'Installed at ' . date('c') . PHP_EOL, LOCK_EX) === false){
                throw new RuntimeException('Could not write install.lock. Check file permissions.');
            }

            $success = true;
            unset($_SESSION['installer_csrf']);
        } catch(Throwable $e){
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCore Setup Wizard</title>
    <link rel="icon" type="image/x-icon" href="<?php echo h($baseUrl . '/favicon.ico'); ?>">
    <link rel="stylesheet" href="<?php echo h($baseUrl . '/css/style.css'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; line-height: 1.6; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
        
        .installer-container { min-height: 100vh; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .installer-wrapper { width: 100%; max-width: 900px; }
        
        .installer-card { background: #ffffff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08), 0 0 1px rgba(0, 0, 0, 0.1); overflow: hidden; }
        
        .installer-header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); padding: 2.5rem 2rem; color: #ffffff; position: relative; overflow: hidden; }
        .installer-header::before { content: ''; position: absolute; top: -50%; right: -10%; width: 400px; height: 400px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%; }
        .installer-header h1 { font-size: 1.875rem; font-weight: 700; margin-bottom: 0.5rem; position: relative; z-index: 1; }
        .installer-header p { font-size: 0.9375rem; opacity: 0.95; position: relative; z-index: 1; }
        
        .installer-body { padding: 2.5rem 2rem; }
        
        .step-indicator { display: flex; align-items: center; justify-content: center; margin-bottom: 2.5rem; gap: 0.75rem; }
        .step-item { flex: 1; max-width: 180px; position: relative; }
        .step-number { width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.875rem; margin: 0 auto 0.5rem; transition: all 0.3s ease; }
        .step-item.active .step-number { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); color: #ffffff; box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3); }
        .step-item.completed .step-number { background: #10b981; color: #ffffff; }
        .step-label { text-align: center; font-size: 0.8125rem; color: #64748b; font-weight: 500; }
        .step-item.active .step-label { color: #0f766e; font-weight: 600; }
        .step-connector { flex: 1; height: 2px; background: #e2e8f0; margin: 0 -0.75rem; margin-top: -20px; position: relative; z-index: 0; }
        
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
        .alert-error-item { color: #dc2626; font-size: 0.875rem; margin: 0.25rem 0; display: flex; align-items: start; gap: 0.5rem; }
        .alert-error-item::before { content: '⚠'; flex-shrink: 0; margin-top: 0.125rem; }
        
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 2rem; text-align: center; }
        .alert-success h2 { color: #065f46; font-size: 1.5rem; font-weight: 700; margin-bottom: 0.75rem; }
        .alert-success p { color: #047857; font-size: 0.9375rem; margin-bottom: 1.5rem; }
        .alert-success .btn { display: inline-block; background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #ffffff; padding: 0.875rem 2rem; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: 0.9375rem; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }
        .alert-success .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3); }
        
        .form-section { display: none; }
        .form-section.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
        @media (min-width: 640px) { .form-grid { grid-template-columns: repeat(2, 1fr); } }
        .form-grid-full { grid-column: 1 / -1; }
        
        .form-group { display: flex; flex-direction: column; }
        .form-label { font-size: 0.875rem; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.375rem; }
        .form-label .required { color: #ef4444; }
        .form-input { width: 100%; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 0.9375rem; transition: all 0.2s ease; background: #ffffff; color: #1e293b; }
        .form-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.1); }
        .form-input::placeholder { color: #94a3b8; }
        
        .form-actions { display: flex; justify-content: space-between; margin-top: 2rem; gap: 1rem; }
        .btn { padding: 0.875rem 1.75rem; border-radius: 10px; font-weight: 600; font-size: 0.9375rem; cursor: pointer; transition: all 0.2s ease; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-primary { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); color: #ffffff; box-shadow: 0 4px 12px rgba(20, 184, 166, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(20, 184, 166, 0.3); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        
        .info-note { background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 10px; padding: 1rem; font-size: 0.8125rem; color: #115e59; margin-bottom: 1.5rem; }
        
        @media (max-width: 640px) {
            .installer-container { padding: 1rem; }
            .installer-header { padding: 2rem 1.5rem; }
            .installer-body { padding: 2rem 1.5rem; }
            .step-indicator { flex-direction: column; gap: 1rem; }
            .step-connector { display: none; }
            .form-actions { flex-direction: column-reverse; }
        }
    </style>
</head>
<body>
    <div class="installer-container">
        <div class="installer-wrapper">
            <div class="installer-card">
                <div class="installer-header">
                    <h1>PharmaCore Setup Wizard</h1>
                    <p>Configure your pharmacy management system in three simple steps</p>
                </div>
                
                <div class="installer-body">
                    <?php if($success): ?>
                        <div class="alert-success">
                            <h2>Installation Complete</h2>
                            <p>Your pharmacy management system has been successfully configured and is ready to use.</p>
                            <a href="<?php echo h($loginUrl); ?>" class="btn">Access Your Dashboard</a>
                        </div>
                    <?php else: ?>
                        <?php if(!empty($errors)): ?>
                            <div class="alert-error">
                                <?php foreach($errors as $err): ?>
                                    <div class="alert-error-item"><?= h($err) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="step-indicator">
                            <div class="step-item active" data-step="1">
                                <div class="step-number">1</div>
                                <div class="step-label">Database</div>
                            </div>
                            <div class="step-connector"></div>
                            <div class="step-item" data-step="2">
                                <div class="step-number">2</div>
                                <div class="step-label">Admin Account</div>
                            </div>
                            <div class="step-connector"></div>
                            <div class="step-item" data-step="3">
                                <div class="step-number">3</div>
                                <div class="step-label">Pharmacy Info</div>
                            </div>
                        </div>

                        <form method="POST" id="installerForm">
                            <input type="hidden" name="csrf" value="<?= h(installer_token()) ?>">

                            <div class="form-section active" data-panel="1">
                                <div class="info-note">Configure database connection settings. Ensure the database user has CREATE DATABASE privileges.</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Database Host <span class="required">*</span></label>
                                        <input type="text" name="db_host" value="<?= h($input['db_host']) ?>" placeholder="127.0.0.1" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Database Port <span class="required">*</span></label>
                                        <input type="text" name="db_port" value="<?= h($input['db_port']) ?>" placeholder="3306" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Database Name <span class="required">*</span></label>
                                        <input type="text" name="db_name" value="<?= h($input['db_name']) ?>" placeholder="pharmacy_npr" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Database User <span class="required">*</span></label>
                                        <input type="text" name="db_user" value="<?= h($input['db_user']) ?>" placeholder="root" class="form-input" required>
                                    </div>
                                    <div class="form-group form-grid-full">
                                        <label class="form-label">Database Password</label>
                                        <input type="password" name="db_pass" autocomplete="new-password" class="form-input">
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <span></span>
                                    <button type="button" class="btn btn-primary" data-next-step>Continue to Admin Account</button>
                                </div>
                            </div>

                            <div class="form-section" data-panel="2">
                                <div class="info-note">Create your administrator account. This account will have full system access.</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Username <span class="required">*</span></label>
                                        <input type="text" name="admin_username" value="<?= h($input['admin_username']) ?>" placeholder="admin" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="admin_full_name" value="<?= h($input['admin_full_name']) ?>" placeholder="Administrator" class="form-input">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Password <span class="required">*</span></label>
                                        <input type="password" name="admin_password" placeholder="Minimum 8 characters" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Confirm Password <span class="required">*</span></label>
                                        <input type="password" name="admin_confirm_password" placeholder="Re-enter password" class="form-input" required>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="btn btn-secondary" data-prev-step>Back to Database</button>
                                    <button type="button" class="btn btn-primary" data-next-step>Continue to Pharmacy Info</button>
                                </div>
                            </div>

                            <div class="form-section" data-panel="3">
                                <div class="info-note">Enter your pharmacy business information. This will appear on invoices and reports.</div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Pharmacy Name <span class="required">*</span></label>
                                        <input type="text" name="pharmacy_name" value="<?= h($input['pharmacy_name']) ?>" placeholder="ABC Pharmacy" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Phone Number <span class="required">*</span></label>
                                        <input type="text" name="pharmacy_phone" value="<?= h($input['pharmacy_phone']) ?>" placeholder="+977-XXX-XXXXXXX" class="form-input" required>
                                    </div>
                                    <div class="form-group form-grid-full">
                                        <label class="form-label">Address <span class="required">*</span></label>
                                        <input type="text" name="pharmacy_address" value="<?= h($input['pharmacy_address']) ?>" placeholder="Street, City, Province" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="pharmacy_email" value="<?= h($input['pharmacy_email']) ?>" placeholder="contact@pharmacy.com" class="form-input">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">PAN / VAT Number <span class="required">*</span></label>
                                        <input type="text" name="pan_vat" value="<?= h($input['pan_vat']) ?>" placeholder="XXX-XXX-XXX" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">DDA Number <span class="required">*</span></label>
                                        <input type="text" name="dda_no" value="<?= h($input['dda_no']) ?>" placeholder="DDA-XXXXX" class="form-input" required>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="btn btn-secondary" data-prev-step>Back to Admin Account</button>
                                    <button type="submit" class="btn btn-primary">Complete Installation</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var stepItems = Array.from(document.querySelectorAll('[data-step]'));
        var panels = Array.from(document.querySelectorAll('[data-panel]'));
        var activeStep = 1;

        function activateStep(step){
            activeStep = step;
            
            stepItems.forEach(function(item){
                var itemStep = parseInt(item.getAttribute('data-step'), 10);
                item.classList.remove('active', 'completed');
                if(itemStep === step){
                    item.classList.add('active');
                } else if(itemStep < step){
                    item.classList.add('completed');
                }
            });

            panels.forEach(function(panel){
                var panelStep = parseInt(panel.getAttribute('data-panel'), 10);
                panel.classList.toggle('active', panelStep === step);
            });

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        document.querySelectorAll('[data-next-step]').forEach(function(btn){
            btn.addEventListener('click', function(){
                if(activeStep < 3) activateStep(activeStep + 1);
            });
        });

        document.querySelectorAll('[data-prev-step]').forEach(function(btn){
            btn.addEventListener('click', function(){
                if(activeStep > 1) activateStep(activeStep - 1);
            });
        });

        activateStep(1);
    })();
    </script>
</body>
</html>