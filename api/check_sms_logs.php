<?php
require_once __DIR__ . '/../config.php';

echo "<h1>SMS Logs</h1>";
echo "<pre>";

$stmt = $pdo->query("SELECT id, customer_id, phone_number, message, sms_type, status, error_message, response_data, created_at FROM sms_logs ORDER BY created_at DESC LIMIT 10");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total logs: " . count($logs) . "\n\n";

foreach($logs as $log) {
    echo "---\n";
    echo "ID: " . $log['id'] . "\n";
    echo "Type: " . $log['sms_type'] . "\n";
    echo "Phone: " . $log['phone_number'] . "\n";
    echo "Status: " . $log['status'] . "\n";
    echo "Error: " . $log['error_message'] . "\n";
    echo "Response: ";
    if(!empty($log['response_data'])) {
        $decodedResponse = json_decode($log['response_data'], true);
        if(json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($decodedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo $log['response_data'] . "\n";
        }
    } else {
        echo "(none)\n";
    }
    echo "Created: " . $log['created_at'] . "\n";
    echo "\n";
}
