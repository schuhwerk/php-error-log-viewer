<?php

/**
 * Packs the viewer into dist/php-error-log-viewer.php: the classes, then the page behind __halt_compiler(),
 * where nothing in it can be taken for PHP.
 */

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

require __DIR__ . '/src/Viewer.php';

$header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Php_Error_Log_Viewer;\n";
$php = '';
foreach (['Parser', 'Viewer'] as $class) {
    $source = file_get_contents(__DIR__ . "/src/$class.php");
    str_starts_with($source, $header) || exit("src/$class.php must start with the common header.\n");
    $php .= substr($source, strlen($header));
}

$main = '(new Viewer(Viewer::settings(__DIR__), '
    . 'static fn(): string => file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__)))->run();';
$note = "\n// PHP Error Log Viewer, built from https://github.com/schuhwerk/php-error-log-viewer: edit the sources, not this file.\n";

is_dir(__DIR__ . '/dist') || mkdir(__DIR__ . '/dist');
file_put_contents(
    __DIR__ . '/dist/php-error-log-viewer.php',
    $header . $note . $php . "\n" . $main . "\n__halt_compiler();" . Viewer::assemble(__DIR__ . '/src')
);
