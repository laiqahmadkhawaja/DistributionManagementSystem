<?php
/**
 * Database Auto-Installer & Verification Script
 * Distribution Management System
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = '127.0.0.1';
$port = 3306;
$db   = 'dms_db';
$user = 'root';
$pass = '';

echo "Connecting to MySQL server on {$host}:{$port}...\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db}`");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    echo "Database `{$db}` ready and FOREIGN_KEY_CHECKS disabled.\n";

    function executeSqlFile($pdo, $filePath) {
        $sql = file_get_contents($filePath);
        // Remove comments
        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            $cleanSql .= $line . "\n";
        }

        // Split by semicolon followed by newline
        $statements = preg_split('/;\s*[\r\n]+/', $cleanSql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (!empty($stmt)) {
                $pdo->exec($stmt);
            }
        }
    }

    echo "Executing schema.sql...\n";
    executeSqlFile($pdo, __DIR__ . '/schema.sql');
    echo "Schema completed.\n";

    echo "Executing seed.sql...\n";
    executeSqlFile($pdo, __DIR__ . '/seed.sql');
    echo "Seed completed.\n";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // Verify
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "SUCCESS: " . count($tables) . " tables created in database:\n";
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo " - {$t} ({$count} rows)\n";
    }

    echo "\n=== DMS DATABASE INSTALLATION COMPLETE ===\n";

} catch (Exception $e) {
    echo "FATAL INSTALLATION ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
