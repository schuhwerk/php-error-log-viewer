<?php

/**
 * The tests: `php tests/run.php`. Prints the failures, then one summary line. No dependencies.
 */

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Viewer.php';

date_default_timezone_set('UTC');
$checks = $failures = 0;

function check(string $what, mixed $actual, mixed $expected = true): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL $what\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n";
    }
}

/** @return list<array<string, mixed>> A fixture's groups, in order of first appearance. */
function groups(string $fixture): array
{
    $parser = new Parser();
    array_map($parser->line(...), file(__DIR__ . "/fixtures/$fixture"));
    return $parser->groups();
}

/** @param list<array<string, mixed>> $rows @return list<string> "count level first-line" per row. */
function summary(array $rows): array
{
    return array_map(static fn(array $row): string => $row['n'] . ' ' . $row['lvl'] . ' ' . strtok($row['msg'], "\n"), $rows);
}

$viewer = static fn(array $settings = []): Viewer => new Viewer($settings + ['max_mb' => 32], static fn(): string => '');

// PHP's own log: grouping, time zones, Xdebug traces, multi-line dumps.
$rows = groups('php.log');
check('php: groups', count($rows), 7);
check('php: equal messages are counted, the latest time wins', [$rows[0]['n'], $rows[0]['t']], [3, strtotime('2022-01-29T16:02:48+01:00')]);
check('php: levels', array_column($rows, 'lvl'), ['error', 'warning', 'info', 'notice', '', '', '']);
check('php: an Xdebug trace stays with its error', substr_count($rows[3]['msg'], "\n"), 10);
check('php: a dump is one entry', str_contains($rows[4]['msg'], '[num_rows] => 1'));
check('php: equal first lines, different bodies', [$rows[4]['n'], str_contains($rows[6]['msg'], 'Snacks')], [1, true]);

// php-fpm: its own messages, relayed worker output, the slow log. The fixtures are real samples from bug reports.
$rows = groups('fpm.log');
check('fpm', summary($rows), [
    '2 notice NOTICE: fpm is running, pid 7',
    '1 notice NOTICE: ready to handle connections',
    '2 notice 0.00017595291137695 :       Auto unscribing after 1 message is received',
    '1 error PHP Fatal error:  Uncaught Symfony\Component\Debug\Exception\UndefinedMethodException: Attempted to call an undefined method named "getQuery" of class "Apisearch\Result\Result".',
    '1 warning WARNING: [pool www] server reached pm.max_children setting (5), consider raising it',
]);
$lines = explode("\n", $rows[3]['msg']);
check('fpm: relayed lines of one message and its trace stay together', [count($lines), $lines[1], $lines[6]], [7, 'Did you mean to call "getQueryUUID"? in /var/www/html/vendor/apisearch-io/sylius-apisearch-plugin/src/Url/UrlBuilder.php:272', '#3 /var/www/html/...']);
$rows = groups('fpm-slow.log');
check('fpm slow log: one group, whatever the process and addresses', [count($rows), $rows[0]['n'], substr_count($rows[0]['msg'], "\n")], [1, 2, 4]);

// nginx and Apache: the message without its wrapper, the request kept but not part of the identity.
$rows = groups('nginx.log');
check('nginx', summary($rows), [
    '2 warning PHP Warning:  Undefined array key "id" in /var/www/wordpress/wp-content/plugins/friends/feed-parsers/class-feed-parser-activitypub.php on line 406',
    '1 warning PHP Warning:  Attempt to read property "ID" on null in /var/www/html/public_html/wp-includes/post-template.php on line 676',
    '1 error Argument 1 passed to Symfony\Component\EventDispatcher\EventDispatcher::dispatch() must be an object, string given, called in /var/www/webpagetest/www/lib/aws/Guzzle/Common/AbstractHasDispatcher.php on line 40',
    '1 error PHP Fatal error:  Uncaught Symfony\Component\Debug\Exception\UndefinedMethodException: Attempted to call an undefined method named "getQuery" of class "Apisearch\Result\Result".',
    '1 error open() "/var/www/html/favicon.ico" failed (2: No such file or directory)',
    '1 notice signal process started',
]);
check('nginx: one message per line, the request on its own', array_slice(explode("\n", $rows[0]['msg']), 1, 1), ['client: 192.0.2.11, server: example.test, request: "POST /wp-admin/admin.php?page=add-friend&url=%40neil%40mastodon.neilzone.co.uk HTTP/1.1", upstream: "fastcgi://unix:/run/php/php8.1-fpm.sock:", host: "example.test", referrer: "https://example.test/wp-admin/admin.php?page=add-friend&url=%40neil%40mastodon.neilzone.co.uk"']);
check('nginx: messages without a separator', explode("\n", $rows[2]['msg'])[1], 'PHP Fatal error:  Uncaught TypeError: Argument 1 passed to Symfony\Component\EventDispatcher\EventDispatcher::dispatch() must be an object, string given, called in /var/www/webpagetest/www/lib/aws/Guzzle/Common/AbstractHasDispatcher.php on line 40 and defined in /var/www/webpagetest/vendor/symfony/event-dispatcher/EventDispatcher.php:48');
check('nginx: a chunk split off stays with its message', str_contains($rows[1]['msg'], "\nar/www/html/public_html/wp-includes/post-template.php on line 677\n"));
check('nginx: a multi-line message', array_slice(explode("\n", $rows[3]['msg']), 4), ['#1 /var/www/html/vendor/t', 'client: 172.26.0.1, server: _, request: "GET /category/artykuly-spozywcze HTTP/1.1", upstream: "fastcgi://127.0.0.1:9000", host: "localhost:80", referrer: "http://localhost:80/category"']);
check('nginx: time', $rows[0]['t'], strtotime('2023-11-07 23:05:06'));
$rows = groups('apache.log');
check('apache', summary($rows), [
    '1 error course 165179 section 1 type Files modulename resource',
    '2 warning PHP Warning:  Undefined array key "cond" in /var/www/dokuwiki/lib/plugins/metaheaders/action.php on line 119',
    '1 error PHP Fatal error:  Uncaught JonnyW\PhantomJs\Exception\NotWritableException: Output file is not writeable by PhantomJs: /opt/bitnami/apache2/htdocs/test.jpg in /opt/bitnami/apache2/htdocs/vendor/jonnyw/php-phantomjs/src/JonnyW/PhantomJs/Http/CaptureRequest.php:199',
    '1 error PHP Fatal error: Uncaught TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given in /home/user/weberp/index.php:106',
    '1 notice AH00489: Apache/2.4.58 (Ubuntu) configured -- resuming normal operations',
]);
check('apache: escaped line breaks, one message per line', array_slice(explode("\n", $rows[0]['msg']), 1, 4), ['Array', '(', '    [0] => stdClass Object', '        (']);
check('apache: no closing quote, the referer on its own line', [str_ends_with($rows[2]['msg'], 'php on line 199'), substr($rows[3]['msg'], -51)], [true, "line 106\nreferer: http://localhost/weberp/index.php"]);
check('apache: time', $rows[1]['t'], strtotime('2026-09-24 08:16:23'));

// Lines without a timestamp.
$parser = new Parser();
array_map($parser->line(...), ["plain one\n", "\n", "plain two\r\n", "plain one\n", "[2026-11-15T10:00:00+01:00] error: iso\n", " more\n"]);
check('no timestamps: one entry per line', summary($parser->groups()), ['2  plain one', '1  plain two', '1 error error: iso']);

// Reading a file: only what is new, only complete lines, the end of a large file.
$file = tempnam(sys_get_temp_dir(), 'log');
file_put_contents($file, "[15-Nov-2026 10:00:00 UTC] one\n[15-Nov-2026 10:00:01 UTC] two\n[15-Nov-2026 10:00:02 UTC] thr");
$first = $viewer()->read($file, 0);
check('read: complete lines only', [summary($first['rows']), $first['to']], [['1  one', '1  two'], 62]);
file_put_contents($file, "ee\n", FILE_APPEND);
$second = $viewer()->read($file, $first['to']);
check('read: what is new', [summary($second['rows']), $second['reset'], $second['to']], [['1  three'], false, 95]);
check('read: nothing new', $viewer()->read($file, 95)['rows'], []);
file_put_contents($file, "[15-Nov-2026 10:00:03 UTC] four\n");
$third = $viewer()->read($file, 95);
check('read: a shrunk file starts over', [summary($third['rows']), $third['reset']], [['1  four'], true]);
file_put_contents($file, str_repeat("[15-Nov-2026 10:00:00 UTC] filler\n", 100) . "[15-Nov-2026 10:00:09 UTC] last\n");
$tail = $viewer(['max_mb' => 50 / 1048576])->read($file, 0);
check('read: the end of a large file, from a line start', [summary($tail['rows']), $tail['from']], [['1  last'], 3400]);
file_put_contents($file, str_repeat('x', 200000) . "\n");
check('read: a huge line is bounded', strlen($viewer()->read($file, 0)['rows'][0]['msg']) < 140000);
unlink($file);

// Settings: an ini next to the viewer, the old vscode_* names, relative and absolute paths.
$dir = sys_get_temp_dir() . '/log-viewer-' . getmypid();
mkdir($dir);
file_put_contents("$dir/php-error-log-viewer.ini", "files[]='../a.log'\nfiles[]='/var/log/b.log'\nvscode_path_search='/srv/'\n");
$settings = Viewer::settings($dir);
check('settings', [$settings['files'], $settings['link_path_search']], [["$dir/../a.log", '/var/log/b.log'], '/srv/']);
unlink("$dir/php-error-log-viewer.ini");
rmdir($dir);
check('settings: default', Viewer::settings('/x/y')['files'], ['/x/y/../debug.log']);

// The command line.
$root = dirname(__DIR__);
$cli = static function (string $args) use ($root): array {
    exec('php ' . escapeshellarg("$root/index.php") . " $args 2>/dev/null", $lines, $status);
    return [$status, $lines];
};
$php = escapeshellarg(__DIR__ . '/fixtures/php.log');
[$status, $lines] = $cli($php);
check('cli: one line per group, newest last', [$status, count($lines), substr($lines[6], 0, 40)], [0, 7, '    1  -           2023-11-12 06:27:29  ']);
check('cli: --level, with none for messages without one', $cli("--level=error,none $php")[1][1], '    3  error       2022-01-29 15:02:48  PHP Fatal error:  require_once(): Failed opening required');
check('cli: --since counts only what follows', $cli("--since='2022-01-29 15:02:48' --level=error $php")[1], ['    1  error       2022-01-29 15:02:48  PHP Fatal error:  require_once(): Failed opening required']);
check('cli: --width cuts in the middle, so the line number stays', substr($cli("--width=60 --level=notice $php")[1][0], 40), 'PHP Notice:  Undefined index: version i…/s2.php on line 4394');
check('cli: --strip shortens paths', str_ends_with($cli("--strip=/var/www/html/ --level=notice $php")[1][0], 'in web/app/plugins/s2/s2.php on line 4394'));
check('cli: --last keeps the newest groups', array_map(static fn(string $line): string => substr($line, 40), $cli("--last=2 $php")[1]), ['### - options - ###', 'Array']);
check('cli: --full adds the rest, indented', array_slice($cli("--full --level=notice $php")[1], 1, 2), ['    PHP Stack trace:', '    PHP   1. {main}() /var/www/html/web/index.php:0']);
$file = tempnam(sys_get_temp_dir(), 'log');
file_put_contents($file, "[15-Nov-2026 10:00:00 UTC] PHP Notice:  a\e]52;c;eA==\x07b\rc\xC2\x9B2Jd\te\n  trace \e[8m\n");
check('cli: control characters are shown, not run', $cli('--full ' . escapeshellarg($file))[1], ['    1  notice      2026-11-15 10:00:00  PHP Notice:  a\\x1b]52;c;eA==\\x07b\\x0dc\\x9b2Jd' . "\te", '      trace \\x1b[8m']);
unlink($file);
check('cli: a wrong option is named first', (string) shell_exec('php ' . escapeshellarg("$root/index.php") . ' --level=fatal 2>&1 | head -1'), "--level: no level fatal.\n");
check('cli: wrong options, a missing file', [$cli("--bogus $php")[0], $cli('--level=fatal')[0], $cli('/nonexistent.log')[0]], [2, 2, 1]);

// The single file behaves like the sources.
exec('php ' . escapeshellarg("$root/build.php"), $output, $status);
$run = static fn(string $entry): string => (string) shell_exec('php ' . escapeshellarg("$root/$entry") . ' ' . escapeshellarg(__DIR__ . '/fixtures/php.log'));
check('build', [$status, $run('dist/php-error-log-viewer.php')], [0, $run('index.php')]);
check('build: one page, assets inlined', preg_match('~__halt_compiler\(\);<!doctype html>.*const LEVELS.*</html>\s*$~s', file_get_contents("$root/dist/php-error-log-viewer.php")), 1);

echo $failures ? "$failures of $checks failed\n" : "ok ($checks)\n";
exit($failures ? 1 : 0);
