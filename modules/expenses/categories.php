<?php
/**
 * Direct Route for Expense Categories Management
 * Enterprise Distribution Management System (DMS)
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

// Forward cleanly to index with categories tab
$qs = !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : '';
redirect(BASE_URL . '/modules/expenses/index.php?tab=categories' . $qs);
