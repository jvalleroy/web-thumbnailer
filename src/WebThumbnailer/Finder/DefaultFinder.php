<?php

namespace WebThumbnailer\Finder;

use WebThumbnailer\Application\WebAccess;
use WebThumbnailer\Utils\ImageUtils;
use WebThumbnailer\Utils\UrlUtils;

/**
 * Class DefaultFinder
 *
 * This finder isn't linked to any domain.
 * It will return the resource if it is an image (by extension, or by content).
 * Otherwise, it'll try to retrieve an OpenGraph resource.
 *
 * @package WebThumbnailer\Finder
 */
class DefaultFinder extends FinderCommon
{
    /**
     * @var WebAccess instance.
     */
    protected $webAccess;

    /**
     * @inheritdoc
     */
    public function __construct($domain, $url, $rules, $options)
    {
        $this->webAccess = new WebAccess();
        $this->url = $url;
        $this->domains = $domain;
    }

    /**
     * Generic finder.
     *
     * @inheritdoc
     */
    function find()
    {
        if (ImageUtils::isImageExtension(UrlUtils::getUrlFileExtension($this->url))) {
            return $this->url;
        }

        $content = $this->webAccess->getWebContent($this->url);
        if (ImageUtils::isImageString($content)) {
            return $this->url;
        }

        // Try to retrieve OpenGraph image.
        $ogRegex = '#<meta property=["\']?og:image["\'\s][^>]+content=["\']?(.*?)["\'\s>]#';
        if (preg_match($ogRegex, $content, $matches) > 0) {
            // Check extension, for example to reject GIF.
            if (ImageUtils::isImageExtension(UrlUtils::getUrlFileExtension($matches[1]))) {
                return $matches[1];
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function isHotlinkAllowed()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    function checkRules($rules)
    {
    }

    /**
     * @inheritdoc
     */
    function loadRules($rules)
    {
    }

    /**
     * @inheritdoc
     */
    function getName()
    {
        return 'default';
    }
}
