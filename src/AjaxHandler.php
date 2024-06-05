<?php

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

/**
 * A very simple class which uses queryvars to handle ajax requests.
 *
 * @package Php_Error_Log_Viewer
 * @property LogHandler $log_handler
 */
class AjaxHandler
{
    public $log_handler;

    public function __construct($log_handler)
    {
        $this->log_handler = $log_handler;
    }

    public function handle_ajax_requests()
    {
        $this->ajax_handle_errors();
        if (isset($_GET['get_log'])) {
            $this->ajax_json_log();
        }
        if (isset($_GET['delete_log'])) {
            $this->ajax_delete();
        }
        if (isset($_GET['filesize'])) {
            $this->ajax_filesize();
        }
    }

    public function ajax_handle_errors()
    {
        $used = array_diff(array( 'get_log', 'delete_log', 'filesize' ), array_keys($_GET));
        if (count($used) === 3) {
            return;
        }
        $file_issues = $this->log_handler->get_file_issues();

        if (! empty($file_issues)) {
            $this->ajax_header();
            echo $file_issues;
            die();
        }
    }

    public function ajax_header()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }

    public function ajax_json_log()
    {
        $this->ajax_header();
        $content = $this->log_handler->get_parsed_content();
        echo json_encode(array_values($content));
        die();
    }

    public function ajax_delete()
    {
        $this->ajax_header();
        echo $this->log_handler->delete();
        die();
    }

    public function ajax_filesize()
    {
        $this->ajax_header();
        echo json_encode($this->log_handler->get_size());
        die();
    }
}
