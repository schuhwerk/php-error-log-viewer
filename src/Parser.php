<?php

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

/**
 * Turns log lines into entries and groups equal ones. Knows nothing about files or HTTP.
 *
 * An entry starts at a line that opens with a timestamp, every other line continues the entry before it.
 */
final class Parser
{
    /**
     * A timestamp at the start of a line, bracketed or not: PHP and php-fpm "15-Nov-2026 10:00:00 UTC",
     * nginx and ISO 8601 "2026/11/15 10:00:00", Apache "Sun Nov 15 10:00:00.123 2026".
     */
    private const string HEAD = '~^\[?(\d{1,2}-[A-Za-z]{3}-\d{4} [\d:.]{8,15}(?: [\w/+:-]+(?=\]))?'
        . '|\d{4}[-/]\d\d[-/]\d\d[ T][\d:.,]{8,15}(?:Z|[+-]\d\d:?\d\d)?'
        . '|[A-Z][a-z]{2} [A-Z][a-z]{2} [ \d]\d [\d:.]{8,15} \d{4})\]?[ \t]*~';

    /** Xdebug and php-fpm give every line of a stack trace a timestamp of its own: these stay with the entry before. */
    private const string TRACE = '~^(?:(?:PHP )?\s*(?:Stack trace:|#\d+ |thrown in )|PHP +\d+\. )~';

    /** What differs between two occurrences of one error (process ids, addresses, the request): not part of its identity. */
    private const string NOISE = '~\b(?:pid|tid|child) \d+|0x[0-9a-f]+|[\n,] ?(?:client|referer): .*$~s';

    /** Where nginx and Apache add the request: on the head line or, for a multi-line one, on its last. */
    private const string REQUEST = '~(?:["\'](?: while reading [^,]*)?)?, (?=client: |referer: )~';

    private const string LEVEL = '~^(?:PHP )?(fatal error|parse error|recoverable|uncaught|error|err|crit|critical|alert'
        . '|emerg|emergency|warn|warning|notice|deprecated|strict standards|info|debug)\b~i';

    /** Keyed by the first three letters of what LEVEL matched. */
    private const array LEVELS = [
        'fat' => 'error', 'par' => 'error', 'rec' => 'error', 'unc' => 'error', 'err' => 'error', 'cri' => 'error',
        'ale' => 'error', 'eme' => 'error', 'war' => 'warning', 'not' => 'notice', 'dep' => 'deprecated',
        'str' => 'deprecated', 'inf' => 'info', 'deb' => 'debug',
    ];

    /** Bytes kept of one entry, so a runaway dump cannot exhaust memory. */
    private const int MAX_ENTRY = 65536;

    /** @var array<string, array{id: string, n: int, t: ?int, lvl: string, msg: string}> Groups by id. */
    private array $groups = [];

    /** @var array{?int, string, string}|null The entry still collecting lines: time, level, message. */
    private ?array $entry = null;

    /** @var array{string, ?int} The last timestamp parsed: neighbouring lines mostly share theirs. */
    private array $clock = ['', null];

    /** @param ?int $since Drops entries older than this Unix time, and those without a time. */
    public function __construct(private ?int $since = null)
    {
    }

    public function line(string $line): void
    {
        $line = rtrim($line, "\r\n");
        $first = $line[0] ?? '';

        if (($first === '[' || ctype_digit($first)) && preg_match(self::HEAD, $line, $m)) {
            [$level, $message, $continues] = self::unwrap(substr($line, strlen($m[0])));
            $continues = $continues && $m[1] === $this->clock[0];
            if ($this->entry !== null && ($continues || preg_match(self::TRACE, $message))) {
                $this->append($message);
                return;
            }
            $this->close();
            $this->entry = [$this->time($m[1]), $level, $message];
        } elseif (($this->entry[0] ?? null) !== null) {
            $this->append($line);
        } elseif ($line !== '') {
            // No timestamp to hang on to (a log without any, or the start of a cut): one entry per line.
            $this->close();
            $this->entry = [null, '', $line];
        }
    }

    /** @return list<array{id: string, n: int, t: ?int, lvl: string, msg: string}> One row per distinct message. */
    public function groups(): array
    {
        $this->close();
        return array_values($this->groups);
    }

    private function append(string $line): void
    {
        if (strlen($this->entry[2]) < self::MAX_ENTRY) {
            $this->entry[2] .= "\n" . $line;
        }
    }

    private function close(): void
    {
        if ($this->entry === null) {
            return;
        }
        [$time, $level, $message] = $this->entry;
        $this->entry = null;
        if ($this->since !== null && ($time ?? PHP_INT_MIN) < $this->since) {
            return;
        }
        $message = rtrim(ltrim(preg_replace(self::REQUEST, "\n", $message) ?? $message, "\n"));
        $id = hash('xxh3', preg_replace(self::NOISE, '', $message) ?? $message);

        $group = &$this->groups[$id];
        $group = [
            'id' => $id,
            'n' => ($group['n'] ?? 0) + 1,
            't' => $time ?? $group['t'] ?? null,
            'lvl' => $group['lvl'] ?? (self::level($message) ?: $level),
            'msg' => $message,
        ];
    }

    private function time(string $date): ?int
    {
        if ($date !== $this->clock[0]) {
            // Without the fraction of a second, which strtotime() rejects in Apache's format.
            $this->clock = [$date, strtotime(preg_replace('~[.,]\d+~', '', $date)) ?: null];
        }
        return $this->clock[1];
    }

    private static function level(string $text): string
    {
        return preg_match(self::LEVEL, $text, $m) ? self::LEVELS[strtolower(substr($m[1], 0, 3))] : '';
    }

    /**
     * Server logs wrap the PHP message. Strips what php-fpm, nginx and Apache put around it.
     *
     * @return array{string, string, bool} The level the server states (the message's own wins later), the message,
     *  and whether it is relayed output that continues the message before (a line of it, a chunk split off).
     */
    private static function unwrap(string $text): array
    {
        // php-fpm relaying a worker, one line each: WARNING: [pool www] child 12 said into stderr: "NOTICE: PHP message: ..."
        if (preg_match('~^\w+: \[pool [^\]]+\] child \d+ said into std\w+: "(?:(\w+): )?(PHP message: )?(.*)"$~s', $text, $m)) {
            return [self::level($m[1]), $m[3], $m[2] === ''];
        }
        // nginx "[error] 12#12: *5 ", Apache "[proxy_fcgi:error] [pid 12] [client 1.2.3.4:5] ".
        if (
            !str_starts_with($text, '[')
            || !preg_match('~^\[(?:\w+:)?(\w+)\] (?:(\d+#\d+: (?:\*\d+ )?)|(?:\[[^\]]*\] )*)~', $text, $m)
            || ($level = self::level($m[1])) === ''
        ) {
            return ['', $text, false];
        }
        $text = substr($text, strlen($m[0]));
        if (($m[2] ?? '') === '') {
            // Apache escapes line breaks and backslashes.
            $text = strtr($text, ['\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
        }
        // The FastCGI wrapper: one PHP message per line. Apache closes it at the end of the line if no referer follows.
        if (!preg_match('~^(?:FastCGI sent in stderr: "|AH\d+: Got error \')~', $text, $w)) {
            return [$level, $text, false];
        }
        $text = preg_replace("~'$~", '', substr($text, strlen($w[0])));
        $continues = !preg_match('~^;? ?PHP message: ~', $text);
        return [$level, ltrim(preg_replace('~\n?;? ?PHP message: ~', "\n", $text)), $continues];
    }
}
