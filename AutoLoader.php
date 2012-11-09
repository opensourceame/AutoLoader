<?php
/**
 * An autoloader class that parses files and caches an index of classes to load
 *
 * @description        An autoloader class that parses files and caches an index of classes to load
 * @package         opensourceame
 * @subpackage        logger
 * @author            David Kelly
 * @copyright        David Kelly, 2012 (http://opensourceame.com)
 * @version            3.0.0
 */

namespace opensourceame\AutoLoader;

class Entity
{
    public            $location;
    public            $status;
    public            $lastCheckTime;
    public            $lastCheckCount;

    /**
     * Set the properties of the entity
     *
     * @param array $data
     * @return \opensourceame\AutoLoader\Entity
     */
    static function __set_state(array $data) {

        $entity = new Entity;

        foreach($data as $key => $val) {
            $entity->key = $val;
        }

        return $entity;
    }
}


class AutoLoader
{
    const       version                = '3.0.0';
    const       FOUND                  = 1;
    const       MISSING                = 2;

    static      $instance              = null;

    private     $extensions            = array('php', 'inc');
    private     $pathsRead             = array();
    private     $pathsParsed           = array();
    private     $initialised           = false;

    private     $debug                 = false;
    private     $cache                 = true;
    private     $cacheRead             = false;
    private     $cacheDir              = null;
    private     $cacheMaxAge           = 0;
    private     $cacheMaxLockTime      = 60;

    private     $index                 = array();
    private     $include               = array();
    private     $exclude               = array();
    private     $ignore                = array();

    private     $missingRefreshTime    = 60;

    private     $configDefaults        = array(
        'debug'            => false,
        'cache'            => true,
        'cacheDir'         => '/tmp',
        'cacheMaxAge'      => 0,
        'cacheMethod'      => 'include',
        'exclude'          => array(),
        'include'          => array(),
        'ignore'           => array(),
    );

    public function __construct($config = false)
    {
        $this->registerInstance();

        $this->readConfigArray($this->configDefaults);

        if (is_array($config)) {
            $this->readConfigArray($config);
        }

        if (is_string($config)) {
            $this->readConfigFile($config);
        }

        spl_autoload_register('\opensourceame\AutoLoader\AutoLoader::load');
    }

    /**
     * Read configuration from an array
     *
     * @param array $config an array of config options
     * @return boolean
     */
    public function readConfigArray($config)
    {
        foreach ($this->configDefaults as $key => $val) {
            if (isset($config[$key]))
                $this->$key = $config[$key];
        }

        return true;
    }

    /**
     * Read config from a YAML file
     *
     * @param string $configFile the config file to read
     * @return boolean
     */
    public function readConfigFile($configFile)
    {
        if (function_exists('yaml_parse_file')) {
            $config = yaml_parse_file($configFile);

        } else {

            require_once __DIR__ . '/../../third_party/spyc/spyc.php';

            $config = \Spyc::YAMLLoad($configFile);
        }

        $this->readConfigArray($config);

        return true;

    }

    /**
     * Add a path to the list of excluded paths
     *
     * @param string $path
     * @return boolean
     */
    public function excludePath($path)
    {
        if (is_array($path)) {
            foreach ($path as $p) {
                $this->excludePath($p);
            }
        }

        $this->exclude[] = realpath($path);

        return true;
    }

    /**
     * Add a path to the list of included paths
     *
     * @param string $path
     * @return boolean
     */
    public function includePath($path)
    {
        if (is_array($path)) {
            foreach ($path as $p) {
                $this->includePath($p);
            }
        }

        $this->include[] = realpath($path);

        return true;
    }

    /**
     * Create a lock file for the cache. This prevents multiple instances of the autoloader
     * trying to overwrite the cache.
     *
     * @return boolean
     */
    private function createCacheLockFile()
    {
        $lockFile = $this->getCacheLockFilename();

        $this->debug("creating lock file $lockFile");

        $lockText        = "opensourceame AutoLoader\n\n";
        $lockText       .= "Version: " . self::version         . "\n";
        $lockText       .= "PID:     " . getmypid()             . "\n";
        $lockText       .= "Date:    " . date("Y-m-d H:i:s") . "\n";

        return file_put_contents($lockFile, $lockText);
    }

    /**
     * Write the cache file
     *
     * @return boolean
     */
    private function writeCache()
    {
        if (! $this->cache) {
            return true;
        }

        $cacheFile     = $this->getCacheFileName();
        $lockFile      = $this->getCacheLockFileName();

        $cacheContent  = "<?php\n";
        $cacheContent .= '$version = "'    . self::version                            . "\";\n";
        $cacheContent .= '$index   = ' . var_export($this->index, true)         . ";\n";

        if (! file_put_contents($cacheFile, $cacheContent)) {
            $this->debug("could not write cache to $cacheFile");

            return false;
        }

        chmod($cacheFile, 0777);

        $this->debug("wrote cache to $cacheFile");

        if (file_exists($lockFile)) {

            unlink($lockFile);

            $this->debug("removed lock file");

        }


        return true;
    }

    /**
     * Read the cache file
     *
     * @return boolean
     */
    private function readCache()
    {
        if (! $this->cache) {
            return false;
        }

        $cacheFile = $this->getCacheFileName();

        if (! is_readable($cacheFile)) {
            return false;
        }

        include $this->getCacheFileName();

        if ($version != self::version) {

            $this->debug("cache version ($version) does not match this version (" . self::version . ")");

            $this->deleteCache();

            return false;
        }

        $this->index            = $index;
        $this->cacheRead        = true;

        $this->debug("read cache from $cacheFile");
        $this->debug(count($this->index) . " classes loaded");

        return true;

    }

    /**
     * Log a debugging message
     *
     * @param unknown $message
     * @return boolean
     */
    protected function debug($message)
    {
        if (! $this->debug) {
            return false;
        }

        openlog('autoloader', false, LOG_USER);

        return syslog(LOG_INFO, $message);
    }

    private function searchForMissingClass($class) {

        if (! isset($this->index[$class])) {

            $this->addMissingClass($class);
        }

        $files = array();

        $this->debug("searching for missing class $class");

        foreach ($this->include as $path) {

            $files     = $this->globRecursive("$path/*.php");

            foreach ($files as $file) {

                if (basename($file, ".php") === $class ) {

                    $this->parseFile($file);

                    if ($this->index[$class]->status == self::FOUND) {

                        $this->writeCache();

                        require_once $file;

                        return true;
                    }
                }
            }
        }

        // create a missing class entry

        $this->writeCache();

        return false;
    }


    /**
     * Load a class
     *
     * @param string $class the name of the class
     * @return boolean
     */
    public function loadClass($className)
    {
        $class = $this->formatClassName($className);

        $this->debug("loading $class");

        if (! isset($this->index[$class])) {

            return $this->searchForMissingClass($className);
        }

        $entity = &$this->index[$class];

        // if we have the entity and it is found, include the file

        if ($entity->status == self::FOUND) {

            require_once $entity->location;

            return true;
        }


        if ($entity->status == self::MISSING) {

            // if we've passed the time to try a refresh then search

            if ($entity->lastCheckTime < (time() - $this->missingRefreshInterval)) {

                return $this->searchForMissingClass($className);
            }

            // otherwise fail

            $this->debug("missing class $class, failing");

            return false;
        }

        return false;
    }

    /**
     * Load a class. This function gets the singleton and calls its loadClass() method
     *
     * @param string $class
     */
    public function load($class)
    {

        $autoloader = autoloader::getInstance();

        $autoloader->init();

        return $autoloader->loadClass($class);

    }

    /**
     * Get the singleton
     *
     * @return unknown
     */
    static public function getInstance()
    {
        return $GLOBALS['__opensourceame_autoloader'];
    }

    /**
     * Register the singleton
     *
     * @return boolean
     */
    protected function registerInstance()
    {
        $GLOBALS['__opensourceame_autoloader'] =& $this;

        return true;
    }

    /**
     * Delete the cache file
     *
     * @return boolean
     */
    private function deleteCache()
    {
        unlink($this->getCacheFileName());

        $this->debug("deleted cache file");

        return true;
    }

    /**
     * Initialise the autoloader
     *
     * @param boolean $forceInit whether to force initialisation
     * @return boolean
     */
    public function init($forceInit = false)
    {
        if ($this->initialised and ! $forceInit) {
            return true;
        }

        $this->debug('initialising');

        // get the real paths for exclusion
        foreach ($this->exclude as $key => $path) {
            $this->exclude[$key] = realpath($path);
        }

        // get the real paths for inclusion
        foreach ($this->include as $key => $path) {
            $this->include[$key] = realpath($path);
        }

        // get the classes to ignore in specific locations
        foreach ($this->ignore as $key => $val) {

            if (is_array($val)) {

                foreach ($val as $k => $v) {
                    $ignore[$this->formatClassName($key)][$k] = realpath($v);

                    $this->ignore = $ignore;
                }
            } else {
                $this->ignore[$this->formatClassName($key)] = realpath($val);
            }
        }

        // kill the cache file if initialisation is forced
        if ($forceInit) {

            $this->debug("killing cache file due to forced init");

            $this->deleteCache();
        }

        // now try to read the cache file if possible
        if ($this->readCache()) {

            $this->initialised = true;

            return true;
        }

        $lockFile = $this->getCacheLockFileName();

        if ($this->cache) {
            if (! file_exists($lockFile)) {

                $this->createCacheLockFile();

            } else {
                $waitCount = 0;

                while (! file_exists($this->getCacheFileName())) {

                    $lockTime     = filemtime($lockFile);
                    $lockAge    = time() - $lockTime;

                    if ($lockAge > $this->cacheMaxLockTime) {

                        $this->debug("lock file is $lockAge seconds old, deleting");

                        unlink($lockFile);

                        return $this->init();
                    }

                    sleep(1);

                    $this->debug("waiting for cache file to complete");

                    $waitCount ++;

                    if ($waitCount > $this->cacheMaxWaitTime) {

                        $this->debug("waited $waitCount seconds but cache was still locked");
                        $this->debug("aborting");

                        return false;
                    }
                }

                if ($this->readCache()) {

                    $this->initialised = true;

                    return true;
                }
            }
        }

        foreach ($this->include as $path) {
            $this->readDirectory($path);
        }

        $this->writeCache();

        $this->initialised = true;

        return true;
    }

    /**
     * Generate a cache file name based on the config for this autoloader
     *
     * @return string
     */
    public function getCacheFileName()
    {
        $tmp = array(
            'in'    => $this->include,
            'ex'    => $this->exclude,
            'ign'   => $this->ignore,
        );

        return $this->cacheDir . "/autoloader." . md5(serialize($tmp)) . ".$ext";
    }

    /**
     * Get the lock file name for a cache file
     *
     * @return string
     */
    public function getCacheLockFileName()
    {
        return $this->getCacheFileName() . '.lock';
    }

    /**
     * Read a directory
     *
     * @param string $path
     * @return boolean
     */
    public function readDirectory($path)
    {
        $path    = realpath($path);

        $this->debug("reading $path");

        $this->pathsRead[] = $path;

        $files     = $this->globRecursive("$path/*");

        foreach ($files as $file) {

            $ext = substr($file, strrpos($file, '.') + 1);

            if (in_array($ext, $this->extensions)) {
                $this->parseFile($file);
            }
        }

        ksort($this->index);

        $this->pathsParsed = true;

        return true;
    }

    /**
     * Parse a file and index the result
     *
     * @param string $filename
     */
    private function parseFile($filename)
    {
//         $this->debug("parsing $filename");

        $content     = file($filename);
        $namespace    = null;

        foreach ($content as $line) {

            if (preg_match("/^\s*(namespace)\s+(.*).*;$/", $line, $matches)) {
                $namespace = $matches[2];
            }

            if (preg_match("/^\s*(abstract|final)*\s*(class|interface|trait)\s+(\w*).*$/", $line, $matches)) {

                $className = $matches[3];

                $this->addFoundClass($filename, $namespace, $className);
            }
        }

        return true;
    }

    /**
     * Format a class name so that MyNameSpace\MyClass becomes \\mynamespace\\myclass
     *
     * @param string $class
     * @return string
     */
    private function formatClassName($class)
    {
        if (substr($class, 0, 1) != '\\') {
            $class = '\\' . $class;
        }

        $class = strtolower($class);

        return $class;
    }

    private function addMissingClass($className)
    {
        $class = $this->formatClassName($className);

        $entity                     = new \opensourceame\AutoLoader\Entity;
        $entity->name               = $className;
        $entity->location           = null;
        $entity->namespace          = null;
        $entity->status             = self::MISSING;
        $entity->lastCheckTime      = time();
        $entity->lastCheckCount     = 1;

        $this->index[$class]        = $entity;

        $this->missing[$class]      = &$this->index[$class];
    }

    private function addFoundClass($filename, $namespace, $className)
    {
        $class = $this->formatClassName("$namespace\\$className");

        if (isset($this->index[$class])) {

            $this->debug("duplicate class name $class in $filename");

            return false;
        }

        if (isset($this->ignore[$class]) and (in_array($filename, $this->ignore[$class]))) {

            $this->debug("ingoring $class in $filename");

            return false;
        }

        $entity                     = new \opensourceame\AutoLoader\Entity;
        $entity->name               = $className;
        $entity->location           = $filename;
        $entity->namespace          = $namespace;
        $entity->status             = self::FOUND;
        $entity->lastCheckTime      = time();
        $entity->lastCheckCount     = 1;

        $this->index[$class]        = $entity;

        return true;
    }

    /**
     * Recursively glob a directory, fetching only directories
     *
     * @param string $pattern
     * @param number $flags
     * @return array
     */
    private function globRecursive($pattern, $flags = 0)
    {

        $files = glob($pattern, $flags);

        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {

            if (in_array($dir, $this->exclude)) {
                 $this->debug("excluding directory $dir");

                continue;
            }

            $files = array_merge($files, $this->globRecursive($dir.'/'.basename($pattern), $flags));
        }

        return $files;
    }

    /**
     * return the index
     *
     * @return array
     */
    public function index()
    {
        return $this->index;
    }
}
