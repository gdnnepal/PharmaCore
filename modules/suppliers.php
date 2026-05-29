<?php
require_once __DIR__ . '/../config.php';

// H-2: Authorization check — only admins or users with suppliers.manage permission
if(!is_admin_user() && !has_permission('suppliers.manage')){
    flash_msg('You do not have permission to manage suppliers.', 'error');
    redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    try {
        $pdo->beginTransaction();

        if(isset($_POST['add_supplier'])){
            $pdo->prepare("INSERT INTO suppliers(name,phone,address,to_pay,to_receive) VALUES(?,?,?,?,?)")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['phone'] ?? '')),
                    trim((string)($_POST['address'] ?? '')),
                    (float)($_POST['to_pay'] ?? 0),
                    (float)($_POST['to_receive'] ?? 0),
                ]);
        } else if(isset($_POST['update_supplier'])){
            $sid = (int)($_POST['supplier_id'] ?? 0);
            if($sid <= 0) throw new Exception('Invalid supplier');

            $pdo->prepare("UPDATE suppliers SET name=?, phone=?, address=?, to_pay=?, to_receive=? WHERE id=?")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['phone'] ?? '')),
                    trim((string)($_POST['address'] ?? '')),
                    (float)($_POST['to_pay'] ?? 0),
                    (float)($_POST['to_receive'] ?? 0),
                    $sid,
                ]);
        } else if(isset($_POST['delete_supplier'])){
            $sid = (int)($_POST['supplier_id'] ?? 0);
            if($sid <= 0) throw new Exception('Invalid supplier');

            $stmtP = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id=?");
            $stmtP->execute([$sid]);
            $paymentCount = (int)$stmtP->fetchColumn();

            $stmtB = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE supplier_id=?");
            $stmtB->execute([$sid]);
            $batchCount = (int)$stmtB->fetchColumn();

            if($paymentCount > 0 || $batchCount > 0){
                throw new Exception('Cannot delete supplier with payment or batch history.');
            }

            $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$sid]);
        } else if(isset($_POST['add_payment_log'])){
            $sid = (int)($_POST['supplier_id'] ?? 0);
            $type = (string)($_POST['payment_type'] ?? 'pay');
            $method = (string)($_POST['payment_method'] ?? 'cash');
            $amount = (float)($_POST['amount'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            $paidAtBs = trim((string)($_POST['paid_at_bs'] ?? ''));

            if($sid <= 0) throw new Exception('Invalid supplier');
            if($amount <= 0) throw new Exception('Amount must be greater than 0');
            if($type !== 'pay' && $type !== 'receive') throw new Exception('Invalid payment type');
            if(!in_array($method, ['cash', 'cheque', 'bank_transfer', 'qr_payment'], true)) throw new Exception('Invalid payment method');

            $pdo->prepare("INSERT INTO supplier_payments(supplier_id,payment_type,payment_method,amount,note,paid_at_bs) VALUES(?,?,?,?,?,?)")
                ->execute([$sid, $type, $method, $amount, $note, $paidAtBs !== '' ? $paidAtBs : null]);

            if($type === 'pay'){
                $pdo->prepare("UPDATE suppliers SET to_pay = GREATEST(to_pay - ?, 0) WHERE id=?")->execute([$amount, $sid]);
            } else {
                $pdo->prepare("UPDATE suppliers SET to_receive = GREATEST(to_receive - ?, 0) WHERE id=?")->execute([$amount, $sid]);
            }
        } else if(isset($_POST['edit_payment_log'])){
            $logId = (int)($_POST['log_id'] ?? 0);
            $type = (string)($_POST['payment_type'] ?? 'pay');
            $method = (string)($_POST['payment_method'] ?? 'cash');
            $amount = (float)($_POST['amount'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));
            $paidAtBs = trim((string)($_POST['paid_at_bs'] ?? ''));

            if($logId <= 0) throw new Exception('Invalid payment log');
            if($amount <= 0) throw new Exception('Amount must be greater than 0');
            if($type !== 'pay' && $type !== 'receive') throw new Exception('Invalid payment type');
            if(!in_array($method, ['cash', 'cheque', 'bank_transfer', 'qr_payment'], true)) throw new Exception('Invalid payment method');

            // Fetch old payment to reverse its effects
            $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE id=?");
            $stmt->execute([$logId]);
            $oldPayment = $stmt->fetch();
            
            if(!$oldPayment) throw new Exception('Payment log not found');

            $supplierId = (int)$oldPayment['supplier_id'];
            $oldType = (string)$oldPayment['payment_type'];
            $oldAmount = (float)$oldPayment['amount'];

            // Reverse old payment impact
            if($oldType === 'pay'){
                $pdo->prepare("UPDATE suppliers SET to_pay = to_pay + ? WHERE id=?")->execute([$oldAmount, $supplierId]);
            } else {
                $pdo->prepare("UPDATE suppliers SET to_receive = to_receive + ? WHERE id=?")->execute([$oldAmount, $supplierId]);
            }

            // Apply new payment
            $pdo->prepare("UPDATE supplier_payments SET payment_type=?, payment_method=?, amount=?, note=?, paid_at_bs=? WHERE id=?")
                ->execute([$type, $method, $amount, $note, $paidAtBs !== '' ? $paidAtBs : null, $logId]);

            if($type === 'pay'){
                $pdo->prepare("UPDATE suppliers SET to_pay = GREATEST(to_pay - ?, 0) WHERE id=?")->execute([$amount, $supplierId]);
            } else {
                $pdo->prepare("UPDATE suppliers SET to_receive = GREATEST(to_receive - ?, 0) WHERE id=?")->execute([$amount, $supplierId]);
            }
        } else if(isset($_POST['delete_payment_log'])){
            $logId = (int)($_POST['log_id'] ?? 0);
            
            if($logId <= 0) throw new Exception('Invalid payment log');

            // Fetch payment to reverse its effects
            $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE id=?");
            $stmt->execute([$logId]);
            $payment = $stmt->fetch();
            
            if(!$payment) throw new Exception('Payment log not found');

            $supplierId = (int)$payment['supplier_id'];
            $paymentType = (string)$payment['payment_type'];
            $amount = (float)$payment['amount'];

            // Reverse payment impact
            if($paymentType === 'pay'){
                $pdo->prepare("UPDATE suppliers SET to_pay = to_pay + ? WHERE id=?")->execute([$amount, $supplierId]);
            } else {
                $pdo->prepare("UPDATE suppliers SET to_receive = to_receive + ? WHERE id=?")->execute([$amount, $supplierId]);
            }

            // Delete payment log
            $pdo->prepare("DELETE FROM supplier_payments WHERE id=?")->execute([$logId]);
        }

        $pdo->commit();
        flash_msg('Supplier data updated successfully.');

        $redirectParams = ['module' => 'suppliers'];
        $searchParam = trim((string)($_GET['supplier_search'] ?? ''));
        if($searchParam !== ''){
            $redirectParams['supplier_search'] = $searchParam;
        }
        redirect_with_fallback('?' . http_build_query($redirectParams));
    } catch(Exception $e){
        $pdo->rollBack();
        flash_msg($e->getMessage(), 'error');
    }
}

$editSupplierId = (int)($_GET['edit_supplier'] ?? 0);
$viewSupplierId = (int)($_GET['view_supplier'] ?? 0);
$editPaymentLogId = (int)($_GET['edit_payment_log'] ?? 0);

$editSupplier = null;
if($editSupplierId > 0){
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $stmt->execute([$editSupplierId]);
    $editSupplier = $stmt->fetch();
}

$editPaymentLog = null;
if($editPaymentLogId > 0){
    $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE id=?");
    $stmt->execute([$editPaymentLogId]);
    $editPaymentLog = $stmt->fetch();
}

$viewSupplier = null;
$viewLogs = [];
if($viewSupplierId > 0){
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $stmt->execute([$viewSupplierId]);
    $viewSupplier = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE supplier_id=? ORDER BY COALESCE(paid_at_bs, DATE_FORMAT(paid_at, '%Y-%m-%d')) DESC, id DESC LIMIT 30");
    $stmt->execute([$viewSupplierId]);
    $viewLogs = $stmt->fetchAll();
}

$allSuppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();
$supplierSearch = trim((string)($_GET['supplier_search'] ?? ''));

$suppliers = $allSuppliers;
if($supplierSearch !== ''){
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE name LIKE ? OR phone LIKE ? OR address LIKE ? ORDER BY name ASC");
    $like = '%' . $supplierSearch . '%';
    $stmt->execute([$like, $like, $like]);
    $suppliers = $stmt->fetchAll();
}

$logs = $pdo->query("SELECT l.*, s.name AS supplier_name FROM supplier_payments l JOIN suppliers s ON s.id=l.supplier_id ORDER BY COALESCE(l.paid_at_bs, DATE_FORMAT(l.paid_at, '%Y-%m-%d')) DESC, l.id DESC LIMIT 100")->fetchAll();

$suppliersMeta = paginate_array($suppliers, 'suppliers_page', 10);
$suppliers = $suppliersMeta['rows'];

$logsMeta = paginate_array($logs, 'supplier_logs_page', 10);
$logs = $logsMeta['rows'];

$viewLogsMeta = paginate_array($viewLogs, 'view_supplier_logs_page', 10);
$viewLogs = $viewLogsMeta['rows'];

$f = flash_msg();
$paymentMethodLabels = [
    'cash' => 'Cash',
    'cheque' => 'Cheque',
    'bank_transfer' => 'Bank Transfer',
    'qr_payment' => 'QR Payment',
];

$totalSuppliers = count($allSuppliers);
$totalToPay = 0.0;
$totalToReceive = 0.0;
foreach($allSuppliers as $s){
    $totalToPay += (float)($s['to_pay'] ?? 0);
    $totalToReceive += (float)($s['to_receive'] ?? 0);
}
?>

<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e($f['msg']) ?></div><?php endif; ?>

    <div class="flex gap-2">
        <button type="button" onclick="toggleSupplierPanel('supplierFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Add Supplier</button>
        <button type="button" onclick="toggleSupplierPanel('paymentFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Add Payment</button>
    </div>

    <?php if($viewSupplier): ?>
    <div id="viewSupplierPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" onclick="if(event.target.id==='viewSupplierPanel') window.location='?module=suppliers'">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800">View Supplier</h3>
                <a href="?module=suppliers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid md:grid-cols-3 gap-3 text-sm bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <div><span class="text-gray-500">Name:</span> <?= e((string)$viewSupplier['name']) ?></div>
                    <div><span class="text-gray-500">Phone:</span> <?= e((string)$viewSupplier['phone']) ?: 'N/A' ?></div>
                    <div><span class="text-gray-500">Address:</span> <?= e((string)$viewSupplier['address']) ?: 'N/A' ?></div>
                    <div><span class="text-gray-500">To Pay:</span> <?= npr((float)$viewSupplier['to_pay']) ?></div>
                    <div><span class="text-gray-500">To Receive:</span> <?= npr((float)$viewSupplier['to_receive']) ?></div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
                    <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 font-medium text-slate-800">Recent Payment Logs</div>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Date</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Type</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Method</th>
                                <th class="px-4 py-2 text-right font-semibold text-slate-700">Amount</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Note</th>
                                <th class="px-4 py-2 text-center font-semibold text-slate-700">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(empty($viewLogs)): ?>
                            <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">No logs found.</td></tr>
                        <?php else: ?>
                            <?php foreach($viewLogs as $l): ?>
                            <?php $methodKey = (string)($l['payment_method'] ?? 'cash'); ?>
                            <tr class="border-b border-slate-100">
                                <td class="px-4 py-2.5"><?= e((string)($l['paid_at_bs'] ?? '-')) ?></td>
                                <td class="px-4 py-2.5"><?= ucfirst((string)$l['payment_type']) ?></td>
                                <td class="px-4 py-2.5"><?= e((string)($paymentMethodLabels[$methodKey] ?? 'Cash')) ?></td>
                                <td class="px-4 py-2.5 text-right"><?= npr((float)$l['amount']) ?></td>
                                <td class="px-4 py-2.5"><?= e((string)$l['note']) ?: 'N/A' ?></td>
                                <td class="px-4 py-2.5 text-center">
                                    <div class="flex justify-center gap-1.5">
                                        <a href="?module=suppliers&edit_payment_log=<?= (int)$l['id'] ?>" class="px-2 py-1 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Delete this payment log?');" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="delete_payment_log" value="1">
                                            <input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
                                            <button class="px-2 py-1 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?= render_pagination($viewLogsMeta) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="supplierFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editSupplier ? '' : 'hidden' ?>" onclick="if(event.target.id==='supplierFormPanel') toggleSupplierPanel('supplierFormPanel')">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-lg w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800"><?= $editSupplier ? 'Edit Supplier' : 'Add Supplier' ?></h3>
                <?php if($editSupplier): ?>
                    <a href="?module=suppliers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
                <?php else: ?>
                    <button type="button" onclick="toggleSupplierPanel('supplierFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
                <?php endif; ?>
            </div>
            <form method="POST" class="p-6 space-y-5">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <?php if($editSupplier): ?>
                    <input type="hidden" name="update_supplier" value="1">
                    <input type="hidden" name="supplier_id" value="<?= (int)$editSupplier['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="add_supplier" value="1">
                <?php endif; ?>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Supplier Name <span class="text-red-500">*</span></label>
                        <input name="name" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editSupplier['name'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone</label>
                        <input name="phone" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editSupplier['phone'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">To Pay</label>
                        <input name="to_pay" type="number" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editSupplier['to_pay'] ?? '0')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">To Receive</label>
                        <input name="to_receive" type="number" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editSupplier['to_receive'] ?? '0')) ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Address</label>
                    <textarea name="address" rows="3" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"><?= e((string)($editSupplier['address'] ?? '')) ?></textarea>
                </div>

                <div class="pt-2 flex gap-2">
                    <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition"><?= $editSupplier ? 'Update Supplier' : 'Save Supplier' ?></button>
                    <?php if($editSupplier): ?><a href="?module=suppliers" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div id="paymentFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editPaymentLog ? '' : 'hidden' ?>" onclick="if(event.target.id==='paymentFormPanel') toggleSupplierPanel('paymentFormPanel')">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800"><?= $editPaymentLog ? 'Edit Payment Log' : 'Add Payment Log' ?></h3>
                <?php if($editPaymentLog): ?>
                    <a href="?module=suppliers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
                <?php else: ?>
                    <button type="button" onclick="toggleSupplierPanel('paymentFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
                <?php endif; ?>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <?php if($editPaymentLog): ?>
                    <input type="hidden" name="edit_payment_log" value="1">
                    <input type="hidden" name="log_id" value="<?= (int)$editPaymentLog['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="add_payment_log" value="1">
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Supplier <span class="text-red-500">*</span></label>
                    <select name="supplier_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" required <?= $editPaymentLog ? 'disabled' : '' ?>>
                        <option value="">Choose Supplier</option>
                        <?php foreach($allSuppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)($editPaymentLog['supplier_id'] ?? 0) ? 'selected' : '' ?>><?= e((string)$s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($editPaymentLog): ?><input type="hidden" name="supplier_id" value="<?= (int)$editPaymentLog['supplier_id'] ?>"><?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Type <span class="text-red-500">*</span></label>
                        <select name="payment_type" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" required>
                            <option value="pay" <?= (string)($editPaymentLog['payment_type'] ?? '') === 'pay' ? 'selected' : '' ?>>Pay</option>
                            <option value="receive" <?= (string)($editPaymentLog['payment_type'] ?? '') === 'receive' ? 'selected' : '' ?>>Receive</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Method <span class="text-red-500">*</span></label>
                        <select name="payment_method" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" required>
                            <option value="cash" <?= (string)($editPaymentLog['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="cheque" <?= (string)($editPaymentLog['payment_method'] ?? '') === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            <option value="bank_transfer" <?= (string)($editPaymentLog['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="qr_payment" <?= (string)($editPaymentLog['payment_method'] ?? '') === 'qr_payment' ? 'selected' : '' ?>>QR Payment</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount <span class="text-red-500">*</span></label>
                    <input name="amount" type="number" step="0.01" min="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" required value="<?= e((string)($editPaymentLog['amount'] ?? '')) ?>">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid Date (AD)</label>
                    <input type="date" id="supplier_paid_at_bs" name="paid_at_bs" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition ad-date-input" value="<?= e((string)($editPaymentLog['paid_at_bs'] ?? '')) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Note</label>
                    <input name="note" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editPaymentLog['note'] ?? '')) ?>">
                </div>

                <div class="pt-2 flex gap-2">
                    <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition"><?= $editPaymentLog ? 'Update Payment Log' : 'Save Payment Log' ?></button>
                    <?php if($editPaymentLog): ?><a href="?module=suppliers" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold text-slate-800">Supplier List</h3>
                    <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$suppliersMeta['total'] ?></span>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="module" value="suppliers">
                    <input
                        type="text"
                        name="supplier_search"
                        value="<?= e($supplierSearch) ?>"
                        placeholder="Search name, phone, address"
                        class="w-64 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                    >
                    <button class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium">Search</button>
                    <?php if($supplierSearch !== ''): ?>
                        <a href="?module=suppliers" class="text-sm text-primary hover:underline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Name</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Phone</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Address</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-700">To Pay</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-700">To Receive</th>
                    <th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if(empty($suppliers)): ?>
                    <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No suppliers found.</td></tr>
                <?php else: ?>
                    <?php foreach($suppliers as $s): ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                        <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$s['name']) ?></td>
                        <td class="px-6 py-3.5 text-slate-600"><?= e((string)$s['phone']) ?: 'N/A' ?></td>
                        <td class="px-6 py-3.5 text-slate-600"><?= e((string)$s['address']) ?: 'N/A' ?></td>
                        <td class="px-6 py-3.5 text-right text-slate-900"><?= npr((float)$s['to_pay']) ?></td>
                        <td class="px-6 py-3.5 text-right text-slate-900"><?= npr((float)$s['to_receive']) ?></td>
                        <td class="px-6 py-3.5 text-center">
                            <div class="flex justify-center gap-1.5">
                                <a href="?module=suppliers&view_supplier=<?= (int)$s['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="View">View</a>
                                <a href="?module=suppliers&edit_supplier=<?= (int)$s['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Edit">Edit</a>
                                <form method="POST" onsubmit="return confirm('Delete this supplier?');" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="delete_supplier" value="1">
                                    <input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
                                    <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Delete">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($suppliersMeta) ?>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">Payment Log</h3>
                <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$logsMeta['total'] ?></span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Date</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Supplier</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Type</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Method</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-700">Amount</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-700">Note</th>
                    <th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if(empty($logs)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No payment logs found.</td></tr>
                <?php else: ?>
                    <?php foreach($logs as $l): ?>
                    <?php $methodKey = (string)($l['payment_method'] ?? 'cash'); ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                        <td class="px-6 py-3.5"><?= e((string)($l['paid_at_bs'] ?? '-')) ?></td>
                        <td class="px-6 py-3.5"><?= e((string)$l['supplier_name']) ?></td>
                        <td class="px-6 py-3.5"><?= ucfirst((string)$l['payment_type']) ?></td>
                        <td class="px-6 py-3.5"><?= e((string)($paymentMethodLabels[$methodKey] ?? 'Cash')) ?></td>
                        <td class="px-6 py-3.5 text-right"><?= npr((float)$l['amount']) ?></td>
                        <td class="px-6 py-3.5"><?= e((string)$l['note']) ?: 'N/A' ?></td>
                        <td class="px-6 py-3.5 text-center">
                            <div class="flex justify-center gap-1.5">
                                <a href="?module=suppliers&edit_payment_log=<?= (int)$l['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Edit</a>
                                <form method="POST" onsubmit="return confirm('Delete this payment log?');" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="delete_payment_log" value="1">
                                    <input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
                                    <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($logsMeta) ?>
    </div>
</div>

<script>
function toggleSupplierPanel(panelId){
    var panel = document.getElementById(panelId);
    panel.classList.toggle('hidden');
}

function getCurrentAdDateString(){
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function initAdDates(){
    document.querySelectorAll('.ad-date-input').forEach(function(input){
        if(!input.value){
            input.value = getCurrentAdDateString();
        }
    });
}

document.addEventListener('DOMContentLoaded', initAdDates);
</script>
