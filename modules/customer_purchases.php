<?php
require_once __DIR__ . '/../config.php';

// H-6: DDL migrations moved to config.php bootstrap block — no longer run on every page load

$isAdmin = is_admin_user();
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$filterUserId = (int)($_GET['user_id'] ?? 0);

$customerId = (int)($_GET['customer_id'] ?? 0);
$customerSearch = trim((string)($_GET['customer_search'] ?? ''));
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$viewSaleId = (int)($_GET['view_sale'] ?? 0);

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    $action = (string)($_POST['action'] ?? '');
    if($action === 'sale_return' || $action === 'admin_sale_return'){
        $canAttemptRefund = $isAdmin || $currentUserId > 0;
        if(!$canAttemptRefund){
            flash_msg('You are not allowed to process refund.', 'error');
            redirect_with_fallback('?module=sales_record');
        }

        $saleId = (int)($_POST['sale_id'] ?? 0);
        $returnRemarks = trim((string)($_POST['return_remarks'] ?? ''));
        if($saleId <= 0){
            flash_msg('Invalid sale selected for return.', 'error');
            redirect_with_fallback('?module=sales_record');
        }
        if($returnRemarks === ''){
            flash_msg('Remarks are required for medicine return.', 'error');
            redirect_with_fallback('?module=sales_record&view_sale=' . $saleId);
        }

        try {
            $pdo->beginTransaction();

            if($isAdmin){
                $saleStmt = $pdo->prepare("SELECT id, invoice_no, customer_id, sold_by_user_id, payment_method, total_amount, paid_amount, due_amount, tender_amount, change_amount, discount FROM sales WHERE id=? LIMIT 1 FOR UPDATE");
                $saleStmt->execute([$saleId]);
            } else {
                // Non-admin can refund only invoices created by them.
                $saleStmt = $pdo->prepare("SELECT id, invoice_no, customer_id, sold_by_user_id, payment_method, total_amount, paid_amount, due_amount, tender_amount, change_amount, discount FROM sales WHERE id=? AND sold_by_user_id=? LIMIT 1 FOR UPDATE");
                $saleStmt->execute([$saleId, $currentUserId]);
            }
            $saleRow = $saleStmt->fetch();
            if(!$saleRow){
                throw new Exception('Sale record not found or access denied for refund.');
            }

            $itemStmt = $pdo->prepare("SELECT si.id, si.product_id, si.batch_id, si.quantity, si.sell_price, si.total, COALESCE(p.name, 'Unknown Product') AS product_name FROM sale_items si LEFT JOIN products p ON p.id=si.product_id WHERE si.sale_id=? ORDER BY si.id ASC FOR UPDATE");
            $itemStmt->execute([$saleId]);
            $saleItems = $itemStmt->fetchAll();
            if(empty($saleItems)){
                throw new Exception('No sale items available to return.');
            }

            $returnQtyMap = $_POST['return_qty'] ?? [];
            $totalReturnedQty = 0.0;
            $returnNotes = [];
            $actorId = (int)($_SESSION['uid'] ?? 0);
            $actorName = trim((string)($_SESSION['username'] ?? 'User'));
            if($actorId <= 0) $actorId = 0;
            if($actorName === '') $actorName = 'User';

            foreach($saleItems as $it){
                $itemId = (int)$it['id'];
                $currentQty = (float)$it['quantity'];
                $retQty = (float)($returnQtyMap[$itemId] ?? 0);
                if($retQty < 0) $retQty = 0;
                if($retQty > $currentQty){
                    throw new Exception('Return quantity cannot exceed sold quantity for one or more items.');
                }
                if($retQty <= 0) continue;

                $newQty = $currentQty - $retQty;
                $unitPrice = (float)$it['sell_price'];
                $newLineTotal = $newQty * $unitPrice;

                // Return stock back to the same sold batch.
                $batchId = (int)$it['batch_id'];
                if($batchId > 0){
                    $updBatch = $pdo->prepare("UPDATE batches SET quantity = quantity + ? WHERE id=?");
                    $updBatch->execute([$retQty, $batchId]);
                }

                if($newQty <= 0){
                    $delItem = $pdo->prepare("DELETE FROM sale_items WHERE id=? LIMIT 1");
                    $delItem->execute([$itemId]);
                } else {
                    $updItem = $pdo->prepare("UPDATE sale_items SET quantity=?, total=? WHERE id=?");
                    $updItem->execute([$newQty, $newLineTotal, $itemId]);
                }

                $retLogStmt = $pdo->prepare("INSERT INTO sale_return_logs(sale_id, sale_item_id, product_id, product_name, returned_qty, returned_by_user_id, returned_by_username, remarks, created_at) VALUES(?,?,?,?,?,?,?,?,?)");
                $retLogStmt->execute([
                    $saleId,
                    $itemId,
                    (int)$it['product_id'],
                    (string)$it['product_name'],
                    $retQty,
                    $actorId,
                    $actorName,
                    $returnRemarks,
                    date('Y-m-d H:i:s'),
                ]);

                $returnNotes[] = [
                    'product' => (string)$it['product_name'],
                    'qty' => $retQty,
                    'remarks' => $returnRemarks,
                ];

                $totalReturnedQty += $retQty;
            }

            if($totalReturnedQty <= 0){
                throw new Exception('Please enter at least one return quantity greater than zero.');
            }

            $newTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM sale_items WHERE sale_id=?");
            $newTotalStmt->execute([$saleId]);
            $newTotal = (float)$newTotalStmt->fetchColumn();

            $oldTotal = (float)$saleRow['total_amount'];
            $oldDue = (float)$saleRow['due_amount'];
            $oldPaid = (float)$saleRow['paid_amount'];
            $oldDiscount = (float)($saleRow['discount'] ?? 0);
            $method = strtolower((string)($saleRow['payment_method'] ?? 'cash'));

            $newPaid = min($oldPaid, $newTotal);
            $newDue = max($newTotal - $newPaid, 0.0);

            $oldTender = (float)($saleRow['tender_amount'] ?? 0);
            $newTender = $method === 'cash' ? $oldTender : 0.0;
            $newChange = $method === 'cash' ? max($oldTender - $newTotal, 0.0) : 0.0;

            $newStatus = 'partial_return';

            $updSale = $pdo->prepare("UPDATE sales SET total_amount=?, paid_amount=?, due_amount=?, tender_amount=?, change_amount=?, discount=?, status=? WHERE id=? LIMIT 1");
            $updSale->execute([$newTotal, $newPaid, $newDue, $newTender, $newChange, $oldDiscount, $newStatus, $saleId]);

            // Keep customer due ledger in sync with new due value after return.
            $customerId = (int)($saleRow['customer_id'] ?? 0);
            if($customerId > 0){
                $deltaDue = $newDue - $oldDue;
                if(abs($deltaDue) > 0.00001){
                    $updCustomerDue = $pdo->prepare("UPDATE customers SET current_due = GREATEST(current_due + ?, 0) WHERE id=? LIMIT 1");
                    $updCustomerDue->execute([$deltaDue, $customerId]);
                }
            }

            $refundAmount = max($oldTotal - $newTotal, 0.0);
            audit_log_action(
                'sale',
                'return_sale',
                'Processed partial return and regenerated invoice totals.',
                [
                    'sale_id' => $saleId,
                    'invoice_no' => (string)$saleRow['invoice_no'],
                    'returned_qty' => $totalReturnedQty,
                    'old_total' => $oldTotal,
                    'new_total' => $newTotal,
                    'refund_amount' => $refundAmount,
                    'old_due' => $oldDue,
                    'new_due' => $newDue,
                    'lines' => $returnNotes,
                ],
                'sale',
                $saleId
            );

            $pdo->commit();
            flash_msg('Sales return processed. Invoice updated successfully.');
            redirect_with_fallback(get_base_url() . '/invoice_print.php?' . http_build_query([
                'invoice_id' => $saleId,
                'auto_print' => 1,
                'return' => get_base_url() . '/dashboard.php?module=sales_record',
            ]));
        } catch(Throwable $e){
            if($pdo->inTransaction()){
                $pdo->rollBack();
            }
            flash_msg($e->getMessage(), 'error');
            redirect_with_fallback('?module=sales_record&view_sale=' . $saleId);
        }
    }
}

$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name ASC")->fetchAll();
$salesUsers = [];
if($isAdmin){
    $salesUsers = $pdo->query("SELECT DISTINCT u.id, COALESCE(NULLIF(u.full_name,''), u.username) AS display_name
                               FROM users u
                               JOIN sales s ON s.sold_by_user_id=u.id
                               ORDER BY display_name ASC")->fetchAll();
}

$where = ["1=1"];
$params = [];

if($customerId > 0){
    $where[] = "s.customer_id = ?";
    $params[] = $customerId;
}

if($isAdmin){
    if($filterUserId > 0){
        $where[] = "s.sold_by_user_id = ?";
        $params[] = $filterUserId;
    }
} else {
    $where[] = "s.sold_by_user_id = ?";
    $params[] = $currentUserId;
}

if($customerSearch !== ''){
    $where[] = "(COALESCE(c.name, s.customer_name, '') LIKE ? OR COALESCE(c.phone, s.customer_phone, '') LIKE ? OR s.invoice_no LIKE ?)";
    $like = '%' . $customerSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if($fromDate !== ''){
    $where[] = "s.sale_date_bs >= ?";
    $params[] = $fromDate;
}

if($toDate !== ''){
    $where[] = "s.sale_date_bs <= ?";
    $params[] = $toDate;
}

$sql = "SELECT
            s.id,
            s.invoice_no,
            s.sale_date_bs,
            s.created_at,
            s.payment_method,
            s.status,
            s.total_amount,
            s.paid_amount,
            s.due_amount,
            s.discount,
            COALESCE(NULLIF(s.sold_by_username,''), 'Unknown') AS sold_by_username,
            s.customer_name AS sale_customer_name,
            s.customer_phone AS sale_customer_phone,
            COALESCE(c.name, s.customer_name) AS customer_name,
            COALESCE(c.phone, s.customer_phone) AS customer_phone
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.sale_date_bs DESC, s.id DESC
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summarySql = "SELECT
                COALESCE(SUM(s.total_amount),0) AS total_sales,
                COALESCE(SUM(s.paid_amount),0) AS total_paid,
                COALESCE(SUM(s.due_amount),0) AS total_due,
                COUNT(*) AS sale_count
            FROM sales s
            LEFT JOIN customers c ON c.id = s.customer_id
            WHERE " . implode(' AND ', $where);
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summaryRow = $summaryStmt->fetch() ?: ['total_sales' => 0, 'total_paid' => 0, 'total_due' => 0, 'sale_count' => 0];
$summaryTotalSales = (float)($summaryRow['total_sales'] ?? 0);
$summaryTotalPaid = (float)($summaryRow['total_paid'] ?? 0);
$summaryTotalDue = (float)($summaryRow['total_due'] ?? 0);
$summarySaleCount = (int)($summaryRow['sale_count'] ?? 0);

$rowsMeta = paginate_array($rows, 'sales_page', 10);
$rows = $rowsMeta['rows'];

$summaryTitle = $isAdmin
    ? ($filterUserId > 0 ? 'Selected User Total Bill Amount' : 'All Users Total Bill Amount')
    : 'Your Total Bill Amount';

$summarySubTitle = $isAdmin
    ? ($filterUserId > 0 ? 'Total bill amount for the selected sales user' : 'Total bill amount for all sales users')
    : 'Total bill amount you need to hand over to account section';

$queryState = ['module' => 'sales_record'];
if($customerId > 0) $queryState['customer_id'] = $customerId;
if($customerSearch !== '') $queryState['customer_search'] = $customerSearch;
if($fromDate !== '') $queryState['from'] = $fromDate;
if($toDate !== '') $queryState['to'] = $toDate;
if($isAdmin && $filterUserId > 0) $queryState['user_id'] = $filterUserId;

$viewSale = null;
$viewItems = [];
$viewReturnLogs = [];
$canRefundSale = false;
if($viewSaleId > 0){
    if($isAdmin){
        $stmt = $pdo->prepare("SELECT s.*, s.customer_name AS sale_customer_name, s.customer_phone AS sale_customer_phone, COALESCE(c.name, s.customer_name) AS live_customer_name, COALESCE(c.phone, s.customer_phone) AS live_customer_phone FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?");
        $stmt->execute([$viewSaleId]);
    } else {
        $stmt = $pdo->prepare("SELECT s.*, s.customer_name AS sale_customer_name, s.customer_phone AS sale_customer_phone, COALESCE(c.name, s.customer_name) AS live_customer_name, COALESCE(c.phone, s.customer_phone) AS live_customer_phone FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=? AND s.sold_by_user_id=?");
        $stmt->execute([$viewSaleId, $currentUserId]);
    }
    $viewSale = $stmt->fetch();

    if($viewSale){
        $canRefundSale = $isAdmin || ((int)($viewSale['sold_by_user_id'] ?? 0) === $currentUserId);

        $stmt = $pdo->prepare("SELECT si.id, si.product_id, si.batch_id, si.quantity, si.sell_price, si.total, p.name AS product_name, b.batch_no, b.expiry_date FROM sale_items si LEFT JOIN products p ON p.id=si.product_id LEFT JOIN batches b ON b.id=si.batch_id WHERE si.sale_id=? ORDER BY si.id ASC");
        $stmt->execute([$viewSaleId]);
        $viewItems = $stmt->fetchAll();
        $viewHasItems = !empty($viewItems);

        $retStmt = $pdo->prepare("SELECT product_name, returned_qty, returned_by_username, remarks, created_at FROM sale_return_logs WHERE sale_id=? ORDER BY id DESC");
        $retStmt->execute([$viewSaleId]);
        $viewReturnLogs = $retStmt->fetchAll();
    }
}

$viewProductCounts = [];
foreach($viewItems as $it){
    $pid = (int)($it['product_id'] ?? 0);
    if($pid <= 0) continue;
    $viewProductCounts[$pid] = (int)($viewProductCounts[$pid] ?? 0) + 1;
}
$hasBatchSplit = false;
foreach($viewProductCounts as $cnt){
    if($cnt > 1){
        $hasBatchSplit = true;
        break;
    }
}

?>

<div class="space-y-6">
    <section class="bg-white p-5 rounded-2xl shadow border border-slate-100">
        <form method="GET" class="flex flex-col gap-3 xl:flex-row xl:flex-nowrap xl:items-end xl:gap-3">
            <input type="hidden" name="module" value="sales_record">

            <div class="w-full xl:w-[31%] xl:shrink-0">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Search Customer / Invoice</label>
                <input type="text" name="customer_search" value="<?= e($customerSearch) ?>" placeholder="Customer name, phone, or invoice" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent transition">
            </div>

            <div class="w-full xl:w-[13.25%] xl:shrink-0">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Customer</label>
                <select name="customer_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent transition">
                    <option value="0">All Customers</option>
                    <?php foreach($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e((string)$c['name']) ?> (<?= e((string)$c['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-full xl:w-[13.25%] xl:shrink-0">
                <label class="block text-xs font-semibold text-slate-700 mb-1">From</label>
                <input type="date" id="sales_from_ad" name="from" value="<?= e($fromDate) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent transition" autocomplete="off">
            </div>

            <div class="w-full xl:w-[13.25%] xl:shrink-0">
                <label class="block text-xs font-semibold text-slate-700 mb-1">To</label>
                <input type="date" id="sales_to_ad" name="to" value="<?= e($toDate) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent transition" autocomplete="off">
            </div>

            <?php if($isAdmin): ?>
            <div class="w-full xl:w-[13.25%] xl:shrink-0">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Sales User</label>
                <select name="user_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent transition">
                    <option value="0">All Users</option>
                    <?php foreach($salesUsers as $su): ?>
                        <option value="<?= (int)$su['id'] ?>" <?= $filterUserId === (int)$su['id'] ? 'selected' : '' ?>><?= e((string)$su['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="flex gap-2 xl:shrink-0 xl:ml-auto">
                <button class="bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap">Filter</button>
                <a href="?module=sales_record" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap">Reset</a>
            </div>
        </form>
    </section>

    <div class="grid md:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500"><?= e($summaryTitle) ?></div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= npr($summaryTotalSales) ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= e($summarySubTitle) ?></div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Sale Count</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= (int)$summarySaleCount ?></div>
            <div class="text-xs text-slate-500 mt-1">Filtered records</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Paid Amount</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= npr($summaryTotalPaid) ?></div>
            <div class="text-xs text-slate-500 mt-1">Amount received</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
            <div class="text-sm text-slate-500">Due Amount</div>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= npr($summaryTotalDue) ?></div>
            <div class="text-xs text-slate-500 mt-1">Outstanding balance</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="font-semibold text-slate-800">Sales Record</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Date &amp; Time</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Invoice</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Customer</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Payment Mode</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Total</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Discount Amt</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Paid</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-700">Due</th>
                        <?php if($isAdmin): ?><th class="px-4 py-3 text-left font-semibold text-slate-700">User</th><?php endif; ?>
                        <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                        <th class="px-4 py-3 text-center font-semibold text-slate-700">View</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($rows)): ?>
                    <tr><td colspan="<?= $isAdmin ? 12 : 11 ?>" class="px-6 py-8 text-center text-slate-500">No sales records found.</td></tr>
                <?php else: ?>
                    <?php foreach($rows as $r): ?>
                        <?php
                            $method = strtolower((string)($r['payment_method'] ?? 'cash'));
                            if($method === 'online') $method = 'qr';
                            $methodLabel = $method === 'credit' ? 'Credit' : ($method === 'qr' ? 'QR' : 'Cash');
                            $status = strtolower((string)($r['status'] ?? 'completed'));
                            $statusLabel = ucwords(str_replace('_', ' ', $status));
                            $saleDateTimeDisplay = (string)($r['sale_date_bs'] ?? '-');
                            if(!empty($r['created_at'])){
                                $saleTs = strtotime((string)$r['created_at']);
                                if($saleTs !== false){
                                    $saleDateTimeDisplay = date('Y-m-d h:i A', $saleTs);
                                }
                            }
                        ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50 transition align-top">
                            <td class="px-4 py-3.5 text-slate-700 whitespace-nowrap"><?= e($saleDateTimeDisplay) ?></td>
                            <td class="px-4 py-3.5 font-medium text-slate-900"><?= e((string)$r['invoice_no']) ?></td>
                            <td class="px-4 py-3.5 text-slate-700">
                                <div><?= e((string)($r['customer_name'] ?? 'Walk-In Customer')) ?></div>
                                <div class="text-xs text-slate-500"><?= e((string)($r['customer_phone'] ?? '-')) ?></div>
                            </td>
                            <td class="px-4 py-3.5 text-slate-700"><?= e($methodLabel) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= npr((float)$r['total_amount'] + (float)($r['discount'] ?? 0)) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= npr((float)($r['discount'] ?? 0)) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= npr((float)$r['paid_amount']) ?></td>
                            <td class="px-4 py-3.5 text-right text-slate-900"><?= npr((float)$r['due_amount']) ?></td>
                            <?php if($isAdmin): ?><td class="px-4 py-3.5 text-slate-700"><?= e((string)($r['sold_by_username'] ?? 'Unknown')) ?></td><?php endif; ?>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs <?= $status === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($status === 'credit' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') ?>"><?= e($statusLabel) ?></span>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                <a href="?<?= e(http_build_query($queryState + ['view_sale' => (int)$r['id']])) ?>" class="inline-flex items-center justify-center w-8 h-8 rounded bg-primary text-white hover:bg-teal-800" title="View Medicines">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($rowsMeta) ?>
    </div>

    <?php if($viewSale): ?>
    <div id="viewSalePanel" class="fixed inset-0 bg-black/50 z-40" style="display:flex; align-items:flex-start; justify-content:center; padding:1rem; padding-top:1.5rem; overflow-y:auto;" onclick="if(event.target.id==='viewSalePanel') window.location='?<?= e(http_build_query($queryState)) ?>'">
        <div class="bg-white rounded-2xl shadow-lg border border-slate-200 overflow-hidden max-w-5xl w-full flex flex-col min-h-0" style="max-height:85vh;">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-white shrink-0">
                <h3 class="text-lg font-semibold text-slate-800">Invoice No : <?= e((string)$viewSale['invoice_no']) ?></h3>
                <div class="flex items-center gap-2">
                    <?php if($viewHasItems): ?>
                    <a href="<?= e(get_base_url() . '/invoice_print.php?' . http_build_query([
                        'invoice_id' => (int)$viewSale['id'],
                        'copy' => 1,
                        'auto_print' => 1,
                        'return' => get_base_url() . '/dashboard.php?' . http_build_query($queryState),
                    ])) ?>" class="bg-primary hover:bg-teal-800 text-white px-3 py-1.5 rounded-lg text-sm font-medium">Print Copy</a>
                    <?php endif; ?>
                    <a href="?<?= e(http_build_query($queryState)) ?>" class="text-slate-500 hover:text-slate-700 text-2xl leading-none">x</a>
                </div>
            </div>

            <div class="px-5 py-4 space-y-4 flex-1 min-h-0" style="overflow-y:auto;">
                <?php if(!$viewHasItems): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    This invoice has no remaining medicine items, so printing is disabled.
                </div>
                <?php endif; ?>
                <?php
                    $viewDiscountAmount = (float)($viewSale['discount'] ?? 0);
                    $viewTotalAfterDiscount = (float)($viewSale['total_amount'] ?? 0);
                    $viewGrandTotal = $viewTotalAfterDiscount + $viewDiscountAmount;
                ?>
                <?php $saleViewTs = strtotime((string)($viewSale['created_at'] ?? '')); ?>
                <div class="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm text-slate-700">
                    <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
                        <div class="min-w-0">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Customer</div>
                            <div class="mt-1 truncate font-medium text-slate-800"><?= e((string)($viewSale['live_customer_name'] ?? $viewSale['sale_customer_name'] ?? 'Walk-In Customer')) ?></div>
                        </div>
                        <div class="min-w-0">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Mode</div>
                            <div class="mt-1 font-medium text-slate-800"><?= e(ucfirst((string)$viewSale['payment_method'])) ?></div>
                        </div>
                        <div class="min-w-0 lg:col-span-2">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Date &amp; Time</div>
                            <div class="mt-1 font-medium text-slate-800 whitespace-nowrap"><?= e($saleViewTs ? date('Y-m-d h:i A', $saleViewTs) : (string)($viewSale['sale_date_bs'] ?? '-')) ?></div>
                        </div>
                        <div class="min-w-0">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Total</div>
                            <div class="mt-1 font-medium text-slate-900 whitespace-nowrap"><?= npr($viewGrandTotal) ?></div>
                        </div>
                        <div class="min-w-0">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">Discount / Net Payable</div>
                            <div class="mt-1 font-medium text-slate-900 whitespace-nowrap"><?= npr($viewDiscountAmount) ?> <span class="text-slate-400">/</span> <span class="text-primary"><?= npr($viewTotalAfterDiscount) ?></span></div>
                        </div>
                    </div>
                </div>

                <?php if(!empty($viewReturnLogs)): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3">
                    <div class="text-sm font-medium text-amber-800 mb-1"></div>
                    <div class="space-y-1.5 text-sm text-amber-900">
                        <?php foreach($viewReturnLogs as $rl): ?>
                            <?php $retWhenTs = strtotime((string)($rl['created_at'] ?? '')); ?>
                            <?php $retWhen = $retWhenTs ? date('Y-m-d h:i A', $retWhenTs) : (string)($rl['created_at'] ?? '-'); ?>
                            <div>Returned <?= e((string)($rl['product_name'] ?? 'Product')) ?> - QTY <?= e((string)($rl['returned_qty'] ?? '0')) ?> by <?= e((string)($rl['returned_by_username'] ?? 'Admin')) ?> on <?= e($retWhen) ?><?php if(trim((string)($rl['remarks'] ?? '')) !== ''): ?> (Remarks: <?= e((string)$rl['remarks']) ?>)<?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if($canRefundSale && $viewHasItems): ?>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="sale_return">
                    <input type="hidden" name="sale_id" value="<?= (int)$viewSale['id'] ?>">
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-1">
                        <div class="text-sm text-amber-700">Refund / Return</div>
                        <div class="text-xs text-amber-700">Enter quantity to return, then update invoice. Returned quantity will be added back to stock and customer due will be adjusted automatically.</div>
                    </div>
                <?php endif; ?>
                <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
                    <?php if($hasBatchSplit): ?>
                    <div class="px-4 py-2.5 text-xs text-amber-800 bg-amber-50 border-b border-amber-200">
                        Batch-wise pricing is applied. Same medicine may appear in multiple rows when stock is deducted from different batches.
                    </div>
                    <?php endif; ?>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Medicine</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Batch</th>
                                <th class="px-4 py-2 text-left font-semibold text-slate-700">Expiry</th>
                                <th class="px-4 py-2 text-right font-semibold text-slate-700">Qty</th>
                                <th class="px-4 py-2 text-right font-semibold text-slate-700">Price</th>
                                <th class="px-4 py-2 text-right font-semibold text-slate-700">Total</th>
                                <?php if($canRefundSale && $viewHasItems): ?><th class="px-4 py-2 text-right font-semibold text-slate-700">Return Qty</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(empty($viewItems)): ?>
                            <tr><td colspan="<?= ($canRefundSale && $viewHasItems) ? 7 : 6 ?>" class="px-4 py-6 text-center text-slate-500">No medicine items found.</td></tr>
                        <?php else: ?>
                            <?php foreach($viewItems as $it): ?>
                                <?php $isSplitLine = ((int)($viewProductCounts[(int)($it['product_id'] ?? 0)] ?? 0)) > 1; ?>
                                <tr class="border-b border-slate-100">
                                    <td class="px-4 py-2.5">
                                        <?= e((string)($it['product_name'] ?? 'Unknown')) ?>
                                        <?php if($isSplitLine): ?>
                                            <span class="ml-2 inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px]">Split batch rate</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-2.5"><?= e((string)($it['batch_no'] ?? '-')) ?></td>
                                    <td class="px-4 py-2.5"><?= e((string)($it['expiry_date'] ?? '-')) ?></td>
                                    <td class="px-4 py-2.5 text-right"><?= e((string)$it['quantity']) ?></td>
                                    <td class="px-4 py-2.5 text-right"><?= npr((float)$it['sell_price']) ?></td>
                                    <td class="px-4 py-2.5 text-right"><?= npr((float)$it['total']) ?></td>
                                    <?php if($canRefundSale && $viewHasItems): ?>
                                    <td class="px-4 py-2.5 text-right"><input type="number" class="w-24 px-2 py-1 border border-slate-300 rounded text-sm text-right return-qty-input" min="0" step="0.01" max="<?= e((string)$it['quantity']) ?>" name="return_qty[<?= (int)$it['id'] ?>]" value="0"></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if($canRefundSale && $viewHasItems): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Remarks <span class="text-red-500">*</span></label>
                        <textarea name="return_remarks" required rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" placeholder="Enter return reason"></textarea>
                    </div>
                    <div>
                        <button type="submit" class="bg-primary hover:bg-teal-800 text-white px-3 py-1.5 rounded-lg text-sm font-medium">Update Invoice</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
</script>
