<?php
/**
 * User Sign Out
 * Enterprise Distribution Management System (DMS)
 */

require_once __DIR__ . '/config/app.php';

logoutUser();
setFlashMessage('info', 'You have been successfully signed out.');
redirect(BASE_URL . '/login.php');
