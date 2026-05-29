<?php
require_once __DIR__ . '/../config.php';

// H-4: Authorization check — only admins or users with report.view permission
if(!is_admin_user() && !has_permission('report.view')){
    flash_msg('You do not have permission to view reports.', 'error');
    redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
}

$pharmacyStmt = $pdo->query("SELECT * FROM pharmacy_details WHERE id=1 LIMIT 1");
$pharmacyDetails = $pharmacyStmt ? ($pharmacyStmt->fetch() ?: null) : null;

function int_to_words(int $num): string {
    $ones = [
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
        16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen'
    ];
    $tens = [
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'
    ];

    if($num < 20) return $ones[$num];
    if($num < 100){
        $t = intdiv($num, 10);
        $r = $num % 10;
        return $tens[$t] . ($r ? '-' . $ones[$r] : '');
    }
    if($num < 1000){
        $h = intdiv($num, 100);
        $r = $num % 100;
        return $ones[$h] . ' hundred' . ($r ? ' ' . int_to_words($r) : '');
    }
    if($num < 1000000){
        $th = intdiv($num, 1000);
        $r = $num % 1000;
        return int_to_words($th) . ' thousand' . ($r ? ' ' . int_to_words($r) : '');
    }
    if($num < 1000000000){
        $m = intdiv($num, 1000000);
        $r = $num % 1000000;
        return int_to_words($m) . ' million' . ($r ? ' ' . int_to_words($r) : '');
    }

    $b = intdiv($num, 1000000000);
    $r = $num % 1000000000;
    return int_to_words($b) . ' billion' . ($r ? ' ' . int_to_words($r) : '');
}

function amount_to_words(float $amount): string {
    $rupees = (int)floor($amount);
    $paisa = (int)round(($amount - $rupees) * 100);

    if($paisa === 100){
        $rupees += 1;
        $paisa = 0;
    }

    $rupeesPart = ucfirst(int_to_words(max(0, $rupees))) . ' rupees';
    if($paisa > 0){
        return $rupeesPart . ' and ' . int_to_words($paisa) . ' paisa only';
    }
    return $rupeesPart . ' only';
}

$type = trim((string)($_GET['type'] ?? 'sales'));
$fromBs = trim((string)($_GET['from'] ?? ''));
$toBs = trim((string)($_GET['to'] ?? ''));
$isAdmin = is_admin_user();
$currentUserId = (int)($_SESSION['uid'] ?? 0);

$branchIdRaw = (int)($_GET['branch_id'] ?? 0);
$hasCustomerBranchColumn = false;
try {
    $hasCustomerBranchColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='branch_id'")->fetchColumn() > 0;
} catch(Throwable $e){
    $hasCustomerBranchColumn = false;
}

$currentUserBranchId = 0;
if($currentUserId > 0){
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(branch_id,0) FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$currentUserId]);
        $currentUserBranchId = (int)$stmt->fetchColumn();
    } catch(Throwable $e){
        $currentUserBranchId = 0;
    }
}

$selectedBranchId = $branchIdRaw > 0 ? $branchIdRaw : 0;
if(!$isAdmin){
    $selectedBranchId = $currentUserBranchId;
}

$branches = [];
if($isAdmin){
    $branches = $pdo->query("SELECT id, name, code FROM branches WHERE is_active=1 ORDER BY name ASC")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, name, code FROM branches WHERE id=? LIMIT 1");
    $stmt->execute([$selectedBranchId]);
    $branchRow = $stmt->fetch();
    if($branchRow){
        $branches[] = $branchRow;
    }
}

$selectedBranchName = 'All Branches';
if($selectedBranchId > 0){
    foreach($branches as $b){
        if((int)$b['id'] === $selectedBranchId){
            $selectedBranchName = trim((string)$b['name']);
            if($selectedBranchName === ''){
                $selectedBranchName = 'Branch #' . $selectedBranchId;
            }
            break;
        }
    }
    if($selectedBranchName === 'All Branches'){
        $selectedBranchName = 'Branch #' . $selectedBranchId;
    }
}

if(!in_array($type, ['sales', 'dues', 'expiry', 'product_sales'], true)){
    $type = 'sales';
}

$salesRows = [];
$duesRows = [];
$expiryRows = [];
$productRows = [];

if($type === 'sales'){
    $where = ["1=1"];
    $params = [];
    if(!$isAdmin){
        $where[] = "s.sold_by_user_id = ?";
        $params[] = $currentUserId;
    }
    if($selectedBranchId > 0){
        $where[] = "COALESCE(u.branch_id,0) = ?";
        $params[] = $selectedBranchId;
    }
    if($fromBs !== ''){
        $where[] = "s.sale_date_bs >= ?";
        $params[] = $fromBs;
    }
    if($toBs !== ''){
        $where[] = "s.sale_date_bs <= ?";
        $params[] = $toBs;
    }
    $sql = "SELECT s.invoice_no, s.sale_date_bs, s.customer_name, s.payment_method, s.total_amount, s.paid_amount, s.due_amount, s.discount
            FROM sales s
            LEFT JOIN users u ON u.id=s.sold_by_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.sale_date_bs DESC, s.id DESC
            LIMIT 10000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $salesRows = $stmt->fetchAll();
}

if($type === 'dues'){
    $where = ["c.current_due > 0"];
    $params = [];
    if($selectedBranchId > 0){
        if($hasCustomerBranchColumn){
            $where[] = "COALESCE(c.branch_id,0) = ?";
            $params[] = $selectedBranchId;
        } else {
            $where[] = "EXISTS (SELECT 1 FROM sales s JOIN users u ON u.id=s.sold_by_user_id WHERE s.customer_id=c.id AND COALESCE(u.branch_id,0)=?)";
            $params[] = $selectedBranchId;
        }
    }
    $sql = "SELECT c.name, c.phone, c.current_due, c.credit_limit
            FROM customers c
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.current_due DESC
            LIMIT 10000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $duesRows = $stmt->fetchAll();
}

if($type === 'expiry'){
    $where = ["b.quantity > 0"];
    $params = [];
    if($selectedBranchId > 0){
        $where[] = "COALESCE(b.branch_id,0) = ?";
        $params[] = $selectedBranchId;
    }
    $sql = "SELECT p.name, b.batch_no, b.expiry_date, b.quantity, b.sell_price
            FROM batches b
            JOIN products p ON p.id=b.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY b.expiry_date ASC
            LIMIT 10000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expiryRows = $stmt->fetchAll();
}

if($type === 'product_sales'){
    $where = ["1=1"];
    $params = [];
    if(!$isAdmin){
        $where[] = "s.sold_by_user_id = ?";
        $params[] = $currentUserId;
    }
    if($selectedBranchId > 0){
        $where[] = "COALESCE(u.branch_id,0) = ?";
        $params[] = $selectedBranchId;
    }
    if($fromBs !== ''){
        $where[] = "s.sale_date_bs >= ?";
        $params[] = $fromBs;
    }
    if($toBs !== ''){
        $where[] = "s.sale_date_bs <= ?";
        $params[] = $toBs;
    }
    $sql = "SELECT p.name, SUM(si.quantity) AS qty, SUM(si.total) AS total
            FROM sale_items si
            JOIN sales s ON s.id=si.sale_id
            LEFT JOIN users u ON u.id=s.sold_by_user_id
            JOIN products p ON p.id=si.product_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY si.product_id
            ORDER BY total DESC
            LIMIT 10000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productRows = $stmt->fetchAll();
}

$salesCount = count($salesRows);
$salesTotal = 0.0;
$salesDiscountTotal = 0.0;
foreach($salesRows as $r){
    $salesTotal += (float)($r['total_amount'] ?? 0) + (float)($r['discount'] ?? 0);
    $salesDiscountTotal += (float)($r['discount'] ?? 0);
}
$salesNetTotal = $salesTotal - $salesDiscountTotal;
$salesTotalWords = amount_to_words($salesTotal);
$salesDiscountWords = amount_to_words($salesDiscountTotal);

$productCount = count($productRows);
$productRevenue = 0.0;
foreach($productRows as $r){ $productRevenue += (float)($r['total'] ?? 0); }
$productRevenueWords = amount_to_words($productRevenue);

$dueCount = count($duesRows);
$dueTotal = 0.0;
foreach($duesRows as $r){ $dueTotal += (float)($r['current_due'] ?? 0); }

$expiryCount = count($expiryRows);
$expiryQtyTotal = 0.0;
$expiryValueTotal = 0.0;
foreach($expiryRows as $r){
    $expiryQtyTotal += (float)($r['quantity'] ?? 0);
    $expiryValueTotal += (float)($r['quantity'] ?? 0) * (float)($r['sell_price'] ?? 0);
}

$salesMeta = paginate_array($salesRows, 'sales_page', max(10, count($salesRows)));
$salesRows = $salesMeta['rows'];

$duesMeta = paginate_array($duesRows, 'dues_page', max(10, count($duesRows)));
$duesRows = $duesMeta['rows'];

$expiryMeta = paginate_array($expiryRows, 'expiry_page', max(10, count($expiryRows)));
$expiryRows = $expiryMeta['rows'];

$productMeta = paginate_array($productRows, 'product_page', max(10, count($productRows)));
$productRows = $productMeta['rows'];

$reportTitle = [
    'sales' => 'Sales Report',
    'product_sales' => 'Product Sales Report',
    'dues' => 'Customer Due Report',
    'expiry' => 'Expiry Stock Report',
][$type] ?? 'Sales Report';

$pharmacyNameText = trim((string)($pharmacyDetails['pharmacy_name'] ?? ''));
$addressText = trim((string)($pharmacyDetails['address'] ?? ''));
$phoneText = trim((string)($pharmacyDetails['phone_number'] ?? ''));
$emailText = trim((string)($pharmacyDetails['email'] ?? ''));
$panVatText = trim((string)($pharmacyDetails['pan_vat'] ?? ''));
$ddaText = trim((string)($pharmacyDetails['dda_no'] ?? ''));

$pharmacyNameDisplay = $pharmacyNameText !== '' ? $pharmacyNameText : '$PharmacyName';
$addressDisplay = $addressText !== '' ? $addressText : '$Address';
$phoneDisplay = $phoneText !== '' ? $phoneText : '$phonenumber';
$emailDisplay = $emailText;
$panVatDisplay = $panVatText !== '' ? $panVatText : '$PANVAT';
$ddaDisplay = $ddaText !== '' ? $ddaText : '$DDA';
$contactLine = 'Phone: ' . $phoneDisplay . ($emailDisplay !== '' ? ' - Email: ' . $emailDisplay : '');
$reportDateDisplay = '';
if($fromBs !== '' && $toBs !== ''){
    $reportDateDisplay = $fromBs === $toBs ? $fromBs : ($fromBs . ' to ' . $toBs);
} elseif($fromBs !== ''){
    $reportDateDisplay = $fromBs;
} elseif($toBs !== ''){
    $reportDateDisplay = $toBs;
}

$dateFilteredTypes = ['sales', 'product_sales'];
$defaultToToday = in_array($type, $dateFilteredTypes, true) && $fromBs === '' && $toBs === '';
?>
<?php if($defaultToToday): ?>
<script>
(function(){
    if(window.location.search.indexOf('from=') !== -1 || window.location.search.indexOf('to=') !== -1){
        return;
    }
    function getCurrentAdDate(){
        var now = new Date();
        var year = now.getFullYear();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }
    function redirectToday(){
        var today = getCurrentAdDate();
        var url = new URL(window.location.href);
        url.searchParams.set('module', 'report');
        url.searchParams.set('type', <?= json_encode($type, JSON_HEX_TAG | JSON_HEX_AMP) ?>);
        <?php if($selectedBranchId > 0): ?>
        url.searchParams.set('branch_id', <?= (int)$selectedBranchId ?>);
        <?php endif; ?>
        url.searchParams.set('from', today);
        url.searchParams.set('to', today);
        window.location.replace(url.toString());
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', redirectToday, { once: true });
    } else {
        redirectToday();
    }
})();
</script>
<?php endif; ?>
<style>
    .report-paper {
        background: #ffffff;
        color: #000000;
        border: 1px solid #bdbdbd;
        border-radius: 10px;
        overflow: visible;
        font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    }
    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        color: #000000;
        table-layout: fixed;
    }
    .report-table th,
    .report-table td {
        border: 1px solid #bdbdbd;
        padding: 7px 9px;
        box-sizing: border-box;
        line-height: 1.35;
        vertical-align: top;
    }
    .report-sales-table th,
    .report-sales-table td {
        padding: 5px 6px;
    }
    .report-sales-table col.report-date-col { width: 11%; }
    .report-sales-table col.report-invoice-col { width: 22%; }
    .report-sales-table col.report-customer-col { width: 26%; }
    .report-sales-table col.report-method-col { width: 11%; }
    .report-sales-table col.report-amount-col { width: 9%; }
    .report-date-cell,
    .report-method-cell,
    .report-amount-cell {
        white-space: nowrap;
    }
    .report-date-head { width: 12%; }
    .report-invoice-head {
        width: 22%;
    }
    .report-customer-head { width: 31%; }
    .report-method-head { width: 9%; }
    .report-amount-head { width: 5.75%; }
    .report-invoice-cell {
        font-size: 11px;
        line-height: 1.2;
        white-space: nowrap;
    }
    .report-customer-cell {
        white-space: nowrap;
    }
    .report-title-row th {
        font-size: 16px;
        font-weight: 700;
        letter-spacing: .2px;
        text-transform: uppercase;
        background: #ffffff;
        color: #000000;
    }
    .report-meta-row th {
        background: #ffffff;
        font-size: 12px;
        font-weight: 500;
        color: #000000;
    }
    .report-head-row th {
        background: #f3f3f3;
        font-weight: 700;
        color: #000000;
    }
    .report-table tbody tr:nth-child(even) {
        background: #ffffff;
    }
    .report-topline {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .report-summary {
        font-size: 11px;
        color: #000000;
        background: #ffffff;
        border: 1px solid #bdbdbd;
        border-radius: 9999px;
        padding: 3px 10px;
    }
    .report-brand-block {
        background: linear-gradient(180deg, #ffffff 0%, #fbfbfb 100%);
        color: #000000;
        text-align: center;
        padding: 14px 16px 12px;
        border-bottom: 1px solid #bdbdbd;
    }
    .report-brand-name {
        font-size: 20px;
        line-height: 1.15;
        font-weight: 700;
        letter-spacing: .3px;
        text-transform: uppercase;
    }
    .report-brand-address {
        font-size: 14px;
        line-height: 1.35;
        margin-top: 5px;
        font-weight: 500;
        color: #222222;
    }
    .report-brand-line {
        font-size: 12px;
        line-height: 1.35;
        margin-top: 3px;
        color: #222222;
        font-weight: 500;
    }
    .report-page-footer {
        margin-top: 8px;
        font-size: 11px;
        color: #222222;
        text-align: right;
        font-weight: 500;
    }
    @media print {
        .print-report-container {
            width: 100% !important;
            max-width: 100% !important;
        }
        .report-paper {
            border: none !important;
            border-radius: 0 !important;
            overflow: visible !important;
        }
        .report-table {
            font-size: 11px;
            width: 100% !important;
        }
        .report-invoice-cell {
            font-size: 10px;
        }
        .report-brand-name { font-size: 17px; }
        .report-brand-address { font-size: 12px; }
        .report-brand-line { font-size: 12px; }
        .report-title-row th { font-size: 13px; }
        .report-table thead {
            display: table-header-group;
        }
        .report-table tbody tr {
            page-break-inside: avoid;
        }
    }
</style>
<div class="space-y-6 print-report-container">
    <section class="bg-white p-5 rounded-2xl shadow border border-slate-200 no-print">
        <div class="flex items-center justify-between gap-3 mb-4 no-print">
            <h3 class="font-semibold text-slate-800">Generate Report</h3>
            <button type="button" onclick="printReportPdf()" class="bg-primary hover:bg-teal-800 text-white px-4 py-2 rounded-lg text-sm font-medium">Print / Save PDF</button>
        </div>
        <form method="GET" class="grid md:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="module" value="report">
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Report Type</label>
                <select name="type" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg">
                    <option value="sales" <?= $type === 'sales' ? 'selected' : '' ?>>Sales Report</option>
                    <option value="product_sales" <?= $type === 'product_sales' ? 'selected' : '' ?>>Product Sales Report</option>
                    <option value="dues" <?= $type === 'dues' ? 'selected' : '' ?>>Customer Due Report</option>
                    <option value="expiry" <?= $type === 'expiry' ? 'selected' : '' ?>>Expiry Stock Report</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">From (AD)</label>
                <input type="date" id="report_from_ad" name="from" value="<?= e($fromBs) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">To (AD)</label>
                <input type="date" id="report_to_ad" name="to" value="<?= e($toBs) ?>" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm text-slate-700 mb-1.5">Branch</label>
                <select name="branch_id" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg" <?= $isAdmin ? '' : 'disabled' ?>>
                    <?php if($isAdmin): ?><option value="0" <?= $selectedBranchId === 0 ? 'selected' : '' ?>>All Branches</option><?php endif; ?>
                    <?php foreach($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)$selectedBranchId === (int)$b['id'] ? 'selected' : '' ?>><?= e((string)$b['name']) ?> (<?= e((string)$b['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php if(!$isAdmin): ?><input type="hidden" name="branch_id" value="<?= (int)$selectedBranchId ?>"><?php endif; ?>
            </div>
            <div>
                <button class="w-full bg-primary hover:bg-teal-800 text-white px-4 py-2.5 rounded-lg text-sm font-medium">Generate</button>
            </div>
            <div>
                <a href="?module=report" class="block text-center bg-slate-200 hover:bg-slate-300 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-medium">Reset</a>
            </div>
        </form>
    </section>

    <?php if($type === 'sales'): ?>
    <section class="report-paper overflow-x-auto">
            <div class="report-brand-block">
                <div class="report-brand-name"><?= e($pharmacyNameDisplay) ?></div>
                <div class="report-brand-address"><?= e($addressDisplay) ?></div>
                <div class="report-brand-line"><?= e($contactLine) ?></div>
                <div class="report-brand-line">PAN/VAT: <?= e($panVatDisplay) ?> - DDA No: <?= e($ddaDisplay) ?></div>
            </div>
            <table class="report-table report-sales-table">
                <colgroup>
                    <col class="report-date-col">
                    <col class="report-invoice-col">
                    <col class="report-customer-col">
                    <col class="report-method-col">
                    <col class="report-amount-col">
                    <col class="report-amount-col">
                    <col class="report-amount-col">
                    <col class="report-amount-col">
                </colgroup>
                <thead>
                    <tr class="report-title-row">
                        <th colspan="8">Sales Report</th>
                    </tr>
                    <tr class="report-meta-row">
                        <th colspan="8">Records: <?= (int)$salesCount ?> | Branch: <?= e($selectedBranchName) ?> | Date (AD): <span class="report-page-date" data-report-date="<?= e($reportDateDisplay) ?>"></span></th>
                    </tr>
                    <tr class="report-head-row">
                        <th class="text-left report-date-head">Date (AD)</th>
                        <th class="text-left report-invoice-head">Invoice</th>
                        <th class="text-left report-customer-head">Customer</th>
                        <th class="text-left report-method-head">Pay Type</th>
                        <th class="text-right report-amount-head">Total</th>
                        <th class="text-right report-amount-head">Discount</th>
                        <th class="text-right report-amount-head">Paid</th>
                        <th class="text-right report-amount-head">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($salesRows)): ?>
                        <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No sales records found.</td></tr>
                    <?php else: foreach($salesRows as $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-3.5 report-date-cell"><?= e((string)($r['sale_date_bs'] ?? '-')) ?></td>
                            <td class="px-4 py-3.5 report-invoice-cell" title="<?= e((string)$r['invoice_no']) ?>"><?= e((string)$r['invoice_no']) ?></td>
                            <td class="px-4 py-3.5 report-customer-cell" title="<?= e((string)($r['customer_name'] ?: 'Walk-In Customer')) ?>"><?= e((string)($r['customer_name'] ?: 'Walk-In Customer')) ?></td>
                            <td class="px-4 py-3.5 report-method-cell" style="text-transform: capitalize;"><?= e((string)$r['payment_method']) ?></td>
                            <td class="px-4 py-3.5 text-right report-amount-cell"><?= npr((float)$r['total_amount'] + (float)($r['discount'] ?? 0)) ?></td>
                            <td class="px-4 py-3.5 text-right report-amount-cell"><?= npr((float)$r['discount']) ?></td>
                            <td class="px-4 py-3.5 text-right report-amount-cell"><?= npr((float)$r['paid_amount']) ?></td>
                            <td class="px-4 py-3.5 text-right report-amount-cell"><?= npr((float)$r['due_amount']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="report-head-row">
                        <th colspan="8" class="text-right">Total: <?= npr($salesTotal) ?></th>
                    </tr>
                    <tr class="report-head-row">
                        <th colspan="8" class="text-right">Discount: <?= npr($salesDiscountTotal) ?></th>
                    </tr>
                    <tr class="report-head-row">
                        <th colspan="8" class="text-right">Grand Total: <?= npr($salesNetTotal) ?></th>
                    </tr>
                </tfoot>
            </table>
        <div class="report-page-footer">Report generated on: <span class="report-generated-date"></span> <span class="report-generated-time"></span></div>
        <div class="no-print"><?= render_pagination($salesMeta) ?></div>
    </section>
    <?php endif; ?>

    <?php if($type === 'product_sales'): ?>
    <section class="report-paper overflow-x-auto">
            <div class="report-brand-block">
                <div class="report-brand-name"><?= e($pharmacyNameDisplay) ?></div>
                <div class="report-brand-address"><?= e($addressDisplay) ?></div>
                <div class="report-brand-line"><?= e($contactLine) ?></div>
                <div class="report-brand-line">PAN/VAT: <?= e($panVatDisplay) ?> - DDA No: <?= e($ddaDisplay) ?></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr class="report-title-row">
                        <th colspan="3">Product Sales Report</th>
                    </tr>
                    <tr class="report-meta-row">
                        <th colspan="3">Products: <?= (int)$productCount ?> | Branch: <?= e($selectedBranchName) ?> | Date (AD): <span class="report-page-date" data-report-date="<?= e($reportDateDisplay) ?>"></span></th>
                    </tr>
                    <tr class="report-head-row">
                        <th class="text-left">Product</th>
                        <th class="text-right">Sold Qty</th>
                        <th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($productRows)): ?>
                        <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">No product sales records found.</td></tr>
                    <?php else: foreach($productRows as $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-3.5"><?= e((string)$r['name']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= e((string)$r['qty']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= npr((float)$r['total']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="report-head-row">
                        <th colspan="3" class="text-right">Grand Total Revenue: <?= npr($productRevenue) ?> (<?= e($productRevenueWords) ?>)</th>
                    </tr>
                </tfoot>
            </table>
        <div class="report-page-footer">Report generated on: <span class="report-generated-date"></span> <span class="report-generated-time"></span></div>
        <div class="no-print"><?= render_pagination($productMeta) ?></div>
    </section>
    <?php endif; ?>

    <?php if($type === 'dues'): ?>
    <section class="report-paper overflow-x-auto">
            <div class="report-brand-block">
                <div class="report-brand-name"><?= e($pharmacyNameDisplay) ?></div>
                <div class="report-brand-address"><?= e($addressDisplay) ?></div>
                <div class="report-brand-line"><?= e($contactLine) ?></div>
                <div class="report-brand-line">PAN/VAT: <?= e($panVatDisplay) ?> - DDA No: <?= e($ddaDisplay) ?></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr class="report-title-row">
                        <th colspan="4">Customer Due Report</th>
                    </tr>
                    <tr class="report-meta-row">
                        <th colspan="4">Customers: <?= (int)$dueCount ?> | Branch: <?= e($selectedBranchName) ?> | Date (AD): <span class="report-page-date" data-report-date="<?= e($reportDateDisplay) ?>"></span></th>
                    </tr>
                    <tr class="report-head-row">
                        <th class="text-left">Customer</th>
                        <th class="text-left">Phone</th>
                        <th class="text-right">Credit Limit</th>
                        <th class="text-right">Current Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($duesRows)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No due customers found.</td></tr>
                    <?php else: foreach($duesRows as $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-3.5"><?= e((string)$r['name']) ?></td>
                            <td class="px-4 py-3.5"><?= e((string)$r['phone']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= npr((float)$r['credit_limit']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= npr((float)$r['current_due']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="report-head-row">
                        <th colspan="4" class="text-right">Grand Total Due: <?= npr($dueTotal) ?> (<?= e(amount_to_words($dueTotal)) ?>)</th>
                    </tr>
                </tfoot>
            </table>
        <div class="report-page-footer">Report generated on: <span class="report-generated-date"></span> <span class="report-generated-time"></span></div>
        <div class="no-print"><?= render_pagination($duesMeta) ?></div>
    </section>
    <?php endif; ?>

    <?php if($type === 'expiry'): ?>
    <section class="report-paper overflow-x-auto">
            <div class="report-brand-block">
                <div class="report-brand-name"><?= e($pharmacyNameDisplay) ?></div>
                <div class="report-brand-address"><?= e($addressDisplay) ?></div>
                <div class="report-brand-line"><?= e($contactLine) ?></div>
                <div class="report-brand-line">PAN/VAT: <?= e($panVatDisplay) ?> - DDA No: <?= e($ddaDisplay) ?></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr class="report-title-row">
                        <th colspan="5">Expiry Stock Report</th>
                    </tr>
                    <tr class="report-meta-row">
                        <th colspan="5">Batches: <?= (int)$expiryCount ?> | Branch: <?= e($selectedBranchName) ?> | Date (AD): <span class="report-page-date" data-report-date="<?= e($reportDateDisplay) ?>"></span></th>
                    </tr>
                    <tr class="report-head-row">
                        <th class="text-left">Product</th>
                        <th class="text-left">Batch</th>
                        <th class="text-left">Expiry (AD)</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Sell Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($expiryRows)): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No expiry stock records found.</td></tr>
                    <?php else: foreach($expiryRows as $r): ?>
                        <tr class="border-b border-slate-100">
                            <td class="px-4 py-3.5"><?= e((string)$r['name']) ?></td>
                            <td class="px-4 py-3.5"><?= e((string)$r['batch_no']) ?></td>
                            <td class="px-4 py-3.5"><?= e((string)$r['expiry_date']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= e((string)$r['quantity']) ?></td>
                            <td class="px-4 py-3.5 text-right"><?= npr((float)$r['sell_price']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="report-head-row">
                        <th colspan="5" class="text-right">Total Qty: <?= rtrim(rtrim(number_format($expiryQtyTotal, 2, '.', ''), '0'), '.') ?></th>
                    </tr>
                    <tr class="report-head-row">
                        <th colspan="5" class="text-right">Stock Value: <?= npr($expiryValueTotal) ?></th>
                    </tr>
                </tfoot>
            </table>
        <div class="report-page-footer">Report generated on: <span class="report-generated-date"></span> <span class="report-generated-time"></span></div>
        <div class="no-print"><?= render_pagination($expiryMeta) ?></div>
    </section>
    <?php endif; ?>
</div>

<script>
function getCurrentAdDate(){
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function applyReportDates(){
    var today = getCurrentAdDate();
    var now = new Date();
    var timeText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    document.querySelectorAll('.report-page-date').forEach(function(node){
        var value = (node.dataset.reportDate || '').trim();
        node.textContent = value !== '' ? value : today;
    });
    document.querySelectorAll('.report-generated-date').forEach(function(node){
        node.textContent = today;
    });
    document.querySelectorAll('.report-generated-time').forEach(function(node){
        node.textContent = timeText;
    });
}

document.addEventListener('DOMContentLoaded', applyReportDates);

function printReportPdf(){
    window.print();
}
</script>