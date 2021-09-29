<?php
/**
 * PHP Error Log Viewer.
 * Check readme.md for more information.
 *
 *  * Disclamer
 * - This contains code for deleting your log-file.
 * - It is meant for development-environments
 */

$pelv_path = 'php-error-log-viewer.ini';
// search settings directly outside the vendor folder.
$pelv_settings = file_exists( '../../' . $pelv_path ) ? parse_ini_file( '../../' . $pelv_path ) : array();
// search settings in the same folder as the file.
$pelv_settings = file_exists( $pelv_path ) ? parse_ini_file( $pelv_path ) : $pelv_settings;
require_once 'pelv.php';

$pelv_log_handler = new Pelv( $pelv_settings );

echo $pelv_log_handler->handle_ajax_requests();
