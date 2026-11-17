<?php
/**
 * Ledger Redirection to Unified General Ledger & Trial Balance
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
redirect(BASE_URL . '/modules/accounting/ledger.php');
