<?php
declare(strict_types=1);

// Set up error handling first
set_error_handler(function($errno, $errstr, $errfile, $errline){
    error_log("[SMS API] Error: [$errno] $errstr in $errfile:$errline");
    return true;
});

register_shutdown_function(function(){
    $error = error_get_last();
    if($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])){
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'A server error occurred. Please try again.',
        ]);
    }
});

require_once __DIR__ . '/../config.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if(!isset($_SESSION['uid'])){
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Login required.']);
    exit;
}

// Only admins or users with settings.manage permission may send bulk SMS
if(!is_admin_user() && !has_permission('settings.manage')){
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied. You are not allowed to send bulk SMS.']);
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()){
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$smsType = trim((string)($_POST['sms_type'] ?? ''));
$customMessage = trim((string)($_POST['custom_message'] ?? ''));
$selectedCustomerIds = isset($_POST['customer_ids']) ? (array)$_POST['customer_ids'] : [];
// H-7: Cast all IDs to positive integers — prevents type confusion in SQL IN() clause
$selectedCustomerIds = array_values(array_filter(array_map('intval', $selectedCustomerIds), fn($v) => $v > 0));

if($smsType === ''){
    echo json_encode(['success' => false, 'message' => 'SMS type not specified']);
    exit;
}

if(!is_sms_configured()){
    echo json_encode(['success' => false, 'message' => 'SMS is not configured']);
    exit;
}

// Get SMS templates from settings
$dueSmsTemplate = get_app_setting('sms_template_due') ?? 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
$customSmsTemplate = get_app_setting('sms_template_custom') ?? '{custom_message}';

if($smsType === 'due'){
    // Send SMS to all customers with due amounts
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
    
    // Fetch phone numbers for due customers
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
    $messageTemplate = $dueSmsTemplate;
} elseif($smsType === 'custom'){
    // Send custom SMS to selected customers
    if(empty($selectedCustomerIds)){
        echo json_encode(['success' => false, 'message' => 'No customers selected']);
        exit;
    }
    
    $placeholders = array_fill(0, count($selectedCustomerIds), '?');
    $customersStmt = $pdo->prepare("
        SELECT id, name, phone FROM customers 
        WHERE id IN (" . implode(',', $placeholders) . ")
        ORDER BY name ASC
    ");
    $customersStmt->execute($selectedCustomerIds);
    $customersToSend = $customersStmt->fetchAll();
    $messageTemplate = str_replace('{custom_message}', $customMessage, $customSmsTemplate);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid SMS type']);
    exit;
}

// Validate phone numbers and calculate credits needed
$validCustomers = [];
foreach($customersToSend as $customer){
    $phone = (string)($customer['phone'] ?? '');
    // Validate: 10 digits, starting with 97 or 98
    if(preg_match('/^(\+977)?9[87][0-9]{8}$/', $phone)){
        // Remove +977 prefix if present
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

if(empty($validCustomers)){
    echo json_encode(['success' => false, 'message' => 'No valid phone numbers found']);
    exit;
}

// Check SMS credit
$balanceResult = get_sms_balance();
if(!$balanceResult['success']){
    echo json_encode(['success' => false, 'message' => 'Could not check SMS balance: ' . $balanceResult['message']]);
    exit;
}

$availableCredits = (int)($balanceResult['balance'] ?? 0);
if($availableCredits < count($validCustomers)){
    echo json_encode([
        'success' => false,
        'message' => 'Insufficient SMS credits. Available: ' . $availableCredits . ', Required: ' . count($validCustomers)
    ]);
    exit;
}

// Send SMS to each customer
$successCount = 0;
$failureCount = 0;
$failedCustomers = [];

foreach($validCustomers as $customer){
    // Prepare message with variable substitution
    $message = $messageTemplate;
    $nameParts = explode(' ', $customer['name'], 2);
    $firstName = $nameParts[0];
    $message = str_replace('{firstname}', $firstName, $message);
    $message = str_replace('{fullname}', $customer['name'], $message);
    $message = str_replace('{dueamt}', (string)($customer['due_amount'] ?? 0), $message);
    $message = str_replace('{phone}', $customer['phone'], $message);
    
    // Send SMS via SmsHelper
    $sendResult = send_sms_notification($customer['phone'], $message);
    
    if($sendResult['success']){
        $successCount++;
        $responseData = isset($sendResult['data']) ? json_encode($sendResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        
        // Log successful SMS
        $logStmt = $pdo->prepare("
            INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, response_data, sent_by, created_at)
            VALUES (?, ?, ?, ?, 'success', ?, ?, NOW())
        ");
        $logStmt->execute([
            $customer['id'],
            $customer['phone'],
            $message,
            $smsType,
            $responseData,
            $_SESSION['uid'] ?? 0
        ]);
    } else {
        $failureCount++;
        $failedCustomers[] = $customer['name'] . ' (' . $customer['phone'] . ')';
        $responseData = isset($sendResult['data']) ? json_encode($sendResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        
        // Log failed SMS
        $logStmt = $pdo->prepare("
            INSERT INTO sms_logs (customer_id, phone_number, message, sms_type, status, error_message, response_data, sent_by, created_at)
            VALUES (?, ?, ?, ?, 'failed', ?, ?, ?, NOW())
        ");
        $logStmt->execute([
            $customer['id'],
            $customer['phone'],
            $message,
            $smsType,
            $sendResult['message'],
            $responseData,
            $_SESSION['uid'] ?? 0
        ]);
    }
}

// Prepare response message
$message = "$successCount SMS sent successfully";
if($failureCount > 0){
    $message .= ", $failureCount failed";
    if(count($failedCustomers) <= 5){
        $message .= ": " . implode(', ', $failedCustomers);
    }
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'success_count' => $successCount,
    'failure_count' => $failureCount,
    'total' => count($validCustomers)
]);
exit;
