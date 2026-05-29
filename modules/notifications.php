
<?php
require_once __DIR__ . '/../config.php';

$canViewCustomers = has_permission('customers.view') || is_admin_user();
if(!$canViewCustomers){
    flash_msg('You do not have permission to access notifications.', 'error');
    redirect_with_fallback('?module=sale');
}

$customerSearch = trim((string)($_GET['customer_search'] ?? ''));

function sms_credit_units(string $message): int {
    $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    return max(1, (int)ceil($length / 160));
}

function get_sms_pharmacy_name(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT pharmacy_name FROM pharmacy_details WHERE id = 1 LIMIT 1");
        $name = trim((string)$stmt->fetchColumn());
        if($name !== ''){
            return $name;
        }
    } catch(Throwable $e){
        // Fall back to app settings if pharmacy_details is unavailable.
    }

    $fallback = trim((string)get_app_setting('pharmacy_name', ''));
    return $fallback !== '' ? $fallback : 'Pharmacy';
}

function ensure_required_phrname_token(string $message): string {
    $message = trim($message);
    if($message === ''){
        return '{phrname}';
    }
    if(stripos($message, '{phrname}') !== false){
        return $message;
    }
    return rtrim($message) . ' - {phrname}';
}

$smsBalance = null;
$smsBalanceError = null;
if(is_sms_configured()){
    try {
        $balanceResult = get_sms_balance();
        if(!empty($balanceResult['success'])){
            $smsBalance = (int)($balanceResult['balance'] ?? 0);
        } else {
            $smsBalanceError = (string)($balanceResult['message'] ?? 'Unable to load balance');
        }
    } catch(Throwable $e){
        $smsBalanceError = $e->getMessage();
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && isset($_POST['send_custom_sms'])){
    $rawCustomerIds = $_POST['customer_ids'] ?? [];
    if(!is_array($rawCustomerIds)){
        $rawCustomerIds = [];
    }

    $customerIds = array_values(array_filter(array_map('intval', $rawCustomerIds), static function(int $value): bool {
        return $value > 0;
    }));

    $messageTemplate = trim((string)($_POST['custom_message'] ?? ''));
    if($messageTemplate === ''){
        flash_msg('Custom SMS message is required.', 'error');
        redirect_with_fallback('?module=notifications');
    }
    if(empty($customerIds)){
        flash_msg('Please select at least one customer.', 'error');
        redirect_with_fallback('?module=notifications');
    }

    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name, phone, current_due FROM customers WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($customerIds);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pharmacyNameForSms = get_sms_pharmacy_name($pdo);
    $messageTemplateWithRequiredName = ensure_required_phrname_token($messageTemplate);

    $sendQueue = [];
    $requiredCredits = 0;
    $failureCount = 0;
    $failedCustomers = [];

    foreach($customers as $customer){
        $phone = trim((string)($customer['phone'] ?? ''));
        if($phone === ''){
            $failureCount++;
            $failedCustomers[] = (string)$customer['name'] . ' (no phone)';
            continue;
        }

        $nameParts = preg_split('/\s+/', trim((string)$customer['name']), 2);
        $firstName = $nameParts[0] ?? (string)$customer['name'];

        $message = str_replace(
            ['{firstname}', '{fullname}', '{dueamt}', '{phone}', '{phrname}'],
            [$firstName, (string)$customer['name'], (string)($customer['current_due'] ?? 0), $phone, $pharmacyNameForSms],
            $messageTemplateWithRequiredName
        );

        $sendQueue[] = [
            'customer' => $customer,
            'phone' => $phone,
            'message' => $message,
        ];
        $requiredCredits += sms_credit_units($message);
    }

    if($requiredCredits <= 0){
        flash_msg('No valid SMS messages were prepared.', 'error');
        redirect_with_fallback('?module=notifications');
    }

    if($smsBalance !== null && $smsBalance < $requiredCredits){
        flash_msg('Insufficient SMS credits. Required: ' . $requiredCredits . ', Available: ' . $smsBalance, 'error');
        redirect_with_fallback('?module=notifications');
    }

    $successCount = 0;

    foreach($sendQueue as $item){
        $customer = $item['customer'];
        $phone = $item['phone'];
        $message = $item['message'];

        $sendResult = send_sms_notification($phone, $message);
        $responseData = isset($sendResult['data']) ? json_encode($sendResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        if($sendResult['success']){
            $successCount++;
            $logStmt = $pdo->prepare("\n                INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, response_data, sent_by, created_at)\n                VALUES (?, ?, ?, 'custom', 'success', ?, ?, NOW())\n            ");
            $logStmt->execute([
                $customer['id'],
                $phone,
                $message,
                $responseData,
                $_SESSION['uid'] ?? 0,
            ]);
        } else {
            $failureCount++;
            $failedCustomers[] = (string)$customer['name'] . ' (' . $phone . ')';
            $logStmt = $pdo->prepare("\n                INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, error_message, response_data, sent_by, created_at)\n                VALUES (?, ?, ?, 'custom', 'failed', ?, ?, ?, NOW())\n            ");
            $logStmt->execute([
                $customer['id'],
                $phone,
                $message,
                $sendResult['message'],
                $responseData,
                $_SESSION['uid'] ?? 0,
            ]);
        }
    }

    $flashMessage = $successCount . ' SMS sent successfully';
    if($failureCount > 0){
        $flashMessage .= ', ' . $failureCount . ' failed';
        if(count($failedCustomers) <= 5){
            $flashMessage .= ': ' . implode(', ', $failedCustomers);
        }
    }

    flash_msg($flashMessage, $failureCount > 0 ? 'error' : 'success');
    redirect_with_fallback('?module=notifications');
}

$allCustomers = $pdo->query("SELECT id, name, phone, address, current_due FROM customers ORDER BY current_due DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$custs = $allCustomers;
if($customerSearch !== ''){
    $stmt = $pdo->prepare("SELECT id, name, phone, address, current_due FROM customers WHERE name LIKE ? OR phone LIKE ? OR address LIKE ? ORDER BY current_due DESC, name ASC");
    $like = '%' . $customerSearch . '%';
    $stmt->execute([$like, $like, $like]);
    $custs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$defaultTemplate = trim((string)(get_app_setting('sms_template_custom') ?? ''));
if($defaultTemplate === ''){
    $defaultTemplate = '{custom_message}';
}

$pharmacyNameForSms = get_sms_pharmacy_name($pdo);

$availableCreditsText = 'Unavailable';
if($smsBalance !== null){
    $availableCreditsText = number_format($smsBalance) . ' credits';
}

?>
<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-bold text-slate-900">Notifications</h1>
        <p class="text-slate-600">Send custom SMS messages to customers from one place.</p>
    </div>

    <?php if($f = flash_msg()): ?>
        <div class="p-4 rounded-xl text-sm border <?= $f['type']=='error' ? 'bg-red-50 text-red-800 border-red-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200' ?>"><?= e((string)$f['msg']) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold text-sm text-slate-900">Overview</h2>
            <button type="button" class="text-xs font-medium text-primary hover:underline" onclick="refreshSmsBalance()">Refresh Balance</button>
        </div>
        <div class="flex gap-3 text-xs">
            <div class="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 min-w-0">
                <p class="text-slate-500 uppercase tracking-wider text-xs">Available Credit</p>
                <p id="availableCreditsCard" class="text-base font-bold text-slate-900 truncate"><?= e($availableCreditsText) ?></p>
            </div>
            <div class="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 min-w-0">
                <p class="text-slate-500 uppercase tracking-wider text-xs">Estimated Credit</p>
                <p id="estimatedCreditsCard" class="text-base font-bold text-slate-900 truncate">0</p>
            </div>
            <div class="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 min-w-0">
                <p class="text-slate-500 uppercase tracking-wider text-xs">Total Customer</p>
                <p id="selectedRecipients" class="text-base font-bold text-slate-900 truncate">0</p>
            </div>
            <div class="flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 min-w-0">
                <p class="text-slate-500 uppercase tracking-wider text-xs">Valid Numbers</p>
                <p class="text-base font-bold text-slate-900 truncate"><?= (int)count(array_filter($allCustomers, static function(array $customer): bool {
                    return (bool)preg_match('/^(\+977)?9[87][0-9]{8}$/', trim((string)($customer['phone'] ?? '')));
                })) ?></p>
            </div>
        </div>
        <div id="balanceError" class="<?= $smsBalanceError !== null ? '' : 'hidden' ?> text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2">
            <?= e($smsBalanceError !== null ? $smsBalanceError : '') ?>
        </div>
    </div>

    <form method="POST" class="grid lg:grid-cols-[1.15fr_0.85fr] gap-6 items-start">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="send_custom_sms" value="1">

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col max-h-[calc(100vh-13rem)]">
            <div class="p-5 border-b border-slate-100 bg-slate-50 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-semibold text-slate-900">Select Customers</h2>
                    <p class="text-sm text-slate-600">Pick recipients for the custom SMS.</p>
                </div>
                <div class="flex items-center gap-2">
                    <input type="hidden" name="module" value="notifications">
                    <input type="text" id="customerSearchInput" placeholder="Search name or phone..." class="w-64 max-w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>
            </div>

            <div class="p-5 border-b border-slate-100 flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 font-medium text-slate-700">
                    <input type="checkbox" id="selectAllCustomers" class="w-4 h-4" onchange="toggleAllCustomers()">
                    Select all
                </label>
                <span class="text-slate-500"><span id="selectedCount">0</span> selected</span>
            </div>

            <div class="flex-1 overflow-y-auto divide-y divide-slate-100 min-h-0">
                <?php if(empty($custs)): ?>
                    <div class="p-8 text-center text-slate-500">No customers found.</div>
                <?php else: ?>
                    <?php foreach($custs as $customer):
                        $phone = trim((string)($customer['phone'] ?? ''));
                        $phoneValid = (bool)preg_match('/^(\+977)?9[87][0-9]{8}$/', $phone);
                    ?>
                    <label class="customer-item flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer <?= $phoneValid ? '' : 'opacity-50' ?>" data-name="<?= e((string)$customer['name']) ?>" data-phone="<?= e($phone) ?>" data-address="<?= e((string)($customer['address'] ?? '')) ?>">
                        <input
                            type="checkbox"
                            name="customer_ids[]"
                            value="<?= (int)$customer['id'] ?>"
                            class="customer-checkbox mt-1 w-4 h-4"
                            <?= $phoneValid ? '' : 'disabled' ?>
                            data-name="<?= e((string)$customer['name']) ?>"
                            data-phone="<?= e($phone) ?>"
                            data-due="<?= e((string)($customer['current_due'] ?? 0)) ?>">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-slate-900"><?= e((string)$customer['name']) ?></span>
                                <span class="text-xs text-slate-500"><?= e($phone !== '' ? $phone : 'No phone') ?></span>
                            </div>
                            <div class="text-xs text-slate-600 mt-1 flex flex-wrap gap-3">
                                <?php if(!$phoneValid): ?><span class="text-red-600 font-medium">Invalid phone number</span><?php endif; ?>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4 sticky top-5">
                <div>
                    <h2 class="font-semibold text-slate-900">Custom Message</h2>
                    <p class="text-sm text-slate-600">Every message automatically ends with - {phrname} as it is required by NTA.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" class="variable-token px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700" data-token="{firstname}">{firstname}</button>
                    <button type="button" class="variable-token px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700" data-token="{fullname}">{fullname}</button>
                    <button type="button" class="variable-token px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700" data-token="{dueamt}">{dueamt}</button>
                    <button type="button" class="variable-token px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700" data-token="{phone}">{phone}</button>
                    <button type="button" class="variable-token px-2.5 py-1 text-xs font-medium rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700" data-token="{phrname}">{phrname}</button>
                </div>

                <textarea id="customMessage" name="custom_message" rows="7" required class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-sm whitespace-pre-wrap" placeholder="Type your message here..."></textarea>
                
                <div class="flex justify-between items-center text-xs text-slate-500 mt-2">
                    <span></span>
                    <span><span id="charCount">0</span>/160 characters</span>
                </div>

                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-2 mt-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Preview</p>
                    <div id="messagePreview" class="text-xs text-slate-800 font-mono break-words min-h-16 whitespace-pre-wrap"></div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="window.location='?module=notifications'" class="px-5 py-2.5 rounded-lg text-sm font-medium bg-slate-100 hover:bg-slate-200 text-slate-700">Reset</button>
                    <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-medium bg-primary hover:bg-teal-800 text-white">Send SMS</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
var pharmacyNameForSms = <?= json_encode($pharmacyNameForSms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function ensureRequiredPhrnameToken(message){
    var text = (message || '').trim();
    if(text === ''){
        return '{phrname}';
    }
    if(text.toLowerCase().indexOf('{phrname}') !== -1){
        return text;
    }
    return text.replace(/\s+$/, '') + ' - {phrname}';
}

function renderMessageTemplate(messageTemplate, selectedCustomer){
    var templateWithRequiredName = ensureRequiredPhrnameToken(messageTemplate);
    var name = '';
    var phone = '';
    var due = '0';

    if(selectedCustomer){
        name = selectedCustomer.dataset.name || '';
        phone = selectedCustomer.dataset.phone || '';
        due = selectedCustomer.dataset.due || '0';
    }

    var firstName = name.trim().split(/\s+/)[0] || name;
    return templateWithRequiredName
        .replace(/\{firstname\}/gi, firstName)
        .replace(/\{fullname\}/gi, name)
        .replace(/\{phone\}/gi, phone)
        .replace(/\{dueamt\}/gi, due)
        .replace(/\{phrname\}/gi, pharmacyNameForSms || 'Pharmacy');
}

function toggleAllCustomers(){
    var selectAll = document.getElementById('selectAllCustomers');
    document.querySelectorAll('.customer-checkbox:not(:disabled)').forEach(function(checkbox){
        checkbox.checked = selectAll.checked;
    });
    updateRecipientStats();
}

function updateRecipientStats(){
    var selectedCount = document.querySelectorAll('.customer-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selectedCount;
    document.getElementById('selectedRecipients').textContent = selectedCount;
    updateEstimatedCredits();
    updatePreview();
}

function estimateMessageCredits(message) {
    var length = message.length;
    return Math.max(1, Math.ceil(length / 160));
}

function updateEstimatedCredits() {
    var rawMessage = document.getElementById('customMessage').value;
    var normalizedMessage = ensureRequiredPhrnameToken(rawMessage);
    var selectedCheckboxes = document.querySelectorAll('.customer-checkbox:checked');
    
    if (selectedCheckboxes.length === 0) {
        document.getElementById('estimatedCreditsCard').textContent = '0';
        return;
    }

    var creditsPerMessage = estimateMessageCredits(normalizedMessage);
    var totalCredits = selectedCheckboxes.length * creditsPerMessage;
    document.getElementById('estimatedCreditsCard').textContent = totalCredits;
}

async function refreshSmsBalance() {
    try {
        var response = await fetch('api/sms_balance.php');
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        var data = await response.json();
        if (data.success && data.balance !== undefined) {
            var balanceText = data.balance + ' credit' + (data.balance !== 1 ? 's' : '');
            document.getElementById('availableCreditsCard').textContent = balanceText;
            var balanceError = document.getElementById('balanceError');
            if (balanceError) {
                balanceError.classList.add('hidden');
            }
        } else {
            throw new Error(data.message || 'Failed to fetch balance');
        }
    } catch (error) {
        var balanceError = document.getElementById('balanceError');
        if (balanceError) {
            balanceError.textContent = 'Failed to refresh balance: ' + error.message;
            balanceError.classList.remove('hidden');
        }
    }
}

function updatePreview(){
    var message = document.getElementById('customMessage').value || '';
    var charCountEl = document.getElementById('charCount');

    var firstSelected = document.querySelector('.customer-checkbox:checked');
    var preview = renderMessageTemplate(message, firstSelected);

    if(charCountEl){
        charCountEl.textContent = preview.length;
    }

    var previewEl = document.getElementById('messagePreview');
    if(preview){
        // Convert newlines to <br> tags and escape HTML
        var escaped = preview
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\n/g, '<br>');
        previewEl.innerHTML = escaped;
    } else {
        previewEl.textContent = '(Preview will appear here)';
    }
    updateEstimatedCredits();
}

function searchCustomers(){
    var searchInput = document.getElementById('customerSearchInput');
    var searchTerm = searchInput.value.toLowerCase().trim();
    var customerLabels = document.querySelectorAll('label.customer-item');
    var visibleCount = 0;

    customerLabels.forEach(function(label){
        var name = label.dataset.name ? label.dataset.name.toLowerCase() : '';
        var phone = label.dataset.phone ? label.dataset.phone.toLowerCase() : '';
        var address = label.dataset.address ? label.dataset.address.toLowerCase() : '';

        var matches = searchTerm === '' || name.includes(searchTerm) || phone.includes(searchTerm) || address.includes(searchTerm);

        if(matches){
            label.style.display = '';
            visibleCount++;
        } else {
            label.style.display = 'none';
        }
    });

    var selectAllCheckbox = document.getElementById('selectAllCustomers');
    if(selectAllCheckbox && visibleCount === 0){
        selectAllCheckbox.disabled = true;
    } else if(selectAllCheckbox){
        selectAllCheckbox.disabled = false;
    }
}

function insertTokenIntoMessage(token){
    var textarea = document.getElementById('customMessage');
    if(!textarea){
        return;
    }

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var currentValue = textarea.value || '';

    textarea.value = currentValue.slice(0, start) + token + currentValue.slice(end);
    var caret = start + token.length;
    textarea.focus();
    textarea.setSelectionRange(caret, caret);
    updatePreview();
}

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.customer-checkbox').forEach(function(checkbox){
        checkbox.addEventListener('change', function(){
            var visibleCheckboxes = Array.from(document.querySelectorAll('.customer-checkbox:not(:disabled)'));
            var checkedVisible = visibleCheckboxes.filter(function(item){ return item.checked; }).length;
            document.getElementById('selectAllCustomers').checked = visibleCheckboxes.length > 0 && checkedVisible === visibleCheckboxes.length;
            updateRecipientStats();
        });
    });

    document.getElementById('customMessage').addEventListener('input', updatePreview);

    document.querySelectorAll('.variable-token').forEach(function(button){
        button.addEventListener('click', function(){
            insertTokenIntoMessage(button.dataset.token || '');
        });
    });
    
        // Add real-time search
        var searchInput = document.getElementById('customerSearchInput');
        if(searchInput){
            searchInput.addEventListener('input', searchCustomers);
        }
    
    updateRecipientStats();
    updatePreview();
    updateEstimatedCredits();
});
</script>