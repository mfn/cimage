<?php
/**
 * Get a image from a remote server using HTTP GET and If-Modified-Since.
 *
 */
class CRemoteImage
{
    /**
     * Path to cache files.
     */
    private $saveFolder = null;



    /**
     * Use cache or not.
     */
    private $useCache = true;



    /**
     * HTTP object to aid in download file.
     */
    private $http;



    /**
     * Status of the HTTP request.
     */
    private $status;



    /**
     * Defalt age for cached items 60*60*24*7.
     */
    private $defaultMaxAge = 604800;



    /**
     * Url of downloaded item.
     */
    private $url;



    /**
     * Base name of cache file for downloaded item.
     */
    private $fileName;



    /**
     * Filename for json-file with details of cached item.
     */
    private $fileJson;



    /**
     * Filename for image-file.
     */
    private $fileImage;



    /**
     * Cache details loaded from file.
     */
    private $cache;



    /**
     * Constructor
     *
     */
    public function __construct()
    {
        ;
    }


    /**
     * Get status of last HTTP request.
     *
     * @return int as status
     */
    public function getStatus()
    {
        return $this->status;
    }



    /**
     * Get JSON details for cache item.
     *
     * @return array with json details on cache.
     */
    public function getDetails()
    {
        return $this->cache;
    }



    /**
     * Set the path to the cache directory.
     *
     * @param boolean $use true to use the cache and false to ignore cache.
     *
     * @return $this
     */
    public function setCache($path)
    {
        $this->saveFolder = $path;
        return $this;
    }



    /**
     * Check if cache is writable or throw exception.
     *
     * @return $this
     *
     * @throws Exception if cahce folder is not writable.
     */
    public function isCacheWritable()
    {
        if (!is_writable($this->saveFolder)) {
            throw new Exception("Cache folder is not writable for downloaded files.");
        }
        return $this;
    }



    /**
     * Decide if the cache should be used or not before trying to download
     * a remote file.
     *
     * @param boolean $use true to use the cache and false to ignore cache.
     *
     * @return $this
     */
    public function useCache($use = true)
    {
        $this->useCache = $use;
        return $this;
    }



    /**
     * Translate a content type to a file extension.
     *
     * @param string $type a valid content type.
     *
     * @return string as file extension or false if no match.
     */
    function contentTypeToFileExtension($type) {
        $extension = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
        );

        return isset($extension[$type])
        ? $extension[$type]
        : false;
    }



    /**
     * Set header fields.
     *
     * @return $this
     */
    function setHeaderFields() {
        $this->http->setHeader("User-Agent", "CImage/0.6 (PHP/". phpversion() . " cURL)");
        $this->http->setHeader("Accept", "image/jpeg,image/png,image/gif");

        if ($this->useCache) {
            $this->http->setHeader("Cache-Control", "max-age=0");
        } else {
            $this->http->setHeader("Cache-Control", "no-cache");
            $this->http->setHeader("Pragma", "no-cache");
        }
    }



    /**
     * Save downloaded resource to cache.
     *
     * @return string as path to saved file or false if not saved.
     */
    function save() {

        $this->cache = array();
        $date         = $this->http->getDate(time());
        $maxAge       = $this->http->getMaxAge($this->defaultMaxAge);
        $lastModified = $this->http->getLastModified();
        $type         = $this->http->getContentType();
        $extension    = $this->contentTypeToFileExtension($type);

        # {{{ Try harder if no recognized content type is provided
        if (false === $extension) {
            $f = finfo_open();
            $type = finfo_buffer($f, $this->http->getBody(), FILEINFO_MIME_TYPE);
            finfo_close($f);
            $extension = $this->contentTypeToFileExtension($type);
        }
        # }}}

        $this->cache['url']            = $this->url;
        $this->cache['Date']           = gmdate("D, d M Y H:i:s T", $date);
        $this->cache['Max-Age']        = $maxAge;
        $this->cache['Content-Type']   = $type;
        $this->cache['File-Extension'] = $extension;

        if ($lastModified) {
            $this->cache['Last-Modified'] = gmdate("D, d M Y H:i:s T", $lastModified);
        }

        if ($extension) {

            $this->fileImage = $this->fileName . "." . $extension;

            // Save only if body is a valid image
            $body = $this->http->getBody();
            $img = imagecreatefromstring($body);

            if ($img !== false) {
                file_put_contents($this->fileImage, $body);
                file_put_contents($this->fileJson, json_encode($this->cache));
                return $this->fileImage;
            }
        }

        return false;
    }



    /**
     * Got a 304 and updates cache with new age.
     *
     * @return string as path to cached file.
     */
    function updateCacheDetails() {

        $date         = $this->http->getDate(time());
        $maxAge       = $this->http->getMaxAge($this->defaultMaxAge);
        $lastModified = $this->http->getLastModified();

        $this->cache['Date']     = gmdate("D, d M Y H:i:s T", $date);
        $this->cache['Max-Age']  = $maxAge;

        if ($lastModified) {
            $this->cache['Last-Modified'] = gmdate("D, d M Y H:i:s T", $lastModified);
        }

        file_put_contents($this->fileJson, json_encode($this->cache));
        return $this->fileImage;
    }



    /**
     * Download a remote file and keep a cache of downloaded files.
     *
     * @param string $url a remote url.
     *
     * @return string as path to downloaded file or false if failed.
     */
    function download($url) {

        $this->http = new CHttpGet();
        $this->url = $url;

        // First check if the cache is valid and can be used
        $this->loadCacheDetails();

        if ($this->useCache) {
            $src = $this->getCachedSource();
            if ($src) {
                $this->status = 1;
                return $src;
            }
        }

        // Do a HTTP request to download item
        $this->setHeaderFields();
        $this->http->setUrl($this->url);
        $this->http->doGet();

        $this->status = $this->http->getStatus();
        if ($this->status === 200) {
            $this->isCacheWritable();
            return $this->save();
        } else if ($this->status === 304) {
            $this->isCacheWritable();
            return $this->updateCacheDetails();
        }

        return false;
    }



    /**
     * Get the path to the cached image file if the cache is valid.
     *
     * @return $this
     */
    public function loadCacheDetails()
    {
        $cacheFile = hash('sha256', $this->url);
        $this->fileName = $this->saveFolder . $cacheFile;
        $this->fileJson = $this->fileName . ".json";
        if (is_readable($this->fileJson)) {
            $this->cache = json_decode(file_get_contents($this->fileJson), true);
        }
    }



    /**
     * Get the path to the cached image file if the cache is valid.
     *
     * @return string as the path ot the image file or false if no cache.
     */
    public function getCachedSource()
    {
        $this->fileImage = $this->fileName . "." . $this->cache['File-Extension'];
        $imageExists = is_readable($this->fileImage);

        // Is cache valid?
        $date   = strtotime($this->cache['Date']);
        $maxAge = $this->cache['Max-Age'];
        $now = time();
        if ($imageExists && $date + $maxAge > $now) {
            return $this->fileImage;
        }

        // Prepare for a 304 if available
        if ($imageExists && isset($this->cache['Last-Modified'])) {
            $this->http->setHeader("If-Modified-Since", $this->cache['Last-Modified']);
        }

        return false;
    }
}
