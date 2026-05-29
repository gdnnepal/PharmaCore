<?php
declare(strict_types=1);

// Harden session cookie before session_start()
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
// Enable secure flag only over HTTPS
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'){
    ini_set('session.cookie_secure', '1');
}

session_start();
error_reporting(0);
ini_set('display_errors', '0');

// Determine the base URL dynamically based on where this app is installed
// Works for: /Pharmacy/, /pharmacy/, domain/pharmacy/, etc.
function get_base_url(): string {
    static $baseUrl = null;
    if($baseUrl === null){
        $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if($requestPath !== ''){
            $requestDir = rtrim(str_replace('\\', '/', dirname($requestPath)), '/');
            if($requestDir !== '' && $requestDir !== '.'){
                $baseUrl = $requestDir;
            }
        }

        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
        $appRoot = realpath(__DIR__);

        if(($baseUrl === null || $baseUrl === '') && $docRoot !== false && $appRoot !== false){
            $docRootNorm = str_replace('\\', '/', strtolower($docRoot));
            $appRootNorm = str_replace('\\', '/', strtolower($appRoot));
            if(str_starts_with($appRootNorm, $docRootNorm)){
                $relative = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
                $baseUrl = '/' . trim($relative, '/');
            }
        }

        if($baseUrl === null || $baseUrl === ''){
            $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
            $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        }

        if($baseUrl === '/' || $baseUrl === '.'){
            $baseUrl = '';
        }
    }
    return $baseUrl;
}

// Load environment variables from .env (if present)
require_once __DIR__ . '/src/Env.php';
Env::load(__DIR__ . '/.env');

$dbConfig = [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'name' => Env::get('DB_NAME', 'pharmacy_npr'),
    'user' => Env::get('DB_USER', 'root'),
    'pass' => Env::get('DB_PASS', ''),
];

$pdo = new PDO(
    "mysql:host=" . $dbConfig['host'] . ";port=" . (int)$dbConfig['port'] . ";dbname=" . $dbConfig['name'] . ";charset=utf8mb4",
    (string)$dbConfig['user'],
    (string)$dbConfig['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);

// Centralized schema bootstrap and backward-compatible migrations.
try {
    $tableExists = static function(string $table) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    };
    $columnExists = static function(string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        code VARCHAR(50) NOT NULL UNIQUE,
        address VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_branches_name (name),
        INDEX idx_branches_active (is_active)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(191) DEFAULT NULL,
        branch_id INT DEFAULT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_users_branch (branch_id),
        INDEX idx_users_active (is_active)
    )");

    if(!$columnExists('users', 'full_name')) $pdo->exec("ALTER TABLE users ADD COLUMN full_name VARCHAR(191) DEFAULT NULL AFTER password_hash");
    if(!$columnExists('users', 'branch_id')) $pdo->exec("ALTER TABLE users ADD COLUMN branch_id INT DEFAULT NULL AFTER full_name");
    if(!$columnExists('users', 'is_admin')) $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER branch_id");
    if(!$columnExists('users', 'is_active')) $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_admin");

    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        label VARCHAR(191) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'general',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        user_id INT NOT NULL,
        permission_id INT NOT NULL,
        PRIMARY KEY (user_id, permission_id),
        INDEX idx_up_permission (permission_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key, setting_value) VALUES('show_pos_menu','1')")->execute();
    $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key, setting_value) VALUES('currency_code','NPR')")->execute();
    $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key, setting_value) VALUES('app_timezone','Asia/Kathmandu')")->execute();

    $pdo->exec("CREATE TABLE IF NOT EXISTS pharmacy_details (
        id TINYINT UNSIGNED PRIMARY KEY,
        pharmacy_name VARCHAR(191) NOT NULL,
        address VARCHAR(255) NOT NULL,
        phone_number VARCHAR(50) NOT NULL,
        email VARCHAR(191) DEFAULT NULL,
        pan_vat VARCHAR(100) NOT NULL,
        dda_no VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        to_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
        to_receive DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        payment_type ENUM('pay','receive') NOT NULL,
        payment_method ENUM('cash','cheque','bank_transfer','qr_payment') NOT NULL DEFAULT 'cash',
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at_bs VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_supplier_id (supplier_id)
    )");
    if($tableExists('supplier_payments') && !$columnExists('supplier_payments', 'payment_method')){
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN payment_method ENUM('cash','cheque','bank_transfer','qr_payment') NOT NULL DEFAULT 'cash' AFTER payment_type");
    }
    if($tableExists('supplier_payments') && !$columnExists('supplier_payments', 'paid_at_bs')){
        $pdo->exec("ALTER TABLE supplier_payments ADD COLUMN paid_at_bs VARCHAR(20) DEFAULT NULL AFTER paid_at");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(50) NOT NULL UNIQUE,
        customer_id INT NULL,
        customer_name VARCHAR(191) DEFAULT NULL,
        customer_phone VARCHAR(50) DEFAULT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        due_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tender_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        change_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'completed',
        payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
        sale_date_bs VARCHAR(20) DEFAULT NULL,
        sold_by_user_id INT DEFAULT NULL,
        sold_by_username VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sales_customer (customer_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        product_id INT NOT NULL,
        batch_id INT NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        sell_price DECIMAL(12,2) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sale_items_sale (sale_id),
        INDEX idx_sale_items_product (product_id),
        INDEX idx_sale_items_batch (batch_id)
    )");

    if(!$columnExists('sales', 'payment_method')) $pdo->exec("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER status");
    if(!$columnExists('sales', 'discount')) $pdo->exec("ALTER TABLE sales ADD COLUMN discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER due_amount");
    if(!$columnExists('sales', 'tender_amount')) $pdo->exec("ALTER TABLE sales ADD COLUMN tender_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER due_amount");
    if(!$columnExists('sales', 'change_amount')) $pdo->exec("ALTER TABLE sales ADD COLUMN change_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tender_amount");
    if(!$columnExists('sales', 'customer_name')) $pdo->exec("ALTER TABLE sales ADD COLUMN customer_name VARCHAR(191) DEFAULT NULL AFTER customer_id");
    if(!$columnExists('sales', 'customer_phone')) $pdo->exec("ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) DEFAULT NULL AFTER customer_name");
    if(!$columnExists('sales', 'sale_date_bs')) $pdo->exec("ALTER TABLE sales ADD COLUMN sale_date_bs VARCHAR(20) DEFAULT NULL AFTER payment_method");
    if(!$columnExists('sales', 'sold_by_user_id')) $pdo->exec("ALTER TABLE sales ADD COLUMN sold_by_user_id INT DEFAULT NULL AFTER sale_date_bs");
    if(!$columnExists('sales', 'sold_by_username')) $pdo->exec("ALTER TABLE sales ADD COLUMN sold_by_username VARCHAR(100) DEFAULT NULL AFTER sold_by_user_id");

    if($columnExists('sales', 'invoice_no')){
        $invoiceLenStmt = $pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='invoice_no'");
        $invoiceLenStmt->execute();
        $invoiceLen = $invoiceLenStmt->fetchColumn();
        if($invoiceLen !== false && (int)$invoiceLen < 50){
            $pdo->exec("ALTER TABLE sales MODIFY COLUMN invoice_no VARCHAR(50) NOT NULL");
        }
    }
    if($columnExists('sales', 'status')){
        $statusTypeStmt = $pdo->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='status'");
        $statusTypeStmt->execute();
        $statusType = $statusTypeStmt->fetchColumn();
        if($statusType !== false && strtolower((string)$statusType) !== 'varchar'){
            $pdo->exec("ALTER TABLE sales MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed'");
        }
    }

    if($tableExists('sales') && !$columnExists('sales', 'payment_method')){
        $pdo->exec("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER status");
    }

    if($tableExists('batches') && !$columnExists('batches', 'supplier_id')){
        $pdo->exec("ALTER TABLE batches ADD COLUMN supplier_id INT NULL AFTER product_id");
    }
    if($tableExists('batches') && !$columnExists('batches', 'branch_id')){
        $pdo->exec("ALTER TABLE batches ADD COLUMN branch_id INT DEFAULT NULL AFTER supplier_id");
    }

    if($tableExists('payments') && !$columnExists('payments', 'paid_at_bs')){
        $pdo->exec("ALTER TABLE payments ADD COLUMN paid_at_bs VARCHAR(20) DEFAULT NULL AFTER amount");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_batch_id INT NOT NULL,
        destination_batch_id INT NOT NULL,
        product_id INT NOT NULL,
        from_branch_id INT NOT NULL,
        to_branch_id INT NOT NULL,
        transferred_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        reversed_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'completed',
        created_by_user_id INT DEFAULT NULL,
        created_by_username VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_stock_transfers_product (product_id),
        INDEX idx_stock_transfers_from_branch (from_branch_id),
        INDEX idx_stock_transfers_to_branch (to_branch_id),
        INDEX idx_stock_transfers_created_at (created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfer_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_transfer_id INT NOT NULL,
        return_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        returned_by_user_id INT DEFAULT NULL,
        returned_by_username VARCHAR(100) DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_transfer_returns_transfer (stock_transfer_id),
        INDEX idx_stock_transfer_returns_created_at (created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_return_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        sale_item_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(191) NOT NULL,
        returned_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        returned_by_user_id INT NOT NULL,
        returned_by_username VARCHAR(100) NOT NULL,
        remarks VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_srl_sale_id (sale_id),
        INDEX idx_srl_sale_item_id (sale_item_id)
    )");
    if(!$columnExists('sale_return_logs', 'remarks')){
        $pdo->exec("ALTER TABLE sale_return_logs ADD COLUMN remarks VARCHAR(255) DEFAULT NULL AFTER returned_by_username");
    }
} catch(Throwable $e){
    // Keep app running even if migration checks fail in restricted environments.
}

// Immutable audit trail for sensitive operations.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        username VARCHAR(100) DEFAULT NULL,
        module_name VARCHAR(100) NOT NULL,
        action_name VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) DEFAULT NULL,
        entity_id VARCHAR(100) DEFAULT NULL,
        description TEXT NOT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_logs_created (created_at),
        INDEX idx_audit_logs_module_action (module_name, action_name),
        INDEX idx_audit_logs_user (user_id)
    )");

    $hasDeleteGuardTrigger = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_audit_logs_no_delete'")->fetchColumn() > 0;
    if(!$hasDeleteGuardTrigger){
        $pdo->exec("CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted.'");
    }
} catch(Throwable $e){
    // Avoid blocking app startup if audit table bootstrap fails.
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function get_currency_catalog(): array {
    static $catalog = null;
    if($catalog !== null) return $catalog;

    $catalog = [
        'NPR' => ['name' => 'Nepalese Rupee', 'symbol' => 'RS'],
        'AED' => ['name' => 'UAE Dirham', 'symbol' => 'AED'],
        'AFN' => ['name' => 'Afghan Afghani', 'symbol' => 'AFN'],
        'ALL' => ['name' => 'Albanian Lek', 'symbol' => 'ALL'],
        'AMD' => ['name' => 'Armenian Dram', 'symbol' => 'AMD'],
        'ANG' => ['name' => 'Netherlands Antillean Guilder', 'symbol' => 'ANG'],
        'AOA' => ['name' => 'Angolan Kwanza', 'symbol' => 'AOA'],
        'ARS' => ['name' => 'Argentine Peso', 'symbol' => 'ARS'],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => '$'],
        'AWG' => ['name' => 'Aruban Florin', 'symbol' => 'AWG'],
        'AZN' => ['name' => 'Azerbaijani Manat', 'symbol' => 'AZN'],
        'BAM' => ['name' => 'Bosnia and Herzegovina Convertible Mark', 'symbol' => 'BAM'],
        'BBD' => ['name' => 'Barbadian Dollar', 'symbol' => 'BBD'],
        'BDT' => ['name' => 'Bangladeshi Taka', 'symbol' => 'BDT'],
        'BGN' => ['name' => 'Bulgarian Lev', 'symbol' => 'BGN'],
        'BHD' => ['name' => 'Bahraini Dinar', 'symbol' => 'BHD'],
        'BIF' => ['name' => 'Burundian Franc', 'symbol' => 'BIF'],
        'BMD' => ['name' => 'Bermudian Dollar', 'symbol' => 'BMD'],
        'BND' => ['name' => 'Brunei Dollar', 'symbol' => 'BND'],
        'BOB' => ['name' => 'Bolivian Boliviano', 'symbol' => 'BOB'],
        'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'BRL'],
        'BSD' => ['name' => 'Bahamian Dollar', 'symbol' => 'BSD'],
        'BTN' => ['name' => 'Bhutanese Ngultrum', 'symbol' => 'BTN'],
        'BWP' => ['name' => 'Botswanan Pula', 'symbol' => 'BWP'],
        'BYN' => ['name' => 'Belarusian Ruble', 'symbol' => 'BYN'],
        'BZD' => ['name' => 'Belize Dollar', 'symbol' => 'BZD'],
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'CAD'],
        'CDF' => ['name' => 'Congolese Franc', 'symbol' => 'CDF'],
        'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF'],
        'CLP' => ['name' => 'Chilean Peso', 'symbol' => 'CLP'],
        'CNY' => ['name' => 'Chinese Yuan', 'symbol' => 'CNY'],
        'COP' => ['name' => 'Colombian Peso', 'symbol' => 'COP'],
        'CRC' => ['name' => 'Costa Rican Colon', 'symbol' => 'CRC'],
        'CUP' => ['name' => 'Cuban Peso', 'symbol' => 'CUP'],
        'CVE' => ['name' => 'Cape Verdean Escudo', 'symbol' => 'CVE'],
        'CZK' => ['name' => 'Czech Koruna', 'symbol' => 'CZK'],
        'DJF' => ['name' => 'Djiboutian Franc', 'symbol' => 'DJF'],
        'DKK' => ['name' => 'Danish Krone', 'symbol' => 'DKK'],
        'DOP' => ['name' => 'Dominican Peso', 'symbol' => 'DOP'],
        'DZD' => ['name' => 'Algerian Dinar', 'symbol' => 'DZD'],
        'EGP' => ['name' => 'Egyptian Pound', 'symbol' => 'EGP'],
        'ERN' => ['name' => 'Eritrean Nakfa', 'symbol' => 'ERN'],
        'ETB' => ['name' => 'Ethiopian Birr', 'symbol' => 'ETB'],
        'EUR' => ['name' => 'Euro', 'symbol' => 'EUR'],
        'FJD' => ['name' => 'Fijian Dollar', 'symbol' => 'FJD'],
        'FKP' => ['name' => 'Falkland Islands Pound', 'symbol' => 'FKP'],
        'GBP' => ['name' => 'British Pound Sterling', 'symbol' => 'GBP'],
        'GEL' => ['name' => 'Georgian Lari', 'symbol' => 'GEL'],
        'GGP' => ['name' => 'Guernsey Pound', 'symbol' => 'GGP'],
        'GHS' => ['name' => 'Ghanaian Cedi', 'symbol' => 'GHS'],
        'GIP' => ['name' => 'Gibraltar Pound', 'symbol' => 'GIP'],
        'GMD' => ['name' => 'Gambian Dalasi', 'symbol' => 'GMD'],
        'GNF' => ['name' => 'Guinean Franc', 'symbol' => 'GNF'],
        'GTQ' => ['name' => 'Guatemalan Quetzal', 'symbol' => 'GTQ'],
        'GYD' => ['name' => 'Guyanaese Dollar', 'symbol' => 'GYD'],
        'HKD' => ['name' => 'Hong Kong Dollar', 'symbol' => 'HKD'],
        'HNL' => ['name' => 'Honduran Lempira', 'symbol' => 'HNL'],
        'HRK' => ['name' => 'Croatian Kuna', 'symbol' => 'HRK'],
        'HTG' => ['name' => 'Haitian Gourde', 'symbol' => 'HTG'],
        'HUF' => ['name' => 'Hungarian Forint', 'symbol' => 'HUF'],
        'IDR' => ['name' => 'Indonesian Rupiah', 'symbol' => 'IDR'],
        'ILS' => ['name' => 'Israeli New Shekel', 'symbol' => 'ILS'],
        'IMP' => ['name' => 'Manx Pound', 'symbol' => 'IMP'],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => 'INR'],
        'IQD' => ['name' => 'Iraqi Dinar', 'symbol' => 'IQD'],
        'IRR' => ['name' => 'Iranian Rial', 'symbol' => 'IRR'],
        'ISK' => ['name' => 'Icelandic Krona', 'symbol' => 'ISK'],
        'JEP' => ['name' => 'Jersey Pound', 'symbol' => 'JEP'],
        'JMD' => ['name' => 'Jamaican Dollar', 'symbol' => 'JMD'],
        'JOD' => ['name' => 'Jordanian Dinar', 'symbol' => 'JOD'],
        'JPY' => ['name' => 'Japanese Yen', 'symbol' => 'JPY'],
        'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KES'],
        'KGS' => ['name' => 'Kyrgystani Som', 'symbol' => 'KGS'],
        'KHR' => ['name' => 'Cambodian Riel', 'symbol' => 'KHR'],
        'KID' => ['name' => 'Kiribati Dollar', 'symbol' => 'KID'],
        'KMF' => ['name' => 'Comorian Franc', 'symbol' => 'KMF'],
        'KRW' => ['name' => 'South Korean Won', 'symbol' => 'KRW'],
        'KWD' => ['name' => 'Kuwaiti Dinar', 'symbol' => 'KWD'],
        'KYD' => ['name' => 'Cayman Islands Dollar', 'symbol' => 'KYD'],
        'KZT' => ['name' => 'Kazakhstani Tenge', 'symbol' => 'KZT'],
        'LAK' => ['name' => 'Lao Kip', 'symbol' => 'LAK'],
        'LBP' => ['name' => 'Lebanese Pound', 'symbol' => 'LBP'],
        'LKR' => ['name' => 'Sri Lankan Rupee', 'symbol' => 'LKR'],
        'LRD' => ['name' => 'Liberian Dollar', 'symbol' => 'LRD'],
        'LSL' => ['name' => 'Lesotho Loti', 'symbol' => 'LSL'],
        'LYD' => ['name' => 'Libyan Dinar', 'symbol' => 'LYD'],
        'MAD' => ['name' => 'Moroccan Dirham', 'symbol' => 'MAD'],
        'MDL' => ['name' => 'Moldovan Leu', 'symbol' => 'MDL'],
        'MGA' => ['name' => 'Malagasy Ariary', 'symbol' => 'MGA'],
        'MKD' => ['name' => 'Macedonian Denar', 'symbol' => 'MKD'],
        'MMK' => ['name' => 'Myanmar Kyat', 'symbol' => 'MMK'],
        'MNT' => ['name' => 'Mongolian Tugrik', 'symbol' => 'MNT'],
        'MOP' => ['name' => 'Macanese Pataca', 'symbol' => 'MOP'],
        'MRU' => ['name' => 'Mauritanian Ouguiya', 'symbol' => 'MRU'],
        'MUR' => ['name' => 'Mauritian Rupee', 'symbol' => 'MUR'],
        'MVR' => ['name' => 'Maldivian Rufiyaa', 'symbol' => 'MVR'],
        'MWK' => ['name' => 'Malawian Kwacha', 'symbol' => 'MWK'],
        'MXN' => ['name' => 'Mexican Peso', 'symbol' => 'MXN'],
        'MYR' => ['name' => 'Malaysian Ringgit', 'symbol' => 'MYR'],
        'MZN' => ['name' => 'Mozambican Metical', 'symbol' => 'MZN'],
        'NAD' => ['name' => 'Namibian Dollar', 'symbol' => 'NAD'],
        'NGN' => ['name' => 'Nigerian Naira', 'symbol' => 'NGN'],
        'NIO' => ['name' => 'Nicaraguan Cordoba', 'symbol' => 'NIO'],
        'NOK' => ['name' => 'Norwegian Krone', 'symbol' => 'NOK'],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => 'NZD'],
        'OMR' => ['name' => 'Omani Rial', 'symbol' => 'OMR'],
        'PAB' => ['name' => 'Panamanian Balboa', 'symbol' => 'PAB'],
        'PEN' => ['name' => 'Peruvian Sol', 'symbol' => 'PEN'],
        'PGK' => ['name' => 'Papua New Guinean Kina', 'symbol' => 'PGK'],
        'PHP' => ['name' => 'Philippine Peso', 'symbol' => 'PHP'],
        'PKR' => ['name' => 'Pakistani Rupee', 'symbol' => 'PKR'],
        'PLN' => ['name' => 'Polish Zloty', 'symbol' => 'PLN'],
        'PYG' => ['name' => 'Paraguayan Guarani', 'symbol' => 'PYG'],
        'QAR' => ['name' => 'Qatari Riyal', 'symbol' => 'QAR'],
        'RON' => ['name' => 'Romanian Leu', 'symbol' => 'RON'],
        'RSD' => ['name' => 'Serbian Dinar', 'symbol' => 'RSD'],
        'RUB' => ['name' => 'Russian Ruble', 'symbol' => 'RUB'],
        'RWF' => ['name' => 'Rwandan Franc', 'symbol' => 'RWF'],
        'SAR' => ['name' => 'Saudi Riyal', 'symbol' => 'SAR'],
        'SBD' => ['name' => 'Solomon Islands Dollar', 'symbol' => 'SBD'],
        'SCR' => ['name' => 'Seychellois Rupee', 'symbol' => 'SCR'],
        'SDG' => ['name' => 'Sudanese Pound', 'symbol' => 'SDG'],
        'SEK' => ['name' => 'Swedish Krona', 'symbol' => 'SEK'],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'SGD'],
        'SHP' => ['name' => 'Saint Helena Pound', 'symbol' => 'SHP'],
        'SLE' => ['name' => 'Sierra Leonean Leone', 'symbol' => 'SLE'],
        'SLL' => ['name' => 'Sierra Leonean Leone (Old)', 'symbol' => 'SLL'],
        'SOS' => ['name' => 'Somali Shilling', 'symbol' => 'SOS'],
        'SRD' => ['name' => 'Surinamese Dollar', 'symbol' => 'SRD'],
        'SSP' => ['name' => 'South Sudanese Pound', 'symbol' => 'SSP'],
        'STN' => ['name' => 'Sao Tome and Principe Dobra', 'symbol' => 'STN'],
        'SYP' => ['name' => 'Syrian Pound', 'symbol' => 'SYP'],
        'SZL' => ['name' => 'Swazi Lilangeni', 'symbol' => 'SZL'],
        'THB' => ['name' => 'Thai Baht', 'symbol' => 'THB'],
        'TJS' => ['name' => 'Tajikistani Somoni', 'symbol' => 'TJS'],
        'TMT' => ['name' => 'Turkmenistani Manat', 'symbol' => 'TMT'],
        'TND' => ['name' => 'Tunisian Dinar', 'symbol' => 'TND'],
        'TOP' => ['name' => 'Tongan Paanga', 'symbol' => 'TOP'],
        'TRY' => ['name' => 'Turkish Lira', 'symbol' => 'TRY'],
        'TTD' => ['name' => 'Trinidad and Tobago Dollar', 'symbol' => 'TTD'],
        'TVD' => ['name' => 'Tuvaluan Dollar', 'symbol' => 'TVD'],
        'TWD' => ['name' => 'New Taiwan Dollar', 'symbol' => 'TWD'],
        'TZS' => ['name' => 'Tanzanian Shilling', 'symbol' => 'TZS'],
        'UAH' => ['name' => 'Ukrainian Hryvnia', 'symbol' => 'UAH'],
        'UGX' => ['name' => 'Ugandan Shilling', 'symbol' => 'UGX'],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
        'UYU' => ['name' => 'Uruguayan Peso', 'symbol' => 'UYU'],
        'UZS' => ['name' => 'Uzbekistani Som', 'symbol' => 'UZS'],
        'VES' => ['name' => 'Venezuelan Bolivar', 'symbol' => 'VES'],
        'VND' => ['name' => 'Vietnamese Dong', 'symbol' => 'VND'],
        'VUV' => ['name' => 'Vanuatu Vatu', 'symbol' => 'VUV'],
        'WST' => ['name' => 'Samoan Tala', 'symbol' => 'WST'],
        'XAF' => ['name' => 'Central African CFA Franc', 'symbol' => 'XAF'],
        'XCD' => ['name' => 'East Caribbean Dollar', 'symbol' => 'XCD'],
        'XOF' => ['name' => 'West African CFA Franc', 'symbol' => 'XOF'],
        'XPF' => ['name' => 'CFP Franc', 'symbol' => 'XPF'],
        'YER' => ['name' => 'Yemeni Rial', 'symbol' => 'YER'],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'ZAR'],
        'ZMW' => ['name' => 'Zambian Kwacha', 'symbol' => 'ZMW'],
        'ZWL' => ['name' => 'Zimbabwean Dollar', 'symbol' => 'ZWL'],
    ];

    return $catalog;
}
function get_app_settings_map(bool $refresh = false): array {
    static $settings = null;
    if($settings !== null && !$refresh) return $settings;

    $settings = [];
    global $pdo;
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
        foreach($rows as $row){
            $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
    } catch(Throwable $e){
        $settings = [];
    }

    return $settings;
}
function get_app_setting(string $key, string $default = ''): string {
    $settings = get_app_settings_map();
    $value = trim((string)($settings[$key] ?? ''));
    return $value !== '' ? $value : $default;
}

// Load SMS Helper for centralized SMS management
require_once __DIR__ . '/src/SmsHelper.php';

// Initialize SMS logging table
require_once __DIR__ . '/src/SmsLogInitializer.php';

// License verification — boots after DB is ready, redirects to activate.php if invalid
require_once __DIR__ . '/src/LicenseManager.php';
LicenseManager::boot();

// Minimum password length — single source of truth (L-4)
define('MIN_PASSWORD_LENGTH', 8);

function get_app_currency_code(): string {
    $catalog = get_currency_catalog();
    $code = strtoupper(get_app_setting('currency_code', 'NPR'));
    if(!isset($catalog[$code])) return 'NPR';
    return $code;
}
function get_app_currency_symbol(): string {
    $catalog = get_currency_catalog();
    $code = get_app_currency_code();
    return (string)($catalog[$code]['symbol'] ?? 'RS');
}
function get_app_timezone(): string {
    $tz = get_app_setting('app_timezone', 'Asia/Kathmandu');
    $all = timezone_identifiers_list();
    if(!in_array($tz, $all, true)) return 'Asia/Kathmandu';
    return $tz;
}
function npr(float $n): string { return get_app_currency_symbol() . ' ' . number_format($n, 2); }

/**
 * Normalize a Nepal phone number: strip +977 prefix, return 10-digit number.
 * M-13: Single source of truth for phone normalization.
 */
function normalize_nepal_phone(string $phone): string {
    $phone = trim($phone);
    $phone = preg_replace('/^\+977/', '', $phone);
    return $phone;
}

function csrf_token(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): bool { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']); }
function require_auth(): void { if(!isset($_SESSION['uid'])) { header('Location: ' . get_base_url() . '/login.php'); exit; } }
function is_admin_user(): bool {
    if(isset($_SESSION['is_admin'])) return (bool)$_SESSION['is_admin'];

    $uid = (int)($_SESSION['uid'] ?? 0);
    if($uid <= 0) return false;

    global $pdo;
    try {
        $hasIsAdminCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='is_admin'")->fetchColumn() > 0;
        if($hasIsAdminCol){
            $stmt = $pdo->prepare("SELECT username, is_admin FROM users WHERE id=? LIMIT 1");
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            $isAdmin = (bool)($u['is_admin'] ?? false);
            $_SESSION['username'] = (string)($u['username'] ?? ($_SESSION['username'] ?? ''));
            $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
            return $isAdmin;
        }

        $stmt = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        $username = strtolower(trim((string)($u['username'] ?? ($_SESSION['username'] ?? ''))));
        $_SESSION['username'] = $username;
        // H-11: removed username-based admin fallback — return false if is_admin column missing
        $_SESSION['is_admin'] = 0;
        return false;
    } catch(Throwable $e){
        $username = strtolower(trim((string)($_SESSION['username'] ?? '')));
        return $username === 'admin';
    }
}
function require_admin(): void {
    if(!is_admin_user()){
        flash_msg('Admin access required.', 'error');
        redirect_with_fallback(get_base_url() . '/dashboard.php?module=sale');
    }
}
function get_current_user_permissions(bool $refresh = false): array {
    if(!$refresh && isset($_SESSION['perm_keys']) && is_array($_SESSION['perm_keys'])){
        return $_SESSION['perm_keys'];
    }

    if(is_admin_user()){
        $_SESSION['perm_keys'] = ['*'];
        return $_SESSION['perm_keys'];
    }

    $uid = (int)($_SESSION['uid'] ?? 0);
    if($uid <= 0){
        $_SESSION['perm_keys'] = [];
        return [];
    }

    global $pdo;
    try {
        $hasPermTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='permissions'")->fetchColumn() > 0;
        $hasUserPermTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_permissions'")->fetchColumn() > 0;
        if(!$hasPermTable || !$hasUserPermTable){
            $_SESSION['perm_keys'] = [];
            return [];
        }

        $stmt = $pdo->prepare("SELECT p.permission_key FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=?");
        $stmt->execute([$uid]);
        $keys = array_values(array_unique(array_filter(array_map(static function($v){
            return trim((string)$v);
        }, array_column($stmt->fetchAll(), 'permission_key')))));

        $_SESSION['perm_keys'] = $keys;
        return $keys;
    } catch(Throwable $e){
        $_SESSION['perm_keys'] = [];
        return [];
    }
}
function has_permission(string $permissionKey): bool {
    if(is_admin_user()) return true;
    $keys = get_current_user_permissions();
    if(in_array('*', $keys, true)) return true;
    return in_array($permissionKey, $keys, true);
}
function has_any_permission(array $permissionKeys): bool {
    if(is_admin_user()) return true;
    foreach($permissionKeys as $k){
        if(has_permission((string)$k)) return true;
    }
    return false;
}
function redirect_with_fallback(string $url): void {
    if(!headers_sent()){
        header('Location: ' . $url);
    } else {
        // Use json_encode for JS context — prevents XSS via single-quote injection (C-5)
        echo '<script>window.location.href=' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"></noscript>';
    }
    exit;
}
function flash_msg(?string $msg = null, string $type = 'success'): ?array {
    if($msg) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; }
    else { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
    return null;
}

function audit_stringify_value($value): string {
    if($value === null) return 'null';
    if(is_bool($value)) return $value ? 'true' : 'false';
    if(is_scalar($value)) return (string)$value;
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return $json === false ? '[unserializable]' : $json;
}

function audit_log_action(string $module, string $action, string $description, array $payload = [], ?string $entityType = null, $entityId = null): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, username, module_name, action_name, entity_type, entity_id, description, payload_json, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $_SESSION['uid'] ?? null,
            $_SESSION['username'] ?? null,
            $module,
            $action,
            $entityType,
            $entityId !== null ? (string)$entityId : null,
            $description,
            !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch(Throwable $e){
        error_log('[Audit] Failed to write audit log: ' . $e->getMessage());
    }
}

function paginate_array(array $rows, string $pageParam, int $perPage = 10): array {
    $total = count($rows);
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = (int)($_GET[$pageParam] ?? 1);
    if($page < 1) $page = 1;
    if($page > $pages) $page = $pages;

    $offset = ($page - 1) * $perPage;

    return [
        'rows' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'param' => $pageParam,
    ];
}

function pagination_link(array $overrides = []): string {
    $query = $_GET;
    foreach($overrides as $k => $v){
        if($v === null || $v === ''){
            unset($query[$k]);
        } else {
            $query[$k] = (string)$v;
        }
    }
    $qs = http_build_query($query);
    return '?' . $qs;
}

function render_pagination(array $meta): string {
    $pages = (int)($meta['pages'] ?? 1);
    $page = (int)($meta['page'] ?? 1);
    $param = (string)($meta['param'] ?? 'page');

    if($pages <= 1) return '';

    $prev = $page > 1 ? '<a class="px-3 py-1.5 border rounded text-sm hover:bg-slate-50" href="' . e(pagination_link([$param => $page - 1])) . '">Prev</a>' : '<span class="px-3 py-1.5 border rounded text-sm text-slate-400">Prev</span>';
    $next = $page < $pages ? '<a class="px-3 py-1.5 border rounded text-sm hover:bg-slate-50" href="' . e(pagination_link([$param => $page + 1])) . '">Next</a>' : '<span class="px-3 py-1.5 border rounded text-sm text-slate-400">Next</span>';

    return '<div class="flex items-center justify-between px-4 py-3 border-t border-slate-200 bg-white">'
        . '<div class="text-xs text-slate-500">Page ' . $page . ' of ' . $pages . '</div>'
        . '<div class="flex items-center gap-2">' . $prev . $next . '</div>'
        . '</div>';
}

// SMS and Notification Functions - Wrapper functions that delegate to SmsHelper

/**
 * Get the configured SMS provider
 * Delegates to SmsHelper::getProvider()
 * @return string Provider name (e.g., 'spellcpaas', 'none')
 */
function get_sms_provider(): string {
    if(!class_exists('SmsHelper')) {
        $smsProvider = get_app_setting('sms_provider');
        return $smsProvider !== null ? (string)$smsProvider : 'none';
    }
    return SmsHelper::getProvider();
}

/**
 * Get the configured SMS API key
 * Delegates to SmsHelper::getApiKey()
 * @return string API key or empty string if not configured
 */
function get_sms_api_key(): string {
    if(!class_exists('SmsHelper')) {
        $apiKey = get_app_setting('sms_api_key');
        return $apiKey !== null ? (string)$apiKey : '';
    }
    return SmsHelper::getApiKey();
}

/**
 * Check if SMS is properly configured
 * Delegates to SmsHelper::isConfigured()
 * @return bool True if SMS provider and API key are configured
 */
function is_sms_configured(): bool {
    if(!class_exists('SmsHelper')) {
        $provider = get_app_setting('sms_provider');
        $apiKey = get_app_setting('sms_api_key');
        return ((string)$provider) === 'spellcpaas' && ((string)$apiKey) !== '';
    }
    return SmsHelper::isConfigured();
}

/**
 * Send SMS notification via configured provider
 * Delegates to SmsHelper::send()
 * @param string $phoneNumber Recipient phone number
 * @param string $message SMS message text
 * @return array ['success' => bool, 'message' => string]
 */
function send_sms_notification(string $phoneNumber, string $message): array {
    if(!class_exists('SmsHelper')) {
        return ['success' => false, 'message' => 'SMS not configured'];
    }
    return SmsHelper::send($phoneNumber, $message);
}



/**
 * Get SMS balance from configured provider
 * Delegates to SmsHelper::getBalance()
 * @return array ['success' => bool, 'message' => string, 'balance' => int|null, 'data' => array|null]
 */
function get_sms_balance(): array {
    if(!class_exists('SmsHelper')) {
        return ['success' => false, 'message' => 'SMS not configured', 'balance' => null, 'data' => null];
    }
    return SmsHelper::getBalance();
}

$entryScript = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$appTimezone = get_app_timezone();
@date_default_timezone_set($appTimezone);
$publicScripts = ['login.php', 'installer.php', 'activate.php'];
if(!in_array($entryScript, $publicScripts, true)){
    require_auth();
}