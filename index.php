<?php

/**
 * PHP Error Log Viewer, run from its sources. `php build.php` packs the same into one file.
 *
 * It shows log files, which may hold sensitive data, and can empty them: see "Security" in readme.md.
 */

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

require __DIR__ . '/src/Parser.php';
require __DIR__ . '/src/Viewer.php';

(new Viewer(Viewer::settings(__DIR__), static fn(): string => Viewer::assemble(__DIR__ . '/src')))->run();
