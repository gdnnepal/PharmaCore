<?php
require_once __DIR__ . '/../config.php';

echo "Inserting SMS templates...\n";

$stmt = $pdo->prepare('INSERT OR REPLACE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');

$stmt->execute(['sms_template_due', 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.']);
echo "✓ sms_template_due inserted\n";

$stmt->execute(['sms_template_custom', '{custom_message}']);
echo "✓ sms_template_custom inserted\n";

echo "\nVerifying...\n";

$verify = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' ORDER BY setting_key");
foreach($verify->fetchAll() as $row) {
    echo "\n" . $row['setting_key'] . ": " . $row['setting_value'] . "\n";
}

echo "\n\nDone!\n";
