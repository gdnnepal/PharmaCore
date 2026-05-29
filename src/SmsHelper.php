<?php
/**
 * SMS Helper - Centralized SMS Gateway Management
 * 
 * Handles all SMS-related operations including:
 * - Provider configuration
 * - Balance checking
 * - Message sending
 * - API calls to Spellc PAAS
 */

declare(strict_types=1);

class SmsHelper {
    
    /**
     * Get configured SMS provider
     * @return string SMS provider name (e.g., 'spellcpaas', 'none')
     */
    public static function getProvider(): string {
        return get_app_setting('sms_provider', 'none');
    }

    /**
     * Get SMS API key from configuration
     * @return string API key or empty string if not configured
     */
    public static function getApiKey(): string {
        return trim((string)get_app_setting('sms_api_key', ''));
    }

    /**
     * Check if SMS is properly configured
     * @return bool True if provider and API key are configured
     */
    public static function isConfigured(): bool {
        $provider = self::getProvider();
        $apiKey = self::getApiKey();
        return $provider !== 'none' && $provider !== '' && $apiKey !== '';
    }

    /**
     * Configure cURL SSL certificate handling
     * Attempts to use proper certificate verification, with fallback for development
     * 
     * Certificate lookup order:
     * 1. Local vendor bundle
     * 2. PHP configuration (curl.cainfo)
     * 3. Common Linux/cPanel paths
     * 
     * @return array cURL options for SSL/TLS configuration
     */
    public static function getCurlSslOptions(): array {
        $options = [];
        
        // Try to find a valid CA certificate bundle
        $caCertPaths = [
            __DIR__ . '/../vendor/cacert.pem',
            ini_get('curl.cainfo'),
            '/etc/ssl/certs/ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ];

        $caCertPath = null;
        foreach($caCertPaths as $path){
            if(!empty($path) && is_file($path)){
                $caCertPath = $path;
                break;
            }
        }

        if($caCertPath){
            // Production: Use proper SSL verification
            $options[CURLOPT_CAINFO]        = $caCertPath;
            $options[CURLOPT_SSL_VERIFYPEER] = 1;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            // Last resort: try to use the system's default CA store via curl
            // CURLOPT_SSL_VERIFYPEER=true without CURLOPT_CAINFO uses the
            // CA bundle compiled into libcurl (works on most Linux servers).
            $options[CURLOPT_SSL_VERIFYPEER] = 1;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            error_log('[SMS Helper] Warning: No explicit CA bundle found. Relying on system CA store.');
        }

        return $options;
    }

    /**
     * Get SMS balance from Spellc PAAS
     * 
     * @return array [
     *   'success' => bool,
     *   'message' => string,
     *   'balance' => int|null (credit count),
     *   'data' => array|null (full response)
     * ]
     */
    public static function getBalance(): array {
        $provider = self::getProvider();
        $apiKey = self::getApiKey();

        if($provider === 'none' || $provider === ''){
            return [
                'success' => false,
                'message' => 'SMS provider is not configured.',
                'balance' => null,
                'data' => null,
            ];
        }

        if($apiKey === ''){
            return [
                'success' => false,
                'message' => 'SMS API key is not configured.',
                'balance' => null,
                'data' => null,
            ];
        }

        if($provider === 'spellcpaas'){
            return self::getBalanceSpellcPaas($apiKey);
        }

        return [
            'success' => false,
            'message' => 'Unknown SMS provider: ' . $provider,
            'balance' => null,
            'data' => null,
        ];
    }

    /**
     * Get balance from Spellc PAAS API
     * 
     * @param string $apiKey Spellc PAAS API key
     * @return array Balance response
     */
    private static function getBalanceSpellcPaas(string $apiKey): array {
        $url = 'https://spellcpaas.com/api/miscapi/' . urlencode($apiKey) . '/getBalance/true/';
        
        $ch = @curl_init($url);
        if($ch === false){
            return [
                'success' => false,
                'message' => 'Failed to initialize cURL.',
                'balance' => null,
                'data' => null,
            ];
        }

        // Set basic cURL options first
        @curl_setopt($ch, CURLOPT_HTTPGET, true);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        // Set SSL options
        $sslOptions = self::getCurlSslOptions();
        foreach($sslOptions as $opt => $val){
            @curl_setopt($ch, $opt, $val);
        }

        $response = @curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if($curlError !== ''){
            error_log('[SMS Helper] cURL error: ' . $curlError);
            return [
                'success' => false,
                'message' => 'Connection error: ' . $curlError,
                'balance' => null,
                'data' => null,
            ];
        }

        if($response === false){
            error_log('[SMS Helper] No response from Spellc PAAS API');
            return [
                'success' => false,
                'message' => 'No response from SMS API. Please check your API key.',
                'balance' => null,
                'data' => null,
            ];
        }

        $responseData = @json_decode($response, true);
        
        if(!is_array($responseData)){
            error_log('[SMS Helper] Invalid JSON response: ' . substr($response, 0, 100));
            return [
                'success' => false,
                'message' => 'Invalid response format from SMS API.',
                'balance' => null,
                'data' => null,
            ];
        }

        if($httpCode === 200 && isset($responseData['credits'])){
            $credits = (int)($responseData['credits'] ?? 0);
            return [
                'success' => true,
                'message' => 'Balance retrieved successfully.',
                'balance' => $credits,
                'data' => $responseData,
            ];
        }

        $errorMsg = $responseData['message'] ?? $responseData['error'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
        error_log('[SMS Helper] API error: HTTP ' . $httpCode . ' - ' . $errorMsg);
        
        return [
            'success' => false,
            'message' => 'SMS API error: ' . $errorMsg,
            'balance' => null,
            'data' => null,
        ];
    }

    /**
     * Send SMS notification
     * 
     * @param string $phoneNumber Recipient phone number
     * @param string $message SMS message text
     * @return array ['success' => bool, 'message' => string]
     */
    public static function send(string $phoneNumber, string $message): array {
        $provider = self::getProvider();

        if($provider === 'none' || $provider === ''){
            return [
                'success' => false,
                'message' => 'SMS provider is not configured.',
            ];
        }

        $phoneNumber = trim($phoneNumber);
        $message = trim($message);

        if($phoneNumber === ''){
            return [
                'success' => false,
                'message' => 'Phone number is required.',
            ];
        }

        if($message === ''){
            return [
                'success' => false,
                'message' => 'Message cannot be empty.',
            ];
        }

        if($provider === 'spellcpaas'){
            return self::sendSpellcPaas($phoneNumber, $message);
        }

        return [
            'success' => false,
            'message' => 'Unknown SMS provider: ' . $provider,
        ];
    }

    /**
     * Send SMS via Spellc PAAS API
     * 
     * @param string $phoneNumber Phone number (10 digits, without +977)
     * @param string $message Message text
     * @return array Send result
     */
    private static function sendSpellcPaas(string $phoneNumber, string $message): array {
        $apiKey = self::getApiKey();
        if($apiKey === ''){
            return [
                'success' => false,
                'message' => 'API key is required for Spellc PAAS',
            ];
        }

        // Ensure phone number format is correct (10 digits, no +977 prefix)
        $phoneNumber = preg_replace('/^\+977/', '', $phoneNumber);
        if(strlen($phoneNumber) !== 10 || !preg_match('/^9[87][0-9]{8}$/', $phoneNumber)){
            return [
                'success' => false,
                'message' => 'Invalid phone number format. Must be 10 digits starting with 97 or 98.',
            ];
        }

        // Construct API request to Spellc PAAS
        // Endpoint: https://spellcpaas.com/api/smsapi
        // Parameters: key, contacts, msg, campaign, routeid, type, responsetype
        
        $url = 'https://spellcpaas.com/api/smsapi';
        
        // Build query parameters
        $params = [
            'key' => $apiKey,
            'contacts' => $phoneNumber,
            'msg' => $message,
            'campaign' => 'API',
            'routeid' => 'SI_Alert',
            'type' => 'text',
            'responsetype' => 'json',
        ];

        // Use POST method
        $postData = http_build_query($params);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]);
        
        // Apply SSL options for proper certificate handling
        $sslOptions = self::getCurlSslOptions();
        foreach($sslOptions as $option => $value){
            curl_setopt($curl, $option, $value);
        }

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if($curlError){
            error_log('[SMS Helper] cURL error for Spellc PAAS: ' . $curlError);
            return [
                'success' => false,
                'message' => 'Network error: ' . $curlError,
            ];
        }

        if($response === false){
            error_log('[SMS Helper] Spellc PAAS returned empty response');
            return [
                'success' => false,
                'message' => 'Empty response from SMS gateway',
            ];
        }

        // Try to parse as JSON first
        $result = json_decode($response, true);
        
        // If not JSON, check if it's a message ID (32-char hex string) or success response
        if(!is_array($result)){
            $response = trim((string)$response);
            
            // Check if response looks like a message ID (32-char hex string)
            if(preg_match('/^[a-f0-9]{32}$/i', $response)){
                error_log('[SMS Helper] SMS sent successfully. Message ID: ' . $response);
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'message_id' => $response,
                    'data' => ['msgid' => $response],
                ];
            }
            
            // Check if response is "success" or similar
            if(strtolower($response) === 'success'){
                error_log('[SMS Helper] SMS sent successfully');
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                ];
            }
            
            // Check if response is a number (0 = success, 1 = queued)
            if(is_numeric($response) && in_array((int)$response, [0, 1])){
                error_log('[SMS Helper] SMS queued/sent. Code: ' . $response);
                return [
                    'success' => true,
                    'message' => 'SMS queued for sending',
                    'code' => (int)$response,
                ];
            }
            
            // If it looks like an error code (2, 3, 4, etc)
            if(is_numeric($response)){
                error_log('[SMS Helper] Spellc PAAS error code: ' . $response);
                $errorMessages = [
                    '2' => 'Invalid API key',
                    '3' => 'Invalid recipient',
                    '4' => 'Insufficient credits',
                    '5' => 'Invalid message',
                ];
                $errorMsg = $errorMessages[$response] ?? ('Error code: ' . $response);
                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }
            
            error_log('[SMS Helper] Unexpected response from Spellc PAAS: ' . substr($response, 0, 200));
            return [
                'success' => false,
                'message' => 'Unexpected response from SMS gateway',
            ];
        }

        // Handle JSON response
        // Check for success in response
        // Spellc PAAS returns various success indicators depending on response format
        // Look for: success=true, status=success, code=200, or presence of message ID
        $isSuccess = false;
        
        if(isset($result['success']) && $result['success'] === true){
            $isSuccess = true;
        } elseif(isset($result['status']) && strtolower($result['status']) === 'success'){
            $isSuccess = true;
        } elseif(isset($result['code']) && in_array((int)$result['code'], [0, 1, 200])){
            $isSuccess = true;
        } elseif(isset($result['message_id']) || isset($result['msgid']) || isset($result['smsid'])){
            $isSuccess = true;
        }
        
        if($isSuccess){
            return [
                'success' => true,
                'message' => $result['message'] ?? $result['response'] ?? 'SMS sent successfully',
                'data' => $result,
            ];
        } else {
            $errorMsg = $result['message'] ?? ($result['error'] ?? ($result['response'] ?? 'Unknown error from SMS gateway'));
            error_log('[SMS Helper] Spellc PAAS error: ' . json_encode($result));
            return [
                'success' => false,
                'message' => $errorMsg,
                'data' => $result,
            ];
        }
    }
}
