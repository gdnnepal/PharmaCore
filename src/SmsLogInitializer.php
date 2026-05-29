<?php
// Initialize SMS logging table if it doesn't exist
try {
    $pdo->query("
        CREATE TABLE IF NOT EXISTS sms_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            customer_id INT,
            phone_number VARCHAR(20),
            message LONGTEXT,
            sms_type VARCHAR(50),
            status VARCHAR(20),
            error_message TEXT,
            response_data LONGTEXT,
            sent_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL,
            KEY (customer_id),
            KEY (status),
            KEY (created_at)
        )
    ");

    try {
        $pdo->query("ALTER TABLE sms_logs ADD COLUMN response_data LONGTEXT NULL");
    } catch(Exception $e) {
        // Column may already exist.
    }
} catch(Exception $e) {
    // Table might already exist, that's fine
}
