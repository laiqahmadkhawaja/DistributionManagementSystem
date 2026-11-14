<?php
/**
 * Audit Logging Service
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

function logAudit(PDO $pdo, string $module, ?int $recordId, string $action, $previousData = null, $newData = null): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $prevJson = $previousData !== null ? json_encode($previousData, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $newData !== null ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare("
            INSERT INTO `audit_logs` (`user_id`, `module`, `record_id`, `action`, `previous_data`, `new_data`, `ip_address`, `user_agent`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $module, $recordId, $action, $prevJson, $newJson, $ip, $userAgent]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
