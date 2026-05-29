<?php
require_once __DIR__ . '/../config.php';

// Test the full SMS sending flow with verbose output
echo "<h1>SMS Sending Detailed Debug</h1>";
echo "<pre>";

$testPhone = "9862510714";
$testMessage = "This is a test message. Dear Bishwash, your outstanding amount is Rs. 5000.";

echo "Test Data:\n";
echo "- Phone: $testPhone\n";
echo "- Message: $testMessage\n";
echo "- Message length: " . strlen($testMessage) . "\n\n";

// Call send directly
$result = SmsHelper::send($testPhone, $testMessage);

echo "Result:\n";
echo "- success: " . ($result['success'] ? "TRUE" : "FALSE") . "\n";
echo "- message: " . $result['message'] . "\n";

if(isset($result['data'])) {
    echo "- data: " . json_encode($result['data']) . "\n";
}

echo "\n\nDetailed Response:\n";
var_dump($result);

echo "\n</pre>";
