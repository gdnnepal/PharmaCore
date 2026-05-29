<?php
require_once __DIR__ . '/../config.php';

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && isset($_POST['save_pharmacy_details'])){
    $pharmacyName = trim((string)($_POST['pharmacy_name'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $panVat = trim((string)($_POST['pan_vat'] ?? ''));
    $ddaNo = trim((string)($_POST['dda_no'] ?? ''));

    if($pharmacyName === '' || $address === '' || $phoneNumber === '' || $panVat === '' || $ddaNo === ''){
        flash_msg('Please fill all required pharmacy details.', 'error');
    } else {
        $stmt = $pdo->prepare("INSERT INTO pharmacy_details(id, pharmacy_name, address, phone_number, email, pan_vat, dda_no) VALUES(1,?,?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE pharmacy_name=VALUES(pharmacy_name), address=VALUES(address), phone_number=VALUES(phone_number), email=VALUES(email), pan_vat=VALUES(pan_vat), dda_no=VALUES(dda_no)");
        $stmt->execute([$pharmacyName, $address, $phoneNumber, $email !== '' ? $email : null, $panVat, $ddaNo]);
        flash_msg('Pharmacy details saved successfully.');
    }

    redirect_with_fallback('?module=settings');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && isset($_POST['save_ui_settings'])){
    $showPosMenu = isset($_POST['show_pos_menu']) ? '1' : '0';
    $currencyCatalog = get_currency_catalog();
    $currencyCode = strtoupper(trim((string)($_POST['currency_code'] ?? 'NPR')));
    if(!isset($currencyCatalog[$currencyCode])) $currencyCode = 'NPR';

    $availableTimezones = timezone_identifiers_list();
    $appTimezone = trim((string)($_POST['app_timezone'] ?? 'Asia/Kathmandu'));
    if(!in_array($appTimezone, $availableTimezones, true)) $appTimezone = 'Asia/Kathmandu';
    $invoicePrefixRaw = strtoupper(trim((string)($_POST['invoice_prefix'] ?? '')));
    $invoicePrefix = preg_replace('/[^A-Z0-9_-]/', '', $invoicePrefixRaw);
    $invoicePrefix = substr($invoicePrefix, 0, 20);

    $defaultInvoiceFooterNote = "Thank you for your purchase. Store medicines in a cool, dry place.\nItems once sold cannot be returned without original receipt.";
    $invoiceFooterNote = trim((string)($_POST['invoice_footer_note'] ?? ''));
    $invoiceFooterNote = str_replace(["\r\n", "\r"], "\n", $invoiceFooterNote);
    if($invoiceFooterNote === '') $invoiceFooterNote = $defaultInvoiceFooterNote;

    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('show_pos_menu', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$showPosMenu]);
    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('currency_code', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$currencyCode]);
    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('app_timezone', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$appTimezone]);
    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('invoice_prefix', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$invoicePrefix]);
    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('invoice_footer_note', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$invoiceFooterNote]);

    flash_msg('UI settings saved successfully.');
    redirect_with_fallback('?module=settings');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && isset($_POST['save_sms_templates'])){
    $dueSmsTemplate = trim((string)($_POST['sms_template_due'] ?? ''));
    $customSmsTemplate = trim((string)($_POST['sms_template_custom'] ?? ''));

    $defaultDueTemplate = 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
    $defaultCustomTemplate = '{custom_message}';

    if($dueSmsTemplate === ''){
        $dueSmsTemplate = $defaultDueTemplate;
    }
    if($customSmsTemplate === ''){
        $customSmsTemplate = $defaultCustomTemplate;
    }

    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('sms_template_due', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$dueSmsTemplate]);
    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('sms_template_custom', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$customSmsTemplate]);

    flash_msg('SMS templates saved successfully.');
    redirect_with_fallback('?module=settings');
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && isset($_POST['save_notification_settings'])){
    $smsProvider = strtolower(trim((string)($_POST['sms_provider'] ?? 'none')));
    $allowedProviders = ['none', 'spellcpaas'];
    if(!in_array($smsProvider, $allowedProviders, true)) $smsProvider = 'none';

    $smsApiKey = trim((string)($_POST['sms_api_key'] ?? ''));
    if($smsProvider !== 'none' && $smsApiKey === ''){
        flash_msg('SMS API key is required when a provider is selected.', 'error');
        redirect_with_fallback('?module=settings');
    }

    $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('sms_provider', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute([$smsProvider]);

    if($smsApiKey !== ''){
        $stmt = $pdo->prepare("INSERT INTO app_settings(setting_key, setting_value) VALUES('sms_api_key', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $stmt->execute([$smsApiKey]);
    }

    flash_msg('Notification settings saved successfully.');
    redirect_with_fallback('?module=settings');
}

$pharmacyStmt = $pdo->query("SELECT * FROM pharmacy_details WHERE id=1 LIMIT 1");
$pharmacyDetails = $pharmacyStmt ? ($pharmacyStmt->fetch() ?: null) : null;
$uiSettingsStmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('show_pos_menu','currency_code','app_timezone','invoice_prefix','invoice_footer_note','sms_provider','sms_api_key','sms_template_due','sms_template_custom')");
$uiSettingsRows = $uiSettingsStmt ? $uiSettingsStmt->fetchAll() : [];
$uiSettings = [];
foreach($uiSettingsRows as $row){
    $uiSettings[(string)$row['setting_key']] = (string)$row['setting_value'];
}
$showPosMenu = (($uiSettings['show_pos_menu'] ?? '1') === '1');
$currencyCatalog = get_currency_catalog();
$selectedCurrencyCode = strtoupper(trim((string)($uiSettings['currency_code'] ?? 'NPR')));
if(!isset($currencyCatalog[$selectedCurrencyCode])) $selectedCurrencyCode = 'NPR';

$availableTimezones = timezone_identifiers_list();
$selectedTimezone = trim((string)($uiSettings['app_timezone'] ?? 'Asia/Kathmandu'));
if(!in_array($selectedTimezone, $availableTimezones, true)) $selectedTimezone = 'Asia/Kathmandu';

$invoicePrefix = strtoupper(trim((string)($uiSettings['invoice_prefix'] ?? '')));
$invoicePrefix = preg_replace('/[^A-Z0-9_-]/', '', $invoicePrefix);

$defaultInvoiceFooterNote = "Thank you for your purchase. Store medicines in a cool, dry place.\nItems once sold cannot be returned without original receipt.";
$invoiceFooterNote = trim((string)($uiSettings['invoice_footer_note'] ?? ''));
if($invoiceFooterNote === '') $invoiceFooterNote = $defaultInvoiceFooterNote;

// Notification settings
$selectedSmsProvider = trim((string)($uiSettings['sms_provider'] ?? 'none'));
$smsApiKey = trim((string)($uiSettings['sms_api_key'] ?? ''));

// Get SMS balance if Spellc PAAS is configured
// Note: Wrapped in timeout to prevent page hanging on API calls
$smsBalance = null;
$smsBalanceError = null;
if($selectedSmsProvider === 'spellcpaas' && $smsApiKey !== ''){
    // Only attempt to fetch balance if we're actually viewing the settings page
    // Use set_time_limit to prevent timeout if API is slow
    $previousTimeLimit = ini_get('max_execution_time');
    @set_time_limit(30); // Give API 30 seconds max
    
    try {
        $balanceResult = get_sms_balance();
        if($balanceResult['success']){
            $smsBalance = $balanceResult['data'];
        } else {
            $smsBalanceError = $balanceResult['message'];
        }
    } catch(Exception $e) {
        $smsBalanceError = 'Error fetching balance: ' . $e->getMessage();
        error_log('[SMS Balance] Exception: ' . $e->getMessage());
    }
    
    @set_time_limit((int)$previousTimeLimit);
}

$f = flash_msg();
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-900">Settings</h1>
        <p class="text-slate-600 mt-2">Manage pharmacy details, application settings, and notifications</p>
    </div>

    <?php if($f): ?>
        <div class="flex items-start gap-3 p-4 rounded-xl text-sm border shadow-sm <?= $f['type']=='error'?'bg-red-50 text-red-800 border-red-200':'bg-emerald-50 text-emerald-800 border-emerald-200' ?>">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <?php if($f['type']=='error'): ?>
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                <?php else: ?>
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                <?php endif; ?>
            </svg>
            <span class="flex-1"><?= e((string)$f['msg']) ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <div class="border-b border-slate-200">
            <nav class="flex overflow-x-auto" role="tablist" aria-label="Settings Tabs">
                <button type="button" id="settingsTabBtnPharmacy" class="settings-tab-btn flex-shrink-0 px-6 py-4 text-sm font-medium border-b-2 transition-all duration-200 border-primary text-primary" data-tab="settingsTabPharmacy">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span>Pharmacy Details</span>
                    </div>
                </button>
                <button type="button" id="settingsTabBtnUi" class="settings-tab-btn flex-shrink-0 px-6 py-4 text-sm font-medium border-b-2 transition-all duration-200 border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300" data-tab="settingsTabUi">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>App Settings</span>
                    </div>
                </button>
                <button type="button" id="settingsTabBtnNotifications" class="settings-tab-btn flex-shrink-0 px-6 py-4 text-sm font-medium border-b-2 transition-all duration-200 border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300" data-tab="settingsTabNotifications">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <span>Notifications</span>
                    </div>
                </button>
            </nav>
        </div>

        <div class="p-8">
            <div id="settingsTabPharmacy" class="settings-tab-panel">
                <div class="mb-8">
                    <h2 class="text-xl font-semibold text-slate-900 mb-2">Pharmacy Information</h2>
                    <p class="text-sm text-slate-600">Manage your pharmacy business details and contact information</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_pharmacy_details" value="1">

                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                Pharmacy Name <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text"
                                name="pharmacy_name" 
                                required 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['pharmacy_name'] ?? '')) ?>"
                                placeholder="Enter pharmacy name">
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                Phone Number <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="tel"
                                name="phone_number" 
                                required 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['phone_number'] ?? '')) ?>"
                                placeholder="Enter phone number">
                        </div>

                        <div class="md:col-span-2 space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                Address <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text"
                                name="address" 
                                required 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['address'] ?? '')) ?>"
                                placeholder="Enter complete address">
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                Email Address
                            </label>
                            <input 
                                type="email"
                                name="email" 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['email'] ?? '')) ?>"
                                placeholder="pharmacy@example.com">
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                PAN/VAT Number <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text"
                                name="pan_vat" 
                                required 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['pan_vat'] ?? '')) ?>"
                                placeholder="Enter PAN/VAT number">
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">
                                DDA Registration Number <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text"
                                name="dda_no" 
                                required 
                                class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all" 
                                value="<?= e((string)($pharmacyDetails['dda_no'] ?? '')) ?>"
                                placeholder="Enter DDA registration number">
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-between pt-6 border-t border-slate-200">
                        <p class="text-xs text-slate-500">Fields marked with <span class="text-red-500">*</span> are required</p>
                        <button type="submit" class="inline-flex items-center gap-2 bg-primary hover:bg-teal-800 text-white px-6 py-2.5 rounded-lg text-sm font-medium transition-colors shadow-sm hover:shadow">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div id="settingsTabUi" class="settings-tab-panel hidden">
                <div class="mb-8">
                    <h2 class="text-xl font-semibold text-slate-900 mb-2">Application Preferences</h2>
                    <p class="text-sm text-slate-600">Configure display options and regional settings for your application</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_ui_settings" value="1">

                    <div class="space-y-8">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">Menu Visibility</h3>
                            <div class="grid md:grid-cols-1 gap-4">
                                <label class="relative flex items-start gap-4 p-4 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg cursor-pointer transition-colors group">
                                    <div class="flex items-center h-5">
                                        <input 
                                            type="checkbox" 
                                            name="show_pos_menu" 
                                            value="1" 
                                            class="h-4 w-4 text-primary border-slate-300 rounded focus:ring-2 focus:ring-offset-0 focus:ring-primary transition" 
                                            <?= $showPosMenu ? 'checked' : '' ?>>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium text-slate-900 group-hover:text-primary transition-colors">Show POS Billing</span>
                                        <span class="block text-xs text-slate-600 mt-0.5">Display point of sale menu in sidebar navigation</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">Regional Settings</h3>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">
                                        Currency
                                    </label>
                                    <select 
                                        name="currency_code" 
                                        class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
                                        <?php foreach($currencyCatalog as $code => $meta): ?>
                                            <option value="<?= e((string)$code) ?>" <?= $selectedCurrencyCode === $code ? 'selected' : '' ?>>
                                                <?= e((string)$code . ' - ' . (string)$meta['name'] . ' (' . (string)$meta['symbol'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-slate-500 mt-1.5">Default currency for all transactions and displays</p>
                                </div>

                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">
                                        Time Zone
                                    </label>
                                    <select 
                                        name="app_timezone" 
                                        class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all">
                                        <?php foreach($availableTimezones as $tz): ?>
                                            <option value="<?= e((string)$tz) ?>" <?= $selectedTimezone === $tz ? 'selected' : '' ?>>
                                                <?= e((string)$tz) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-slate-500 mt-1.5">Applied to dates, timestamps, and report generation</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">Invoice Configuration</h3>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">
                                        Invoice Number Prefix
                                    </label>
                                    <input 
                                        type="text"
                                        name="invoice_prefix" 
                                        maxlength="20" 
                                        class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all font-mono tracking-wider" 
                                        value="<?= e($invoicePrefix) ?>" 
                                        placeholder="INV">
                                    <p class="text-xs text-slate-500 mt-1.5">Allowed characters: A-Z, 0-9, underscore, hyphen (max 20 chars)</p>
                                </div>

                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">
                                        Invoice Footer Note
                                    </label>
                                    <textarea 
                                        name="invoice_footer_note" 
                                        rows="3" 
                                        class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all resize-none" 
                                        placeholder="Footer note shown in printed invoice"><?= e($invoiceFooterNote) ?></textarea>
                                    <p class="text-xs text-slate-500 mt-1.5">Displayed at the bottom of printed invoices</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-end pt-6 border-t border-slate-200">
                        <button type="submit" class="inline-flex items-center gap-2 bg-primary hover:bg-teal-800 text-white px-6 py-2.5 rounded-lg text-sm font-medium transition-colors shadow-sm hover:shadow">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div id="settingsTabNotifications" class="settings-tab-panel hidden">
                <div class="mb-8">
                    <h2 class="text-xl font-semibold text-slate-900 mb-2">SMS Notifications</h2>
                    <p class="text-sm text-slate-600">Configure SMS service provider for sending notifications to customers and staff</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="save_notification_settings" value="1">

                    <div class="space-y-6">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">SMS Provider Selection</h3>
                            
                            <div class="space-y-3">
                                <label class="relative flex items-start gap-4 p-4 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg cursor-pointer transition-colors group">
                                    <div class="flex items-center h-5 pt-0.5">
                                        <input 
                                            type="radio" 
                                            name="sms_provider" 
                                            value="none" 
                                            class="h-4 w-4 text-primary border-slate-300 focus:ring-2 focus:ring-primary transition" 
                                            <?= $selectedSmsProvider === 'none' ? 'checked' : '' ?>>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium text-slate-900 group-hover:text-primary transition-colors">None (Disabled)</span>
                                        <span class="block text-xs text-slate-600 mt-0.5">SMS notifications are disabled</span>
                                    </div>
                                </label>

                                <label class="relative flex items-start gap-4 p-4 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg cursor-pointer transition-colors group">
                                    <div class="flex items-center h-5 pt-0.5">
                                        <input 
                                            type="radio" 
                                            name="sms_provider" 
                                            value="spellcpaas" 
                                            class="h-4 w-4 text-primary border-slate-300 focus:ring-2 focus:ring-primary transition" 
                                            <?= $selectedSmsProvider === 'spellcpaas' ? 'checked' : '' ?>>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium text-slate-900 group-hover:text-primary transition-colors">Spellc PAAS</span>
                                        <span class="block text-xs text-slate-600 mt-0.5">Requires API Key. Contact Spellcpass (Mamata Luitel - +977-9801047726) for API Key.</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div id="spellcpaasApiKeySection" class="<?= $selectedSmsProvider === 'spellcpaas' ? '' : 'hidden' ?>">
                            <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">API Configuration</h3>
                            
                            <div class="bg-slate-50 border border-slate-300 rounded-lg p-4 mb-4">
                                <div class="flex gap-3">
                                    <svg class="w-4 h-4 text-slate-600 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <div class="text-sm text-slate-700 space-y-1">
                                        <p class="font-medium">Spellc PAAS API Key Required</p>
                                        <p class="text-xs text-slate-600">Contact Spellcpass (Mamata Luitel - +977-9801047726) for API Key.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-slate-700">
                                    API Key <span class="text-red-500">*</span>
                                </label>
                                <input 
                                    type="password"
                                    name="sms_api_key" 
                                    class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all font-mono text-sm tracking-wider" 
                                    value="<?= e($smsApiKey) ?>"
                                    placeholder="your-spellcpaas-api-key-here"
                                    <?= $selectedSmsProvider === 'spellcpaas' ? 'required' : '' ?>>
                                <p class="text-xs text-slate-500 mt-1.5">Your API key is encrypted and stored securely. Only admins can view it.</p>
                            </div>
                        </div>

                        <?php if($selectedSmsProvider === 'spellcpaas'): ?>
                            <div class="border-t border-slate-200 pt-6">
                                <h3 class="text-sm font-semibold text-slate-900 mb-4 uppercase tracking-wider">Credit Left</h3>

                                <?php if($smsApiKey === ''): ?>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                                        <div class="flex items-start gap-3">
                                            <svg class="w-4 h-4 text-slate-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-slate-800">Enter API key to view balance and send SMS</p>
                                                <p class="mt-1 text-xs text-slate-600">Save your Spellc PAAS API key above to check balance and usage statistics.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif($smsBalance): ?>
                                    <?php
                                        $credits = (int)($smsBalance['credits'] ?? 0);
                                    ?>

                                    <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-5">
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Available Credits</p>
                                            <p class="mt-2 text-2xl font-bold text-slate-500"><?= number_format($credits) ?></p>
                                            <p class="mt-1 text-xs text-slate-600">SMS credits available for sending</p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-5 overflow-hidden">
                                        <div class="flex items-start gap-3">
                                            <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-amber-900">Could not fetch balance</p>
                                                <p class="mt-1 text-xs text-amber-800 break-words whitespace-normal"><?= e($smsBalanceError ?? 'Unknown error occurred') ?></p>
                                                <p class="mt-2 text-xs text-amber-700 break-words whitespace-normal">Please verify your API key and try again.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-8 flex items-center justify-between pt-6 border-t border-slate-200">
                        <div></div>
                        <div class="flex gap-3">
                            <p></p>
                            <button type="submit" class="inline-flex items-center gap-2 bg-primary hover:bg-teal-800 text-white px-6 py-2.5 rounded-lg text-sm font-medium transition-colors shadow-sm hover:shadow">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSmsProviderSections(){
    const provider = document.querySelector('input[name="sms_provider"]:checked')?.value || 'none';
    const spellcpaasSection = document.getElementById('spellcpaasApiKeySection');
    const apiKeyInput = document.querySelector('input[name="sms_api_key"]');
    
    // Handle Spellc PAAS section
    if(spellcpaasSection){
        if(provider === 'spellcpaas'){
            spellcpaasSection.classList.remove('hidden');
        } else {
            spellcpaasSection.classList.add('hidden');
        }
    }
    
    // Handle required attribute on API key input
    if(apiKeyInput){
        if(provider === 'none'){
            apiKeyInput.removeAttribute('required');
        } else {
            apiKeyInput.setAttribute('required', 'required');
        }
    }
}

document.querySelectorAll('input[name="sms_provider"]').forEach(radio => {
    radio.addEventListener('change', toggleSmsProviderSections);
});

function activateSettingsTab(tabId){
    const panels = document.querySelectorAll('.settings-tab-panel');
    const buttons = document.querySelectorAll('.settings-tab-btn');

    panels.forEach(panel => panel.classList.add('hidden'));
    
    buttons.forEach(btn => {
        btn.classList.remove('border-primary', 'text-primary');
        btn.classList.add('border-transparent', 'text-slate-600', 'hover:text-slate-900', 'hover:border-slate-300');
    });

    const activePanel = document.getElementById(tabId);
    const activeBtn = document.querySelector('.settings-tab-btn[data-tab="' + tabId + '"]');
    
    if(activePanel) activePanel.classList.remove('hidden');
    if(activeBtn){
        activeBtn.classList.remove('border-transparent', 'text-slate-600', 'hover:text-slate-900', 'hover:border-slate-300');
        activeBtn.classList.add('border-primary', 'text-primary');
    }
}

document.querySelectorAll('.settings-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => activateSettingsTab(btn.getAttribute('data-tab')));
});

// Initialize the first tab on page load
document.addEventListener('DOMContentLoaded', () => {
    // Activate the first settings tab
    activateSettingsTab('settingsTabPharmacy');
    
    // Initialize SMS provider sections
    toggleSmsProviderSections();
});
</script>