<?php

namespace LearnosityQti\Utils;

class StringUtil
{
    public static function generateRandomString($length)
    {
        if (!$length || $length % 2 !== 0) {
            throw new \Exception('Length must be even number');
        }
        $bytes = $length / 2;
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }

    public static function contains($haystack, $needle)
    {
        return strpos($haystack, $needle) !== false;
    }

    public static function startsWith($haystack, $needle) {
        // Search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
    }

    public static function endsWith($haystack, $needle) {
        // Search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== FALSE);
    }
}