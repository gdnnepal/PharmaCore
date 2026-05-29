<?php
require_once __DIR__ . '/../config.php';

try {
    echo "Attempting to insert SMS templates...\n\n";
    
    // Check if settings already exist
    $check = $pdo->prepare("SELECT id FROM app_settings WHERE setting_key = ?");
    
    $settingsToInsert = [
        'sms_template_due' => 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.',
        'sms_template_custom' => '{custom_message}',
    ];
    
    foreach($settingsToInsert as $key => $value) {
        $check->execute([$key]);
        if($check->fetch()) {
            // Update existing
            $update = $pdo->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
            $update->execute([$value, $key]);
            echo "✓ Updated $key\n";
        } else {
            // Insert new
            $insert = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            $insert->execute([$key, $value]);
            echo "✓ Inserted $key\n";
        }
    }
    
    echo "\n\nVerifying all SMS settings:\n";
    
    $verify = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' ORDER BY setting_key");
    $results = $verify->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($results) . " settings:\n\n";
    
    foreach($results as $row) {
        echo "Key: " . $row['setting_key'] . "\n";
        echo "Value: " . substr($row['setting_value'], 0, 80) . (strlen($row['setting_value']) > 80 ? '...' : '') . "\n\n";
    }
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
