<?php
namespace Xrt\ContentGrabber;

class ContentGrabber {
    /**
     * headeri koje dobijemo u odgovoru
     * @var string
     */
    protected $header;
    
    /**
     * neparsiran odgovor url-a
     * @var string
     */
    protected $rawdata;
    
    /**
     * rezultat curl_getinfo() poziva nakon curl_exec()
     * @var array
     */
    protected $info;
    
    /**
     * 0 - tiho radi
     * 1 - ispisi koji si url zahtevao
     * 2 - ispisi komunikaciju sa kesom
     * 3 - 1 & 2
     *
     * @var int
     */
    protected $verbose;
    
    /**
     * Duzina u sekundama koliko vazi podatak iz kesa
     * @var int
     */
    protected $cache_lifetime = 0;
    
    /**
     *
     * @var string
     */
    protected $cacheKeySalt = '';
    
    protected $user_agent;
    
    public $curl_options, $content_retrieved_from_cache, $post_fields = '';
    
    /**
     *
     * @var \Xrt\Xrtfetcher\CacheSimple\File
     */
    protected $cache_obj = null;
    
    protected $lastCacheKey;
    protected $lastRequestServedFromCache = false;
    
    protected $cacheReadBypass = false;
    
    protected $cachedHttpResponseStatusesRegEx = '~^[2-4]~';

    public function __construct($verbose = false, $cache_lifetime = 0, $cacheKeySalt = '') {
        $this->setVerbose($verbose)->setCacheLifetime($cache_lifetime)->setCacheKeySalt($cacheKeySalt);
    }
    
    public function check_header($content_type = 'text/xml') {
        return $this->info['http_code'] == 200 && preg_match("~$content_type~", $this->info['content_type']);
    }

    public function clean() {
        unset($this->rawdata, $this->header, $this->info);
        return $this;
    }
    
    private function cache_get($key) {
        if ($this->cache_lifetime) {
            if (!$this->cacheReadBypass && ($this->content_retrieved_from_cache = $this->getCacheObj()->check($key))) {
                if ($this->verbose & 2) echo "... from cache ";
                list($this->header, $this->rawdata, $this->info, $this->user_agent) = $this->getCacheObj()->get($key);
                return $this->lastRequestServedFromCache = true;
            } else {
                if ($this->verbose & 2) echo "... fetching ";
                $this->getCacheObj()->lock_obtain($key);
                return $this->lastRequestServedFromCache = false;
            }
        } else {
            return $this->lastRequestServedFromCache = false;
        }
    }
    
    private function cache_put($key) {
        if ($this->cache_lifetime && preg_match($this->cachedHttpResponseStatusesRegEx, $this->info['http_code']) && $this->info['http_code'] != 401) {
            $this->getCacheObj()->put($key, array($this->header, $this->rawdata, $this->info, $this->user_agent), (0.9 + mt_rand(0, 20) / 100) * $this->cache_lifetime);
        }
    }
    
    /**
     * Dovlaci zadati url, uz kontrolu da to nemamo vec kesirano
     *
     * @param string $url
     * @param string $referer
     * @param array $post_fields
     * @param string $user_agent
     * @param array $curl_req_options
     */
    public function get_content($url, $referer = 'auto', $post_fields = false, $user_agent = 'web', $curl_req_options = array()) {
    	if (!$post_fields) $_mypost = $post_fields = array();
        else $_mypost = is_array($post_fields) ? $post_fields : array($post_fields);
        ksort($_mypost);
        $this->setLastCacheKey($key = md5(serialize(array($this->getCacheKeySalt(), $url, $_mypost))));
        
        if (!$this->cache_get($key)) {
            $this->__get_content($url, $referer, $post_fields, $user_agent, $curl_req_options);
            $this->cache_put($key);
        }
    }
        
    protected function __get_content($url, $referer = 'auto', $post_fields = false, $user_agent = 'web', $curl_req_options = array()) {
//         error_log(date('Y-m-d H:i:s') . "\t" . $url . "\t" . http_build_query($post_fields, '', '&') . "\n", 3, '/tmp/content_grabber.log');
        
        // create a new curl resource
        $ch = curl_init();
        
        // set URL and other appropriate options
        if (!is_array($curl_req_options)) $curl_req_options = array();
        $curl_default_opts = array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 1,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 10,
        );
        if (!strcmp($user_agent, 'web')) {
            $buf = array_values(array_filter(file(__DIR__ . "/ua/web.txt"), 'trim'));
            $user_agent = trim($buf[mt_rand(0, count($buf) - 1)]);
        } elseif (!strcmp($user_agent, 'mobile')) {
            $buf = array_values(array_filter(file(__DIR__ . "/ua/mobile.txt"), 'trim'));
            $user_agent = trim($buf[mt_rand(0, count($buf) - 1)]);
        }
        if ($user_agent) {
            $this->user_agent = $user_agent;
            $curl_default_opts[CURLOPT_USERAGENT] = $user_agent;
        }
        if (!strcmp($referer, 'auto')) {
            $referer = preg_replace('~(^[^/]+//[^/]+).*~', '$1', $url);
        }
        
        if ($referer) $curl_default_opts[CURLOPT_REFERER] = $referer;
        if ($post_fields) {
            if (is_array($post_fields)) {
                $post_fields = $this->http_build_query_for_curl($post_fields);
            }
            
            $curl_default_opts[CURLOPT_POST] = 1;
            $curl_default_opts[CURLOPT_POSTFIELDS] = ($this->post_fields = $post_fields);
        }
        if (defined('CURLOPT_ENCODING')) {
            $curl_default_opts[CURLOPT_ENCODING] = 'gzip, deflate';
        }
        
        $curl_options = $curl_req_options + $curl_default_opts;
        if (is_null($curl_options[CURLOPT_TIMEOUT])) unset($curl_options[CURLOPT_TIMEOUT]);
        $this->curl_options = $curl_options;
        
//         error_log(print_r($curl_options, true), 3, '/tmp/log.txt');
        
        if ($curl_options) {
            if (function_exists('curl_setopt_array')) {
                curl_setopt_array($ch, $curl_options);
            } else {
                foreach ($curl_options as $curl_key => $curl_value) {
                    curl_setopt($ch, $curl_key, $curl_value);
                }
            }
        }
        
        // grab URL and save it
        $feed_data = curl_exec($ch);
        $this->info = curl_getinfo($ch);
        
        if ($this->curl_options[CURLOPT_HEADER]) {
            $this->header = trim(substr($feed_data, 0, $this->info['header_size']));
            $this->rawdata = trim(substr($feed_data, $this->info['header_size']));
        } else {
            $this->header = '';
            $this->rawdata = trim($feed_data);
        }
        
        if ($this->verbose & 1) {
            if (curl_errno($ch)) {
                echo "<br>\nUrl: $url " .
                     "\nReferer: $referer " .
                     "\nBrowser: $user_agent " .
                     "\ncurl error: " . curl_errno($ch) . " - " . curl_error($ch) . "\n";
            } else {
                echo "<br>\nUrl got: $url ";
            }
        }

        // close curl resource, and free up system resources
        curl_close($ch);
    }
    
    protected function http_build_query_for_curl($arrays, $prefix = null) {
        if (is_object($arrays)) {
            $arrays = get_object_vars($arrays);
        }
    
        $out = array();
        foreach ($arrays as $key => $value) {
            $k = isset($prefix) ? $prefix . '[' . $key . ']' : $key;
            if ($value instanceof CURLFile) {
                $out[$k] = $value;
            } elseif (is_array($value) || is_object($value)) {
                $out += $this->http_build_query_for_curl($value, $k);
            } else {
                $out[$k] = $value;
            }
        }
        
        return $out;
    }
    
    public function generate_xml($characterEncoding = 'utf-8', $content = null) {
        $content = $content ? $content : $this->rawdata;
        if (!$content) return false;

        $config1 = array(
            'show-body-only' => false,
            'quote-ampersand' => false,
            'quote-nbsp' => FALSE,
            'output-encoding' => 'UTF8',
            'quiet' => TRUE,
            'show-warnings' => FALSE,
            'tidy-mark' => FALSE,
            'indent' => 0,
            'wrap' => 0,
            'clean' => true,
            'bare' => true,
            'drop-font-tags' => false,
            'drop-proprietary-attributes' => false,
            'hide-comments' => TRUE,
            'numeric-entities' => FALSE,
            'write-back' => TRUE
        );
         
        $tidy = new \tidy();

        $rawdata = isset($characterEncoding) && $characterEncoding != 'utf-8' ? iconv($characterEncoding, 'utf-8', $content) : $content;
        $tidy->parseString($rawdata, $config1, 'utf8');
        $tidy->cleanRepair();

        $config2 = array(
            'add-xml-decl' => TRUE,
            'bare' => true,
            'clean' => true,
            'doctype' => 'omit',
            'drop-font-tags' => false,
            'drop-proprietary-attributes' => false,
            'force-output' => TRUE,
            'hide-comments' => TRUE,
            'indent' => 0,
            'numeric-entities' => FALSE,
            'output-xml' => TRUE,
            'output-encoding' => 'UTF8',
            'quiet' => TRUE,
            'quote-ampersand' => FALSE,
            'quote-nbsp' => FALSE,
            'show-warnings' => FALSE,
            'tidy-mark' => FALSE,
            'wrap' => 0,
            'write-back' => TRUE
        );

        $tidy2 = new \tidy();
        $tidy2->parseString((string)$tidy, $config2, 'utf8');
        $tidy2->cleanRepair();
        return (string)$tidy2;
    }
    
    /**
     *
     * @return string
     */
    public function getCacheKeySalt() {
        return $this->cacheKeySalt;
    }
    
    /**
     *
     * @param string $cacheKeySalt
     * @return ContentGrabber
     */
    public function setCacheKeySalt($cacheKeySalt) {
        $this->cacheKeySalt = $cacheKeySalt;
        return $this;
    }
     
    /**
     *
     * @return int
     */
    public function getCacheLifetime() {
        return $this->cache_lifetime;
    }
    
    /**
     *
     * @param int $cache_lifetime
     * @return ContentGrabber
     */
    public function setCacheLifetime($cache_lifetime) {
        $this->cache_lifetime = $cache_lifetime;
        return $this;
    }
    
    /**
     *
     * @return \Xrt\Xrtfetcher\CacheSimple\File
     */
    public function getCacheObj() {
        if (!isset($this->cache_obj)) {
            $this->cache_obj = new \Xrt\Xrtfetcher\CacheSimple\File(array(
                'root_subdir'   => 'remotedata-xrt/',
                'chmod_dir'     => 0777,
                'chmod_file'    => 0777,
            ));
        }
        return $this->cache_obj;
    }
    
    /**
     *
     * @param \Xrt\Xrtfetcher\CacheSimple\File $cache_obj
     */
    public function setCacheObj(\Xrt\Xrtfetcher\CacheSimple\File $cache_obj) {
        $this->cache_obj = $cache_obj;
        return $this;
    }

    /**
     *
     * @return boolean
     */
    public function getCacheReadBypass() {
        return $this->cacheReadBypass;
    }
    
    /**
     *
     * @param boolean $cacheReadBypass
     * @return ContentGrabber
     */
    public function setCacheReadBypass($cacheReadBypass) {
        $this->cacheReadBypass = $cacheReadBypass;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    public function getHeader() {
        return $this->header;
    }
    
    /**
     *
     * @return array
     */
    public function getInfo() {
        return $this->info;
    }
 
    public function getLastCacheKey() {
        return $this->lastCacheKey;
    }
    
    public function setLastCacheKey($lastCacheKey) {
        $this->lastCacheKey = $lastCacheKey;
        return $this;
    }
    
    /**
     *
     * @return boolean
     */
    public function getLastRequestServedFromCache() {
        return $this->lastRequestServedFromCache;
    }
    
    /**
     *
     * @return string
     */
    public function getRawdata() {
        return $this->rawdata;
    }
    
    /**
     *
     * @return string
     */
    public function getUserAgent() {
        return $this->user_agent;
    }
    
    /**
     *
     * @return int
     */
    public function getVerbose() {
        return $this->verbose;
    }
    
    /**
     *
     * @param int $verbose
     * @return ContentGrabber
     */
    public function setVerbose($verbose) {
        $this->verbose = $verbose;
        return $this;
    }
    
}
