<?php
/**
 * SMS Balance API Endpoint
 * 
 * GET /api/sms_balance.php
 * 
 * Response:
 * {
 *   "success": true|false,
 *   "message": "Balance retrieved successfully",
 *   "provider": "nestsms|spellcpaas|none",
 *   "balance": 100,
 *   "data": {...}
 * }
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if(!isset($_SESSION['uid'])){
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Login required.',
        'provider' => 'none',
        'balance' => null,
        'data' => null
    ]);
    exit;
}

$provider = get_sms_provider();
$apiKey = get_sms_api_key();

// If no provider is configured
if($provider === 'none' || $provider === ''){
    echo json_encode([
        'success' => false,
        'message' => 'SMS provider is not configured.',
        'provider' => 'none',
        'balance' => null,
        'data' => null
    ]);
    exit;
}

// If no API key is configured
if($apiKey === ''){
    echo json_encode([
        'success' => false,
        'message' => 'SMS API key is not configured.',
        'provider' => $provider,
        'balance' => null,
        'data' => null
    ]);
    exit;
}

// Get balance for Spellc PAAS provider
if($provider === 'spellcpaas'){
    $result = get_sms_balance();
    $response = [
        'success' => $result['success'],
        'message' => $result['message'],
        'provider' => 'spellcpaas',
        'balance' => $result['balance'],
        'data' => $result['data']
    ];
} else {
    http_response_code(400);
    $response = [
        'success' => false,
        'message' => 'Unknown or unsupported SMS provider: ' . $provider,
        'provider' => $provider,
        'balance' => null,
        'data' => null
    ];
}

http_response_code($response['success'] ? 200 : 400);
echo json_encode($response);
