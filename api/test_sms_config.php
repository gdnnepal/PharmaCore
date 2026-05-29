<?php
require_once __DIR__ . '/../config.php';

// Check SMS configuration
$provider = get_app_setting('sms_provider');
$apiKey = get_app_setting('sms_api_key');

echo "SMS Provider: " . (string)$provider . "\n";
echo "SMS API Key: " . (string)$apiKey . "\n";
echo "is_sms_configured(): " . (is_sms_configured() ? "TRUE" : "FALSE") . "\n";

if(class_exists('SmsHelper')) {
    echo "SmsHelper exists\n";
    echo "SmsHelper::getProvider(): " . SmsHelper::getProvider() . "\n";
    echo "SmsHelper::getApiKey(): " . SmsHelper::getApiKey() . "\n";
    echo "SmsHelper::isConfigured(): " . (SmsHelper::isConfigured() ? "TRUE" : "FALSE") . "\n";
}

// Query database directly
$stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('sms_provider', 'sms_api_key')");
echo "\nDatabase results:\n";
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['setting_key'] . " = " . $row['setting_value'] . "\n";
}
