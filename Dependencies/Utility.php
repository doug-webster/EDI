<?php

namespace GZMP\Dependencies;

class Utility
{
    /**
     * Assemble a multi-part address into a single line appropriate for google maps.
     *
     * @param string $address
     * @param string $city
     * @param mixed $state A string for a state, or an index into the states lookup table.
     * @param string $zip
     * @return string
     */
    public static function assembleAddress($address = '', $city = '', $state = '', $zip = '')
    {
        return trim($address . ' ' . $city . ', ' . $state . ' ' . $zip);
    }

    /**
     *
     * @param string|array $toEmail
     * @param int $imageId
     * @param string $subject
     * @param string $messageBody
     * @param string $zipFilePath
     * @param string $staticFilePath
     * @param bool $replyTo
     * @param string $htmlBody
     * @param null $emailID
     * @param bool $log
     * @return bool
     */
    public static function sendEmail(
        $toEmail,
        $imageId,
        $subject,
        $messageBody,
        $zipFilePath = '',
        $staticFilePath = '',
        $replyTo = false,
        $htmlBody = ''
    ) {
    }

    public static function explodeDateTime($str)
    {
        $timezone = '';

        if (!$str) {
            return false;
        }

        $month = $year = $day = $hour = $min = $sec = 0;

        $stamp = explode(" ", $str);
        if (count($stamp) > 1) {
            list($date, $time) = $stamp;

            $p = explode(":", $time);
            if (isset($p[0])) {
                $hour = $p[0];
            }
            if (isset($p[1])) {
                $min = $p[1];
            }
            if (isset($p[2])) {
                $sp = explode('+', $p[2]);
                if (count($sp) == 2) {
                    //we have a timezone on this
                    $sec = $sp[0];
                    $timezone = $sp[1];
                } else {
                    $sec = $p[2];
                }
            }
        } else {
            list($date) = $stamp;
        }


        if ($dateFormat == '' && count(explode('-', $date)) >= 3) {
            $timeArray = explode("-", $date);
            $dateFormat = "yearMonthDay";
        } elseif ($dateFormat == '' && count(explode('/', $date)) >= 3) {
            $timeArray = explode("/", $date);
            $dateFormat = "monthDayYear";
        } else {
            return false;
        }

        if (count($timeArray) == 3) {
            if ($dateFormat == "yearMonthDay") {
                list($year, $month, $day) = $timeArray;
            } elseif ($dateFormat == "monthDayYear") {
                list($month, $day, $year) = $timeArray;
            }
        } else {
            return false;
        }

        return [
            'year'     => $year,
            'month'    => $month,
            'day'      => $day,
            'hour'     => $hour,
            'minute'   => $min,
            'second'   => $sec,
            'timezone' => $timezone,
        ];
    }

    // returns the given path with a trailing / if not already present
    public static function ensureTrailingSlash($path)
    {
        if (substr($path, -1) != '/') {
            $path .= '/';
        }
        return $path;
    }

    // limits a string to the specified max_length; returns string
    public static function stringLengthLimit($string, $max_length, $append = '')
    {
        mb_internal_encoding('UTF-8');
        if (mb_strlen($string) > $max_length) {
            $string = mb_substr($string, 0, $max_length);
            $string = mb_substr($string, 0, mb_strrpos($string, ' '));
            $string .= $append;
        }
        return $string;
    }

    public static function nonAlphaNumericReplace($text, $replace = '_', $trim = true)
    {
        if (!is_string($text) || !is_string($replace)) {
            return false;
        }
        $text = preg_replace('/[^0-9a-zA-Z]+/', $replace, $text);
        if ($trim) {
            $text = trim($text, $replace);
        }
        return $text;
    }

    // attempts to improve handling of string values over empty() by returning true for strings containing only whitespace, and decimal values equalling 0
    // can't check if variable isset, otherwise should mirror empty()
    public static function isEmpty($value)
    {
        // match the empty function which will return true for an empty array
        if (is_array($value) && empty($value)) {
            return true;
        }
        if (is_string($value)) {
            // consider a string with only whitespace to be empty
            if (trim($value) == '') {
                return true;
            }
            // when non-numeric strings are cast to numeric they equal zero
            // however we want to return false in these cases
            if (!is_numeric($value)) {
                return false;
            }
        }
        return self::equal($value, 0);
    }

    // returns true if values are equal, false otherwise
    // intended for use in checking equality of floating point values though I believe it will work for other values as well
    public static function equal($a, $b, $precision = 2)
    {
        if (!is_numeric($a) || !is_numeric($b)) {
            return $a == $b;
        }
        // The round function is necessary to address challenges with comparing floating point numbers
        return round($a, $precision) == round($b, $precision);
    }

    // takes a number and returns a string formatted for display
    // ex. $value = 34 returns "$34.00"
    // if $blank_zero is true, will return an empty string if $value == 0.00
    public static function formatCurrency($value, $blank_zero = true)
    {
        if ($blank_zero && self::isEmpty(round($value, 2))) {
            return '';
        }
        return (is_numeric($value)) ? '$' . number_format($value, 2) : $value;
    }

    // maskString('somestring', 'X', 2)  returns soXXXXXXXX
    // maskString('somestring', 'X', -2) returns XXXXXXXXng
    public static function maskString($string, $mask, $num_chrs_to_keep = 0)
    {
        if (empty($string)) {
            return '';
        }

        // if $num_chrs_to_keep positive, characters will be kept at beginning
        // if $num_chrs_to_keep negative, characters will be kept at end
        mb_internal_encoding('UTF-8');
        $str_length = mb_strlen($string);
        if ($num_chrs_to_keep >= 0) {
            $string = mb_substr($string, 0, $num_chrs_to_keep);
            $string = str_pad($string, $str_length, $mask, STR_PAD_RIGHT);
        } else {
            $string = mb_substr($string, $num_chrs_to_keep);
            $string = str_pad($string, $str_length, $mask, STR_PAD_LEFT);
        }
        return $string;
    }

    // adds an associative array element to the beginning of an array
    public static function arrayUnshiftAssoc(&$arr, $key, $val)
    {
        if (!is_array($arr)) {
            return $arr;
        }
        $arr = array_reverse($arr, true);
        $arr[$key] = $val;
        $arr = array_reverse($arr, true);
        return $arr;
    }

    // adds associative array elements into the specified location of the array
    public static function arraySpliceAssoc($array, $offset, $new_elements)
    {
        if (!is_array($array) || !is_array($new_elements)) {
            return $array;
        }
        $offset = (int)$offset;
        $array1 = array_splice($array, 0, $offset, true);
        $array2 = array_splice($array, $offset, null, true);
        return array_merge($array1, $new_elements, $array2);
    }

    // taken from PHP.net comment on filesize function page
    public static function filesizeFormat($bytes, $decimals = 2)
    {
        $sz = 'BKMGTP';
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[(int)$factor];
    }

    // returns the extension for the given filename
    public static function getFileExtension($filename, $delimiter = '.')
    {
        mb_internal_encoding('UTF-8');
        return mb_strtolower(mb_strrchr($filename, $delimiter));
    }

    /**
     * @param $filename
     * @param $path
     * @param bool $force_lowercase
     * @param bool $safe_filename replace non-letter / number characters with $replacement
     * @param string $replacement
     * @param int $max_increment limits the maximum number of tries to prevent an infinite loop
     * @return string the new unique file name
     */
    public static function ensureUniqueFilename($filename, $path, $force_lowercase = true, $safe_filename = true, $replacement = '_', $max_increment = 10000)
    {
        // Linux systems are case sensitive, meaning that file.ext and File.ext may exist in the same folder
        // Windows is case insensitive which can cause problems if transferring files between OS's
        if ($force_lowercase) {
            $filename = strtolower($filename);
        }

        // non-letter / number characters could cause problems and/or be disallowed by the OS
        if ($safe_filename) {
            // \p indicates a unicode class; L includes letters and N numbers
            // the u at the end apparently turns on unicode mode
            // we're replacing non-letters and numbers with an underscore
            $filename = preg_replace('/[^\p{L}\p{N}\.]+/u', $replacement, $filename);
            $filename = trim($filename, '_');
        }

        // if the filename exists, append a number until we find an unused name
        $pieces = pathinfo($filename);
        $ext = (isset($pieces['extension'])) ? ".{$pieces['extension']}" : '';
        $j = 0;
        while (file_exists("{$path}/{$filename}") && $j < $max_increment) {
            $filename = "{$pieces['filename']}{$j}{$ext}";
            $j++;
        }

        return $filename;
    }

    public static function fixUrl($url, $protocol = 'http')
    {
        if (!preg_match('/^http(s)?:\/\//', $url)) {
            return "{$protocol}://{$url}";
        }
        return $url;
    }

    public static function fixStringWidth($string, $width, $pad = ' ', $pad_type = STR_PAD_RIGHT)
    {
        return str_pad(substr($string, 0, $width), $width, $pad, $pad_type);
    }

    /**
     * @param string $xml_string
     * @return SimpleXMLElement
     */
    public static function convertXML($xml_string)
    {
        // handle XML errors
        libxml_clear_errors();
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);
        $xml_errors = libxml_get_errors();
        if (!empty($xml_errors)) {
            $err_msgs = array();
            foreach ($xml_errors as $xml_error) {
                $msg = '';
                $msg .= " $xml_error->code line {$xml_error->line} column {$xml_error->column}: {$xml_error->message}";
                switch ($xml_error->level) {
                    case LIBXML_ERR_WARNING:
                        $warn_msgs[] = "Warning: {$msg}\n";
                        break;
                    case LIBXML_ERR_ERROR:
                        $err_msgs[] = "Error: {$msg}\n";
                        break;
                    case LIBXML_ERR_FATAL:
                        $err_msgs[] = "Fatal Error: {$msg}\n";
                        break;
                }
            }
            if (!empty($warn_msgs)) {
                Logger::warning(implode("\n", $err_msgs) . "\nXML: {$xml_string}");
            }
            if (!empty($err_msgs)) {
                Logger::error(implode("\n", $err_msgs) . "\nXML: {$xml_string}");
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $xml;
    }

    /**
     * Returns an array of all dates in a date range
     *
     * @param string $first starting date
     * @param string $last  ending date
     * @param string $step step between dates
     * @param string $outputFormat date format
     * @param string $keyName name array key
     * @return array array of dates
     */
    public static function dateRange($first, $last, $step = '+1 day', $outputFormat = 'YYYY-mm-dd', $keyName = '')
    {
        $dates = array();
        $current = strtotime($first);
        $last = strtotime($last);

        while ($current <= $last) {
            $dates[$keyName][] = date($outputFormat, $current);
            $current = strtotime($step, $current);
        }
        return $dates;
    }

    // delete directory by deleting all contents
    public static function deleteDirectory($dir, $delete_dir = true)
    {
        if (!is_dir($dir)) {
            return false;
        }
        $contents = scandir($dir);
        foreach ($contents as $item) {
            if (in_array($item, array('.', '..'))) {
                continue;
            }
            $item_path = "{$dir}/{$item}";
            if (is_dir($item_path)) {
                self::deleteDirectory($item_path);
            }
            if (!is_file($item_path)) {
                continue;
            }
            unlink($item_path);
        }
        if ($delete_dir) {
            rmdir($dir);
        }
    }

    public static function copyDirectory($org_dir, $new_dir)
    {
        if (!is_dir($org_dir)) {
            return false;
        }
        if (!is_dir($new_dir)) {
            if (!mkdir($new_dir)) {
                return false;
            }
        }

        $contents = scandir($org_dir);
        foreach ($contents as $item) {
            if (in_array($item, array('.', '..'))) {
                continue;
            }
            if (is_dir($item)) {
                self::copyDirectory("{$org_dir}/{$item}", "{$new_dir}/{$item}");
            } else {
                if (is_file("{$org_dir}/{$item}")) {
                    copy("{$org_dir}/{$item}", "{$new_dir}/{$item}");
                }
            }
        }
    }

    /**
     * Convert an array to a string; intended for display of array data
     * @param array $info
     * @return string
     */
    public static function convertArrayForDisplay($info)
    {
        if (!is_array($info)) {
            return $info;
        }

        $output = '';
        foreach ($info as $key => $value) {
            $output .= "$key: ";
            if (is_array($value)) {
                $value = array_unique($value);
                if (count($value) <= 1) {
                    $value = array_pop($value);
                }
            }
            if (is_array($value)) {
                $output .= "\n";
                $output .= Utility::convertArrayForDisplay(array_unique($value));
            } else {
                $output .= $value;
            }
            $output .= "\n";
        }

        return $output;
    }

    public static function dateReplace($string, DateTimeInterface $dateTime = null)
    {
        while (preg_match('/\{date\|(.*)\}/', $string, $matches)) {
            if (!empty($matches[1])) {
                if (empty($dateTime)) {
                    $dateTime = new DateTime();
                }
                $string = str_replace("{date|{$matches[1]}}", $dateTime->format($matches[1]), $string);
            } else {
                break;
            }
        }

        return $string;
    }

    public static function keyValueArrayToString(array $key_value_pairs, string $value_separator = '=', string $delimiter = '&')
    {
        array_walk($key_value_pairs, fn(&$value, $key) => $value = $key . $value_separator . urlencode($value));
        return implode($delimiter, $key_value_pairs);
    }

    public static function str_ends_with(string $haystack, string $needle): bool
    {
        if (function_exists('str_ends_with')) {
            return str_ends_with($haystack, $needle);
        }

        return strrpos($haystack, $needle) === (strlen($haystack) - strlen($needle));
    }
}
