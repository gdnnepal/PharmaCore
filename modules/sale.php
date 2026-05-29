<?php
require_once __DIR__ . '/../config.php';

$canGiveCredit = is_admin_user() || has_permission('sale.credit');
$isAdminSale = is_admin_user();
$saleUserId = (int)($_SESSION['uid'] ?? 0);
$saleBranchId = (int)($_SESSION['branch_id'] ?? 0);
$saleBranchName = 'No Branch';
$appCurrencySymbol = get_app_currency_symbol();
if($saleUserId > 0){
    $stmt = $pdo->prepare("SELECT u.branch_id, COALESCE(NULLIF(b.name,''), 'No Branch') AS branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.id=? LIMIT 1");
    $stmt->execute([$saleUserId]);
    $branchMeta = $stmt->fetch();
    if($branchMeta){
        // Always refresh branch from DB so POS reflects branch changes made by admin.
        $saleBranchId = (int)($branchMeta['branch_id'] ?? 0);
        $saleBranchName = (string)($branchMeta['branch_name'] ?? 'No Branch');
        $_SESSION['branch_id'] = $saleBranchId;
    }
}

function get_invoice_prefix(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='invoice_prefix' LIMIT 1");
    $stmt->execute();
    $prefix = strtoupper(trim((string)($stmt->fetchColumn() ?: '')));
    $prefix = preg_replace('/[^A-Z0-9_-]/', '', $prefix);
    return substr($prefix, 0, 20);
}

function generate_ad_invoice_no(PDO $pdo, string $saleDateAd, string $invoicePrefix): string {
    $adDate = preg_replace('/[^0-9]/', '', $saleDateAd);
    if(strlen($adDate) !== 8){
        throw new Exception('Invalid AD sale date for invoice generation.');
    }

    $invoicePrefix = preg_replace('/[^A-Z0-9_-]/', '', strtoupper(trim($invoicePrefix)));

    $parts = [];
    if($invoicePrefix !== '') $parts[] = $invoicePrefix;
    $parts[] = $adDate;

    $base = implode('-', $parts);

    $stmt = $pdo->prepare("SELECT invoice_no FROM sales WHERE invoice_no LIKE ? ORDER BY invoice_no DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$base . '-%']);
    $lastInvoice = (string)($stmt->fetchColumn() ?: '');

    $seq = 1;
    if($lastInvoice !== '' && preg_match('/(\d{5})$/', $lastInvoice, $m)){
        $seq = ((int)$m[1]) + 1;
    }
    if($seq > 99999){
        throw new Exception('Invoice sequence overflow for current second. Please retry.');
    }

    return $base . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()){
    try {
        $pdo->beginTransaction();

        if(!$isAdminSale && $saleBranchId <= 0){
            throw new Exception('Your user account is not assigned to any branch. Billing is not allowed.');
        }

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $customerId = $customerId > 0 ? $customerId : null;

        $method = strtolower(trim((string)($_POST['method'] ?? 'cash')));
        if($method === 'online') $method = 'qr';
        if(!in_array($method, ['cash', 'qr', 'credit'], true)){
            throw new Exception('Invalid payment method.');
        }
        if($method === 'credit' && !$canGiveCredit){
            throw new Exception('You do not have permission to create credit sales.');
        }

        $rawItems = $_POST['items'] ?? [];
        $items = [];
        foreach($rawItems as $row){
            $pid = (int)($row['pid'] ?? 0);
            $bid = (int)($row['bid'] ?? 0);
            $qty = (float)($row['qty'] ?? 0);
            $prc = (float)($row['prc'] ?? 0);
            $disc = (float)($row['disc'] ?? 0);
            $discType = strtolower(trim((string)($row['disc_type'] ?? 'rs')));
            if($pid > 0 && $qty > 0 && $prc >= 0){
                if($disc < 0) $disc = 0;
                if(!in_array($discType, ['rs', 'percent'], true)) $discType = 'rs';
                $items[] = ['pid' => $pid, 'bid' => $bid, 'qty' => $qty, 'prc' => $prc, 'disc' => $disc, 'disc_type' => $discType];
            }
        }

        if(empty($items)) throw new Exception('Cart is empty. Add at least one item.');

        $lineInserts = [];
        $subTotal = 0.0;
        $itemDiscountTotal = 0.0;

        foreach($items as $it){
            $pid = (int)$it['pid'];
            $bid = (int)($it['bid'] ?? 0);
            $qty = (float)$it['qty'];
            $lineDiscount = (float)$it['disc'];
            $lineDiscountType = (string)($it['disc_type'] ?? 'rs');
            $remaining = $qty;

            if($bid > 0){
                if($isAdminSale){
                    $batchStmt = $pdo->prepare("SELECT id, product_id, quantity, sell_price FROM batches WHERE id=? AND quantity>0 AND expiry_date>=CURDATE() FOR UPDATE");
                    $batchStmt->execute([$bid]);
                } else {
                    $batchStmt = $pdo->prepare("SELECT id, product_id, quantity, sell_price FROM batches WHERE id=? AND branch_id=? AND quantity>0 AND expiry_date>=CURDATE() FOR UPDATE");
                    $batchStmt->execute([$bid, $saleBranchId]);
                }
                $selectedBatch = $batchStmt->fetch();
                if(!$selectedBatch){
                    throw new Exception('Selected batch is not available for sale.');
                }
                if((int)$selectedBatch['product_id'] !== $pid){
                    throw new Exception('Selected batch does not match the chosen medicine.');
                }

                $available = (float)$selectedBatch['quantity'];
                if($available < $qty){
                    throw new Exception('Selected batch has insufficient quantity.');
                }

                $batchPrice = (float)$selectedBatch['sell_price'];
                $gross = $qty * $batchPrice;
                $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")->execute([$qty, (int)$selectedBatch['id']]);

                $allocations = [[
                    'product_id' => $pid,
                    'batch_id' => (int)$selectedBatch['id'],
                    'quantity' => $qty,
                    'batch_price' => $batchPrice,
                    'gross' => $gross,
                ]];
                $lineGross = $gross;
                $remaining = 0;
            } else {
                if($isAdminSale){
                    $batchStmt = $pdo->prepare("SELECT id, quantity, sell_price FROM batches WHERE product_id=? AND quantity>0 AND expiry_date>=CURDATE() ORDER BY expiry_date ASC, id ASC FOR UPDATE");
                    $batchStmt->execute([$pid]);
                } else {
                    $batchStmt = $pdo->prepare("SELECT id, quantity, sell_price FROM batches WHERE product_id=? AND branch_id=? AND quantity>0 AND expiry_date>=CURDATE() ORDER BY expiry_date ASC, id ASC FOR UPDATE");
                    $batchStmt->execute([$pid, $saleBranchId]);
                }

                $allocations = [];
                $lineGross = 0.0;

                while($remaining > 0 && ($batch = $batchStmt->fetch())){
                    $available = (float)$batch['quantity'];
                    if($available <= 0) continue;

                    $take = min($remaining, $available);
                    $batchPrice = (float)$batch['sell_price'];
                    $gross = $take * $batchPrice;

                    $pdo->prepare("UPDATE batches SET quantity = quantity - ? WHERE id=?")->execute([$take, (int)$batch['id']]);

                    $allocations[] = [
                        'product_id' => $pid,
                        'batch_id' => (int)$batch['id'],
                        'quantity' => $take,
                        'batch_price' => $batchPrice,
                        'gross' => $gross,
                    ];

                    $lineGross += $gross;

                    $remaining -= $take;
                }
            }

            if($remaining > 0){
                throw new Exception('Insufficient stock for one or more products.');
            }

            if($lineDiscountType === 'percent'){
                $lineDiscount = $lineGross * ($lineDiscount / 100);
            }
            if($lineDiscount > $lineGross) $lineDiscount = $lineGross;
            if($lineDiscount < 0) $lineDiscount = 0;

            $allocatedDiscount = 0.0;
            $lastIdx = count($allocations) - 1;
            foreach($allocations as $idx => $al){
                $gross = (float)$al['gross'];
                if($lastIdx === $idx){
                    $batchDiscount = $lineDiscount - $allocatedDiscount;
                } else {
                    $batchDiscount = $lineGross > 0 ? ($lineDiscount * ($gross / $lineGross)) : 0.0;
                    $allocatedDiscount += $batchDiscount;
                }

                if($batchDiscount > $gross) $batchDiscount = $gross;
                if($batchDiscount < 0) $batchDiscount = 0;

                $net = $gross - $batchDiscount;
                $effectiveUnitPrice = $al['quantity'] > 0 ? ($net / $al['quantity']) : (float)$al['batch_price'];

                $lineInserts[] = [
                    'product_id' => (int)$al['product_id'],
                    'batch_id' => (int)$al['batch_id'],
                    'quantity' => (float)$al['quantity'],
                    'sell_price' => $effectiveUnitPrice,
                    'total' => $net,
                ];
            }

            $lineNet = $lineGross - $lineDiscount;

            $subTotal += $lineNet;
            $itemDiscountTotal += $lineDiscount;
        }

        $invoiceDiscount = (float)($_POST['invoice_discount'] ?? 0);
        $invoiceDiscountType = strtolower(trim((string)($_POST['invoice_discount_type'] ?? 'rs')));
        if(!in_array($invoiceDiscountType, ['rs', 'percent'], true)) $invoiceDiscountType = 'rs';
        if($invoiceDiscount < 0) $invoiceDiscount = 0;
        if($invoiceDiscountType === 'percent'){
            $invoiceDiscount = $subTotal * ($invoiceDiscount / 100);
        }
        if($invoiceDiscount > $subTotal) $invoiceDiscount = $subTotal;

        $totalDiscount = $itemDiscountTotal + $invoiceDiscount;
        $total = $subTotal - $invoiceDiscount;

        if($method === 'credit' && !$customerId){
            throw new Exception('Credit sale requires selecting a customer.');
        }

        $customerName = null;
        $customerPhone = null;
        if($customerId){
            $stmt = $pdo->prepare("SELECT name, phone FROM customers WHERE id=?");
            $stmt->execute([$customerId]);
            $customerRow = $stmt->fetch();
            if($customerRow){
                $customerName = (string)($customerRow['name'] ?? '');
                $customerPhone = (string)($customerRow['phone'] ?? '');
            }
        }

        $paidInput = (float)($_POST['paid'] ?? 0);
        $tenderInput = (float)($_POST['tender'] ?? 0);
        if($paidInput < 0) $paidInput = 0;
        if($tenderInput < 0) $tenderInput = 0;

        if($method === 'cash' && $total > 0 && $tenderInput <= 0){
            throw new Exception('Tender amount is required for cash payment.');
        }

        $tenderAmount = 0.0;
        $changeAmount = 0.0;

        if($method === 'credit'){
            $paidAmount = 0.0;
            $dueAmount = $total;
            $status = 'credit';
            $tenderAmount = 0.0;
            $changeAmount = 0.0;
        } else if($method === 'cash' && $tenderInput > 0){
            $tenderAmount = $tenderInput;
            $paidAmount = min($tenderAmount, $total);
            $dueAmount = max($total - $paidAmount, 0);
            $changeAmount = max($tenderAmount - $total, 0);
            $status = $dueAmount > 0 ? 'partial' : 'completed';
        } else {
            $paidAmount = min($paidInput, $total);
            $dueAmount = max($total - $paidAmount, 0);
            $status = $dueAmount > 0 ? 'partial' : 'completed';
            $tenderAmount = $method === 'cash' ? $paidAmount : 0.0;
            $changeAmount = 0.0;
        }

        if($dueAmount > 0 && !$canGiveCredit){
            throw new Exception('You do not have permission to keep due/credit in sales.');
        }

        if($dueAmount > 0 && !$customerId){
            throw new Exception('Walk-In sales cannot keep due. Select a customer for due/credit sales.');
        }

        // Enforce customer's credit limit: lock customer row and ensure new due won't exceed limit
        if($customerId && $dueAmount > 0){
            $limitStmt = $pdo->prepare("SELECT credit_limit, current_due FROM customers WHERE id=? FOR UPDATE");
            $limitStmt->execute([$customerId]);
            $limitRow = $limitStmt->fetch();
            if(!$limitRow){
                throw new Exception('Selected customer not found.');
            }
            $creditLimit = (float)($limitRow['credit_limit'] ?? 0.0);
            $currentDue = (float)($limitRow['current_due'] ?? 0.0);
            if($creditLimit > 0){
                $projectedDue = $currentDue + $dueAmount;
                if($projectedDue > $creditLimit){
                    throw new Exception('Customer credit limit exceeded. Transaction denied.');
                }
            }
        }

        // M-16: Field is named 'sale_date_bs' for backward compatibility but stores AD (Gregorian) date.
        // The column name in the DB is also 'sale_date_bs' — renaming would break existing data.
        $saleDateAd = trim((string)($_POST['sale_date_bs'] ?? ''));
        if(
            !preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $saleDateAd, $m)
            || (int)$m[1] < 1970
            || (int)$m[1] > 2100
        ){
            throw new Exception('Sale date (AD) is required.');
        }
        $invoicePrefix = get_invoice_prefix($pdo);
        $invoiceNo = generate_ad_invoice_no($pdo, $saleDateAd, $invoicePrefix);

        $soldByUserId = (int)($_SESSION['uid'] ?? 0);
        $soldByUsername = trim((string)($_SESSION['username'] ?? ''));
        if($soldByUserId <= 0){
            throw new Exception('Unable to identify logged-in user for this sale. Please login again.');
        }
        if($soldByUsername === ''){
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
            $stmt->execute([$soldByUserId]);
            $soldByUsername = trim((string)($stmt->fetchColumn() ?: ''));
        }

        // Use PHP-generated timestamp (respects selected timezone) instead of MySQL CURRENT_TIMESTAMP
        $currentTimestamp = date('Y-m-d H:i:s');

        $pdo->prepare("INSERT INTO sales(invoice_no,customer_id,customer_name,customer_phone,total_amount,paid_amount,due_amount,tender_amount,change_amount,discount,status,payment_method,sale_date_bs,sold_by_user_id,sold_by_username,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$invoiceNo, $customerId, $customerName, $customerPhone, $total, $paidAmount, $dueAmount, $tenderAmount, $changeAmount, $totalDiscount, $status, $method, $saleDateAd !== '' ? $saleDateAd : null, $soldByUserId, $soldByUsername !== '' ? $soldByUsername : null, $currentTimestamp]);


        $saleId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("INSERT INTO sale_items(sale_id,product_id,batch_id,quantity,sell_price,total) VALUES(?,?,?,?,?,?)");
        foreach($lineInserts as $ln){
            $itemStmt->execute([
                $saleId,
                (int)$ln['product_id'],
                (int)$ln['batch_id'],
                (float)$ln['quantity'],
                (float)$ln['sell_price'],
                (float)$ln['total'],
            ]);
        }

        if($customerId && $dueAmount > 0){
            $pdo->prepare("UPDATE customers SET current_due = current_due + ? WHERE id=?")->execute([$dueAmount, $customerId]);
        }

        audit_log_action(
            'sale',
            'create_sale',
            'Completed billing transaction.',
            [
                'sale_id' => $saleId,
                'invoice_no' => $invoiceNo,
                'customer_id' => $customerId,
                'total_amount' => $total,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'discount_amount' => $totalDiscount,
                'payment_method' => $method,
                'tender_amount' => $tenderAmount,
                'change_amount' => $changeAmount,
                'status' => $status,
                'item_count' => count($lineInserts),
                'sold_by_user_id' => $soldByUserId,
                'sale_date_bs' => $saleDateAd,
            ],
            'sale',
            $saleId
        );

        $pdo->commit();
        $_SESSION['invoice_auto_print_id'] = $saleId;
        $_SESSION['invoice_auto_print_expires'] = time() + 30; // L-5: 30-second TTL
        flash_msg('Sale completed. Invoice ' . $invoiceNo . ' generated.');
        redirect_with_fallback(get_base_url() . '/invoice_print.php?' . http_build_query([
            'invoice_id' => $saleId,
            'auto_print' => 1,
            'return' => get_base_url() . '/dashboard.php?module=sale',
        ]));
    } catch(Exception $e){
        $pdo->rollBack();
        flash_msg($e->getMessage(), 'error');
    }
}

if($isAdminSale){
    $products = $pdo->query("SELECT p.id, p.name, COALESCE((SELECT SUM(b1.quantity) FROM batches b1 WHERE b1.product_id=p.id AND b1.expiry_date>=CURDATE()),0) AS stock, COALESCE((SELECT b2.sell_price FROM batches b2 WHERE b2.product_id=p.id AND b2.quantity>0 AND b2.expiry_date>=CURDATE() ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1),0) AS sell_price FROM products p ORDER BY p.name ASC")->fetchAll();
    $batchPricingRows = $pdo->query("SELECT b.product_id, b.id, b.batch_no, b.quantity, b.sell_price, b.expiry_date, p.name AS product_name FROM batches b INNER JOIN products p ON p.id=b.product_id WHERE b.quantity>0 AND b.expiry_date>=CURDATE() ORDER BY p.name ASC, b.expiry_date ASC, b.id ASC")->fetchAll();
} else {
    $prodStmt = $pdo->prepare("SELECT p.id, p.name, COALESCE((SELECT SUM(b1.quantity) FROM batches b1 WHERE b1.product_id=p.id AND b1.branch_id=? AND b1.expiry_date>=CURDATE()),0) AS stock, COALESCE((SELECT b2.sell_price FROM batches b2 WHERE b2.product_id=p.id AND b2.branch_id=? AND b2.quantity>0 AND b2.expiry_date>=CURDATE() ORDER BY b2.expiry_date ASC, b2.id ASC LIMIT 1),0) AS sell_price FROM products p ORDER BY p.name ASC");
    $prodStmt->execute([$saleBranchId, $saleBranchId]);
    $products = $prodStmt->fetchAll();

    $batchStmt = $pdo->prepare("SELECT b.product_id, b.id, b.batch_no, b.quantity, b.sell_price, b.expiry_date, p.name AS product_name FROM batches b INNER JOIN products p ON p.id=b.product_id WHERE b.branch_id=? AND b.quantity>0 AND b.expiry_date>=CURDATE() ORDER BY p.name ASC, b.expiry_date ASC, b.id ASC");
    $batchStmt->execute([$saleBranchId]);
    $batchPricingRows = $batchStmt->fetchAll();
}
$noBranchStockForUser = !$isAdminSale && empty($batchPricingRows);
$batchPricingMap = [];
foreach($batchPricingRows as $br){
    $pid = (int)($br['product_id'] ?? 0);
    if($pid <= 0) continue;
    if(!isset($batchPricingMap[$pid])) $batchPricingMap[$pid] = [];
    $batchPricingMap[$pid][] = [
        'id' => (int)$br['id'],
        'batch_no' => (string)($br['batch_no'] ?? ''),
        'qty' => (float)$br['quantity'],
        'price' => (float)$br['sell_price'],
        'product_name' => (string)($br['product_name'] ?? ''),
    ];
}
$customers = $pdo->query("SELECT id, name, phone, current_due, credit_limit FROM customers ORDER BY name ASC")->fetchAll();

$f = flash_msg();

$invoiceViewId = (int)($_GET['invoice_id'] ?? 0);
$invoiceCopyMode = ((string)($_GET['copy'] ?? '0') === '1');
$autoPrintInvoice = ((string)($_GET['auto_print'] ?? '0') === '1');
if($invoiceViewId > 0){
    if($isAdminSale){
        $invoiceStmt = $pdo->prepare("SELECT s.* FROM sales s WHERE s.id=? LIMIT 1");
        $invoiceStmt->execute([$invoiceViewId]);
    } else {
        $invoiceStmt = $pdo->prepare("SELECT s.* FROM sales s WHERE s.id=? AND s.sold_by_user_id=? LIMIT 1");
        $invoiceStmt->execute([$invoiceViewId, $saleUserId]);
    }
    $invoiceSale = $invoiceStmt->fetch();

    if(!$invoiceSale){
        flash_msg('Invoice not found or access denied.', 'error');
        redirect_with_fallback('?module=sale');
    }

    $invoiceItemsStmt = $pdo->prepare("SELECT si.*, p.name AS product_name, b.batch_no FROM sale_items si JOIN products p ON p.id=si.product_id LEFT JOIN batches b ON b.id=si.batch_id WHERE si.sale_id=? ORDER BY si.id ASC");
    $invoiceItemsStmt->execute([$invoiceViewId]);
    $invoiceItems = $invoiceItemsStmt->fetchAll();

    $pharmacyStmt = $pdo->query("SELECT * FROM pharmacy_details WHERE id=1 LIMIT 1");
    $pharmacyDetails = $pharmacyStmt ? ($pharmacyStmt->fetch() ?: null) : null;

    $methodLabel = strtoupper((string)($invoiceSale['payment_method'] ?? 'cash'));
    $invoiceTitle = $invoiceCopyMode ? 'Copy of Invoice' : 'Invoice';
    $invoiceHasItems = !empty($invoiceItems);
    ?>
    <style>
    @media print {
        .invoice-no-print { display: none !important; }
        .invoice-wrap { box-shadow: none !important; border: none !important; }
        body { background: #fff !important; }
    }
    </style>
    <div class="space-y-4">
        <div class="invoice-no-print flex items-center justify-between">
            <h3 class="font-semibold text-slate-800"><?= e($invoiceTitle) ?> <?= e((string)$invoiceSale['invoice_no']) ?></h3>
            <div class="flex gap-2">
                <a href="?module=sale" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-4 py-2 rounded-lg text-sm font-medium">Back to POS</a>
                <?php if($invoiceHasItems): ?>
                <a href="<?= h(get_base_url() . '/invoice_print.php?' . http_build_query([
                    'invoice_id' => (int)$invoiceSale['id'],
                    'auto_print' => 1,
                    'return' => get_base_url() . '/dashboard.php?module=sale',
                ] + ($invoiceCopyMode ? ['copy' => 1] : []))) ?>" class="bg-primary hover:bg-teal-800 text-white px-4 py-2 rounded-lg text-sm font-medium">Print Invoice</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="invoice-wrap relative bg-white border border-slate-200 rounded-2xl shadow-sm p-6 max-w-4xl mx-auto">
            <?php if($invoiceCopyMode): ?>
            <div class="absolute inset-x-0 top-0 flex justify-center pointer-events-none">
                <div class="mt-3 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-semibold">COPY</div>
            </div>
            <?php endif; ?>
            <?php if(!$invoiceHasItems): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                This invoice has no remaining medicine items, so printing is disabled.
            </div>
            <?php endif; ?>
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4 mb-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900"><?= e((string)($pharmacyDetails['pharmacy_name'] ?? 'Pharmacy')) ?></h2>
                    <div class="text-sm text-slate-600"><?= e((string)($pharmacyDetails['address'] ?? '')) ?></div>
                    <div class="text-sm text-slate-600">Phone: <?= e((string)($pharmacyDetails['phone_number'] ?? '-')) ?></div>
                    <div class="text-sm text-slate-600">PAN/VAT: <?= e((string)($pharmacyDetails['pan_vat'] ?? '-')) ?></div>
                </div>
                <div class="text-sm text-slate-700 text-right">
                    <div><span class="font-medium">Invoice No:</span> <?= e((string)$invoiceSale['invoice_no']) ?></div>
                    <div><span class="font-medium">Date:</span> <?= e((string)($invoiceSale['sale_date_bs'] ?: date('Y-m-d', strtotime((string)$invoiceSale['created_at'])))) ?></div>
                    <div><span class="font-medium">Time:</span> <?= date('h:i A', strtotime((string)$invoiceSale['created_at'])) ?></div>
                    <div><span class="font-medium">Cashier:</span> <?= e((string)($invoiceSale['sold_by_username'] ?? '-')) ?></div>
                    <div><span class="font-medium">Payment:</span> <?= e($methodLabel) ?></div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-3 text-sm mb-4">
                <div><span class="font-medium text-slate-700">Customer:</span> <?= e((string)($invoiceSale['customer_name'] ?: 'Walk-In Customer')) ?></div>
                <div><span class="font-medium text-slate-700">Phone:</span> <?= e((string)($invoiceSale['customer_phone'] ?: '-')) ?></div>
            </div>

            <div class="overflow-x-auto border border-slate-200 rounded-xl">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-2 text-left">#</th>
                            <th class="px-4 py-2 text-left">Medicine</th>
                            <th class="px-4 py-2 text-left">Batch</th>
                            <th class="px-4 py-2 text-right">Qty</th>
                            <th class="px-4 py-2 text-right">Rate</th>
                            <th class="px-4 py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($invoiceItems as $idx => $it): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-2"><?= $idx + 1 ?></td>
                            <td class="px-4 py-2"><?= e((string)$it['product_name']) ?></td>
                            <td class="px-4 py-2"><?= e((string)($it['batch_no'] ?: '-')) ?></td>
                            <td class="px-4 py-2 text-right"><?= e((string)$it['quantity']) ?></td>
                            <td class="px-4 py-2 text-right"><?= npr((float)$it['sell_price']) ?></td>
                            <td class="px-4 py-2 text-right"><?= npr((float)$it['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 grid md:grid-cols-2 gap-3 text-sm">
                <div></div>
                <div class="space-y-1 border border-slate-200 rounded-xl p-3">
                    <div class="flex justify-between"><span class="text-slate-600">Discount</span><span class="font-medium"><?= npr((float)$invoiceSale['discount']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Tender</span><span class="font-medium"><?= npr((float)($invoiceSale['tender_amount'] ?? 0)) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Change</span><span class="font-medium"><?= npr((float)($invoiceSale['change_amount'] ?? 0)) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Paid</span><span class="font-medium"><?= npr((float)$invoiceSale['paid_amount']) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Due</span><span class="font-medium"><?= npr((float)$invoiceSale['due_amount']) ?></span></div>
                    <div class="flex justify-between text-base font-semibold border-t border-slate-200 pt-2"><span>Total</span><span><?= npr((float)$invoiceSale['total_amount']) ?></span></div>
                </div>
            </div>
        </section>
    </div>
    <?php if($autoPrintInvoice): ?>
    <script>
    window.addEventListener('load', function(){
        var waitingForPrintClose = false;
        var redirected = false;
        function backToSale(){
            if(redirected) return;
            redirected = true;
            window.location.href = '?module=sale';
        }
        function onPrintClosed(){
            if(waitingForPrintClose){
                setTimeout(backToSale, 120);
            }
        }

        window.addEventListener('afterprint', onPrintClosed);
        if(window.matchMedia){
            var mediaQuery = window.matchMedia('print');
            var mediaHandler = function(e){
                if(!e.matches){
                    onPrintClosed();
                }
            };
            if(mediaQuery.addEventListener){
                mediaQuery.addEventListener('change', mediaHandler);
            } else if(mediaQuery.addListener){
                mediaQuery.addListener(mediaHandler);
            }
        }
        window.addEventListener('focus', function(){
            if(waitingForPrintClose){
                setTimeout(onPrintClosed, 250);
            }
        });

        setTimeout(function(){
            waitingForPrintClose = true;
            window.print();
        }, 250);
    });
    </script>
    <?php endif; ?>
    <?php
    return;
}
?>
<div id="toast-container" class="fixed top-4 right-4 z-50"></div>

<div class="space-y-6">
    <?php if($f): ?><div class="p-3 rounded-lg text-sm border <?= $f['type']=='error'?'bg-red-50 text-red-700 border-red-200':'bg-emerald-50 text-emerald-700 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div><?php endif; ?>
    <?php if($noBranchStockForUser): ?><div class="p-3 rounded-lg text-sm border bg-amber-50 text-amber-800 border-amber-200">No sellable stock found in your assigned branch (<?= e($saleBranchName) ?>). Please add stock with a future expiry date in this branch before billing.</div><?php endif; ?>
    <div class="w-full">
        <div class="flex items-center justify-between mb-4 px-1">
            <h3 class="font-semibold text-slate-800">POS Billing</h3>
            <span class="text-xs text-slate-500"></span>
        </div>

        <form method="POST" class="space-y-4" onsubmit="return validateSaleForm();">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" id="sale_date_bs" name="sale_date_bs" value="<?= e(date('Y-m-d')) ?>">

                <div class="grid lg:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.9fr)] gap-6 items-start">
                    <div class="bg-white border border-slate-200 rounded-2xl overflow-visible shadow-sm">
                        <div class="px-5 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-3">
                            <h4 class="font-semibold text-slate-800 min-w-0 truncate">Customer & Products</h4>
                            <div id="customer_credit_pill" class="hidden text-sm shrink-0 whitespace-nowrap ml-auto">
                                <span id="ci_pill_text" class="inline-flex items-center gap-2 rounded-full px-3 py-1 font-semibold shadow-sm text-sm"></span>
                            </div>
                        </div>
                        <div class="p-5 space-y-5">
                            <div class="grid md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Search Customer</label>
                                    <input type="text" id="customer_search" placeholder="Type name or phone" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" oninput="filterCustomers()">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Customer</label>
                                    <select name="customer_id" id="customer_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                                        <option value="">Walk-In Customer</option>
                                        <?php foreach($customers as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>" data-search="<?= e(strtolower((string)$c['name'] . ' ' . (string)$c['phone'])) ?>" data-current-due="<?= e((string)number_format((float)($c['current_due'] ?? 0), 2, '.', '')) ?>" data-credit-limit="<?= e((string)number_format((float)($c['credit_limit'] ?? 0), 2, '.', '')) ?>"><?= e((string)$c['name']) ?> (<?= e((string)$c['phone']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- credit info moved to header pill -->

                            <div class="space-y-3">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="text-sm font-medium text-slate-700">Sale Items</div>
                                </div>
                                <div id="cart" class="space-y-3">
                                    <div class="grid grid-cols-12 gap-2 items-end cart-row">
                                        <div class="col-span-4">
                                            <label class="block text-xs text-slate-500 mb-1">Product</label>
                                            <div class="relative">
                                                <input type="text" name="items[0][product_name]" class="w-full px-2 py-2 border border-slate-300 rounded-lg product-search" placeholder="Type to search..." oninput="showProductSuggestions(this)" onfocus="showProductSuggestions(this)" onkeydown="handleProductSearchKeydown(this, event)" onblur="setTimeout(() => hideProductSuggestions(this), 200)" required autocomplete="off">
                                                <input type="hidden" name="items[0][pid]" class="product-id" value="">
                                                <input type="hidden" name="items[0][bid]" class="batch-id" value="">
                                                <div class="product-suggestions hidden absolute top-full left-0 right-0 mt-1 bg-white border border-slate-300 rounded-lg shadow-lg z-50 max-h-48 overflow-y-auto text-sm"></div>
                                            </div>
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-xs text-slate-500 mb-1">Qty</label>
                                            <input type="number" name="items[0][qty]" min="0.1" step="0.1" value="1" class="w-full px-2 py-2 border border-slate-300 rounded-lg qty" oninput="calc()" required>
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-xs text-slate-500 mb-1">Price</label>
                                            <input type="number" name="items[0][prc]" min="0" step="0.01" class="w-full px-2 py-2 border border-slate-300 rounded-lg prc bg-slate-50" required readonly>
                                        </div>
                                        <div class="col-span-3">
                                            <label class="block text-xs text-slate-500 mb-1">Disc</label>
                                            <div class="flex items-center gap-2 w-full">
                                                <select name="items[0][disc_type]" class="w-16 px-2 py-2 border border-slate-300 rounded-lg disc-type text-sm flex-shrink-0" onchange="calc()">
                                                    <option value="rs"><?= e($appCurrencySymbol) ?></option>
                                                    <option value="percent">%</option>
                                                </select>
                                                <input type="number" name="items[0][disc]" min="0" step="0.01" value="0" class="flex-1 min-w-0 px-2 py-2 border border-slate-300 rounded-lg disc" oninput="calc()">
                                            </div>
                                        </div>
                                        <div class="col-span-1">
                                            <label class="block text-xs mb-1 text-transparent select-none">Action</label>
                                            <div class="flex justify-center">
                                                <button type="button" onclick="removeRow(this)" class="w-8 h-8 rounded-lg bg-primary hover:bg-teal-800 text-white inline-flex items-center justify-center" title="Remove Item">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" onclick="addRow()" class="bg-primary hover:bg-teal-800 text-white px-3 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14M5 12h14"/></svg>
                                    <span>Add Item</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm sticky top-5">
                        <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                            <h4 class="font-semibold text-slate-800">Amount & Payment</h4>
                        </div>
                        <div class="p-5 space-y-4">

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Payment Method <span class="text-red-500">*</span></label>
                                <select name="method" id="payment_method" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition" onchange="handleMethodChange()" required>
                                    <option value="cash">Cash</option>
                                    <option value="qr">QR</option>
                                    <?php if($canGiveCredit): ?>
                                        <option value="credit">Credit</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount</label>
                                <div class="flex items-center gap-2">
                                    <select id="invoice_discount_type" name="invoice_discount_type" class="w-20 px-2 py-2.5 border border-slate-300 rounded-lg" onchange="calc()">
                                        <option value="rs"><?= e($appCurrencySymbol) ?></option>
                                        <option value="percent">%</option>
                                    </select>
                                    <input type="number" id="invoice_discount" name="invoice_discount" step="0.01" min="0" value="0" class="w-full px-3 py-2.5 border border-slate-300 rounded-lg" oninput="calc()">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Total</label>
                                    <input type="number" id="total" name="total" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Discount Amount</label>
                                    <input type="number" id="discount_amount" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Net Total</label>
                                    <input type="number" id="net_total" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Paid</label>
                                    <input type="number" id="paid" name="paid" step="0.01" min="0" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" oninput="markPaidManual();calcDue()" value="0" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Due</label>
                                    <input type="number" id="due_preview" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" readonly>
                                </div>
                                <div id="tender_wrap">
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Tender</label>
                                    <input type="number" id="tender" name="tender" step="0.01" min="0" value="0" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" oninput="calcDue()" required>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Change Return</label>
                                    <input type="number" id="change_preview" step="0.01" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg bg-slate-50" readonly>
                                </div>
                            </div>

                            <div id="batch_pricing_note" class="hidden text-xs rounded-lg border px-3 py-2"></div>

                            <button class="w-full bg-primary hover:bg-teal-800 text-white font-medium px-4 py-3 rounded-lg transition inline-flex items-center justify-center gap-2">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 13l4 4L19 7"/></svg>
                                <span>Complete Sale</span>
                            </button>
                        </div>
                    </div>
                </div>
        </form>
    </div>
</div>

<script>
var canGiveCredit = <?php echo $canGiveCredit ? 'true' : 'false'; ?>;
var allBatchOptions = <?php echo json_encode(array_values(array_reduce($batchPricingRows, function($carry, $br){
    $carry[] = [
        'batch_id' => (int)$br['id'],
        'product_id' => (int)$br['product_id'],
        'product_name' => (string)$br['product_name'],
        'batch_no' => (string)($br['batch_no'] ?? ''),
        'qty' => (float)$br['quantity'],
        'price' => (float)$br['sell_price'],
    ];
    return $carry;
}, [])), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    function formatCurrency(n){
        try{
            return '<?= addslashes(get_app_currency_symbol()) ?>' + parseFloat(n || 0).toFixed(2);
        }catch(e){
            return (n||0).toFixed(2);
        }
    }

    function updateCustomerCreditInfo(){
        var sel = document.getElementById('customer_id');
        var pill = document.getElementById('customer_credit_pill');
        var pillText = document.getElementById('ci_pill_text');
        if(!sel) return;
        var val = sel.value;
        if(!val){
            if(pill) pill.classList.add('hidden');
            if(pillText) pillText.textContent = '';
            return;
        }
        var opt = sel.options[sel.selectedIndex];
        var currentDue = parseFloat(opt.getAttribute('data-current-due') || '0');
        var creditLimit = parseFloat(opt.getAttribute('data-credit-limit') || '0');
        var text = 'Due: ' + formatCurrency(currentDue);
        if(creditLimit > 0){
            var pct = Math.round((currentDue / creditLimit) * 100);
            text += ' • Limit: ' + formatCurrency(creditLimit) + ' (' + pct + '%)';
        } else {
            text += ' • Limit: No limit';
        }
        if(pillText) {
            // choose variant class based on utilization
            var base = 'inline-block rounded-full px-3 py-1 font-semibold shadow-sm text-sm';
            var variant = '';
            var bg = '';
            var fg = '';
            if(creditLimit <= 0){
                bg = '#f1f5f9'; fg = '#0f172a'; // slate-100 / slate-800
            } else if(currentDue > creditLimit){
                bg = '#dc2626'; fg = '#ffffff'; // red-600
            } else {
                var ratio = creditLimit > 0 ? (currentDue / creditLimit) : 0;
                if(ratio >= 0.9){ bg = '#dc2626'; fg = '#ffffff'; }
                else if(ratio >= 0.75){ bg = '#f59e0b'; fg = '#000000'; }
                else { bg = '#0ea5e9'; fg = '#ffffff'; }
            }
            pillText.className = 'inline-flex items-center gap-2 rounded-full px-3 py-1 font-semibold shadow-sm text-sm';
            pillText.style.backgroundColor = bg;
            pillText.style.color = fg;
            // small SVG icons with inline sizes and fill color
            var icon = '';
            var iconFill = fg;
            if(creditLimit <= 0){
                icon = '<svg width="14" height="14" viewBox="0 0 20 20" fill="' + iconFill + '" aria-hidden="true"><path fill-rule="evenodd" d="M18 10c0 4.418-3.582 8-8 8s-8-3.582-8-8 3.582-8 8-8 8 3.582 8 8zM9 7a1 1 0 112 0v4a1 1 0 11-2 0V7zm1 8a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 15z" clip-rule="evenodd"/></svg>';
            } else if(currentDue > creditLimit){
                icon = '<svg width="14" height="14" viewBox="0 0 20 20" fill="' + iconFill + '" aria-hidden="true"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l6.518 11.595A1.75 1.75 0 0116.518 17H3.482a1.75 1.75 0 01-1.742-2.306L8.257 3.1zM11 13a1 1 0 10-2 0 1 1 0 002 0zm-1-8a1 1 0 00-.993.883L8 6v4a1 1 0 001.993.117L10 10V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>';
            } else if((creditLimit > 0) && ((currentDue / creditLimit) >= 0.75)){
                icon = '<svg width="14" height="14" viewBox="0 0 20 20" fill="' + iconFill + '" aria-hidden="true"><path d="M8.257 3.099c.765-1.36 2.72-1.36 3.485 0l6.518 11.595A1.75 1.75 0 0116.518 17H3.482a1.75 1.75 0 01-1.742-2.306L8.257 3.1z"/></svg>';
            } else {
                icon = '<svg width="14" height="14" viewBox="0 0 20 20" fill="' + iconFill + '" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 7a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 14z" clip-rule="evenodd"/></svg>';
            }
            pillText.innerHTML = icon + '<span style="font-size:0.85rem;line-height:1">' + text + '</span>';
        }
        if(pill) pill.classList.remove('hidden');
    }

    document.getElementById('customer_id').addEventListener('change', function(){
        updateCustomerCreditInfo();
    });
    // initialize on load
    updateCustomerCreditInfo();

function addRow(){
    var cart = document.getElementById('cart');
    var row = cart.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(function(inp){
        if(inp.classList.contains('qty')) inp.value = '1';
        else if(inp.classList.contains('disc')) inp.value = '0';
        else if(inp.classList.contains('product-id') || inp.classList.contains('batch-id')) inp.value = '';
        else inp.value = '';
    });
    row.querySelectorAll('select').forEach(function(sel){
        if(sel.classList.contains('disc-type')) sel.value = 'rs';
        else sel.value = '';
    });
    row.querySelector('.product-search').value = '';
    row.querySelector('.product-suggestions').classList.add('hidden');
    cart.appendChild(row);
    normalizeCartFieldNames();
    calc();
}

function removeRow(btn){
    var rows = document.querySelectorAll('.cart-row');
    if(rows.length <= 1){
        return;
    }
    btn.closest('.cart-row').remove();
    normalizeCartFieldNames();
    calc();
}

function normalizeCartFieldNames(){
    document.querySelectorAll('.cart-row').forEach(function(row, index){
        row.querySelector('.product-search').setAttribute('name', 'items[' + index + '][product_name]');
        row.querySelector('.product-id').setAttribute('name', 'items[' + index + '][pid]');
        row.querySelector('.batch-id').setAttribute('name', 'items[' + index + '][bid]');
        row.querySelector('.qty').setAttribute('name', 'items[' + index + '][qty]');
        row.querySelector('.prc').setAttribute('name', 'items[' + index + '][prc]');
        row.querySelector('.disc').setAttribute('name', 'items[' + index + '][disc]');
        row.querySelector('.disc-type').setAttribute('name', 'items[' + index + '][disc_type]');
    });
}

function getBatchOptionById(batchId){
    return allBatchOptions.find(function(b){ return String(b.batch_id) === String(batchId); }) || null;
}

function formatQtyValue(qty){
    var n = parseFloat(qty || '0');
    if(!isFinite(n)) return '0';
    return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.00$/, '');
}

function updateBatchPricingNote(messages, hasInsufficient){
    var note = document.getElementById('batch_pricing_note');
    if(!note) return;

    if(!messages.length){
        note.classList.add('hidden');
        note.textContent = '';
        note.className = 'hidden text-xs rounded-lg border px-3 py-2';
        return;
    }

    note.classList.remove('hidden');
    note.textContent = messages.join(' | ');
    if(hasInsufficient){
        note.className = 'text-xs rounded-lg border px-3 py-2 border-red-200 bg-red-50 text-red-700';
    } else {
        note.className = 'text-xs rounded-lg border px-3 py-2 border-amber-200 bg-amber-50 text-amber-800';
    }
}

function calc(){
    var grossTotal = 0;
    var lineDiscountTotal = 0;
    var pricingNotes = [];
    var hasInsufficient = false;
    var batchRequested = {};
    document.querySelectorAll('.cart-row').forEach(function(row){
        var qty = parseFloat(row.querySelector('.qty').value || '0');
        var batchId = row.querySelector('.batch-id').value;
        var productId = row.querySelector('.product-id').value;
        var productName = row.querySelector('.product-search').value.trim();
        var prcInput = row.querySelector('.prc');
        var disc = parseFloat(row.querySelector('.disc').value || '0');
        var discType = row.querySelector('.disc-type').value || 'rs';

        var lineGross = 0;
        var lineDiscountAmount = 0;
        if(productId && batchId && qty > 0){
            var opt = getBatchOptionById(batchId);
            var unitPrice = opt ? parseFloat(opt.price || '0') : 0;
            if(prcInput) prcInput.value = unitPrice.toFixed(2);
            lineGross = qty * unitPrice;

            batchRequested[batchId] = (batchRequested[batchId] || 0) + qty;
            var available = opt ? parseFloat(opt.qty || '0') : 0;
            if(!opt || batchRequested[batchId] > available){
                hasInsufficient = true;
                pricingNotes.push((productName || 'Selected medicine') + ' batch quantity exceeded. Available: ' + formatQtyValue(available));
                row.classList.add('ring-1', 'ring-red-300', 'rounded-lg');
            } else {
                row.classList.remove('ring-1', 'ring-red-300', 'rounded-lg');
            }
        } else {
            if(prcInput){
                prcInput.value = '0.00';
            }
            row.classList.remove('ring-1', 'ring-red-300', 'rounded-lg');
        }

        if(discType === 'percent'){
            disc = lineGross * (disc / 100);
        }
        if(disc > lineGross) disc = lineGross;
        lineDiscountAmount = disc;

        grossTotal += lineGross;
        lineDiscountTotal += lineDiscountAmount;
    });

    var invoiceDiscount = parseFloat(document.getElementById('invoice_discount').value || '0');
    var invoiceDiscountType = document.getElementById('invoice_discount_type').value || 'rs';
    var subtotalAfterLineDiscount = Math.max(grossTotal - lineDiscountTotal, 0);
    if(invoiceDiscountType === 'percent'){
        invoiceDiscount = subtotalAfterLineDiscount * (invoiceDiscount / 100);
    }
    if(invoiceDiscount < 0) invoiceDiscount = 0;
    if(invoiceDiscount > subtotalAfterLineDiscount) invoiceDiscount = subtotalAfterLineDiscount;

    var totalDiscount = lineDiscountTotal + invoiceDiscount;
    var netTotal = Math.max(grossTotal - totalDiscount, 0);

    document.getElementById('total').value = grossTotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('net_total').value = netTotal.toFixed(2);
    updateBatchPricingNote(pricingNotes, hasInsufficient);
    syncPaidWithMethod();
    calcDue();
}

function getPayableTotal(){
    return parseFloat(document.getElementById('net_total').value || '0');
}

function calcDue(){
    var total = getPayableTotal();
    var method = document.getElementById('payment_method').value;
    var paidInput = document.getElementById('paid');
    var tenderInput = document.getElementById('tender');
    var changePreview = document.getElementById('change_preview');
    var paid = parseFloat(paidInput.value || '0');

    if(method === 'cash' && tenderInput){
        var tender = parseFloat(tenderInput.value || '0');
        if(tender < 0) tender = 0;
        paid = Math.min(tender, total);
        paidInput.value = paid.toFixed(2);
        if(changePreview){
            var change = Math.max(tender - total, 0);
            changePreview.value = change.toFixed(2);
        }
    } else if(method === 'qr' && tenderInput){
        tenderInput.value = total.toFixed(2);
        paid = total;
        paidInput.value = total.toFixed(2);
        if(changePreview){
            changePreview.value = '0.00';
        }
    } else if(changePreview){
        changePreview.value = '0.00';
    }

    var due = Math.max(total - paid, 0);
    document.getElementById('due_preview').value = due.toFixed(2);
}

function updateTenderState(){
    var method = document.getElementById('payment_method').value;
    var total = getPayableTotal();
    var tenderInput = document.getElementById('tender');
    if(!tenderInput) return;

    var isFreeSale = total <= 0.00001;
    if(isFreeSale){
        tenderInput.value = '0';
        tenderInput.disabled = true;
        tenderInput.required = false;
        return;
    }

    tenderInput.disabled = false;
    if(method === 'cash'){
        tenderInput.required = true;
        if(tenderInput.value === ''){
            tenderInput.value = '0';
        }
    } else if(method === 'qr'){
        tenderInput.required = false;
        tenderInput.value = total.toFixed(2);
    } else {
        tenderInput.required = false;
        tenderInput.value = '0';
    }
}

function handleMethodChange(){
    var methodSelect = document.getElementById('payment_method');
    var tenderInput = document.getElementById('tender');
    if(!canGiveCredit && methodSelect.value === 'credit'){
        methodSelect.value = 'cash';
    }

    updateTenderState();

    var paidInput = document.getElementById('paid');
    paidInput.dataset.manual = '0';
    syncPaidWithMethod();
    calcDue();
}

function syncPaidWithMethod(){
    var method = document.getElementById('payment_method').value;
    var customerId = document.getElementById('customer_id').value;
    var total = getPayableTotal();
    var paidInput = document.getElementById('paid');
    var tenderWrap = document.getElementById('tender_wrap');
    var tenderInput = document.getElementById('tender');
    var changePreview = document.getElementById('change_preview');
    var paid = parseFloat(paidInput.value || '0');
    var isManual = paidInput.dataset.manual === '1';

    if(tenderWrap){
        tenderWrap.classList.toggle('hidden', method !== 'cash');
    }
    updateTenderState();
    if(changePreview && method !== 'cash'){
        changePreview.value = '0.00';
    }

    function setPaidReadOnly(isReadOnly){
        paidInput.readOnly = isReadOnly;
        paidInput.classList.toggle('bg-slate-50', isReadOnly);
    }

    if(!canGiveCredit){
        if(method === 'cash' && tenderInput){
            paidInput.value = Math.min(parseFloat(tenderInput.value || '0'), total).toFixed(2);
        } else {
            paidInput.value = total.toFixed(2);
        }
        paidInput.dataset.manual = '0';
        setPaidReadOnly(true);
        return;
    }

    if(method === 'credit'){
        paidInput.value = '0';
        setPaidReadOnly(true);
        paidInput.dataset.manual = '0';
    } else if(method === 'cash'){
        if(tenderInput && tenderInput.value === '') tenderInput.value = '0';
        setPaidReadOnly(true);
        paidInput.dataset.manual = '0';
    } else if(method === 'qr'){
        paidInput.value = total.toFixed(2);
        setPaidReadOnly(true);
        paidInput.dataset.manual = '0';
    } else {
        // Walk-in cash/QR should not carry due, keep paid equal to total.
        if(!customerId){
            paidInput.value = total.toFixed(2);
            paidInput.dataset.manual = '0';
            setPaidReadOnly(true);
        } else if(!isManual || paid <= 0){
            // For selected customers, default to full payment unless user manually overrides.
            paidInput.value = total.toFixed(2);
            setPaidReadOnly(false);
        } else {
            setPaidReadOnly(false);
        }
    }
}

function markPaidManual(){
    var method = (document.getElementById('payment_method').value || '');
    if(method === 'cash' || method === 'qr') return;
    var paidInput = document.getElementById('paid');
    paidInput.dataset.manual = '1';
}

function showProductSuggestions(input){
    var term = input.value.toLowerCase().trim();
    var row = input.closest('.cart-row');
    var suggestionsDiv = row.querySelector('.product-suggestions');
    var hiddenProductId = row.querySelector('.product-id');
    var hiddenBatchId = row.querySelector('.batch-id');

    if(hiddenProductId){
        hiddenProductId.value = '';
    }
    if(hiddenBatchId){
        hiddenBatchId.value = '';
    }

    if(term === ''){
        row.dataset.suggestionIndex = '-1';
        suggestionsDiv.classList.add('hidden');
        return;
    }

    var filtered = allBatchOptions.filter(function(p){
        var hay = (p.product_name + ' ' + p.batch_no).toLowerCase();
        return hay.indexOf(term) !== -1;
    }).slice(0, 10);

    if(filtered.length === 0){
        row.dataset.suggestionIndex = '-1';
        suggestionsDiv.innerHTML = '<div class="px-3 py-2 text-slate-500">No products found</div>';
        suggestionsDiv.classList.remove('hidden');
        return;
    }

    row.dataset.suggestionIndex = '-1';

    suggestionsDiv.innerHTML = filtered.map(function(p, idx){
        var label = p.product_name + ' [Batch: ' + (p.batch_no || '-') + ']';
        var safeLabel = label.replace(/'/g, "\\'");
        return '<div class="px-3 py-2 hover:bg-slate-100 cursor-pointer border-b border-slate-100 last:border-0 transition product-suggestion-item" data-index="' + idx + '" data-product-id="' + p.product_id + '" data-batch-id="' + p.batch_id + '" data-product-name="' + escapeHtml(label) + '" data-product-price="' + p.price + '" onmousedown="selectProductSuggestion(this, ' + p.product_id + ', ' + p.batch_id + ', \'' + safeLabel + '\', ' + p.price + '); return false;" onclick="selectProductSuggestion(this, ' + p.product_id + ', ' + p.batch_id + ', \'' + safeLabel + '\', ' + p.price + ')">' +
            '<div class="font-medium text-slate-700">' + escapeHtml(label) + '</div>' +
            '<div class="text-xs text-slate-500">Batch Qty: ' + formatQtyValue(p.qty) + ' | Price: <?= e($appCurrencySymbol) ?> ' + Number(p.price).toFixed(2) + '</div>' +
            '</div>';
    }).join('');

    suggestionsDiv.classList.remove('hidden');
}

function hideProductSuggestions(input){
    var row = input.closest('.cart-row');
    row.dataset.suggestionIndex = '-1';
    row.querySelector('.product-suggestions').classList.add('hidden');
}

function selectProductSuggestion(suggestionEl, productId, batchId, productName, price){
    var row = suggestionEl.closest('.cart-row');
    applyProductSuggestion(row, productId, batchId, productName, price);
}

function applyProductSuggestion(row, productId, batchId, productName, price){
    row.querySelector('.product-search').value = productName;
    row.querySelector('.product-id').value = productId;
    row.querySelector('.batch-id').value = batchId;
    row.querySelector('.product-suggestions').classList.add('hidden');
    row.dataset.suggestionIndex = '-1';
    row.querySelector('.prc').value = Number(price).toFixed(2);
    calc();
}

function handleProductSearchKeydown(input, event){
    var row = input.closest('.cart-row');
    var suggestionsDiv = row.querySelector('.product-suggestions');
    var items = suggestionsDiv.querySelectorAll('.product-suggestion-item');

    if(!items.length || suggestionsDiv.classList.contains('hidden')){
        return;
    }

    var currentIndex = parseInt(row.dataset.suggestionIndex || '-1', 10);

    if(event.key === 'ArrowDown'){
        event.preventDefault();
        currentIndex = (currentIndex + 1) % items.length;
        setActiveSuggestion(row, items, currentIndex);
        return;
    }

    if(event.key === 'ArrowUp'){
        event.preventDefault();
        currentIndex = currentIndex <= 0 ? items.length - 1 : currentIndex - 1;
        setActiveSuggestion(row, items, currentIndex);
        return;
    }

    if(event.key === 'Enter'){
        event.preventDefault();
        var targetIndex = currentIndex >= 0 ? currentIndex : 0;
        var selected = items[targetIndex];
        if(selected){
            applyProductSuggestion(
                row,
                parseInt(selected.dataset.productId, 10),
                parseInt(selected.dataset.batchId, 10),
                selected.dataset.productName || '',
                parseFloat(selected.dataset.productPrice || '0')
            );
        }
        return;
    }

    if(event.key === 'Tab'){
        var tabIndex = currentIndex >= 0 ? currentIndex : 0;
        var tabSelected = items[tabIndex];
        if(tabSelected){
            applyProductSuggestion(
                row,
                parseInt(tabSelected.dataset.productId, 10),
                parseInt(tabSelected.dataset.batchId, 10),
                tabSelected.dataset.productName || '',
                parseFloat(tabSelected.dataset.productPrice || '0')
            );
        }
    }
}

function setActiveSuggestion(row, items, activeIndex){
    row.dataset.suggestionIndex = String(activeIndex);
    items.forEach(function(item, idx){
        if(idx === activeIndex){
            item.classList.add('bg-slate-100');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('bg-slate-100');
        }
    });
}

function showAlert(message) {
    var container = document.getElementById('toast-container');
    
    // If container doesn't exist, create it
    if(!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed top-4 right-4 z-50';
        document.body.appendChild(container);
    }
    
    var alertDiv = document.createElement('div');
    alertDiv.className = 'bg-red-500 text-white px-6 py-4 rounded-lg shadow-2xl flex items-center gap-3 mb-3 animate-slide-in';
    alertDiv.innerHTML = '<svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg><span class="flex-1">' + escapeHtml(message) + '</span>';
    
    container.appendChild(alertDiv);
    
    // Remove and animate out after 3 seconds
    setTimeout(function() {
        alertDiv.classList.remove('animate-slide-in');
        alertDiv.classList.add('animate-slide-out');
        setTimeout(function() {
            alertDiv.remove();
        }, 300);
    }, 3000);
}

function validateSaleForm(){
    normalizeCartFieldNames();
    syncCartRowPrices();
    calc();

    if(!ensureSaleDateAd()){
        showAlert('Sale date (AD) is required. Please refresh and try again.');
        return false;
    }

    // Validate all products are selected from suggestions
    var hasEmptyProducts = false;
    var hasInsufficientQty = false;
    var insufficientMessages = [];
    var batchRequested = {};
    document.querySelectorAll('.cart-row').forEach(function(row){
        var productId = row.querySelector('.product-id').value;
        var batchId = row.querySelector('.batch-id').value;
        var productName = row.querySelector('.product-search').value.trim();
        if(productName && (!productId || !batchId)){
            hasEmptyProducts = true;
        }
        var qty = parseFloat(row.querySelector('.qty').value || '0');
        if(productId && batchId && qty > 0){
            var opt = getBatchOptionById(batchId);
            batchRequested[batchId] = (batchRequested[batchId] || 0) + qty;
            var available = opt ? parseFloat(opt.qty || '0') : 0;
            if(!opt || batchRequested[batchId] > available){
                hasInsufficientQty = true;
                insufficientMessages.push((productName || 'Selected medicine') + ': quantity exceeds selected batch stock (' + formatQtyValue(available) + ').');
            }
        }
    });

    if(hasEmptyProducts){
        showAlert('Please select medicine from the batch suggestions.');
        return false;
    }

    if(hasInsufficientQty){
        showAlert(insufficientMessages.join(' | '));
        return false;
    }

    var method = (document.getElementById('payment_method').value || '').toLowerCase().trim();
    var customerId = document.getElementById('customer_id').value;
    var grossTotal = parseFloat(document.getElementById('total').value || '0');
    var total = getPayableTotal();
    var paid = parseFloat(document.getElementById('paid').value || '0');
    var tender = parseFloat((document.getElementById('tender') || {}).value || '0');

    // Compute effective paid by method to avoid stale paid-field edge cases.
    var effectivePaid = paid;
    if(method === 'cash'){
        effectivePaid = Math.min(Math.max(tender, 0), total);
    } else if(method === 'qr'){
        effectivePaid = total;
    }

    if(grossTotal <= 0){
        showAlert('Add valid items to cart.');
        return false;
    }

    if(method === 'cash' && tender < 0){
        showAlert('Tender amount cannot be negative.');
        return false;
    }

    if(method === 'cash' && tender <= 0){
        showAlert('Tender is required for cash payment.');
        return false;
    }

    if(method === 'credit' && !customerId){
        showAlert('Credit sale requires selecting a customer.');
        return false;
    }

    if(!canGiveCredit && (method === 'credit' || effectivePaid < total)){
        showAlert('You do not have permission to create due/credit sales.');
        return false;
    }

    if(method !== 'credit' && effectivePaid < total && !customerId){
        showAlert('Walk-In cannot keep due. Select a customer for due sale.');
        return false;
    }

    return true;
}

function filterCustomers(){
    var term = document.getElementById('customer_search').value.toLowerCase().trim();
    var select = document.getElementById('customer_id');
    var options = Array.from(select.options).slice(1);

    if(term === ''){
        select.value = '';
        return;
    }

    var found = options.find(function(opt){
        var hay = (opt.dataset.search || opt.text || '').toLowerCase();
        return hay.indexOf(term) !== -1;
    });

    if(found){
        select.value = found.value;
    } else {
        select.value = '';
    }

}

document.getElementById('customer_id')?.addEventListener('change', function(){
    document.getElementById('paid').dataset.manual = '0';
    if(!this.value){
        var search = document.getElementById('customer_search');
        if(search) search.value = '';
    }
    syncPaidWithMethod();
    calcDue();
});

window.addEventListener('DOMContentLoaded', function(){
    initSaleDateAdInput();
    ensureSaleDateAdWithRetry();
    normalizeCartFieldNames();
    syncCartRowPrices();
    calc();
    handleMethodChange();
    var customerSelect = document.getElementById('customer_id');
    if(customerSelect && !customerSelect.value){
        customerSelect.value = '';
    }
    
    // Add form submission handler
    var form = document.querySelector('form[method="POST"]');
    if(form) {
        form.addEventListener('submit', function(event) {
            if(!validateSaleForm()) {
                event.preventDefault();
                return false;
            }
        });
    }
});

window.addEventListener('load', function(){
    ensureSaleDateAdWithRetry();
});

function escapeHtml(text){
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function isValidAdDateString(value){
    return /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/.test(value || '');
}

function getCurrentAdDateString(){
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function initSaleDateAdInput(){
    var saleDateInput = document.getElementById('sale_date_bs');
    if(!saleDateInput) return;
    if(!saleDateInput.value){
        saleDateInput.value = getCurrentAdDateString();
    }
}

function getCurrentSaleDateString(){
    var adDate = getCurrentAdDateString();
    return isValidAdDateString(adDate) ? adDate : '';
}

function ensureSaleDateAd(){
    var saleDateInput = document.getElementById('sale_date_bs');
    if(!saleDateInput) return false;

    if(isValidAdDateString(saleDateInput.value)){
        return true;
    }

    try {
        var adDate = getCurrentSaleDateString();
        if(isValidAdDateString(adDate)){
            saleDateInput.value = adDate;
            return true;
        }
    } catch (e) {
        // Ignore and fail via return false below.
    }

    return false;
}

function ensureSaleDateAdWithRetry(){
    var attempts = 0;
    var maxAttempts = 15;
    var timer = setInterval(function(){
        attempts += 1;
        if(ensureSaleDateAd() || attempts >= maxAttempts){
            clearInterval(timer);
        }
    }, 200);
}

var style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
    .animate-slide-in {
        animation: slideIn 0.3s ease-out;
    }
    .animate-slide-out {
        animation: slideOut 0.3s ease-out;
    }
`;
document.head.appendChild(style);
</script>

</div><!-- End of main content -->
