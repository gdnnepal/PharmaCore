<?php
require_once __DIR__ . '/config.php';

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$copyMode = ((string)($_GET['copy'] ?? '0') === '1');
$autoPrint = ((string)($_GET['auto_print'] ?? '0') === '1');

// Some redirects may HTML-escape ampersands, producing query keys like `amp;auto_print`.
if(!$copyMode && isset($_GET['amp;copy']) && (string)$_GET['amp;copy'] === '1'){
    $copyMode = true;
}
if(!$autoPrint && isset($_GET['amp;auto_print']) && (string)$_GET['amp;auto_print'] === '1'){
    $autoPrint = true;
}

if($invoiceId <= 0){
    flash_msg('Invalid invoice request.', 'error');
    redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
}

$isAdmin = is_admin_user();
$currentUserId = (int)($_SESSION['uid'] ?? 0);

if($isAdmin){
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(NULLIF(u.full_name,''), u.username) AS sold_by_name FROM sales s LEFT JOIN users u ON u.id=s.sold_by_user_id WHERE s.id=? LIMIT 1");
    $stmt->execute([$invoiceId]);
} else {
    $stmt = $pdo->prepare("SELECT s.*, COALESCE(NULLIF(u.full_name,''), u.username) AS sold_by_name FROM sales s LEFT JOIN users u ON u.id=s.sold_by_user_id WHERE s.id=? AND s.sold_by_user_id=? LIMIT 1");
    $stmt->execute([$invoiceId, $currentUserId]);
}
$sale = $stmt->fetch();

if(!$sale){
    flash_msg('Invoice not found or access denied.', 'error');
    redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
}

$sessionAutoPrintId = (int)($_SESSION['invoice_auto_print_id'] ?? 0);
if($sessionAutoPrintId > 0 && $sessionAutoPrintId === $invoiceId){
    $autoPrint = true;
    unset($_SESSION['invoice_auto_print_id']);
}

$itemsStmt = $pdo->prepare("SELECT si.quantity, si.sell_price, si.total, p.name AS product_name, b.batch_no, b.expiry_date FROM sale_items si JOIN products p ON p.id=si.product_id LEFT JOIN batches b ON b.id=si.batch_id WHERE si.sale_id=? ORDER BY si.id ASC");
$itemsStmt->execute([$invoiceId]);
$items = $itemsStmt->fetchAll();

$pharmacyStmt = $pdo->query("SELECT * FROM pharmacy_details WHERE id=1 LIMIT 1");
$pharmacy = $pharmacyStmt ? ($pharmacyStmt->fetch() ?: null) : null;
$defaultInvoiceFooterNote = "Thank you for your purchase. Store medicines in a cool, dry place.\nItems once sold cannot be returned without original receipt.";
$invoiceFooterNote = $defaultInvoiceFooterNote;
try {
    $invoiceFooterStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='invoice_footer_note' LIMIT 1");
    $invoiceFooterStmt->execute();
    $savedFooterNote = trim((string)($invoiceFooterStmt->fetchColumn() ?: ''));
    if($savedFooterNote !== ''){
        $invoiceFooterNote = str_replace(["\r\n", "\r"], "\n", $savedFooterNote);
    }
} catch(Throwable $e){
    $invoiceFooterNote = $defaultInvoiceFooterNote;
}
$hasMedicineItems = !empty($items);

$defaultReturnUrl = get_base_url() . '/dashboard.php?module=sale';
$returnAfterPrintUrl = trim((string)($_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
if($returnAfterPrintUrl === ''){
    $returnAfterPrintUrl = $defaultReturnUrl;
}

$parsedReturn = parse_url($returnAfterPrintUrl);
if($parsedReturn === false){
    $returnAfterPrintUrl = $defaultReturnUrl;
} else {
    $hasAbsoluteTarget = isset($parsedReturn['host']) || isset($parsedReturn['scheme']);
    if($hasAbsoluteTarget){
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $targetHost = strtolower((string)($parsedReturn['host'] ?? ''));
        if($targetHost !== '' && $currentHost !== '' && $targetHost !== $currentHost){
            $returnAfterPrintUrl = $defaultReturnUrl;
        } else {
            $path = (string)($parsedReturn['path'] ?? '/');
            $query = isset($parsedReturn['query']) ? ('?' . (string)$parsedReturn['query']) : '';
            $returnAfterPrintUrl = $path . $query;
        }
    }
}

$invoiceTitle = $copyMode ? 'Copy of Invoice' : 'Invoice';
$paymentMethod = strtoupper((string)($sale['payment_method'] ?? 'cash'));
$saleDateDisplay = (string)($sale['sale_date_bs'] ?: date('Y-m-d', strtotime((string)$sale['created_at'])));
$saleDateTimeDisplay = $saleDateDisplay;
if(!empty($sale['created_at'])){
    $ts = strtotime((string)$sale['created_at']);
    if($ts !== false){
        $saleDateTimeDisplay = date('Y-m-d h:i A', $ts);
    }
}
$autoPrint = $autoPrint && $hasMedicineItems;
$netTotal = (float)$sale['total_amount'];
$discountAmount = (float)$sale['discount'];
$grossTotal = $netTotal + $discountAmount;
$ddaNo = (string)($pharmacy['dda_no'] ?? ($pharmacy['drug_license'] ?? '-'));
$customerAddress = '-';

if(!empty($sale['customer_id'])){
    $hasCustomerAddressCol = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='address'")->fetchColumn();
    if((int)$hasCustomerAddressCol > 0){
        $addrStmt = $pdo->prepare("SELECT address FROM customers WHERE id=? LIMIT 1");
        $addrStmt->execute([(int)$sale['customer_id']]);
        $addressVal = trim((string)($addrStmt->fetchColumn() ?: ''));
        if($addressVal !== ''){
            $customerAddress = $addressVal;
        }
    }
}

function number_to_words_en(float $amount): string {
    $amount = round($amount, 2);
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);

    $ones = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten', 11 => 'Eleven',
        12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
        17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];
    $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];

    $convert = function(int $num) use (&$convert, $ones, $tens): string {
        if($num < 20) return $ones[$num];
        if($num < 100){
            $ten = intdiv($num, 10);
            $rest = $num % 10;
            return $tens[$ten] . ($rest ? ' ' . $ones[$rest] : '');
        }
        if($num < 1000){
            $hundred = intdiv($num, 100);
            $rest = $num % 100;
            return $ones[$hundred] . ' Hundred' . ($rest ? ' ' . $convert($rest) : '');
        }
        if($num < 100000){
            $thousand = intdiv($num, 1000);
            $rest = $num % 1000;
            return $convert($thousand) . ' Thousand' . ($rest ? ' ' . $convert($rest) : '');
        }
        if($num < 10000000){
            $lakh = intdiv($num, 100000);
            $rest = $num % 100000;
            return $convert($lakh) . ' Lakh' . ($rest ? ' ' . $convert($rest) : '');
        }
        $crore = intdiv($num, 10000000);
        $rest = $num % 10000000;
        return $convert($crore) . ' Crore' . ($rest ? ' ' . $convert($rest) : '');
    };

    $text = 'Rupees ' . $convert($rupees);
    if($paise > 0){
        $text .= ' and ' . $convert($paise) . ' Paise';
    }
    return $text . ' Only';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($invoiceTitle . ' ' . (string)$sale['invoice_no']) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <link rel="shortcut icon" href="<?= h(get_base_url() . '/favicon.ico') ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #ffffff;
            display: block;
            min-height: 100vh;
            padding: 16px;
            color: #111827;
        }
        .bill {
            width: min(148mm, calc(100% - 32px));
            min-height: 210mm;
            background: #fff;
            padding: 12mm;
            border: 1px solid #d1d5db;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            position: relative;
            font-size: 9.5pt;
            line-height: 1.4;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .pharmacy h1 { font-size: 14pt; color: #0f766e; margin-bottom: 2px; font-weight: 600; }
        .pharmacy p { font-size: 9pt; color: #4b5563; margin: 1px 0; }
        .invoice-meta { text-align: right; }
        .invoice-meta .title { font-size: 13pt; font-weight: 600; color: #0f766e; }
        .invoice-meta .details { font-size: 9pt; margin-top: 4px; }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 9pt;
        }
        .label { font-weight: 500; color: #374151; display: block; margin-bottom: 2px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9.5pt; }
        th { background: #f9fafb; text-align: left; padding: 5px 4px; font-weight: 500; border-bottom: 1px solid #d1d5db; }
        td { padding: 4px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .amount-words { font-size: 9.5pt; color: #374151; margin-top: 8px; }

        .totals { display: flex; justify-content: flex-end; margin-bottom: 12px; }
        .totals-box { width: 65%; }
        .totals-row { display: flex; justify-content: space-between; padding: 2px 0; }
        .totals-row.grand {
            font-size: 9.5pt;
            font-weight: 500;
            border-top: 2px solid #0f766e;
            padding-top: 5px;
            margin-top: 4px;
            color: #0f766e;
        }

        .footer {
            border-top: 1px dashed #9ca3af;
            padding-top: 8px;
            font-size: 9.5pt;
            color: #6b7280;
            text-align: center;
            margin-top: 8px;
        }
        .footer p { margin: 2px 0; }

        .signature-qr {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 10px;
        }
        .cashier {
            text-align: left;
            font-size: 9.5pt;
            color: #374151;
        }
        .sig {
            border-top: 1px solid #374151;
            padding-top: 2px;
            width: 130px;
            text-align: left;
        }

        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #0f766e;
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 10pt;
            box-shadow: 0 3px 6px rgba(0,0,0,0.2);
        }
        .print-btn:hover { background: #0d9488; }

        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            background: #111827;
            color: #fff;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 6px;
            font-size: 9pt;
            box-shadow: 0 2px 6px rgba(0,0,0,0.18);
        }
        .back-btn:hover { background: #1f2937; }

        @media print {
            body {
                background: #fff;
                padding: 0;
                display: block;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .bill {
                box-shadow: none;
                margin: 0;
                width: 100%;
                min-height: auto;
                padding: 8mm;
            }
            .print-btn,
            .back-btn {
                display: none !important;
            }
            @page {
                size: A5 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <a href="<?= h($returnAfterPrintUrl) ?>" class="back-btn">Back to POS</a>

    <div class="bill">
        <?php if(!$hasMedicineItems): ?>
        <div style="margin-bottom: 12px; padding: 10px 12px; border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; border-radius: 8px; font-size: 9.5pt;">
            This invoice is kept for record only. All medicine items have been returned, so printing is disabled.
        </div>
        <?php endif; ?>

        <div class="header">
            <div class="pharmacy">
                <h1><?= e((string)($pharmacy['pharmacy_name'] ?? 'Pharmacy')) ?></h1>
                <p><?= e((string)($pharmacy['address'] ?? '')) ?> | <?= e((string)($pharmacy['phone_number'] ?? '-')) ?></p>
                <p>PAN: <?= e((string)($pharmacy['pan_vat'] ?? '-')) ?></p>
                <p>DDA No: <?= e($ddaNo !== '' ? $ddaNo : '-') ?></p>
            </div>
            <div class="invoice-meta">
                <div class="title"><?= $copyMode ? 'COPY INVOICE' : 'INVOICE' ?></div>
                <div class="details">
                    <div>Invoice #: <?= e((string)$sale['invoice_no']) ?></div>
                    <div>Date &amp; Time: <?= e($saleDateTimeDisplay) ?></div>
                </div>
            </div>
        </div>

        <div class="meta-grid">
            <div>
            
                <div><?= e((string)($sale['customer_name'] ?: 'Walk-In Customer')) ?></div>
                <div><?= e((string)($sale['customer_phone'] ?: '-')) ?></div>
                <div><?= e($customerAddress) ?></div>
            </div>
            <div style="text-align: right;">
                
                <div>Method: <?= e($paymentMethod) ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 35%">Medicine</th>
                    <th style="width: 14%">Batch</th>
                    <th style="width: 12%">Exp</th>
                    <th style="width: 9%" class="text-center">Qty</th>
                    <th style="width: 11%" class="text-right">Rate</th>
                    <th style="width: 14%" class="text-right">Amt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($items as $idx => $item): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= e((string)$item['product_name']) ?></td>
                    <td><?= e((string)($item['batch_no'] ?? '-')) ?></td>
                    <td><?= e(!empty($item['expiry_date']) ? date('M Y', strtotime((string)$item['expiry_date'])) : '-') ?></td>
                    <td class="text-center"><?= e((string)$item['quantity']) ?></td>
                    <td class="text-right"><?= npr((float)$item['sell_price']) ?></td>
                    <td class="text-right"><?= npr((float)$item['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-box">
                <div class="totals-row"><span>Gross Total:</span> <span><?= npr($grossTotal) ?></span></div>
                <div class="totals-row"><span>Discount:</span> <span>-<?= npr($discountAmount) ?></span></div>
                <div class="totals-row grand"><span>Net Total:</span> <span><?= npr($netTotal) ?></span></div>
                <div class="totals-row" style="margin-top: 4px; font-size: 9pt; color: #374151;"><span>Tender:</span> <span><?= npr((float)($sale['tender_amount'] ?? 0)) ?></span></div>
                <div class="totals-row" style="font-size: 9pt; color: #059669;"><span>Change:</span> <span><?= npr((float)($sale['change_amount'] ?? 0)) ?></span></div>
           <!--     <div class="totals-row" style="font-size: 9pt; color: #374151;"><span>Paid:</span> <span><?= npr((float)$sale['paid_amount']) ?></span></div> -->
                <?php if(strtolower((string)($sale['payment_method'] ?? '')) === 'credit'): ?>
                <div class="totals-row" style="font-size: 9pt; color: #b91c1c;"><span>Due:</span> <span><?= npr((float)$sale['due_amount']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="amount-words">Amount in words: <?= e(number_to_words_en($netTotal)) ?></div>

        <div class="footer">
            <div class="signature-qr">
                <div class="cashier">
                    <div>Cashier: <?= e((string)($sale['sold_by_name'] ?? $sale['sold_by_username'] ?? '-')) ?></div>
                </div>
                <div class="sig">Authorized Signature</div>
            </div><br><br>
            <p style="margin-top: 10px;"><?= nl2br(e($invoiceFooterNote)) ?></p>
        </div>
    </div>

    <?php if($hasMedicineItems): ?>
    <button class="print-btn" onclick="startPrintFlow()">Print / Save PDF</button>
    <?php endif; ?>

    <?php if($hasMedicineItems): ?>
    <script>
        (function(){
            var shouldAutoPrint = <?= $autoPrint ? 'true' : 'false' ?>;
            var waitingForPrintClose = false;
            var redirected = false;
            var redirectUrl = <?= json_encode($returnAfterPrintUrl, JSON_UNESCAPED_SLASHES) ?>;

            function redirectAfterPrint(){
                if(redirected) return;
                redirected = true;
                window.location.href = redirectUrl;
            }

            function onPrintClosed(){
                if(!waitingForPrintClose) return;
                setTimeout(redirectAfterPrint, 120);
            }

            function startPrintFlow(){
                if(waitingForPrintClose) return;
                waitingForPrintClose = true;
                try {
                    window.focus();
                    window.print();
                } catch (e) {
                    waitingForPrintClose = false;
                }
            }

            window.startPrintFlow = startPrintFlow;
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

            if(shouldAutoPrint){
                window.addEventListener('load', function(){
                    if(document.fonts && document.fonts.ready){
                        document.fonts.ready.then(function(){
                            requestAnimationFrame(function(){
                                setTimeout(startPrintFlow, 900);
                            });
                        });
                    } else {
                        requestAnimationFrame(function(){
                            setTimeout(startPrintFlow, 900);
                        });
                    }
                });

                setTimeout(startPrintFlow, 2200);
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>