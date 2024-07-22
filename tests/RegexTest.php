<?php

namespace Php_Error_Log_Viewer\Tests;

require_once dirname(__DIR__) . '/src/LogHandler.php';

use PHPUnit\Framework\TestCase;
use Php_Error_Log_Viewer\LogHandler;

final class EmailTest extends TestCase
{
    public function testLogParsing(): void
    {

        $log_handler = new LogHandler(array( 'file_path' => dirname(__FILE__) . '/test-data/test-debug-1' ));

        $content = $log_handler->get_parsed_content();

        // multiple tests in one, we could split them up for better readability.
        $this->assertEquals($content[0], array (
            "id" => "2450744674",
            "cnt" => "3", // grouping works
            "time" => "2022-01-29T16:02:48+01:00", // timezone is parsed properly & most recent time is used
            "msg" => "PHP Fatal error:  require_once(): Failed opening required",
            "cls" => "Fatal error", // proper class is assigned
        ));

        // Make sure multiline strings (like print_r) are parsed properly. remove newlines, so test runs in win & linux.
        $this->assertEquals(1111982704, crc32(str_replace(["\r", "\n"], '', $content[14]['msg'])));

        $this->assertStringContainsString("Snacks", $content[16]['msg']);
    }
}
