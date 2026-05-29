<?php
require_once __DIR__ . '/config.php';

$dashboardUrl = get_base_url() . '/dashboard.php';

if(isset($_SESSION['uid'])){
    if(is_admin_user()){
        header('Location: ' . $dashboardUrl . '?module=dashboard');
    } else {
        header('Location: ' . $dashboardUrl . '?module=sale');
    }
    exit;
}

$error = '';
$notice = '';

$reason = trim((string)($_GET['reason'] ?? ''));
if($reason === 'refresh_logout'){
    $notice = 'Session refreshed successfully. Please sign in again.';
} else if($reason === 'logout'){
    $notice = 'You have been logged out.';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!verify_csrf()){
        $error = 'Session token mismatch. Please try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // --- H-5: DB-backed brute-force protection (not bypassable by deleting session cookie) ---
        $maxAttempts  = 10;
        $maxIpAttempts = 50; // L-3: IP-only threshold (higher, prevents targeted DoS)
        $lockoutSecs  = 15 * 60; // 15 minutes
        $ip           = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $identifier   = md5(strtolower($username) . '|' . $ip);
        $ipIdentifier = 'ip_' . md5($ip);

        // Clean up old attempts (older than lockout window)
        try {
            $pdo->prepare("DELETE FROM login_attempts WHERE identifier IN (?,?) AND attempted_at < NOW() - INTERVAL ? SECOND")
                ->execute([$identifier, $ipIdentifier, $lockoutSecs]);
        } catch(Throwable $e){ /* table may not exist yet on first boot */ }

        // Count recent attempts (per username+IP)
        $attempts = 0;
        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND attempted_at > NOW() - INTERVAL ? SECOND");
            $countStmt->execute([$identifier, $lockoutSecs]);
            $attempts = (int)$countStmt->fetchColumn();
        } catch(Throwable $e){ /* graceful fallback */ }

        // L-3: Also check IP-only attempts (prevents mass username enumeration from one IP)
        $ipAttempts = 0;
        try {
            $ipCountStmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND attempted_at > NOW() - INTERVAL ? SECOND");
            $ipCountStmt->execute([$ipIdentifier, $lockoutSecs]);
            $ipAttempts = (int)$ipCountStmt->fetchColumn();
        } catch(Throwable $e){}

        if($attempts >= $maxAttempts || $ipAttempts >= $maxIpAttempts){
            $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
        } else {

            $stmt = $pdo->prepare("SELECT id, username, password_hash, COALESCE(is_admin,0) AS is_admin, COALESCE(is_active,1) AS is_active, COALESCE(branch_id,0) AS branch_id FROM users WHERE username=? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            $isValid = false;
            if($user){
                $storedHash = (string)($user['password_hash'] ?? '');

                if(password_verify($password, $storedHash)){
                    $isValid = true;
                }

                // Migrate legacy MD5 hashes on successful login
                if(!$isValid && preg_match('/^[a-f0-9]{32}$/i', $storedHash)){
                    if(hash_equals(strtolower($storedHash), md5($password))){
                        $isValid = true;
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $up = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
                        $up->execute([$newHash, (int)$user['id']]);
                    }
                }
            }

            if($isValid && (int)($user['is_active'] ?? 0) !== 1){
                $error = 'This user account is inactive.';
                $isValid = false;
            }

            if($isValid){
                // Successful login — clear attempts and regenerate session
                try {
                    $pdo->prepare("DELETE FROM login_attempts WHERE identifier IN (?,?)")->execute([$identifier, $ipIdentifier]);
                } catch(Throwable $e){}
                session_regenerate_id(true);

                $_SESSION['uid']       = (int)$user['id'];
                $_SESSION['username']  = (string)($user['username'] ?? '');
                $_SESSION['branch_id'] = (int)($user['branch_id'] ?? 0);
                $_SESSION['is_admin']  = (int)($user['is_admin'] ?? 0) ? 1 : 0;

                if((int)$_SESSION['is_admin'] === 1){
                    header('Location: ' . $dashboardUrl . '?module=dashboard');
                } else {
                    header('Location: ' . $dashboardUrl . '?module=sale');
                }
                exit;
            }

            // Failed login — record attempt in DB (both per-user+IP and per-IP)
            try {
                $pdo->prepare("INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())")->execute([$identifier]);
                $pdo->prepare("INSERT INTO login_attempts (identifier, attempted_at) VALUES (?, NOW())")->execute([$ipIdentifier]);
            } catch(Throwable $e){}

            $remaining = $maxAttempts - ($attempts + 1);
            if($remaining <= 0){
                $error = 'Too many failed attempts. Account locked for 15 minutes.';
            } else {
                $error = 'Invalid username or password. ' . $remaining . ' attempt(s) remaining.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCore Login</title>
    <link rel="icon" type="image/x-icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <link rel="shortcut icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= h(get_base_url() . '/css/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0f172a;
            --mint: #0d9488;
            --mint-deep: #0f766e;
            --sky: #e0f2fe;
            --sand: #f8fafc;
        }
        body { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#ccfbf1,_transparent_45%),radial-gradient(circle_at_bottom_right,_#e0f2fe,_transparent_55%),linear-gradient(135deg,#f8fafc_0%,#ecfeff_40%,#f8fafc_100%)] text-slate-800">
    <div class="min-h-screen grid lg:grid-cols-2">
        <section class="hidden lg:flex relative overflow-hidden p-12 xl:p-16 bg-[linear-gradient(145deg,#0f766e_0%,#134e4a_45%,#0f172a_100%)] text-white">
            <div class="absolute -top-24 -left-16 w-72 h-72 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute -bottom-24 -right-16 w-80 h-80 rounded-full bg-teal-300/20 blur-2xl"></div>
            <div class="relative z-10 max-w-md self-end space-y-6">
                <p class="text-xs tracking-[0.35em] uppercase text-teal-100">Pharmacy Management</p>
                <h1 class="text-4xl font-extrabold leading-tight">Secure access to billing, stock and branch operations.</h1>
                <p class="text-teal-50/90 text-sm leading-relaxed">Role-based login ensures admins land on the analytics dashboard, while sales users go directly to POS billing.</p>
            </div>
        </section>

        <section class="flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-md bg-white/90 backdrop-blur rounded-3xl border border-white shadow-[0_24px_80px_rgba(15,23,42,0.14)] p-7 sm:p-9">
                <div class="mb-8">
                    <p class="text-xs font-bold tracking-[0.25em] uppercase text-teal-700">PharmaCore</p>
                    <h2 class="mt-2 text-3xl font-extrabold text-slate-900">Sign in</h2>
                    <p class="mt-1 text-sm text-slate-500">Use your account credentials to continue.</p>
                </div>

                <?php if($notice !== ''): ?>
                    <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800"><?= e($notice) ?></div>
                <?php endif; ?>
                <?php if($error !== ''): ?>
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Username</label>
                        <input type="text" name="username" required autocomplete="username" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-teal-600 focus:ring-4 focus:ring-teal-100" placeholder="Enter username">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Password</label>
                        <input type="password" name="password" required autocomplete="current-password" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-teal-600 focus:ring-4 focus:ring-teal-100" placeholder="Enter password">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-[linear-gradient(135deg,var(--mint)_0%,var(--mint-deep)_100%)] px-4 py-3 text-sm font-bold text-white shadow-lg shadow-teal-900/20 transition hover:translate-y-[-1px]">Login</button>
                </form>
            </div>
        </section>
    </div>
</body>
</html>