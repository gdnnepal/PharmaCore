<?php
require_once __DIR__ . '/../config.php';

$canViewCustomers = has_permission('customers.view') || is_admin_user();
$canCreateCustomers = has_permission('customers.create') || is_admin_user();
$canDeleteCustomers = has_permission('customers.delete') || is_admin_user();
$canEditCustomers = has_permission('customers.edit') || is_admin_user();
$canManagePayments = has_permission('customers.payment') || is_admin_user();

if(!$canViewCustomers){
    flash_msg('You do not have permission to view customers.', 'error');
    redirect_with_fallback('?module=sale');
}

if($_SERVER['REQUEST_METHOD']==='POST' && verify_csrf()){
    $attemptedCustomerEdit = isset($_POST['update_cust']);
    $attemptedPaymentManageAction = isset($_POST['pay']) || isset($_POST['edit_payment']) || isset($_POST['delete_payment']);
    $attemptedCustomerCreate = isset($_POST['add_cust']);
    $attemptedCustomerDelete = isset($_POST['delete_cust']);
    $attemptedSendDueSms = isset($_POST['send_due_sms']);

    if($attemptedCustomerEdit && !$canEditCustomers){
        flash_msg('You are not allowed to edit customers.', 'error');
        redirect_with_fallback('?module=customers');
    }
    if($attemptedPaymentManageAction && !$canManagePayments){
        flash_msg('You are not allowed to edit customer/payment records.', 'error');
        redirect_with_fallback('?module=customers');
    }
    if($attemptedCustomerCreate && !$canCreateCustomers){
        flash_msg('You are not allowed to add customers.', 'error');
        redirect_with_fallback('?module=customers');
    }
    if($attemptedCustomerDelete && !$canDeleteCustomers){
        flash_msg('You are not allowed to delete customers.', 'error');
        redirect_with_fallback('?module=customers');
    }
    if($attemptedSendDueSms && !$canViewCustomers){
        flash_msg('You are not allowed to send SMS notifications.', 'error');
        redirect_with_fallback('?module=customers');
    }
    try {
        $pdo->beginTransaction();

        if(isset($_POST['add_cust'])){
            $phone = trim((string)($_POST['phone'] ?? ''));
            if($phone !== ''){
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
                $stmt->execute([$phone]);
                if($stmt->fetch()) throw new Exception('Phone number already used.');
            }

            $pdo->prepare("INSERT INTO customers(name,phone,address,credit_limit) VALUES(?,?,?,?)")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    $phone,
                    trim((string)($_POST['addr'] ?? '')),
                    (float)($_POST['limit'] ?? 0),
                ]);
        } else if(isset($_POST['update_cust'])){
            $cid = (int)($_POST['cid'] ?? 0);
            if($cid <= 0) throw new Exception('Invalid customer');

            $beforeStmt = $pdo->prepare("SELECT id, name, phone, address, credit_limit FROM customers WHERE id=? LIMIT 1");
            $beforeStmt->execute([$cid]);
            $beforeCustomer = $beforeStmt->fetch();
            if(!$beforeCustomer) throw new Exception('Customer not found.');

            $phone = trim((string)($_POST['phone'] ?? ''));
            if($phone !== ''){
                $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone=? AND id<>? LIMIT 1");
                $stmt->execute([$phone, $cid]);
                if($stmt->fetch()) throw new Exception('Phone number already used.');
            }

            $pdo->prepare("UPDATE customers SET name=?, phone=?, address=?, credit_limit=? WHERE id=?")
                ->execute([
                    trim((string)($_POST['name'] ?? '')),
                    $phone,
                    trim((string)($_POST['addr'] ?? '')),
                    (float)($_POST['limit'] ?? 0),
                    $cid,
                ]);

            audit_log_action(
                'customers',
                'update_customer',
                'Customer updated.',
                [
                    'customer_id' => $cid,
                    'before' => $beforeCustomer,
                    'after' => [
                        'id' => $cid,
                        'name' => trim((string)($_POST['name'] ?? '')),
                        'phone' => $phone,
                        'address' => trim((string)($_POST['addr'] ?? '')),
                        'credit_limit' => (float)($_POST['limit'] ?? 0),
                    ],
                ],
                'customer',
                $cid
            );
        } else if(isset($_POST['delete_cust'])){
            $cid = (int)($_POST['cid'] ?? 0);
            if($cid <= 0) throw new Exception('Invalid customer');

            $stmt = $pdo->prepare("SELECT current_due FROM customers WHERE id=?");
            $stmt->execute([$cid]);
            $currentDue = (float)($stmt->fetchColumn() ?: 0);
            if($currentDue > 0){
                throw new Exception('Cannot delete customer with pending due.');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE customer_id=?");
            $stmt->execute([$cid]);
            $paymentCount = (int)$stmt->fetchColumn();
            if($paymentCount > 0){
                throw new Exception('Cannot delete customer with payment history.');
            }

            $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$cid]);
        } else if(isset($_POST['pay'])){
            $amt = (float)($_POST['amt'] ?? 0);
            $cid = (int)($_POST['cid'] ?? 0);
            $method = trim((string)($_POST['method'] ?? 'cash'));
            $paidAtBs = trim((string)($_POST['paid_at_bs'] ?? ''));

            if($cid <= 0) throw new Exception('Invalid customer');
            if($amt <= 0) throw new Exception('Amount must be greater than 0');
            if(!in_array($method, ['cash', 'online'], true)) throw new Exception('Invalid payment method');

            $pdo->prepare("INSERT INTO payments(customer_id,amount,method,paid_at_bs) VALUES(?,?,?,?)")
                ->execute([$cid, $amt, $method, $paidAtBs !== '' ? $paidAtBs : null]);
            $pdo->prepare("UPDATE customers SET current_due=GREATEST(current_due-?,0) WHERE id=?")
                ->execute([$amt, $cid]);
        } else if(isset($_POST['edit_payment'])){
            $payId = (int)($_POST['pay_id'] ?? 0);
            $amt = (float)($_POST['amt'] ?? 0);
            $method = trim((string)($_POST['method'] ?? 'cash'));
            $paidAtBs = trim((string)($_POST['paid_at_bs'] ?? ''));

            if($payId <= 0) throw new Exception('Invalid payment');
            if($amt <= 0) throw new Exception('Amount must be greater than 0');
            if(!in_array($method, ['cash', 'online'], true)) throw new Exception('Invalid payment method');

            // M-4: Only admins can edit payments (prevents IDOR across branches)
            if(!is_admin_user()) throw new Exception('Only administrators can edit payment records.');

            // Fetch old payment to reverse its effects
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id=?");
            $stmt->execute([$payId]);
            $oldPayment = $stmt->fetch();

            if(!$oldPayment) throw new Exception('Payment not found');

            $customerId = (int)$oldPayment['customer_id'];
            $oldAmount = (float)$oldPayment['amount'];

            // Reverse old payment impact
            $pdo->prepare("UPDATE customers SET current_due = current_due + ? WHERE id=?")->execute([$oldAmount, $customerId]);

            // Update payment
            $pdo->prepare("UPDATE payments SET amount=?, method=?, paid_at_bs=? WHERE id=?")->execute([$amt, $method, $paidAtBs !== '' ? $paidAtBs : null, $payId]);

            // Apply new payment
            $pdo->prepare("UPDATE customers SET current_due=GREATEST(current_due-?,0) WHERE id=?")->execute([$amt, $customerId]);
        } else if(isset($_POST['delete_payment'])){
            $payId = (int)($_POST['pay_id'] ?? 0);

            if($payId <= 0) throw new Exception('Invalid payment');

            // M-4: Only admins can delete payments (prevents IDOR across branches)
            if(!is_admin_user()) throw new Exception('Only administrators can delete payment records.');

            // Fetch payment to reverse its effects
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id=?");
            $stmt->execute([$payId]);
            $payment = $stmt->fetch();

            if(!$payment) throw new Exception('Payment not found');

            $customerId = (int)$payment['customer_id'];
            $amount = (float)$payment['amount'];

            // Reverse payment impact
            $pdo->prepare("UPDATE customers SET current_due = current_due + ? WHERE id=?")->execute([$amount, $customerId]);

            // Delete payment
            $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$payId]);
        } else if(isset($_POST['send_due_sms'])){
            $cid = (int)($_POST['cid'] ?? 0);
            if($cid <= 0) throw new Exception('Invalid customer');

            $customerStmt = $pdo->prepare("SELECT id, name, phone, current_due FROM customers WHERE id=? LIMIT 1");
            $customerStmt->execute([$cid]);
            $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

            if(!$customer) throw new Exception('Customer not found');

            $dueAmount = (float)($customer['current_due'] ?? 0);
            if($dueAmount <= 0){
                throw new Exception('Customer has no outstanding due.');
            }

            $template = trim((string)(get_app_setting('sms_template_due') ?? ''));
            if($template === ''){
                $template = 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
            }

            $nameParts = preg_split('/\s+/', trim((string)$customer['name']), 2);
            $firstName = $nameParts[0] ?? (string)$customer['name'];
            $message = str_replace(
                ['{firstname}', '{fullname}', '{dueamt}', '{phone}'],
                [$firstName, (string)$customer['name'], (string)$dueAmount, (string)$customer['phone']],
                $template
            );

            if(trim($message) === ''){
                throw new Exception('SMS message cannot be empty.');
            }

            $sendResult = send_sms_notification((string)$customer['phone'], $message);
            $responseData = isset($sendResult['data']) ? json_encode($sendResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            if($sendResult['success']){
                $logStmt = $pdo->prepare("
                    INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, response_data, sent_by, created_at)
                    VALUES (?, ?, ?, 'due', 'success', ?, ?, NOW())
                ");
                $logStmt->execute([
                    $customer['id'],
                    $customer['phone'],
                    $message,
                    $responseData,
                    $_SESSION['uid'] ?? 0,
                ]);
                flash_msg('Due SMS sent successfully.');
            } else {
                $logStmt = $pdo->prepare("
                    INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, error_message, response_data, sent_by, created_at)
                    VALUES (?, ?, ?, 'due', 'failed', ?, ?, ?, NOW())
                ");
                $logStmt->execute([
                    $customer['id'],
                    $customer['phone'],
                    $message,
                    $sendResult['message'],
                    $responseData,
                    $_SESSION['uid'] ?? 0,
                ]);
                throw new Exception('Failed to send due SMS: ' . $sendResult['message']);
            }
        }

        $pdo->commit();
        if(!isset($_POST['send_due_sms'])){
            flash_msg('Customer record updated.');
        }

        $redirectParams = ['module' => 'customers'];
        if(isset($_POST['send_due_sms'])){
            $redirectParams['view_customer'] = (int)($_POST['cid'] ?? 0);
        }
        $searchParam = trim((string)($_GET['customer_search'] ?? ''));
        if($searchParam !== ''){
            $redirectParams['customer_search'] = $searchParam;
        }
        redirect_with_fallback('?' . http_build_query($redirectParams));
    } catch(Exception $e){
        $pdo->rollBack();
        $msg = $e->getMessage();
        if(
            $e instanceof PDOException
            && (string)$e->getCode() === '23000'
            && str_contains(strtolower($msg), 'duplicate entry')
            && str_contains(strtolower($msg), 'customers.phone')
        ){
            $msg = 'Phone number already used.';
        }
        flash_msg($msg,'error');
    }
}
$customerSearch = trim((string)($_GET['customer_search'] ?? ''));
$editCustomerId = (int)($_GET['edit_customer'] ?? 0);
$viewCustomerId = (int)($_GET['view_customer'] ?? 0);
$editPaymentId = (int)($_GET['edit_payment'] ?? 0);

if(!$canEditCustomers){
    $editCustomerId = 0;
}
if(!$canManagePayments){
    $editPaymentId = 0;
}

$editCustomer = null;
if($editCustomerId > 0){
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->execute([$editCustomerId]);
    $editCustomer = $stmt->fetch();
}

$editPayment = null;
if($editPaymentId > 0){
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id=?");
    $stmt->execute([$editPaymentId]);
    $editPayment = $stmt->fetch();
}

$allCustomers = $pdo->query("SELECT * FROM customers ORDER BY current_due DESC, name ASC")->fetchAll();
$custs = $allCustomers;
if($customerSearch !== ''){
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE name LIKE ? OR phone LIKE ? OR address LIKE ? ORDER BY current_due DESC, name ASC");
    $like = '%' . $customerSearch . '%';
    $stmt->execute([$like, $like, $like]);
    $custs = $stmt->fetchAll();
}

$viewCustomer = null;
$viewPayments = [];
if($viewCustomerId > 0){
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id=?");
    $stmt->execute([$viewCustomerId]);
    $viewCustomer = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE customer_id=? ORDER BY id DESC LIMIT 30");
    $stmt->execute([$viewCustomerId]);
    $viewPayments = $stmt->fetchAll();
}

$paymentLogs = $pdo->query("SELECT p.*, c.name AS customer_name FROM payments p JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC LIMIT 100")->fetchAll();

$custsMeta = paginate_array($custs, 'customers_page', 10);
$custs = $custsMeta['rows'];

$paymentLogsMeta = paginate_array($paymentLogs, 'payment_logs_page', 10);
$paymentLogs = $paymentLogsMeta['rows'];

$viewPaymentsMeta = paginate_array($viewPayments, 'view_payments_page', 10);
$viewPayments = $viewPaymentsMeta['rows'];

$f = flash_msg();

$totalCustomers = count($allCustomers);
$totalDue = 0.0;
$overLimitCount = 0;
foreach($allCustomers as $c){
    $due = (float)($c['current_due'] ?? 0);
    $limit = (float)($c['credit_limit'] ?? 0);
    $totalDue += $due;
    if($due > $limit) $overLimitCount++;
}
?>
<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div><?php endif; ?>

    <?php if($canCreateCustomers || $canManagePayments): ?>
    <div class="flex flex-wrap gap-2">
        <?php if($canCreateCustomers): ?>
        <button type="button" onclick="toggleCustomerPanel('customerFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Add Customer</button>
        <?php endif; ?>
        <?php if($canManagePayments): ?>
        <button type="button" onclick="toggleCustomerPanel('paymentFormPanel')" class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Settle Payment</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if($viewCustomer): ?>
    <div id="viewCustomerPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4" onclick="if(event.target.id==='viewCustomerPanel') window.location='?module=customers'">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-3xl w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800">View Customer</h3>
                <a href="?module=customers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid md:grid-cols-3 gap-3 text-sm bg-slate-50 rounded-xl p-3 border border-slate-200">
                    <div><span class="text-gray-500">Name:</span> <?= e((string)$viewCustomer['name']) ?></div>
                    <div><span class="text-gray-500">Phone:</span> <?= e((string)$viewCustomer['phone']) ?></div>
                    <div><span class="text-gray-500">Address:</span> <?= e((string)$viewCustomer['address']) ?: 'N/A' ?></div>
                    <div><span class="text-gray-500">Current Due:</span> <?= npr((float)$viewCustomer['current_due']) ?></div>
                    <div><span class="text-gray-500">Credit Limit:</span> <?= npr((float)$viewCustomer['credit_limit']) ?></div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <?php if(is_sms_configured() && (float)($viewCustomer['current_due'] ?? 0) > 0): ?>
                    <form method="POST" onsubmit="return confirm('Send due SMS to this customer?');">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="send_due_sms" value="1">
                        <input type="hidden" name="cid" value="<?= (int)$viewCustomer['id'] ?>">
                        <button class="px-4 py-2 rounded-lg bg-primary text-white hover:bg-teal-800 text-sm font-medium">Send Due SMS</button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
                    <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 font-medium text-slate-800">Recent Payments</div>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Date</th>
                                <th class="px-4 py-2 text-right font-semibold text-slate-700">Amount</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Method</th>
                                <?php if($canManagePayments): ?><th class="px-4 py-2 text-center font-semibold text-slate-700">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(empty($viewPayments)): ?>
                            <tr><td colspan="<?= $canManagePayments ? 4 : 3 ?>" class="px-4 py-6 text-center text-slate-500">No payments found.</td></tr>
                        <?php else: ?>
                            <?php foreach($viewPayments as $p): ?>
                            <tr class="border-b border-slate-100">
                                <td class="px-4 py-2.5"><?= e((string)($p['paid_at_bs'] ?? '-')) ?></td>
                                <td class="px-4 py-2.5 text-right"><?= npr((float)$p['amount']) ?></td>
                                <td class="px-4 py-2.5"><?= e((string)$p['method']) ?></td>
                                <?php if($canManagePayments): ?><td class="px-4 py-2.5 text-center">
                                    <div class="flex justify-center gap-1.5">
                                        <a href="?module=customers&edit_payment=<?= (int)$p['id'] ?>" class="px-2 py-1 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Delete this payment?');" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="delete_payment" value="1">
                                            <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
                                            <button class="px-2 py-1 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Delete</button>
                                        </form>
                                    </div>
                                </td><?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?= render_pagination($viewPaymentsMeta) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="customerFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editCustomer ? '' : 'hidden' ?>" onclick="if(event.target.id==='customerFormPanel') toggleCustomerPanel('customerFormPanel')">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-lg w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800"><?= $editCustomer ? 'Edit Customer' : 'Add Customer' ?></h3>
                <?php if($editCustomer): ?>
                    <a href="?module=customers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
                <?php else: ?>
                    <button type="button" onclick="toggleCustomerPanel('customerFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
                <?php endif; ?>
            </div>
            <form method="POST" class="p-6 space-y-5">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <?php if($editCustomer): ?>
                    <input type="hidden" name="update_cust" value="1">
                    <input type="hidden" name="cid" value="<?= (int)$editCustomer['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="add_cust" value="1">
                <?php endif; ?>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Customer Name <span class="text-red-500">*</span></label>
                        <input name="name" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editCustomer['name'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Phone <span class="text-red-500">*</span></label>
                        <input name="phone" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editCustomer['phone'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Address</label>
                        <input name="addr" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editCustomer['address'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Credit Limit</label>
                        <input name="limit" type="number" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editCustomer['credit_limit'] ?? '0')) ?>">
                    </div>
                </div>

                <div class="pt-2 flex gap-2">
                    <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition"><?= $editCustomer ? 'Update Customer' : 'Save Customer' ?></button>
                    <?php if($editCustomer): ?><a href="?module=customers" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div id="paymentFormPanel" class="fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4 <?= $editPayment ? '' : 'hidden' ?>" onclick="if(event.target.id==='paymentFormPanel') toggleCustomerPanel('paymentFormPanel')">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white">
                <h3 class="text-lg font-semibold text-slate-800"><?= $editPayment ? 'Edit Payment' : 'Settle Payment' ?></h3>
                <?php if($editPayment): ?>
                    <a href="?module=customers" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
                <?php else: ?>
                    <button type="button" onclick="toggleCustomerPanel('paymentFormPanel')" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</button>
                <?php endif; ?>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <?php if($editPayment): ?>
                    <input type="hidden" name="edit_payment" value="1">
                    <input type="hidden" name="pay_id" value="<?= (int)$editPayment['id'] ?>">
                <?php else: ?>
                    <input type="hidden" name="pay" value="1">
                <?php endif; ?>

                <?php if(!$editPayment): ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Customer <span class="text-red-500">*</span></label>
                    <select name="cid" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                        <?php foreach($allCustomers as $c): if((float)$c['current_due'] > 0): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?> (Due: <?= npr((float)$c['current_due']) ?>)</option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Amount <span class="text-red-500">*</span></label>
                        <input type="number" name="amt" step="0.01" required class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" value="<?= e((string)($editPayment['amount'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Method</label>
                        <select name="method" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                            <option value="cash" <?= (string)($editPayment['method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="online" <?= (string)($editPayment['method'] ?? '') === 'online' ? 'selected' : '' ?>>Online</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid Date (AD)</label>
                    <input type="date" id="customer_paid_at_bs" name="paid_at_bs" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition ad-date-input" value="<?= e((string)($editPayment['paid_at_bs'] ?? '')) ?>">
                </div>

                <div class="pt-2 flex gap-2">
                    <button class="flex-1 bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition"><?= $editPayment ? 'Update Payment' : 'Record Payment' ?></button>
                    <?php if($editPayment): ?><a href="?module=customers" class="flex-1 text-center bg-primary hover:bg-teal-800 text-white font-medium px-4 py-2.5 rounded-lg transition">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold text-slate-800">Customers</h3>
                    <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$custsMeta['total'] ?></span>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="module" value="customers">
                    <input type="text" name="customer_search" value="<?= e($customerSearch) ?>" placeholder="Search name, phone, address" class="w-64 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium">Search</button>
                    <?php if($customerSearch !== ''): ?><a href="?module=customers" class="text-sm text-primary hover:underline">Clear</a><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto text-sm">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Name</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Phone</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Address</th>
                        <th class="px-6 py-3 text-right font-semibold text-slate-700">Due</th>
                        <th class="px-6 py-3 text-right font-semibold text-slate-700">Limit</th>
                        <th class="px-6 py-3 text-center font-semibold text-slate-700">Status</th>
                        <th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($custs)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No customers found.</td></tr>
                <?php else: ?>
                    <?php foreach($custs as $c):
                        $due = (float)($c['current_due'] ?? 0);
                        $limit = (float)($c['credit_limit'] ?? 0);
                        $over = $due > $limit;
                    ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                        <td class="px-6 py-3.5 font-medium text-slate-900"><?= e((string)$c['name']) ?></td>
                        <td class="px-6 py-3.5 text-slate-600"><?= e((string)$c['phone']) ?></td>
                        <td class="px-6 py-3.5 text-slate-600"><?= e((string)$c['address']) ?: 'N/A' ?></td>
                        <td class="px-6 py-3.5 text-right <?= $over ? 'text-red-700 font-semibold' : 'text-slate-900' ?>"><?= npr($due) ?></td>
                        <td class="px-6 py-3.5 text-right text-slate-900"><?= npr($limit) ?></td>
                        <td class="px-6 py-3.5 text-center">
                            <?php if($over): ?>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">Over Limit</span>
                            <?php else: ?>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Normal</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3.5 text-center">
                            <div class="flex justify-center gap-1.5">
                                <a href="?module=customers&view_customer=<?= (int)$c['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="View">View</a>
                                <?php if($canEditCustomers): ?>
                                    <a href="?module=customers&edit_customer=<?= (int)$c['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Edit">Edit</a>
                                <?php endif; ?>
                                <?php if($canDeleteCustomers): ?>
                                    <form method="POST" onsubmit="return confirm('Delete this customer?');" style="display:inline;">
                                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="delete_cust" value="1">
                                        <input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
                                        <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800" title="Delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($custsMeta) ?>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex items-center gap-2">
                <h3 class="font-semibold text-slate-800">Payment History</h3>
                <span class="inline-block bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-full text-xs font-medium"><?= (int)$paymentLogsMeta['total'] ?></span>
            </div>
        </div>
        <div class="overflow-x-auto text-sm">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Date</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Customer</th>
                        <th class="px-6 py-3 text-right font-semibold text-slate-700">Amount</th>
                        <th class="px-6 py-3 text-left font-semibold text-slate-700">Method</th>
                        <?php if($canManagePayments): ?><th class="px-6 py-3 text-center font-semibold text-slate-700">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($paymentLogs)): ?>
                    <tr><td colspan="<?= $canManagePayments ? 5 : 4 ?>" class="px-6 py-8 text-center text-slate-500">No payments found.</td></tr>
                <?php else: ?>
                    <?php foreach($paymentLogs as $p): ?>
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition">
                        <td class="px-6 py-3.5"><?= e((string)($p['paid_at_bs'] ?? '-')) ?></td>
                        <td class="px-6 py-3.5"><?= e((string)$p['customer_name']) ?></td>
                        <td class="px-6 py-3.5 text-right"><?= npr((float)$p['amount']) ?></td>
                        <td class="px-6 py-3.5"><?= e((string)$p['method']) ?></td>
                        <?php if($canManagePayments): ?><td class="px-6 py-3.5 text-center">
                            <div class="flex justify-center gap-1.5">
                                <a href="?module=customers&edit_payment=<?= (int)$p['id'] ?>" class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Edit</a>
                                <form method="POST" onsubmit="return confirm('Delete this payment?');" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="delete_payment" value="1">
                                    <input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>">
                                    <button class="px-3 py-1.5 text-xs font-medium rounded bg-primary text-white hover:bg-teal-800">Delete</button>
                                </form>
                            </div>
                        </td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($paymentLogsMeta) ?>
    </div>
</div>

<script>
function toggleCustomerPanel(panelId){
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