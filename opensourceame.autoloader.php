<?php
/**
 * An autoloader class that parses files and caches an index of classes to load
 *
 * @description		An autoloader class that parses files and caches an index of classes to load
 * @package 		opensourceame
 * @subpackage		logger
 * @author			David Kelly
 * @copyright		David Kelly, 2012 (http://opensourceame.com)
 * @version			2.7.0
 */

namespace opensourceame;

class autoloader
{
	const			version			= '2.7.0';

	static			$instance		= null;

	private			$extensions 	= array('php', 'inc');
	private			$index			= array();
	private			$pathsRead		= array();
	private			$initialised	= false;

	private			$debug;
	private			$cache;
	private			$cacheRead		= false;
	private			$cacheDir;
	private			$cacheMaxAge;
	private			$include;
	private			$exclude;
	private			$ignore;

	private			$missingClasses 		= array();
	private			$missingRefreshInterval	= 60;

	private			$configDefaults	= array(
		'debug'			=> false,
		'cache'			=> true,
		'cacheDir'		=> '/tmp',
		'cacheMaxAge'	=> 600,
		'cacheMethod'	=> 'include',
		'exclude'		=> array(),
		'include'		=> array(),
		'ignore'		=> array(),
	);

	public function __construct($config = false)
	{
		$this->registerInstance();

		$this->readConfigArray($this->configDefaults);

		if (is_array($config))
			$this->readConfigArray($config);

		if (is_string($config))
			$this->readConfigFile($config);

		spl_autoload_register('\opensourceame\autoloader::load');
	}

	public function readConfigArray($config)
	{
		foreach ($this->configDefaults as $key => $val)
		{
			if (isset($config[$key]))
				$this->$key = $config[$key];
		}

		return true;
	}

	public function readConfigFile($configFile)
	{
		if (function_exists('yaml_parse_file'))
		{
			$config = yaml_parse_file($configFile);

		} else {

			require_once __DIR__ . '/../../third_party/spyc/spyc.php';

			$config = \Spyc::YAMLLoad($configFile);
		}

		$this->readConfigArray($config);

		return true;

	}

	public function excludePath($path)
	{
		if (is_array($path))
		{
			foreach ($path as $p)
			{
				$this->excludePath($p);
			}
		}

		$this->exclude[] = realpath($path);

		return true;
	}


	public function includePath($path)
	{
		if (is_array($path))
		{
			foreach ($path as $p)
			{
				$this->includePath($p);
			}
		}

		$this->include[] = realpath($path);

		return true;
	}



	private function createCacheLockFile()
	{
		$lockFile = $this->getCacheLockFilename();

		$this->debug("create lock file $lockFile");

		return file_put_contents($lockFile, date("Y-m-d H:i:s"));
	}

	private function writeCache()
	{
		if (! $this->cache)
			return true;

		$cacheFile = $this->getCacheFileName();

		unlink($this->getCacheLockFileName());

		$cacheContent  = "<?php\n";
		$cacheContent .= '$index = ' . var_export($this->index, true) . ";\n";
		$cacheContent .= '$missing = ' . var_export($this->missingClasses, true) . "; \n";

		if (! file_put_contents($cacheFile, $cacheContent))
		{
			$this->debug("could not write cache to $cacheFile");

			return false;
		}

		chmod($cacheFile, 0777);

		$this->debug("wrote cache to $cacheFile");

		return true;
	}

	private function readCache()
	{
		if (! $this->cache)
			return false;

		$cacheFile = $this->getCacheFileName();

		if (! is_readable($cacheFile))
			return false;

		include_once $this->getCacheFileName();

		$this->index 			= $index;
		$this->missingClasses 	= $missing;
		$this->cacheRead 		= true;

		$this->debug("read cache from $cacheFile");
		$this->debug(count($this->index) . " classes loaded");
		$this->debug(count($this->missingClasses) . " classes missing");

		return true;

	}

	protected function debug($message)
	{
		if (! $this->debug)
			return false;

		openlog('autoloader', false, LOG_USER);

		return syslog(LOG_INFO, $message);
	}

    public function loadClass($class)
	{
		$unformatted_classname = $class;
		$class = $this->formatClassName($class);

		$this->debug("loading $class");

		if (isset($this->index[$class]))
		{
			require_once $this->index[$class];

			return true;

		} else { // class is not found : searching the file through the include paths

			if (isset($this->missingClasses[$class]))
			{
				$timeMissing = time() - $this->missingClasses[$class];

				if ( $timeMissing < $this->missingRefreshInterval)
				{
					$this->debug("$class has only been missing for $timeMissing seconds, not refreshing");

					return false;
				}
			}

			$this->debug("class not found, running search");

			foreach ($this->include as $path)
			{
				$files 	= $this->glob_recursive("$path/*.php");

				foreach ($files as $file) {

					if (basename($file, ".php") === $unformatted_classname ) {

						$this->init(true);

						require_once $file;

						return true;
					}
				}
			}

			// class not found, mark this class as missing

			$this->debug("class not found, marking as missing");

			$this->missingClasses[$class] = time();

			$this->writeCache();
		}

		return false;
	}

	public function load($class)
	{

		$autoloader = autoloader::getInstance();

		$autoloader->init();

		return $autoloader->loadClass($class);

	}

	static public function getInstance()
	{
		return $GLOBALS['__opensourceame_autoloader'];
	}

	protected function registerInstance()
	{
		$GLOBALS['__opensourceame_autoloader'] =& $this;

		return true;
	}

	public function init($forceInit = false)
	{
		if ($this->initialised and ! $forceInit)
			return true;

		$this->debug('initialising');

		foreach ($this->exclude as $key => $path)
		{
			$this->exclude[$key] = realpath($path);
		}

		foreach ($this->include as $key => $path)
		{
			$this->include[$key] = realpath($path);
		}

		foreach ($this->ignore as $key => $val)
		{
			if (is_array($val))
			{
				foreach ($val as $k => $v)
				{
					$ignore[$this->formatClassName($key)][$k] = realpath($v);

					$this->ignore = $ignore;
				}
			} else {
				$this->ignore[$this->formatClassName($key)] = realpath($val);
			}
		}

		if ($forceInit)
		{
			if (is_writeable($this->getCacheFileName()))
			{
				unlink($this->getCacheFileName());

				$this->debug("deleted cache");
			}
		}

		if ($this->readCache())
		{
			$this->initialised = true;

			return true;
		}

		if ($this->cache)
		{
			if (! file_exists($this->getCacheLockFileName()))
			{
				$this->createCacheLockFile();
			} else {

				// TODO: delete the lock file if it is older than a specified number of seconds

				$waitCount = 0;

				while (! file_exists($this->getCacheFileName()))
				{
					// TODO: check how long the lock file has been hanging around and delete if necessary

					sleep(1);

					$this->debug("waiting for cache file to complete");

					$waitCount ++;

					if ($waitCount > 20)
						return false;
				}

				$this->readCache();

				$this->initialised = true;

				return true;
			}
		}

		foreach ($this->include as $path)
		{
			$this->readDirectory($path);
		}

		$this->writeCache();

		$this->initialised = true;

		return true;
	}

	public function getCacheFileName()
	{
		$tmp = array(
			'in'	=> $this->include,
			'ex'	=> $this->exclude,
			'ign'	=> $this->ignore,
		);

		if ($this->cacheMethod == 'include')
		{
			$ext = 'php';
		} else {
			$ext = 'cache';
		}

		return $this->cacheDir . "/autoloader." . md5(serialize($tmp)) . ".$ext";
	}

	public function getCacheLockFileName()
	{
		return $this->getCacheFileName() . '.lock';
	}

	public function readDirectory($path)
	{
		$path	= realpath($path);

		$this->debug("reading $path");

		$this->pathsRead[] = $path;

		$files 	= $this->glob_recursive("$path/*");

		foreach ($files as $file)
		{
			$ext = substr($file, strrpos($file, '.') + 1);

			if (in_array($ext, $this->extensions))
			{
				$this->parseFile($file);
			}
		}

		ksort($this->index);

		$this->pathsParsed = true;

		return true;
	}

	private function parseFile($filename)
	{
		$content 	= file($filename);
		$namespace	= null;

		foreach ($content as $line)
		{
			if(preg_match("/^\s*(namespace)\s+(.*).*;$/", $line, $matches))
			{
				$namespace = $matches[2];
			}

			if(preg_match("/^\s*(abstract|final)*\s*(class|interface)\s+(\w*).*$/", $line, $matches))
			{
				$className = $matches[3];

				$this->addClassIndex($filename, $namespace, $className);

			}
		}
	}

	private function formatClassName($class)
	{
		if (substr($class, 0, 1) != '\\')
			$class = '\\' . $class;

		$class = strtolower($class);

		return $class;
	}

	private function addClassIndex($filename, $namespace, $className)
	{
		$class = $this->formatClassName("$namespace\\$className");

		if (isset($this->index[$class]))
		{
			$this->debug("duplicate class name $class in $filename");

			return false;
		}

		if (isset($this->ignore[$class]) and (in_array($filename, $this->ignore[$class])))
		{
			$this->debug("ingoring $class in $filename");

			return false;
		}


		$this->index[$class] = $filename;

		return true;
	}

	private function glob_recursive($pattern, $flags = 0)
	{

		$files = glob($pattern, $flags);

		foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir)
		{
			if (in_array($dir, $this->exclude))
			{
 				$this->debug("excluding directory $dir");

				continue;
			}

			$files = array_merge($files, $this->glob_recursive($dir.'/'.basename($pattern), $flags));
		}

		return $files;
	}

	public function index()
	{
		return $this->index;
	}
}