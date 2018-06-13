<?php

namespace WebThumbnailer\Utils;

/**
 * Class UrlUtils
 *
 * Util class for operations on URL strings.
 *
 * @package WebThumbnailer\Utils
 */
class UrlUtils
{
    /**
     * Extract the domains from an URL.
     *
     * @param string $url Given URL.
     *
     * @return string Extracted domains, lowercase.
     */
    public static function getDomain($url)
    {
        if (! parse_url($url, PHP_URL_SCHEME)) {
            $url = 'http://' . $url;
        }
        return strtolower(parse_url($url, PHP_URL_HOST));
    }

    /**
     * Generate a relative URL from absolute local path.
     * Example:
     *    - /home/website/resources/file.txt
     *    ====>
     *    - resources/file.txt
     *
     * @param array  $server $_SERVER array.
     * @param string $path   Absolute path to transform.
     *
     * @return string Relative path.
     */
    public static function generateRelativeUrlFromPath($server, $path)
    {
        if (isset($server['CONTEXT_DOCUMENT_ROOT'])) {
            $root = ! empty($server['CONTEXT_DOCUMENT_ROOT']) ? rtrim($server['CONTEXT_DOCUMENT_ROOT'], '/') .'/' : '';
            $path = substr($path, strlen($root));
        } elseif (isset($server['DOCUMENT_ROOT'])) {
            $root = ! empty($server['DOCUMENT_ROOT']) ? rtrim($server['DOCUMENT_ROOT'], '/') .'/' : '';
            $path = substr($path, strlen($root));
        }

        if (isset($server['SCRIPT_NAME']) && preg_match('#/?(.+/)\w+\.php$#', $server['SCRIPT_NAME'], $matches) > 0) {
            $path = substr($path, strlen($matches[1]));
        }

        return $path;
    }

    /**
     * Retrieve the file extension from a URL.
     *
     * @param string $url given URL.
     *
     * @return string|bool File extension or false if not found.
     */
    public static function getUrlFileExtension($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (preg_match('/\.(\w+)$/i', $path, $match) > 0) {
            return strtolower($match[1]);
        }
        return '';
    }
}
