<?php

namespace WebThumbnailer\Application;

use WebThumbnailer\Finder\Finder;
use WebThumbnailer\Finder\FinderFactory;
use WebThumbnailer\Utils\ImageUtils;
use WebThumbnailer\Utils\SizeUtils;
use WebThumbnailer\Utils\UrlUtils;
use WebThumbnailer\WebThumbnailer;

/**
 * Class Thumbnailer
 *
 * Main application class, it will:
 *   - retrieve the thumbnail URL using the approriate finder,
 *   - in download mode, download the thumb and resize it,
 *   - use the cache.
 *
 * @package WebThumbnailer\Application
 */
class Thumbnailer
{
    /**
     * @var string Array key for download type option.
     */
    protected static $DL_OPTION = 'dl';

    /**
     * @var string User given URL, from where to generate a thumbnail.
     */
    protected $url;

    /**
     * @var Finder instance.
     */
    protected $finder;

    /**
     * @var array Thumbnailer user options.
     */
    protected $options;

    /**
     * @var array $_SERVER.
     */
    protected $server;

    /**
     * Thumbnailer constructor.
     *
     * @param string $url     User given URL, from where to generate a thumbnail.
     * @param array  $options Thumbnailer user options.
     * @param array  $server  $_SERVER.
     */
    public function __construct($url, $options, $server)
    {
        $this->url = $url;
        $this->server = $server;
        $this->finder = FinderFactory::getFinder($url);
        $this->finder->setUserOptions($options);
        $this->setOptions($options);
    }

    /**
     * Get the thumbnail according to download mode:
     *   - HOTLINK_STRICT: will only try to get hotlink thumb.
     *   - HOTLINK: will retrieve hotlink if available, or download otherwise.
     *   - DOWNLOAD: will download the thumb, resize it, and store it in cache.
     *
     * Default mode: DOWNLOAD.
     *
     * @return string|bool The thumbnail URL (relative if downloaded), or false if no thumb found.
     *
     * @throws \Exception Something went wrong, see exception message for more info.
     */
    public function getThumbnail()
    {
        $thumburl = $this->finder->find();
        if (empty($thumburl)) {
            $error = 'No thumbnail could be found for this URL using '. $this->finder->getName() .' finder.';
            throw new \Exception($error);
        }

        // Only hotlink, find() is enough.
        if ($this->options[self::$DL_OPTION] === WebThumbnailer::HOTLINK_STRICT) {
            return $this->thumbnailStrictHotlink($thumburl);
        }
        // Hotlink if available, download otherwise.
        if ($this->options[self::$DL_OPTION] === WebThumbnailer::HOTLINK) {
            return $this->thumbnailHotlink($thumburl);
        }
        // Download
        else {
            return $this->thumbnailDownload($thumburl);
        }
    }

    /**
     * Get thumbnails in HOTLINK_STRICT mode.
     * Won't work for domains which doesn't allow hotlinking.
     *
     * @param string $thumburl Thumbnail URL, generated by the Finder.
     *
     * @return string The thumbnail URL, or false if hotlinking is disabled.
     *
     * @throws \Exception Hotlink is disabled for this domains.
     */
    protected function thumbnailStrictHotlink($thumburl)
    {
        if (! $this->finder->isHotlinkAllowed()) {
            throw new \Exception('Hotlink is not supported for this URL.');
        }
        return $thumburl;
    }

    /**
     * Get thumbnails in HOTLINK mode.
     *
     * @param string $thumburl Thumbnail URL, generated by the Finder.
     *
     * @return string The thumbnail URL, or false if no thumb found.
     */
    protected function thumbnailHotlink($thumburl)
    {
        if (! $this->finder->isHotlinkAllowed()) {
            return $this->thumbnailDownload($thumburl);
        }
        return $thumburl;
    }

    /**
     * Get thumbnails in HOTLINK mode.
     *
     * @param string $thumburl Thumbnail URL, generated by the Finder.
     *
     * @return string|bool The thumbnail URL, or false if no thumb found.
     *
     * @throws \Exception
     * @throws \WebThumbnailer\Exception\NotAnImageException
     */
    protected function thumbnailDownload($thumburl)
    {
        // Cache file path.
        $thumbPath = CacheManager::getCacheFilePath(
            $thumburl,
            $this->finder->getDomains(),
            CacheManager::TYPE_THUMB,
            $this->options[WebThumbnailer::MAX_WIDTH],
            $this->options[WebThumbnailer::MAX_HEIGHT]
        );

        // If the cache is valid, serve it.
        if (empty($this->options[WebThumbnailer::NOCACHE])
            && CacheManager::isCacheValid(
                $thumbPath,
                $this->finder->getDomains(),
                CacheManager::TYPE_THUMB
            )
        ) {
            return UrlUtils::generateRelativeUrlFromPath($this->server, $thumbPath);
        }

        // FIXME! cURL implementation.
        $webaccess = new WebAccess();
        list($headers, $finalThumburl) = $webaccess->getRedirectedHeaders($thumburl);
        if (strpos($headers[0], '200') === false) {
            throw new \Exception(
                'Unreachable thumbnail URL. HTTP '. $headers[0] .'.'. PHP_EOL .
                ' - Original thumbnail URL: '. $thumburl . PHP_EOL .
                ' - Redirected thumbnail URL: '. $finalThumburl
            );
        }

        // Download the thumb.
        $data = $webaccess->getWebContent($finalThumburl, $this->options[WebThumbnailer::DOWNLOAD_MAX_SIZE]);
        if ($data === false) {
            throw new \Exception('Couldn\'t download the thumbnail at '. $finalThumburl);
        }

        // Resize and save it locally.
        ImageUtils::generateThumbnail(
            $data,
            $thumbPath,
            $this->options[WebThumbnailer::MAX_WIDTH],
            $this->options[WebThumbnailer::MAX_HEIGHT],
            $this->options[WebThumbnailer::CROP]
        );

        if (! is_file($thumbPath)) {
            throw new \Exception('Thumbnail was not generated.');
        }

        return UrlUtils::generateRelativeUrlFromPath($this->server, $thumbPath);
    }

    /**
     * Set Thumbnailer options from user input.
     *
     * @param array $options User options array.
     *
     * @throws \Exception
     */
    protected function setOptions($options)
    {
        self::checkOptions($options);

        $this->options[self::$DL_OPTION] = ConfigManager::get('settings.default.download_mode', 'DOWNLOAD');

        foreach ($options as $key => $value) {
            // Download option.
            if ($value === WebThumbnailer::DOWNLOAD
                || $value === WebThumbnailer::HOTLINK
                || $value === WebThumbnailer::HOTLINK_STRICT
            ) {
                $this->options[self::$DL_OPTION] = $value;
                break;
            }
        }

        // DL size option
        if (isset($options[WebThumbnailer::DOWNLOAD_MAX_SIZE])
            && is_int($options[WebThumbnailer::DOWNLOAD_MAX_SIZE])
        ) {
            $this->options[WebThumbnailer::DOWNLOAD_MAX_SIZE] = $options[WebThumbnailer::DOWNLOAD_MAX_SIZE];
        } else {
            $maxdl = ConfigManager::get('settings.default.max_img_dl', 4194304);
            $this->options[WebThumbnailer::DOWNLOAD_MAX_SIZE] = $maxdl;
        }

        if (isset($options[WebThumbnailer::NOCACHE])) {
            $this->options[WebThumbnailer::NOCACHE] = $options[WebThumbnailer::NOCACHE];
        }

        if (isset($options[WebThumbnailer::CROP])) {
            $this->options[WebThumbnailer::CROP] = $options[WebThumbnailer::CROP];
        } else {
            $this->options[WebThumbnailer::CROP] = false;
        }

        // Image size
        $this->setSizeOptions($options);
    }

    /**
     * Set specific size option, allowing 'meta' size SMALL, MEDIUM, etc.
     *
     * @param array $options User options array.
     */
    protected function setSizeOptions($options) {
        // Width
        $width = 0;
        if (! empty($options[WebThumbnailer::MAX_WIDTH])) {
            if (SizeUtils::isMetaSize($options[WebThumbnailer::MAX_WIDTH])) {
                $width = SizeUtils::getMetaSize($options[WebThumbnailer::MAX_WIDTH]);
            } else if (is_int($options[WebThumbnailer::MAX_WIDTH])) {
                $width = $options[WebThumbnailer::MAX_WIDTH];
            }
        }
        $this->options[WebThumbnailer::MAX_WIDTH] = $width;

        // Height
        $height = 0;
        if (!empty($options[WebThumbnailer::MAX_HEIGHT])) {
            if (SizeUtils::isMetaSize($options[WebThumbnailer::MAX_HEIGHT])) {
                $height = SizeUtils::getMetaSize($options[WebThumbnailer::MAX_HEIGHT]);
            } else if (is_int($options[WebThumbnailer::MAX_HEIGHT])) {
                $height = $options[WebThumbnailer::MAX_HEIGHT];
            }
        }
        $this->options[WebThumbnailer::MAX_HEIGHT] = $height;

        if ($this->options[WebThumbnailer::MAX_WIDTH] == 0 && $this->options[WebThumbnailer::MAX_HEIGHT] == 0) {
            $maxwidth = ConfigManager::get('settings.default.max_width', 160);
            $this->options[WebThumbnailer::MAX_WIDTH] = $maxwidth;
            $maxheight = ConfigManager::get('settings.default.max_height', 160);
            $this->options[WebThumbnailer::MAX_HEIGHT] = $maxheight;
        }
    }

    /**
     * Make sure user options are coherent.
     *   - Only one thumb mode can be defined.
     *
     * @param array $options User options array.
     *
     * @throws \Exception Invalid options.
     */
    protected static function checkOptions($options)
    {
        $incompatibleFlagsList = [
            [WebThumbnailer::DOWNLOAD, WebThumbnailer::HOTLINK, WebThumbnailer::HOTLINK_STRICT]
        ];

        foreach ($incompatibleFlagsList as $incompatibleFlags) {
            if (count(array_intersect($incompatibleFlags, $options)) > 1) {
                $error = 'Only one of these flags can be set between: ';
                foreach ($incompatibleFlags as $flag) {
                    $error .= $flag .' ';
                }
                throw new \Exception($error);
            }
        }
    }
}
