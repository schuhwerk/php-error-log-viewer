<?php

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

use RuntimeException;

/**
 * Settings, access control and the three requests: the page, a log's new entries, emptying a log.
 * From the command line it prints a log's groups instead: `php index.php --help`.
 */
final class Viewer
{
    private const array DEFAULTS = [
        'files' => [],
        'file_path' => '../debug.log',
        'link_template' => 'vscode://file/{{path}}:{{line_number}}',
        'link_path_search' => '',
        'link_path_replace' => '',
        'password' => '',
        'max_mb' => 32,
    ];

    private const array LEVELS = ['error', 'warning', 'notice', 'deprecated', 'info', 'debug', 'none'];

    private const string USAGE = <<<'TXT'
        Usage: php index.php [options] [file]
        One line per group of equal messages, newest last: count, level, time of the latest, first line.
        Without a file it reads the first one in the settings.

          --since=TIME    only entries from TIME on, and counts only those (what strtotime() reads: '10 min ago')
          --level=LIST    only these levels, comma-separated: error,warning,notice,deprecated,info,debug,none
          --strip=PREFIX  remove PREFIX from messages, e.g. the server's root for short paths
          --width=N       cut the first line to N characters in the middle, so its "on line N" stays (default 200, 0: none)
          --last=N        only the newest N groups
          --full          the whole message below its line (stack traces, dumps), uncut

        Exit status: 0, also with nothing to show; 1 the file cannot be read; 2 wrong options.

        TXT;

    /**
     * @param array<string, mixed> $settings As settings() returns them.
     * @param \Closure(): string $page Returns the page's HTML.
     */
    public function __construct(private array $settings, private \Closure $page)
    {
    }

    /**
     * Reads php-error-log-viewer.ini from the viewer's folder, else from its grandparent (for a composer install: vendor/).
     *
     * @return array<string, mixed> The settings, with absolute log paths in 'files'.
     */
    public static function settings(string $dir): array
    {
        $ini = [];
        foreach ([$dir, dirname($dir, 2)] as $folder) {
            if (is_file($folder . '/php-error-log-viewer.ini')) {
                $ini = parse_ini_file($folder . '/php-error-log-viewer.ini') ?: [];
                break;
            }
        }
        foreach ($ini as $key => $value) {
            if (str_starts_with($key, 'vscode_')) { // Former names, from when links went to VS Code only.
                $ini['link_' . substr($key, 7)] ??= $value;
            }
        }
        $settings = array_merge(self::DEFAULTS, $ini);
        $settings['files'] = array_map(
            static fn(string $file): string => preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $file) ? $file : $dir . '/' . $file,
            array_values((array) ($settings['files'] ?: $settings['file_path']))
        );
        return $settings;
    }

    /** The page with the stylesheet and script inlined, from the sources in $src. */
    public static function assemble(string $src): string
    {
        return strtr(file_get_contents($src . '/ui.html'), [
            '/*css*/' => file_get_contents($src . '/ui.css'),
            '/*js*/' => file_get_contents($src . '/ui.js'),
        ]);
    }

    public function run(): void
    {
        if (PHP_SAPI === 'cli') {
            exit($this->cli());
        }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        try {
            $this->authorize();
            if (isset($_GET['log'])) {
                $this->api('GET');
                $this->json($this->read($this->file($_GET['log']), (int) ($_GET['from'] ?? 0)));
            } elseif (isset($_GET['clear'])) {
                $this->api('POST');
                $this->json($this->clear($this->file($_GET['clear'])));
            } else {
                $this->page();
            }
        } catch (RuntimeException $e) {
            http_response_code($e->getCode());
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
    }

    /**
     * Groups what a log gained since $from. Reads complete lines only, and at most the last 'max_mb'.
     *
     * @return array{size: int, from: int, to: int, reset: bool, writable: bool, rows: list<array<string, mixed>>}
     *         'from'..'to' is the byte range read; 'reset' says the file shrank, so what the caller holds is void.
     */
    public function read(string $file, int $from, ?int $since = null): array
    {
        $handle = is_file($file) && is_readable($file) ? fopen($file, 'rb') : false;
        if (!$handle) {
            throw new RuntimeException($file . (is_file($file) ? ' is not readable.' : ' does not exist.'), 404);
        }
        $size = fstat($handle)['size'];
        $reset = $from < 0 || $from > $size;
        $from = $reset ? 0 : $from;
        $start = max($from, $size - (int) ($this->settings['max_mb'] * 1048576));
        if ($start > $from) {
            fseek($handle, $start - 1);
            fgets($handle); // Lands on the next line's start.
            $start = ftell($handle);
        } else {
            fseek($handle, $start);
        }

        $parser = new Parser($since);
        $to = $start;
        // The length bounds memory: a longer line arrives in pieces.
        while (($line = fgets($handle, 65536)) !== false) {
            if (!str_ends_with($line, "\n") && feof($handle)) {
                break; // Still being written: next time.
            }
            $parser->line($line);
            $to = ftell($handle);
        }
        fclose($handle);

        return [
            'size' => $size,
            'from' => $start,
            'to' => $to,
            'reset' => $reset,
            'writable' => is_writable($file),
            'rows' => $parser->groups(),
        ];
    }

    /** @return array{ok: true} */
    private function clear(string $file): array
    {
        $handle = is_writable($file) ? fopen($file, 'r+') : false;
        if (!$handle || !ftruncate($handle, 0)) {
            throw new RuntimeException($file . ' is not writable.', 403);
        }
        return ['ok' => true];
    }

    /** With a password set, asks for it (HTTP Basic, any user name). Without one, serves local and private addresses only. */
    private function authorize(): void
    {
        $password = (string) $this->settings['password'];
        if ($password === '') {
            $forwarded = ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') . ',' . ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            foreach (array_map('trim', explode(',', $_SERVER['REMOTE_ADDR'] . ',' . $forwarded)) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('Local clients only. Set a password in php-error-log-viewer.ini to open the viewer to others.', 403);
                }
            }
            return;
        }
        $given = $_SERVER['PHP_AUTH_PW'] ?? null;
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($given === null && preg_match('~^Basic (.+)~i', $header, $m)) {
            $given = explode(':', (string) base64_decode($m[1]), 2)[1] ?? '';
        }
        $hashed = password_get_info($password)['algo'] !== null;
        if ($given === null || !($hashed ? password_verify($given, $password) : hash_equals($password, $given))) {
            header('WWW-Authenticate: Basic realm="Log Viewer", charset="UTF-8"');
            throw new RuntimeException('Password required.', 401);
        }
    }

    /** No other site can forge these: a custom header needs a CORS preflight, which is never answered. */
    private function api(string $method): void
    {
        if (
            $_SERVER['REQUEST_METHOD'] !== $method
            || !isset($_SERVER['HTTP_X_LOG_VIEWER'])
            || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin'
        ) {
            throw new RuntimeException('Not a request from the viewer.', 403);
        }
    }

    /** Clients name a log by its position in the settings, never by path. */
    private function file(mixed $index): string
    {
        $files = $this->settings['files'];
        if (!is_string($index) || !ctype_digit($index) || !isset($files[(int) $index])) {
            throw new RuntimeException('No such log.', 404);
        }
        return $files[(int) $index];
    }

    private function page(): void
    {
        $nonce = base64_encode(random_bytes(16));
        $config = [
            'files' => array_map('basename', $this->settings['files']),
            'link' => [
                'template' => (string) $this->settings['link_template'],
                'search' => (string) $this->settings['link_path_search'],
                'replace' => (string) $this->settings['link_path_replace'],
            ],
        ];
        header('Content-Type: text/html; charset=utf-8');
        header(
            "Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; "
            . "connect-src 'self'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
        );
        echo strtr(($this->page)(), [
            '{{nonce}}' => $nonce,
            '{{config}}' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): void
    {
        header('Content-Type: application/json');
        if (function_exists('ob_gzhandler') && !ini_get('zlib.output_compression')) {
            ob_start('ob_gzhandler');
        }
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Prints a log's groups as USAGE describes. @return int The exit status. */
    private function cli(): int
    {
        $known = ['since:', 'level:', 'strip:', 'width:', 'last:', 'full', 'help'];
        $opts = getopt('h', $known, $next);
        $args = array_slice($_SERVER['argv'], $next);
        // getopt() skips an option it does not know.
        $unknown = array_filter(
            array_slice($_SERVER['argv'], 1, $next - 1),
            static fn(string $arg): bool => $arg[0] === '-' && $arg !== '-h'
                && !in_array(preg_replace('~^--([^=]*).*~s', '$1', $arg), str_replace(':', '', $known), true)
        );
        if (isset($opts['h']) || isset($opts['help'])) {
            echo self::USAGE;
            return 0;
        }
        $since = isset($opts['since']) ? strtotime((string) $opts['since']) : null;
        $levels = isset($opts['level']) ? explode(',', (string) $opts['level']) : null;
        $width = (string) ($opts['width'] ?? '200');
        $last = (string) ($opts['last'] ?? '0');
        $wrong = array_diff($levels ?? [], self::LEVELS);
        $error = match (true) {
            $unknown !== [] => 'Unknown option ' . reset($unknown),
            array_filter($opts, 'is_array') !== [] => 'An option given twice',
            $since === false => '--since: strtotime() cannot read ' . $opts['since'],
            $wrong !== [] => '--level: no level ' . implode(', ', $wrong),
            !ctype_digit($width . $last) => '--width and --last take a whole number',
            count($args) > 1, str_starts_with($args[0] ?? '', '-') => 'One file at most, after the options',
            default => '',
        };
        if ($error !== '') {
            fwrite(STDERR, "$error.\n\n" . self::USAGE);
            return 2;
        }
        try {
            $rows = $this->read($args[0] ?? $this->settings['files'][0], 0, $since)['rows'];
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
        if ($levels !== null) {
            $rows = array_filter($rows, static fn(array $row): bool => in_array($row['lvl'] ?: 'none', $levels, true));
        }
        usort($rows, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        $rows = (int) $last ? array_slice($rows, -(int) $last) : $rows;
        $full = isset($opts['full']);
        foreach ($rows as $row) {
            $message = ($opts['strip'] ?? '') === '' ? $row['msg'] : str_replace((string) $opts['strip'], '', $row['msg']);
            $message = self::printable($message);
            [$head, $body] = explode("\n", $message, 2) + [1 => ''];
            $time = $row['t'] ? date('Y-m-d H:i:s', $row['t']) : str_repeat('-', 19);
            printf("%5d  %-10s  %s  %s\n", $row['n'], $row['lvl'] ?: '-', $time, $full ? $head : self::cut($head, (int) $width));
            if ($full && $body !== '') {
                echo preg_replace('~^~m', '    ', $body), "\n";
            }
        }
        return 0;
    }

    /**
     * Control characters but tab and line feed, as `\x1b`: an escape sequence a log message carries (user input often)
     * could otherwise retitle the terminal, write its clipboard or hide lines. C1 counts in UTF-8 form, as terminals read it.
     */
    private static function printable(string $text): string
    {
        return preg_replace_callback(
            '~[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]~',
            static fn(array $m): string => sprintf('\\x%02x', ord($m[0][-1])),
            $text
        ) ?? $text;
    }

    /** Cuts $text to $width bytes in the middle, where a message tells least: its start and its "on line N" stay. */
    private static function cut(string $text, int $width): string
    {
        if ($width === 0 || strlen($text) <= $width) {
            return $text;
        }
        $end = intdiv($width, 3);
        return substr($text, 0, max(0, $width - $end - 1)) . '…' . ($end ? substr($text, -$end) : '');
    }
}
