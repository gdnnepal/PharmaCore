<?php
require_once __DIR__ . '/../config.php';

// Fake the necessary POST data and SESSION for testing
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['sms_type'] = 'due';
$_POST['csrf'] = 'test-token'; // We'll bypass CSRF for testing
$_SESSION['uid'] = 1;

// Debug: Show what we're checking
echo "=== DEBUG INFO ===\n";
echo "SMS Configured: " . (is_sms_configured() ? 'Yes' : 'No') . "\n";

// Check balance
$balance = get_sms_balance();
echo "Balance Result: " . json_encode($balance) . "\n";

// Check database connection
echo "DB Connection: " . (isset($pdo) ? 'Yes' : 'No') . "\n";

// Check customers query
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM customers");
$result = $stmt->fetch();
echo "Total Customers: " . $result['cnt'] . "\n";

// Check due customers query
$dueStmt = $pdo->query("
    SELECT DISTINCT c.id, c.name,
           COALESCE(SUM(CASE WHEN s.payment_status = 'credit' THEN s.total_amount ELSE 0 END), 0) as due_amount
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id
    WHERE s.payment_status = 'credit'
    GROUP BY c.id, c.name
    HAVING due_amount > 0
    ORDER BY c.name ASC
");
$dueCustomers = $dueStmt ? $dueStmt->fetchAll() : [];
echo "Due Customers: " . count($dueCustomers) . "\n";
foreach($dueCustomers as $cust){
    echo "  - ID: " . $cust['id'] . ", Name: " . $cust['name'] . ", Due: " . $cust['due_amount'] . "\n";
}
