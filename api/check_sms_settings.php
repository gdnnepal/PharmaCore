<?php
require_once __DIR__ . '/../config.php';

echo "<h1>Check SMS Settings in Database</h1>";
echo "<pre>";

$stmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' ORDER BY setting_key");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "SMS-related settings:\n\n";

foreach($results as $row) {
    echo "Key: " . $row['setting_key'] . "\n";
    echo "Value: " . (strlen($row['setting_value']) > 100 ? substr($row['setting_value'], 0, 100) . '...' : $row['setting_value']) . "\n";
    echo "Length: " . strlen($row['setting_value']) . "\n\n";
}

if(empty($results)) {
    echo "NO SMS SETTINGS FOUND IN DATABASE!\n\n";
    echo "Creating default settings...\n\n";
    
    // Insert default templates
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO app_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
    
    $dueSmsTemplate = 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
    $customSmsTemplate = '{custom_message}';
    
    $stmt->execute(['sms_template_due', $dueSmsTemplate]);
    echo "✓ Inserted sms_template_due\n";
    
    $stmt->execute(['sms_template_custom', $customSmsTemplate]);
    echo "✓ Inserted sms_template_custom\n";
    
    echo "\nVerifying...\n\n";
    
    $verify = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' ORDER BY setting_key");
    foreach($verify->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Key: " . $row['setting_key'] . "\n";
        echo "Value: " . $row['setting_value'] . "\n\n";
    }
}

echo "</pre>";
