<?php
require_once __DIR__ . '/../config.php';

try {
    echo "Checking app_settings table schema:\n\n";
    
    // Get table structure
        $result = $pdo->query("DESCRIBE app_settings");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columns:\n";
        foreach($columns as $col) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")" . ($col['Null'] == 'NO' ? " NOT NULL" : "") . "\n";
    }
    
    echo "\n\nInserting templates using correct columns...\n\n";
    
    $settingsToInsert = [
        'sms_template_due' => 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.',
        'sms_template_custom' => '{custom_message}',
    ];
    
    foreach($settingsToInsert as $key => $value) {
        $check = $pdo->prepare("SELECT * FROM app_settings WHERE setting_key = ?");
        $check->execute([$key]);
        if($check->fetch()) {
            // Update
            $update = $pdo->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
            $update->execute([$value, $key]);
            echo "✓ Updated $key\n";
        } else {
            // Insert
            $insert = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            $insert->execute([$key, $value]);
            echo "✓ Inserted $key\n";
        }
    }
    
    echo "\n✓ All SMS templates successfully saved!\n\n";
    
    echo "Verifying:\n";
    $verify = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE '%sms%' ORDER BY setting_key");
    foreach($verify->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "  " . $row['setting_key'] . ": " . substr($row['setting_value'], 0, 60) . "...\n";
    }
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
