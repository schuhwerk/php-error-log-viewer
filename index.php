<?php

/**
 * PHP Error Log Viewer.
 * Check readme.md for more information.
 *
 * Disclaimer
 * - This contains code for reading & deleting your log-file.
 * - Log files might contain sensitive information.
 * - It is meant for development-environments
 */

namespace Php_Error_Log_Viewer;

require_once 'src/LogHandler.php';
require_once 'src/AjaxHandler.php';

$path = 'php-error-log-viewer.ini';
// search settings directly outside the vendor folder.
$settings = file_exists('../../' . $path) ? parse_ini_file('../../' . $path) : array();
// search settings in the same folder as the file.
$settings = file_exists($path) ? parse_ini_file($path) : $settings;

$log_handler = new LogHandler($settings);
$ajax_handler = new AjaxHandler($log_handler);
$ajax_handler->handle_ajax_requests();

readfile('./src/error-log-viewer-frontend.html');
