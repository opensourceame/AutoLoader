more info at: http://opensourceame.com

version 3.0.0:

note:

Version 3 uses multiple classes and changes file names. Please set up as follows:

--- php ---

require_once '/path/to/opensourceame/AutoLoader/AutoLoader.php';

$autoloader = new \opensourceame\AutoLoader\AutoLoader;
$autoloader->readConfigFile('/path/to/autoloader.yaml');
$autoloader->init();

-----------

changes:

* new index system
* improved checking of missing classes
* improved debugging
* smarter cache file
* better lock file checking (e.g.: deletes stale locks) 
