<?php
/**
 * Root Entry Point
 * Enterprise Distribution Management System (DMS)
 */

require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
} else {
    redirect(BASE_URL . '/login.php');
}
