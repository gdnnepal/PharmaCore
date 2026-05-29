<?php
require_once __DIR__ . '/../config.php';
require_admin();

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    try {
        $pdo->beginTransaction();

        if(isset($_POST['save_branch'])){
            $bid = (int)($_POST['branch_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $isActive = ((string)($_POST['is_active'] ?? '1')) === '1' ? 1 : 0;

            if($name === '' || $code === ''){
                throw new Exception('Branch name and code are required.');
            }

            if($bid > 0){
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE code=? AND id<>?");
                $stmt->execute([$code, $bid]);
                if((int)$stmt->fetchColumn() > 0){
                    throw new Exception('Branch code already exists.');
                }

                $stmt = $pdo->prepare("UPDATE branches SET name=?, code=?, address=?, phone=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $code, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $isActive, $bid]);
                flash_msg('Branch updated successfully.');
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE code=?");
                $stmt->execute([$code]);
                if((int)$stmt->fetchColumn() > 0){
                    throw new Exception('Branch code already exists.');
                }

                $stmt = $pdo->prepare("INSERT INTO branches(name, code, address, phone, is_active) VALUES(?,?,?,?,?)");
                $stmt->execute([$name, $code, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $isActive]);
                flash_msg('Branch created successfully.');
            }
        }

        if(isset($_POST['delete_branch'])){
            $bid = (int)($_POST['branch_id'] ?? 0);
            if($bid <= 0){
                throw new Exception('Invalid branch selected.');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM branches");
            $stmt->execute();
            $totalBranches = (int)$stmt->fetchColumn();
            if($totalBranches <= 1){
                throw new Exception('At least one branch must remain.');
            }

            $hasBranchIdOnUsers = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='branch_id'")->fetchColumn() > 0;
            if($hasBranchIdOnUsers){
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE branch_id=?");
                $stmt->execute([$bid]);
                if((int)$stmt->fetchColumn() > 0){
                    throw new Exception('Cannot delete branch assigned to one or more users.');
                }
            }

            $stmt = $pdo->prepare("DELETE FROM branches WHERE id=?");
            $stmt->execute([$bid]);
            flash_msg('Branch deleted successfully.');
        }

        $pdo->commit();
        redirect_with_fallback('?module=branches');
    } catch(Exception $e){
        $pdo->rollBack();
        flash_msg($e->getMessage(), 'error');
        redirect_with_fallback('?module=branches');
    }
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editBranch = null;
if($editId > 0){
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id=?");
    $stmt->execute([$editId]);
    $editBranch = $stmt->fetch() ?: null;
}

$rows = $pdo->query("SELECT * FROM branches ORDER BY is_active DESC, name ASC")->fetchAll();
$rowsMeta = paginate_array($rows, 'branch_page', 20);
$rows = $rowsMeta['rows'];

$f = flash_msg();
?>

<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div><?php endif; ?>

    <div id="branchFormModal" class="fixed inset-0 z-50 <?= $editBranch ? '' : 'hidden' ?>">
        <div class="absolute inset-0 bg-slate-900/40" onclick="closeBranchFormModal()"></div>
        <div class="relative z-10 flex items-start justify-center min-h-full p-4 md:p-8 overflow-y-auto">
            <section class="bg-white w-full max-w-3xl p-5 rounded-2xl shadow border border-slate-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-800"><?= $editBranch ? 'Edit Branch' : 'Create Branch' ?></h3>
                    <button type="button" onclick="closeBranchFormModal()" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 inline-flex items-center justify-center" title="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" class="grid md:grid-cols-2 gap-4">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_branch" value="1">
                    <input type="hidden" name="branch_id" value="<?= (int)($editBranch['id'] ?? 0) ?>">

                    <div>
                        <label class="block text-sm text-slate-700 mb-1.5">Branch Name <span class="text-red-500">*</span></label>
                        <input name="name" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" value="<?= e((string)($editBranch['name'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-700 mb-1.5">Branch Code <span class="text-red-500">*</span></label>
                        <input name="code" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg uppercase" value="<?= e((string)($editBranch['code'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-700 mb-1.5">Phone</label>
                        <input name="phone" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" value="<?= e((string)($editBranch['phone'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-700 mb-1.5">Status</label>
                        <select name="is_active" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                            <option value="1" <?= (string)($editBranch['is_active'] ?? '1') === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= (string)($editBranch['is_active'] ?? '1') === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm text-slate-700 mb-1.5">Address</label>
                        <input name="address" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" value="<?= e((string)($editBranch['address'] ?? '')) ?>">
                    </div>
                    <div class="md:col-span-2 flex items-center gap-2">
                        <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium"><?= $editBranch ? 'Update Branch' : 'Create Branch' ?></button>
                        <?php if($editBranch): ?>
                            <a href="?module=branches" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">Branch List</h3>
                <span class="text-xs text-slate-500">Total: <?= (int)$rowsMeta['total'] ?></span>
            </div>
            <button type="button" onclick="openBranchFormModal()" class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2 w-fit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14M5 12h14"/></svg>
                <span>Create Branch</span>
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Name</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Code</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Phone</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Address</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                        <th class="px-4 py-3 text-center font-semibold text-slate-700">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($rows)): ?>
                    <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No branches found.</td></tr>
                <?php else: ?>
                    <?php foreach($rows as $r): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50 transition align-top">
                            <td class="px-4 py-3.5 text-slate-900 font-medium"><?= e((string)$r['name']) ?></td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)$r['code']) ?></td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($r['phone'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e((string)($r['address'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs <?= (int)$r['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>"><?= (int)$r['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                <div class="inline-flex items-center gap-2">
                                    <a href="?module=branches&edit_id=<?= (int)$r['id'] ?>" class="px-2.5 py-1.5 rounded bg-sky-100 text-sky-700 hover:bg-sky-200 text-xs font-medium">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Delete this branch?');" class="inline">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_branch" value="1">
                                        <input type="hidden" name="branch_id" value="<?= (int)$r['id'] ?>">
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
        <?= render_pagination($rowsMeta) ?>
    </section>
</div>

<script>
function openBranchFormModal(){
    var modal = document.getElementById('branchFormModal');
    if(modal) modal.classList.remove('hidden');
}

function closeBranchFormModal(){
    <?php if($editBranch): ?>
    window.location.href = '?module=branches';
    <?php else: ?>
    var modal = document.getElementById('branchFormModal');
    if(modal) modal.classList.add('hidden');
    <?php endif; ?>
}
</script>
