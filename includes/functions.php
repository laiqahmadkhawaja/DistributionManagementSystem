<?php
/**
 * Global Utility Functions
 * Enterprise Distribution Management System (DMS)
 */

if (!defined('DMS_INIT')) {
    define('DMS_INIT', true);
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function formatMoney(float|int|string|null $amount, bool $showSymbol = true): string {
    $num = (float)($amount ?? 0);
    $formatted = number_format($num, 2, '.', ',');
    return $showSymbol ? DEFAULT_CURRENCY_SYMBOL . ' ' . $formatted : $formatted;
}

function formatDate(?string $date, string $format = 'd M Y'): string {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime, string $format = 'd M Y, h:i A'): string {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($datetime));
}

function redirect(string $url): void {
    if (!headers_sent()) {
        header("Location: {$url}");
        exit;
    }
    echo '<script type="text/javascript">window.location.href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

function setFlashMessage(string $type, string $message): void {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashMessages(): array {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function generateDocNumber(PDO $pdo, string $table, string $column, string $prefix): string {
    $datePart = date('Ymd');
    $query = "SELECT {$column} FROM `{$table}` WHERE `{$column}` LIKE ? ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $pattern = $prefix . $datePart . '%';
    $stmt->execute([$pattern]);
    $lastDoc = $stmt->fetchColumn();

    if ($lastDoc) {
        $sequence = (int)substr($lastDoc, strlen($prefix . $datePart));
        $newSequence = str_pad((string)($sequence + 1), 4, '0', STR_PAD_LEFT);
    } else {
        $newSequence = '0001';
    }

    return $prefix . $datePart . $newSequence;
}

function getSetting(PDO $pdo, string $key, ?string $default = null, bool $refresh = false): ?string {
    static $settingsCache = [];
    if (!$refresh && isset($settingsCache[$key])) {
        return $settingsCache[$key];
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM `settings` WHERE `setting_key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $settingsCache[$key] = ($val !== false) ? $val : $default;
    return $settingsCache[$key];
}

function getCompanyInfo(PDO $pdo): array {
    static $company = null;
    if ($company === null) {
        $stmt = $pdo->query("SELECT * FROM `companies` WHERE id = 1 LIMIT 1");
        $company = $stmt->fetch() ?: [
            'name' => 'Falcon FMCG Distribution',
            'legal_name' => 'Falcon Distribution Pvt Ltd',
            'currency' => 'PKR',
            'currency_symbol' => 'Rs.',
            'city' => 'Gujranwala',
            'phone' => '+92 300 1234567',
            'email' => 'info@falcon-dist.com'
        ];
    }
    return $company;
}
