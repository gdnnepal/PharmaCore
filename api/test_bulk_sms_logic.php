<?php
require_once __DIR__ . '/../config.php';

echo "<h1>Bulk SMS Test - Simulating Form Submission</h1>";
echo "<pre>";

// Simulate the POST request
$_POST['sms_type'] = 'due';
$_POST['customer_ids'] = [];  // Empty for due SMS (will fetch all with due)
$_SESSION['uid'] = 1;  // Set user ID

// Call the send_bulk_sms logic directly
$smsType = trim((string)($_POST['sms_type'] ?? ''));
$customMessage = trim((string)($_POST['custom_message'] ?? ''));
$selectedCustomerIds = isset($_POST['customer_ids']) ? (array)$_POST['customer_ids'] : [];

echo "sms_type: " . $smsType . "\n";
echo "custom_message: " . $customMessage . "\n";
echo "customer_ids: " . json_encode($selectedCustomerIds) . "\n\n";

// Get SMS templates
$dueSmsTemplate = get_app_setting('sms_template_due') ?? 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
echo "Template: " . $dueSmsTemplate . "\n\n";

if($smsType === 'due'){
    // Get due customers
    $dueCustomersStmt = $pdo->query("
        SELECT DISTINCT c.id, c.name,
               COALESCE(SUM(CASE WHEN s.status = 'credit' OR s.status = 'partial' THEN s.due_amount ELSE 0 END), 0) as due_amount
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id
        WHERE s.status IN ('credit', 'partial')
        GROUP BY c.id, c.name
        HAVING due_amount > 0
        ORDER BY c.name ASC
    ");
    $dueCustomers = $dueCustomersStmt ? $dueCustomersStmt->fetchAll() : [];
    
    echo "Found " . count($dueCustomers) . " customers with due:\n";
    foreach($dueCustomers as $cust) {
        echo "  - " . $cust['name'] . ": Rs. " . $cust['due_amount'] . "\n";
    }
    echo "\n";
    
    // Get phones
    $dueCustomerIds = array_column($dueCustomers, 'id');
    if(!empty($dueCustomerIds)){
        $placeholders = array_fill(0, count($dueCustomerIds), '?');
        $phoneStmt = $pdo->prepare("SELECT id, phone FROM customers WHERE id IN (" . implode(',', $placeholders) . ")");
        $phoneStmt->execute($dueCustomerIds);
        $phoneMap = [];
        foreach($phoneStmt->fetchAll() as $row){
            $phoneMap[$row['id']] = $row['phone'];
        }
        foreach($dueCustomers as &$customer){
            $customer['phone'] = $phoneMap[$customer['id']] ?? '';
        }
    }
    
    $customersToSend = $dueCustomers;
}

echo "Customers to send: " . count($customersToSend) . "\n";
echo json_encode($customersToSend, JSON_PRETTY_PRINT) . "\n\n";

// Validate phones
$validCustomers = [];
foreach($customersToSend as $customer){
    $phone = (string)($customer['phone'] ?? '');
    if(preg_match('/^(\+977)?9[87][0-9]{8}$/', $phone)){
        $phone = preg_replace('/^\+977/', '', $phone);
        if(strlen($phone) === 10){
            $validCustomers[] = [
                'id' => $customer['id'],
                'name' => $customer['name'],
                'phone' => $phone,
                'due_amount' => $customer['due_amount'] ?? 0,
            ];
        }
    }
}

echo "Valid customers: " . count($validCustomers) . "\n";
echo json_encode($validCustomers, JSON_PRETTY_PRINT) . "\n\n";

// Test message substitution
if(!empty($validCustomers)){
    $messageTemplate = $dueSmsTemplate;
    foreach($validCustomers as $customer){
        $message = $messageTemplate;
        $nameParts = explode(' ', $customer['name'], 2);
        $firstName = $nameParts[0];
        $message = str_replace('{firstname}', $firstName, $message);
        $message = str_replace('{fullname}', $customer['name'], $message);
        $message = str_replace('{dueamt}', (string)($customer['due_amount'] ?? 0), $message);
        $message = str_replace('{phone}', $customer['phone'], $message);
        
        echo "Customer: " . $customer['name'] . "\n";
        echo "Message: " . $message . "\n";
        echo "Message empty? " . (empty($message) ? "YES" : "NO") . "\n";
        echo "Message length: " . strlen($message) . "\n\n";
        
        // Try to send
        $result = send_sms_notification($customer['phone'], $message);
        echo "Send result:\n";
        echo "  success: " . ($result['success'] ? "TRUE" : "FALSE") . "\n";
        echo "  message: " . $result['message'] . "\n\n";
    }
}

echo "</pre>";
