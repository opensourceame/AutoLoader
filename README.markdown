[Autoloader][]
==============

Autoloading in PHP is a mixed bag. Many packages and applications come with their own autoloaders, frameworks have their own ways of autoloading classes, many scripts and applications are still littered with *require* and *include* statements.  
  
The current trend in autoloading is to register a namespace and locate your classes according to a well defined structure, such as [the FIG standard][] in which each class is put in its own file, and namespaces translate into multiple directories, so that:

    new \this\Is\My\FirstClass;
    new \this\Is\My\SecondClass;

translates into loading the file:

    {base path}\this\Is\My\FirstClass.php {base path}\this\Is\My\SecondClass.php

While this method works very well there are some drawbacks:

- **complex structure** : in the example above, three or more directories and two files are needed to contain the classes, even if each of them contains only a few lines. While it’s undoubtedly a good way to define classes in large projects, for smaller pieces of code it makes navigating the code base unnecessarily cumbersome.
- **legacy code** : there is a huge volume of apps and scripts which don’t follow this convention, making an autoloader which strictly adheres to the standard seless for those cases.

My approach to autoloading is to build up an index of which files contain classes, and then load the class files on demand according to that index. Of course this gives a performance hit as each file needs to be parsed to search for namespace and class names, however by caching the index this performance hit only occurs occasionally at a configurable interval. You could, for example, re-index your class files only on deployment of new code to a production server making the
performance hit practically zero.


Configuration
-------------

Config options can be passed as an array to the constructor, or read
from a YAML file when specifying a config file to the constructor.

    require_once "/path/to/opensourceame.autoloader.php";
     
    new \opensourceame\autoloader('/path/to/config.yaml');
     
    // or
     
    new \opensourceame\autoloader($autoloaderConfigArray);

### Options

The main options are:

*cache* : a boolean value which turns caching on or off  
*cacheDir* : which directory to store cache files in  
*cacheMaxAge* : the number of seconds until a cache file is considered stale  
*cacheMethod* : a choice of ‘serialize’ or ‘include’, see more info in the performance section below  
*include* : a list of paths to search for class files  
*exclude* : paths to exclude from the search  
*ignore* : a list of classes and the files that should not be considered as class definitions  


Caveats
-------

### Cache files

You will need to make sure that where two different users (e.g. your
apache user and a command line script user) can both read and write to
the cache directory if they share the same cache file. However it’s also
easy to make each separate user cache autoloader indexes to different
directories.

### Class name duplication

Class name duplication in files is easily enough avoided by excluding directories that hold the same classes as another directory. However there’s one other possible scenario that can be problematic, consider the following:

    if (! class_exists("SomeClassName"))
    {
      class SomeClassName {}
    }
     
    class SomeOtherClass extends SomeClassName
    {
    ...
    }

The problem here is that the file parser recognises “class SomeClassName” as the definition of a class, although the class may never actually be defined at that point. The index therefore points to that file as the holder of the class definition. There’s an easy way to fix this thankfully, and that is to use the ignore option, like this:

    $autoloader = new \opensourceame\autoloader(array(
      'ignore' => array(
        'TheProblemClassName' => '/some/path/where/the/fake/class/is/defined.php',
      ),
    ));

The ignore value for a class can be an array of multiple files. For example, if using YAML config you can do this:

    ignore:
       ProblemClassName:
         - /is/defined/here.php
         - /and/here/too.php


Performance
-------

Using XDebug I have found that loading the autoloader uses about 7kb of memory. Indexes, of course, take up far more memory. In my case an index of 2300 classes uses up about 200k of memory, which I think is negligible.


### Caching

As mentioned above, using the autoloader without caching will cause a huge performance hit so it is important to make sure caching is on. There are two possible caching methods; “serialize”, which causes the index to be stored as a serialized file, or “include” where the index is saved as a PHP file which is then included.

If you use APC or XCache then it’s preferable to use the “include” option as the index will then be cached by the bytecode compiler. In my tests this technique allows the autoloader to load the index in less than a millisecond even on a moderately powered machine.


Download
-------

You can download the source from GitHub [here][]

[Autoloader]: http://opensourceame.com/code/autoloader/ "Autoloader"
[the FIG standard]: https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md
[here]: https://github.com/opensourceame/opensourceame-autoloader