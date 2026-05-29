<?php
require_once __DIR__ . '/../config.php';

// Test the full SMS sending flow
echo "<h1>SMS Sending Debug Test</h1>";
echo "<pre>";

// Test 1: Check if SmsHelper exists
echo "1. Check SmsHelper class:\n";
if(class_exists('SmsHelper')) {
    echo "   ✓ SmsHelper class exists\n";
} else {
    echo "   ✗ SmsHelper class NOT found\n";
}

// Test 2: Check configuration
echo "\n2. Check SMS Configuration:\n";
$provider = SmsHelper::getProvider();
$apiKey = SmsHelper::getApiKey();
echo "   Provider: $provider\n";
echo "   API Key: " . substr($apiKey, 0, 10) . "...\n";
echo "   isConfigured: " . (SmsHelper::isConfigured() ? "TRUE" : "FALSE") . "\n";

// Test 3: Try to send a test SMS
echo "\n3. Try to send test SMS:\n";
$testPhone = "9862510714";
$testMessage = "This is a test message. Dear Bishwash, your outstanding amount is Rs. 5000.";

echo "   Phone: $testPhone\n";
echo "   Message: $testMessage\n";
echo "   Message length: " . strlen($testMessage) . "\n";
echo "   Message empty? " . (empty($testMessage) ? "YES" : "NO") . "\n";

// Call send directly
$result = SmsHelper::send($testPhone, $testMessage);
echo "   Result:\n";
echo "   - success: " . ($result['success'] ? "TRUE" : "FALSE") . "\n";
echo "   - message: " . $result['message'] . "\n";

echo "\n</pre>";
