<?php
require_once __DIR__ . '/../config.php';

// Check all the conditions that control button visibility
$canCreateCustomers = has_permission('customers.view') || is_admin_user();
$canManagePayments = has_permission('customers.payment') || is_admin_user();
$smsConfigured = is_sms_configured();

echo "<h1>Button Visibility Debug</h1>";
echo "<pre>";
echo "canCreateCustomers: " . ($canCreateCustomers ? "TRUE" : "FALSE") . "\n";
echo "canManagePayments: " . ($canManagePayments ? "TRUE" : "FALSE") . "\n";
echo "is_sms_configured(): " . ($smsConfigured ? "TRUE" : "FALSE") . "\n";
echo "Main condition (canCreateCustomers || canManagePayments): " . (($canCreateCustomers || $canManagePayments) ? "TRUE" : "FALSE") . "\n";
echo "\nSMS Configuration Details:\n";
echo "Provider: " . get_app_setting('sms_provider') . "\n";
echo "API Key: " . substr((string)get_app_setting('sms_api_key'), 0, 10) . "...\n";

if(class_exists('SmsHelper')) {
    echo "\nSmsHelper Status:\n";
    echo "getProvider(): " . SmsHelper::getProvider() . "\n";
    echo "getApiKey(): " . substr(SmsHelper::getApiKey(), 0, 10) . "...\n";
    echo "isConfigured(): " . (SmsHelper::isConfigured() ? "TRUE" : "FALSE") . "\n";
}
echo "</pre>";
