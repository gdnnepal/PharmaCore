<?php
require_once __DIR__ . '/../config.php';
require_admin();

$defaultPermissions = [
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

$permIns = $pdo->prepare("INSERT INTO permissions(permission_key, label, category) VALUES(?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label), category=VALUES(category)");
foreach($defaultPermissions as $p){
    $permIns->execute([$p[0], $p[1], $p[2]]);
}

// Fixed permission set for regular users.
$regularAutoPermissionKeys = [
    'sale.create',
    'sales_record.view',
    'report.view',
    'customers.create',
    'customers.delete',
    'customers.edit',
    'customers.manage',
    'customers.view',
];
$permMapRows = $pdo->query("SELECT id, permission_key FROM permissions")->fetchAll();
$permIdByKey = [];
foreach($permMapRows as $pr){
    $permIdByKey[(string)$pr['permission_key']] = (int)$pr['id'];
}
$regularAutoPermissionIds = [];
foreach($regularAutoPermissionKeys as $pk){
    if(isset($permIdByKey[$pk])) $regularAutoPermissionIds[] = (int)$permIdByKey[$pk];
}
if(!empty($regularAutoPermissionIds)){
    $usersWithoutPermStmt = $pdo->query("SELECT u.id FROM users u WHERE COALESCE(u.is_admin,0)=0 AND NOT EXISTS (SELECT 1 FROM user_permissions up WHERE up.user_id=u.id)");
    $usersWithoutPerm = $usersWithoutPermStmt ? $usersWithoutPermStmt->fetchAll() : [];
    if(!empty($usersWithoutPerm)){
        $insUP = $pdo->prepare("INSERT IGNORE INTO user_permissions(user_id, permission_id) VALUES(?,?)");
        foreach($usersWithoutPerm as $uRow){
            $uid = (int)($uRow['id'] ?? 0);
            if($uid <= 0) continue;
            foreach($regularAutoPermissionIds as $pid){
                $insUP->execute([$uid, $pid]);
            }
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    try {
        $pdo->beginTransaction();

        if(isset($_POST['save_user'])){
            $uid = (int)($_POST['user_id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $isAdmin = ((string)($_POST['is_admin'] ?? '0')) === '1' ? 1 : 0;
            $isActive = ((string)($_POST['is_active'] ?? '1')) === '1' ? 1 : 0;
            $permissionIds = $isAdmin === 1 ? [] : $regularAutoPermissionIds;

            if($username === ''){
                throw new Exception('Username is required.');
            }
            if($branchId <= 0){
                throw new Exception('Please select a branch.');
            }

            $branchStmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE id=?");
            $branchStmt->execute([$branchId]);
            if((int)$branchStmt->fetchColumn() <= 0){
                throw new Exception('Selected branch is invalid.');
            }

            if($uid > 0){
                $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id=?");
                $stmt->execute([$uid]);
                $currentUser = $stmt->fetch();
                if(!$currentUser){
                    throw new Exception('User not found.');
                }
                $currentIsAdmin = (int)($currentUser['is_admin'] ?? 0);

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? AND id<>?");
                $stmt->execute([$username, $uid]);
                if((int)$stmt->fetchColumn() > 0){
                    throw new Exception('Username already exists.');
                }

                if($password !== ''){
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, password_hash=?, branch_id=?, is_admin=?, is_active=? WHERE id=?");
                    $stmt->execute([$username, $fullName !== '' ? $fullName : null, $hash, $branchId, $isAdmin, $isActive, $uid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, branch_id=?, is_admin=?, is_active=? WHERE id=?");
                    $stmt->execute([$username, $fullName !== '' ? $fullName : null, $branchId, $isAdmin, $isActive, $uid]);
                }

                if($currentIsAdmin !== $isAdmin){
                    $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid]);
                    if($isAdmin === 0 && !empty($permissionIds)){
                        $ins = $pdo->prepare("INSERT INTO user_permissions(user_id, permission_id) VALUES(?,?)");
                        foreach($permissionIds as $pid){
                            $ins->execute([$uid, $pid]);
                        }
                    }
                }

                audit_log_action(
                    'users',
                    'update_user',
                    'Updated user profile and role settings.',
                    [
                        'target_user_id' => $uid,
                        'username' => $username,
                        'branch_id' => $branchId,
                        'is_admin' => $isAdmin,
                        'is_active' => $isActive,
                        'password_changed' => $password !== '',
                    ],
                    'user',
                    $uid
                );

                flash_msg('User updated successfully.');
            } else {
                if($password === ''){
                    throw new Exception('Password is required for new user.');
                }

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
                $stmt->execute([$username]);
                if((int)$stmt->fetchColumn() > 0){
                    throw new Exception('Username already exists.');
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users(username, full_name, password_hash, branch_id, is_admin, is_active) VALUES(?,?,?,?,?,?)");
                $stmt->execute([$username, $fullName !== '' ? $fullName : null, $hash, $branchId, $isAdmin, $isActive]);
                $newUserId = (int)$pdo->lastInsertId();

                if(!empty($permissionIds)){
                    $ins = $pdo->prepare("INSERT INTO user_permissions(user_id, permission_id) VALUES(?,?)");
                    foreach($permissionIds as $pid){
                        $ins->execute([$newUserId, $pid]);
                    }
                }

                audit_log_action(
                    'users',
                    'create_user',
                    'Created new user account.',
                    [
                        'target_user_id' => $newUserId,
                        'username' => $username,
                        'branch_id' => $branchId,
                        'is_admin' => $isAdmin,
                        'is_active' => $isActive,
                        'auto_permission_ids' => array_values($permissionIds),
                    ],
                    'user',
                    $newUserId
                );

                flash_msg('User created successfully.');
            }
        }

        if(isset($_POST['save_permissions'])){
            $uid = (int)($_POST['user_id'] ?? 0);
            $permissionIds = $_POST['permission_ids'] ?? [];
            if(!is_array($permissionIds)) $permissionIds = [];
            $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

            if($uid <= 0){
                throw new Exception('Invalid user selected.');
            }

            $stmt = $pdo->prepare("SELECT id, is_admin FROM users WHERE id=?");
            $stmt->execute([$uid]);
            $targetUser = $stmt->fetch();
            if(!$targetUser){
                throw new Exception('User not found.');
            }

            $existingPermStmt = $pdo->prepare("SELECT permission_id FROM user_permissions WHERE user_id=?");
            $existingPermStmt->execute([$uid]);
            $beforePermissionIds = array_map('intval', array_column($existingPermStmt->fetchAll(), 'permission_id'));

            $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid]);
            if((int)($targetUser['is_admin'] ?? 0) === 0 && !empty($permissionIds)){
                $ins = $pdo->prepare("INSERT INTO user_permissions(user_id, permission_id) VALUES(?,?)");
                foreach($permissionIds as $pid){
                    $ins->execute([$uid, $pid]);
                }
            }

            $addedPermissionIds = array_values(array_diff($permissionIds, $beforePermissionIds));
            $removedPermissionIds = array_values(array_diff($beforePermissionIds, $permissionIds));
            audit_log_action(
                'users',
                'update_user_permissions',
                'Updated user permissions.',
                [
                    'target_user_id' => $uid,
                    'before_permission_ids' => array_values($beforePermissionIds),
                    'after_permission_ids' => array_values($permissionIds),
                    'added_permission_ids' => $addedPermissionIds,
                    'removed_permission_ids' => $removedPermissionIds,
                ],
                'user',
                $uid
            );

            flash_msg('User permissions updated successfully.');
        }

        if(isset($_POST['delete_user'])){
            $uid = (int)($_POST['user_id'] ?? 0);
            if($uid <= 0){
                throw new Exception('Invalid user selected.');
            }
            if($uid === (int)($_SESSION['uid'] ?? 0)){
                throw new Exception('You cannot delete your own account.');
            }

            $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id=?");
            $stmt->execute([$uid]);
            $target = $stmt->fetch();
            if(!$target){
                throw new Exception('User not found.');
            }

            if((int)($target['is_admin'] ?? 0) === 1){
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn();
                if($adminCount <= 1){
                    throw new Exception('At least one admin user must remain.');
                }
            }

            $userMetaStmt = $pdo->prepare("SELECT username, full_name, branch_id, is_admin, is_active FROM users WHERE id=? LIMIT 1");
            $userMetaStmt->execute([$uid]);
            $deletedUserMeta = $userMetaStmt->fetch() ?: [];

            $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);

            audit_log_action(
                'users',
                'delete_user',
                'Deleted user account.',
                [
                    'target_user_id' => $uid,
                    'deleted_user' => $deletedUserMeta,
                ],
                'user',
                $uid
            );

            flash_msg('User deleted successfully.');
        }

        $pdo->commit();
        redirect_with_fallback('?module=users');
    } catch(Exception $e){
        $pdo->rollBack();
        flash_msg($e->getMessage(), 'error');
        redirect_with_fallback('?module=users');
    }
}

$branches = $pdo->query("SELECT id, name, code FROM branches WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$permissions = $pdo->query("SELECT id, permission_key, label, category FROM permissions ORDER BY category ASC, label ASC")->fetchAll();
$permByCategory = [];
foreach($permissions as $p){
    $cat = (string)($p['category'] ?? 'general');
    if(!isset($permByCategory[$cat])) $permByCategory[$cat] = [];
    $permByCategory[$cat][] = $p;
}

$userSearch = trim((string)($_GET['search'] ?? ''));

$editId = (int)($_GET['edit_id'] ?? 0);
$editUser = null;
$editPermissionIds = [];
if($editId > 0){
    $stmt = $pdo->prepare("SELECT id, username, full_name, branch_id, is_admin, is_active FROM users WHERE id=?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch() ?: null;

    if($editUser){
        $stmt = $pdo->prepare("SELECT permission_id FROM user_permissions WHERE user_id=?");
        $stmt->execute([$editId]);
        $editPermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

$permEditId = (int)($_GET['perm_user_id'] ?? 0);
$permEditUser = null;
$permEditPermissionIds = [];
if($permEditId > 0){
    $stmt = $pdo->prepare("SELECT id, username, full_name, branch_id, is_admin, is_active FROM users WHERE id=?");
    $stmt->execute([$permEditId]);
    $permEditUser = $stmt->fetch() ?: null;

    if($permEditUser){
        $stmt = $pdo->prepare("SELECT permission_id FROM user_permissions WHERE user_id=?");
        $stmt->execute([$permEditId]);
        $permEditPermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

$userWhere = [];
$userParams = [];
if($userSearch !== ''){
    $userWhere[] = "(u.username LIKE ? OR COALESCE(u.full_name,'') LIKE ? OR COALESCE(b.name,'') LIKE ? OR COALESCE(b.code,'') LIKE ?)";
    $like = '%' . $userSearch . '%';
    $userParams[] = $like;
    $userParams[] = $like;
    $userParams[] = $like;
    $userParams[] = $like;
}

$userSql = "SELECT u.id, u.username, u.full_name, u.branch_id, u.is_admin, u.is_active, b.name AS branch_name,
                    (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id=u.id) AS permission_count
             FROM users u
             LEFT JOIN branches b ON b.id=u.branch_id";
if(!empty($userWhere)){
    $userSql .= " WHERE " . implode(' AND ', $userWhere);
}
$userSql .= " ORDER BY u.is_admin DESC, u.username ASC";

$stmt = $pdo->prepare($userSql);
$stmt->execute($userParams);
$users = $stmt->fetchAll();

$usersMeta = paginate_array($users, 'user_page', 15);
$users = $usersMeta['rows'];

$f = flash_msg();
?>

<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div><?php endif; ?>

    <div id="userFormModal" class="fixed inset-0 z-50 <?= $editUser ? '' : 'hidden' ?>">
        <div class="absolute inset-0 bg-slate-900/40" onclick="closeUserFormModal()"></div>
        <div class="relative z-10 flex items-start justify-center min-h-full p-4 md:p-8 overflow-y-auto">
            <section class="bg-white w-full max-w-5xl p-5 rounded-2xl shadow border border-slate-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-800"><?= $editUser ? 'Edit User' : 'Create User' ?></h3>
                    <button type="button" onclick="closeUserFormModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 inline-flex items-center justify-center" title="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" class="grid md:grid-cols-2 gap-4">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_user" value="1">
                    <input type="hidden" name="user_id" value="<?= (int)($editUser['id'] ?? 0) ?>">

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Username <span class="text-red-500">*</span></label>
                <input name="username" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" value="<?= e((string)($editUser['username'] ?? '')) ?>">
            </div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Full Name</label>
                <input name="full_name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" value="<?= e((string)($editUser['full_name'] ?? '')) ?>">
            </div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5"><?= $editUser ? 'New Password (leave blank to keep)' : 'Password *' ?></label>
                <input type="password" name="password" <?= $editUser ? '' : 'required' ?> class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
            </div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Branch <span class="text-red-500">*</span></label>
                <select name="branch_id" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="">Select Branch</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)($editUser['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= e((string)$b['name']) ?> (<?= e((string)$b['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Role Type</label>
                <select name="is_admin" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="0" <?= (int)($editUser['is_admin'] ?? 0) === 0 ? 'selected' : '' ?>>Regular User</option>
                    <option value="1" <?= (int)($editUser['is_admin'] ?? 0) === 1 ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>

            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Status</label>
                <select name="is_active" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="1" <?= (int)($editUser['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= (int)($editUser['is_active'] ?? 1) === 0 ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="md:col-span-2 flex items-center gap-2">
                <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium"><?= $editUser ? 'Update User' : 'Create User' ?></button>
                <?php if($editUser): ?>
                    <a href="?<?= e(http_build_query(array_merge(['module' => 'users'], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium">Cancel</a>
                <?php endif; ?>
            </div>
                </form>
            </section>
        </div>
    </div>

    <div id="permissionFormModal" class="fixed inset-0 z-50 <?= $permEditUser ? '' : 'hidden' ?>">
        <div class="absolute inset-0 bg-slate-900/40" onclick="closePermissionFormModal()"></div>
        <div class="relative z-10 flex items-center justify-center min-h-full p-4 md:p-6 overflow-y-auto">
            <section class="bg-white w-full max-w-4xl p-5 rounded-2xl shadow border border-slate-200 h-[90vh] max-h-[90vh] overflow-hidden flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="font-semibold text-slate-800">User Permissions</h3>
                        <?php if($permEditUser): ?>
                            <p class="text-xs text-slate-500 mt-1"><?= e((string)$permEditUser['username']) ?><?= !empty($permEditUser['full_name']) ? ' - ' . e((string)$permEditUser['full_name']) : '' ?></p>
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="closePermissionFormModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 inline-flex items-center justify-center" title="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" class="space-y-4 flex-1 min-h-0 flex flex-col overflow-hidden">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_permissions" value="1">
                    <input type="hidden" name="user_id" value="<?= (int)($permEditUser['id'] ?? 0) ?>">

                    <?php if($permEditUser && (int)($permEditUser['is_admin'] ?? 0) === 1): ?>
                        <div class="p-3 rounded-lg text-sm border border-amber-200 bg-amber-50 text-amber-800">This user is an admin. Permission controls are not required for admin users.</div>
                    <?php endif; ?>

                    <div class="border border-slate-200 rounded-xl bg-slate-50 flex-1 min-h-0 overflow-y-auto overscroll-contain">
                        <?php if(empty($permByCategory)): ?>
                            <div class="text-sm text-slate-500 p-4">No permissions configured yet.</div>
                        <?php else: ?>
                            <?php foreach($permByCategory as $cat => $permRows): ?>
                                <div class="bg-white border-b border-slate-200 last:border-b-0 p-3 md:p-4">
                                    <div class="text-xs uppercase tracking-wide font-semibold text-slate-500 mb-2\"><?= e((string)$cat) ?></div>
                                    <ul class="space-y-2">
                                        <?php foreach($permRows as $p): ?>
                                            <?php $pid = (int)$p['id']; ?>
                                            <li>
                                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                                    <input type="checkbox" name="permission_ids[]" value="<?= $pid ?>" <?= in_array($pid, $permEditPermissionIds, true) ? 'checked' : '' ?>>
                                                    <span><?= e((string)$p['label']) ?> <span class="text-xs text-slate-400">(<?= e((string)$p['permission_key']) ?>)</span></span>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Save Permissions</button>
                        <a href="?<?= e(http_build_query(array_merge(['module' => 'users'], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium">Cancel</a>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">User List</h3>
                <span class="text-xs text-slate-500">Total: <?= (int)$usersMeta['total'] ?></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="module" value="users">
                    <input type="text" id="user_search" name="search" value="<?= e($userSearch) ?>" placeholder="Username, full name, branch name or code" class="w-full sm:w-72 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" oninput="filterUsersLive()">
                    <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Search</button>
                    <?php if($userSearch !== ''): ?><a href="?module=users" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-3 py-2.5 rounded-lg text-sm font-medium">Reset</a><?php endif; ?>
                </form>
                <button type="button" onclick="openUserFormModal()" class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14M5 12h14"/></svg>
                    <span>Add User</span>
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Username</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Name</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Branch</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Type</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Permissions</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                        <th class="px-4 py-3 text-center font-semibold text-slate-700">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($users)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach($users as $u): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50 transition align-top user-row" data-search="<?= e(strtolower((string)$u['username'] . ' ' . (string)($u['full_name'] ?? '') . ' ' . (string)($u['branch_name'] ?? ''))) ?>">
                            <td class="px-4 py-3.5 text-slate-900 font-medium"><?= e((string)$u['username']) ?></td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($u['full_name'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($u['branch_name'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs <?= (int)$u['is_admin'] === 1 ? 'bg-purple-100 text-purple-700' : 'bg-sky-100 text-sky-700' ?>"><?= (int)$u['is_admin'] === 1 ? 'Admin' : 'User' ?></span>
                            </td>
                            <td class="px-4 py-3.5 text-slate-700"><?= (int)$u['permission_count'] ?></td>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs <?= (int)$u['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>"><?= (int)$u['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                <div class="inline-flex items-center gap-2">
                                    <a href="?<?= e(http_build_query(array_merge(['module' => 'users', 'edit_id' => (int)$u['id']], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>" class="px-2.5 py-1.5 rounded bg-sky-100 text-sky-700 hover:bg-sky-200 text-xs font-medium">Edit</a>
                                    <a href="?<?= e(http_build_query(array_merge(['module' => 'users', 'perm_user_id' => (int)$u['id']], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>" class="px-2.5 py-1.5 rounded bg-amber-100 text-amber-700 hover:bg-amber-200 text-xs font-medium">Permissions</a>
                                    <form method="POST" onsubmit="return confirm('Delete this user?');" class="inline">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_user" value="1">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button class="px-2.5 py-1.5 rounded bg-red-100 text-red-700 hover:bg-red-200 text-xs font-medium">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($usersMeta) ?>
    </section>
</div>

<script>
function openUserFormModal(){
    var modal = document.getElementById('userFormModal');
    if(modal) modal.classList.remove('hidden');
}

function closeUserFormModal(){
    <?php if($editUser): ?>
    window.location.href = '?<?= e(http_build_query(array_merge(['module' => 'users'], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>';
    <?php else: ?>
    var modal = document.getElementById('userFormModal');
    if(modal) modal.classList.add('hidden');
    <?php endif; ?>
}

function closePermissionFormModal(){
    window.location.href = '?<?= e(http_build_query(array_merge(['module' => 'users'], $userSearch !== '' ? ['search' => $userSearch] : []))) ?>';
}

function filterUsersLive(){
    var input = document.getElementById('user_search');
    if(!input) return;
    var term = input.value.toLowerCase().trim();
    document.querySelectorAll('.user-row').forEach(function(row){
        var hay = (row.dataset.search || '').toLowerCase();
        row.style.display = hay.indexOf(term) !== -1 ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function(){
    filterUsersLive();
});
</script>
