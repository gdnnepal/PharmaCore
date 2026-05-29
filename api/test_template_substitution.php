<?php
require_once __DIR__ . '/../config.php';

echo "<h1>Debug Template Substitution</h1>";
echo "<pre>";

$dueSmsTemplate = get_app_setting('sms_template_due');
echo "Template from get_app_setting():\n";
echo "  Value: '" . $dueSmsTemplate . "'\n";
echo "  Type: " . gettype($dueSmsTemplate) . "\n";
echo "  Length: " . strlen($dueSmsTemplate) . "\n";
echo "  Empty? " . (empty($dueSmsTemplate) ? "YES" : "NO") . "\n\n";

// Use the default if empty
if(empty($dueSmsTemplate)) {
    $dueSmsTemplate = 'Dear {firstname}, your outstanding amount is Rs. {dueamt}. Please settle at your earliest convenience.';
    echo "Using default template\n\n";
}

// Test substitution
$testCustomer = [
    'id' => 1,
    'name' => 'Bishwash Neupane',
    'phone' => '9862510714',
    'due_amount' => 2700,
];

echo "Test Customer Data:\n";
echo json_encode($testCustomer, JSON_PRETTY_PRINT) . "\n\n";

$message = $dueSmsTemplate;
echo "Before substitution:\n";
echo "  Message: '" . $message . "'\n";
echo "  Length: " . strlen($message) . "\n\n";

$nameParts = explode(' ', $testCustomer['name'], 2);
$firstName = $nameParts[0];
echo "Name parts: " . json_encode($nameParts) . "\n";
echo "First name: '" . $firstName . "'\n\n";

$message = str_replace('{firstname}', $firstName, $message);
echo "After replacing {firstname}:\n";
echo "  Message: '" . $message . "'\n";
echo "  Length: " . strlen($message) . "\n\n";

$message = str_replace('{fullname}', $testCustomer['name'], $message);
echo "After replacing {fullname}:\n";
echo "  Message: '" . $message . "'\n";
echo "  Length: " . strlen($message) . "\n\n";

$message = str_replace('{dueamt}', (string)($testCustomer['due_amount'] ?? 0), $message);
echo "After replacing {dueamt}:\n";
echo "  Message: '" . $message . "'\n";
echo "  Length: " . strlen($message) . "\n\n";

$message = str_replace('{phone}', $testCustomer['phone'], $message);
echo "After replacing {phone}:\n";
echo "  Message: '" . $message . "'\n";
echo "  Length: " . strlen($message) . "\n";
echo "  Empty? " . (empty($message) ? "YES" : "NO") . "\n";

echo "</pre>";
