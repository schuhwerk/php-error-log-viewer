<?php

declare(strict_types=1);

namespace Php_Error_Log_Viewer;

/**
 * Read, process, delete log files. Output as json.
 */
class LogHandler
{
    /**
     * Contains grouped content of the log file.
     *
     * @var array
     */
    public $content = array();

    /**
     * Index used for grouping same messages.
     * Created via crc32().
     *
     * @var int[]
     */
    public $index = array();

    /**
     * The size of the file.
     *
     * @var int
     */
    public $filesize = 0;

    /**
     * The settings which are being applied.
     *
     * @var array
     */
    public $settings = array();

    /**
     * The default setting
     *
     * @var array
     */
    public $default_settings = array(
        'file_path'           => '../debug.log',
        /**
         * Stack trace references files. Make those links clickable.
         * Parts in double curly braces are placeholders.
         * @see https://code.visualstudio.com/docs/editor/command-line#_opening-vs-code-with-urls).
         */
        'link_template'        => 'vscode://file/{{path}}:{{line_number}}',
        'link_path_search'  => '', // This is needed if you develop on a vm. like '/srv/www/...'.
        'link_path_replace' => '', // The local path to your repo. like 'c:/users/...'.
    );

    public function __construct($settings)
    {
        $this->settings = array_merge($this->default_settings, $this->handle_deprecated_settings($settings));
    }

    /**
     * Settings-keys were previously named vscode_*. We generalized that.
     * To support backwards-compatibility we rename the keys (vscode_foo -> code_foo).
     *
     * @param array[] $s settings.
     * @return array []
     */
    private function handle_deprecated_settings($s)
    {
        if (isset($s['vscode_links']) && true == $s['vscode_links']) {
            $s['link_template'] = $this->default_settings['link_template'];
        }
        foreach ($s as $key => $value) {
            if (0 === strpos($key, 'vscode_')) {
                $new_key = str_replace('vscode_', 'link_', $key);
                $s[ $new_key ] = ! isset($s[ $new_key ]) ? $value : $s[ $new_key ];
            }
        }
        return $s;
    }

    /**
     * Read the log-file.
     *
     * @return string|false The read string or false on failure.
     */
    public function get_file()
    {
        $my_file = fopen($this->settings['file_path'], 'r');
        $size    = $this->get_size();
        return ( $my_file && $size ) ? fread($my_file, $size) : false;
    }

    /**
     * Get the size of the log-file.
     *
     * @return int|false The size of the log file in bytes or false.
     */
    public function get_size()
    {
        if (empty($this->filesize)) {
            $this->filesize = filesize($this->settings['file_path']);
        }
        return $this->filesize;
    }

    /**
     * Get a description of any issue with the log-file. Empty string if no issue.
     *
     * @return string The description of the issue (or empty string).
     */
    public function get_file_issues()
    {
        if (! file_exists($this->settings['file_path'])) {
            return "The file ({$this->settings['file_path']}) was not found. " .
            'You can specify a different file/location in the settings (check readme.md).';
        }
        $mbs = $this->get_size() / 1024 / 1024; // in MB.
        if ($mbs > 100) {
            if (! isset($_GET['ignore'])) {
                return( "Aborting. debug.log is larger than 100 MB ($mbs).
					If you want to continue anyway add the 'ignore' queryvar"
                );
            }
        }
        return '';
    }

    /**
     * Triggers preg_replace_callback which calls
     * replace_callback function which stores values in $this->content.
     *
     * @param string $raw The content of the log file.
     * @return void
     */
    private function parse($raw)
    {
        $error = preg_replace_callback('~^\[([^\]]*)\]((?:[^\r\n]*[\r\n]?(?!\[).*)*)~m', array( $this, 'replace_callback' ), $raw);
    }

    public function get_parsed_content()
    {
        $file = $this->get_file();
        if (! $file) {
            die("File is empty or can't be opened.");
        }
        $this->parse($file); // writes to $this->content. preg_replace_callback is odd.
        return array_values($this->content);
    }

    public function link_files($string)
    {
        $string = preg_replace_callback('$([A-Z]:)?([\\\/][^:(\s]+)(?: on line |[:\(])([0-9]+)\)?$', array( $this, 'link_filter' ), $string);
        return $string;
    }

    /**
     *
     * @param array $matches
     *      0 => full match
     *      1 => hard-drive ( windows only, like "C:" )
     *      2 => path (from: on line")
     *      3 => line number
     * @return string|bool
     */
    public function link_filter($matches)
    {
        $template = array(
            'path' => str_replace($this->settings['link_path_search'], $this->settings['link_path_replace'], $matches[1] . $matches[2]),
            'line_number' => $matches[3]
        );
        $link = $this->settings['link_template'];
        foreach ($template as $key => $value) {
            $link = str_replace("{{" . $key . "}}", $value, $link); // apply the template.
        }
        return "<a href='$link'>" . $matches[0] . '</a>';
    }

    /**
     * Callback function which is triggered by preg_replace_callback.
     * Doesn't return but writes to $this->content.
     *
     * @param array $arr
     * looks like that:
     * array (
     *      0   =>  [01-Jun-2016 09:24:02 UTC] PHP Fatal error:  Allowed memory size of 456 bytes exhausted (tried to allocate 27 bytes) in ...
     *      1   =>  [01-Jun-2016 09:24:02 UTC]
     *      2   =>  PHP Fatal error:  Allowed memory size of 56 bytes exhausted (tried to allocate 15627 bytes) in ... *
     * )
     * @return void
     */
    public function replace_callback($arr)
    {
        $err_id = crc32(trim($arr[2])); // create a unique identifier for the error message.
        if (! isset($this->content[ $err_id ])) { // we have a new error.
            $this->content[ $err_id ]        = array();
            $this->content[ $err_id ]['id']  = $err_id; // err_id.
            $this->content[ $err_id ]['cnt'] = 1; // counter.
            $this->index[] = $err_id;
        } else { // we already have that error...
            $this->content[ $err_id ]['cnt']++; // counter.
        }

        $date = date_create($arr[1]); // false if no valid date.
        $this->content[ $err_id ]['time'] = $date ? $date->format(\DateTime::ATOM) : $arr[1]; // ISO8601, readable in js
        $message = htmlspecialchars(trim($arr[2]), ENT_QUOTES);
        $this->content[ $err_id ]['msg'] = $this->settings['link_template'] ? $this->link_files($message) : $message;
        $this->content[ $err_id ]['cls'] = implode(
            ' ',
            array_slice(
                str_word_count($this->content[ $err_id ]['msg'], 2),
                1,
                2
            )
        ); // the first few words of the message become class items.
    }

    public function delete()
    {
        if (! file_exists($this->settings['file_path'])) {
            return 'There was no file to delete';
        }
        if (! is_writeable(realpath($this->settings['file_path']))) {
            return 'Your log file is not writable';
        }
        $f = @fopen($this->settings['file_path'], 'r+');
        if ($f !== false) {
            ftruncate($f, 0);
            fclose($f);
            return 'Emptied file';
        } else {
            return 'File could not be emptied';
        }
    }
}
