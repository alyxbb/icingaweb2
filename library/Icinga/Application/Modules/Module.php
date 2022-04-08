<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Application\Modules;

use Exception;
use Icinga\Application\ApplicationBootstrap;
use Icinga\Application\Config;
use Icinga\Application\Hook;
use Icinga\Application\Icinga;
use Icinga\Application\Logger;
use Icinga\Exception\IcingaException;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Setup\SetupWizard;
use Icinga\Util\File;
use Icinga\Web\Navigation\Navigation;
use Icinga\Web\Widget;
use ipl\I18n\GettextTranslator;
use ipl\I18n\StaticTranslator;
use ipl\I18n\Translation;
use ipl\Web\Url;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Zend_Controller_Router_Route;
use Zend_Controller_Router_Route_Abstract;
use Zend_Controller_Router_Route_Regex;

/**
 * Module handling
 *
 * Register modules and initialize it
 */
class Module
{
    use Translation {
        translate as protected;
        translatePlural as protected;
    }

    /**
     * Module name
     *
     * @var string
     */
    private $name;

    /**
     * Base directory of module
     *
     * @var string
     */
    private $basedir;

    /**
     * Directory for styles
     *
     * @var string
     */
    private $cssdir;

    /**
     * Directory for Javascript
     *
     * @var string
     */
    private $jsdir;

    /**
     * Base application directory
     *
     * @var string
     */
    private $appdir;

    /**
     * Library directory
     *
     * @var string
     */
    private $libdir;

    /**
     * Directory containing translations
     *
     * @var string
     */
    private $localedir;

    /**
     * Directory where controllers reside
     *
     * @var string
     */
    private $controllerdir;

    /**
     * Directory containing form implementations
     *
     * @var string
     */
    private $formdir;

    /**
     * Module bootstrapping script
     *
     * @var string
     */
    private $runScript;

    /**
     * Module configuration script
     *
     * @var string
     */
    private $configScript;

    /**
     * Module metadata filename
     *
     * @var string
     */
    private $metadataFile;

    /**
     * Module metadata (version...)
     *
     * @var object
     */
    private $metadata;

    /**
     * Whether we already tried to include the module configuration script
     *
     * @var bool
     */
    private $triedToLaunchConfigScript = false;

    /**
     * Whether the module's namespaces have been registered on our autoloader
     *
     * @var bool
     */
    protected $registeredAutoloader = false;

    /**
     * Whether this module has been registered
     *
     * @var bool
     */
    private $registered = false;

    /**
     * Provided permissions
     *
     * @var array
     */
    private $permissionList = array();

    /**
     * Provided restrictions
     *
     * @var array
     */
    private $restrictionList = array();

    /**
     * Provided config tabs
     *
     * @var array
     */
    private $configTabs = array();

    /**
     * Provided setup wizard
     *
     * @var string
     */
    private $setupWizard;

    /**
     * Icinga application
     *
     * @var \Icinga\Application\Web
     */
    private $app;

    /**
     * The CSS/LESS files this module provides
     *
     * @var array
     */
    protected $cssFiles = array();

    /**
     * The Javascript files this module provides
     *
     * @var array
     */
    protected $jsFiles = array();

    /**
     * Routes to add to the route chain
     *
     * @var array Array of name-route pairs
     *
     * @see addRoute()
     */
    protected $routes = array();

    /**
     * A set of menu elements
     *
     * @var MenuItemContainer[]
     */
    protected $menuItems = array();

    /**
     * A set of Pane elements
     *
     * @var DashboardContainer
     */
    protected $paneItems = [];

    /**
     * A set of Dashlet elements
     *
     * @var DashletContainer[]
     */
    protected $dashletItems = [];

    /**
     * A set of objects representing a searchUrl configuration
     *
     * @var array
     */
    protected $searchUrls = array();

    /**
     * This module's user backends providing several authentication mechanisms
     *
     * @var array
     */
    protected $userBackends = array();

    /**
     * This module's user group backends
     *
     * @var array
     */
    protected $userGroupBackends = array();

    /**
     * This module's configurable navigation items
     *
     * @var array
     */
    protected $navigationItems = array();

    /**
     * Create a new module object
     *
     * @param ApplicationBootstrap  $app
     * @param string                $name
     * @param string                $basedir
     */
    public function __construct(ApplicationBootstrap $app, $name, $basedir)
    {
        $this->app            = $app;
        $this->name           = $name;
        $this->basedir        = $basedir;
        $this->cssdir         = $basedir . '/public/css';
        $this->jsdir          = $basedir . '/public/js';
        $this->libdir         = $basedir . '/library';
        $this->configdir      = $app->getConfigDir('modules/' . $name);
        $this->appdir         = $basedir . '/application';
        $this->localedir      = $basedir . '/application/locale';
        $this->formdir        = $basedir . '/application/forms';
        $this->controllerdir  = $basedir . '/application/controllers';
        $this->runScript      = $basedir . '/run.php';
        $this->configScript   = $basedir . '/configuration.php';
        $this->metadataFile   = $basedir . '/module.info';

        $this->translationDomain = $name;
    }

    /**
     * Provide a search URL
     *
     * @param   string    $title
     * @param   string    $url
     * @param   int       $priority
     *
     * @return  $this
     */
    public function provideSearchUrl($title, $url, $priority = 0)
    {
        $this->searchUrls[] = (object) array(
            'title'     => (string) $title,
            'url'       => (string) $url,
            'priority'  => (int) $priority
        );

        return $this;
    }

    /**
     * Get this module's search urls
     *
     * @return array
     */
    public function getSearchUrls()
    {
        $this->launchConfigScript();
        return $this->searchUrls;
    }

    /**
     * Return this module's dashboard
     *
     * @return Navigation
     */
    public function getDashboard()
    {
        $this->launchConfigScript();
        return $this->createDashboard($this->paneItems);
    }

    /**
     * Create and return a new navigation for the given dashboard panes
     *
     * @param   DashboardContainer[] $panes
     *
     * @return  Navigation
     */
    public function createDashboard(array $panes)
    {
        $navigation = new Navigation();
        foreach ($panes as $pane) {
            /** @var DashboardContainer $pane */
            $dashlets = [];
            foreach ($pane->getDashlets() as $dashletName => $dashletConfig) {
                $dashlets[$dashletName] = [
                    'label'     => $this->translate($dashletName),
                    'url'       => $dashletConfig['url'],
                    'priority'  => $dashletConfig['priority']
                ];
            }

            $navigation->addItem(
                $pane->getName(),
                array_merge(
                    $pane->getProperties(),
                    array(
                        'label'     => $this->translate($pane->getName()),
                        'type'      => 'dashboard-pane',
                        'children'  => $dashlets
                    )
                )
            );
        }

        return $navigation;
    }

    /**
     * Add or get a dashboard pane
     *
     * @param   string  $name
     * @param   array   $properties
     *
     * @return  DashboardContainer
     */
    protected function dashboard($name, array $properties = array())
    {
        if (array_key_exists($name, $this->paneItems)) {
            $this->paneItems[$name]->setProperties($properties);
        } else {
            $this->paneItems[$name] = new DashboardContainer($name, $properties);
        }

        return $this->paneItems[$name];
    }

    /**
     * Get this module's provided dashlets
     *
     * @return DashletContainer[]
     */
    public function getDashlets()
    {
        $this->launchConfigScript();
        return $this->dashletItems;
    }

    /**
     * Add or get a dashlet
     *
     * @param string $name
     * @param Url|string $url
     * @param array $properties
     *
     * @return DashletContainer
     */
    protected function provideDashlet($name, $url, array $properties = [])
    {
        if (array_key_exists($name, $this->dashletItems)) {
            $this->dashletItems[$name]->setProperties($properties);
        } else {
            $this->dashletItems[$name] = new DashletContainer($name, $url, $properties);
        }

        return $this->dashletItems[$name];
    }

    /**
     * Return this module's menu
     *
     * @return  Navigation
     */
    public function getMenu()
    {
        $this->launchConfigScript();
        return Navigation::fromArray($this->createMenu($this->menuItems));
    }

    /**
     * Create and return an array structure for the given menu items
     *
     * @param   MenuItemContainer[]     $items
     *
     * @return  array
     */
    private function createMenu(array $items)
    {
        $navigation = array();
        foreach ($items as $item) {
            /** @var MenuItemContainer $item */
            $properties = $item->getProperties();
            $properties['children'] = $this->createMenu($item->getChildren());
            if (! isset($properties['label'])) {
                $properties['label'] = $this->translate($item->getName());
            }

            $navigation[$item->getName()] = $properties;
        }

        return $navigation;
    }

    /**
     * Add or get a menu section
     *
     * @param   string  $name
     * @param   array   $properties
     *
     * @return  MenuItemContainer
     */
    protected function menuSection($name, array $properties = array())
    {
        if (array_key_exists($name, $this->menuItems)) {
            $this->menuItems[$name]->setProperties($properties);
        } else {
            $this->menuItems[$name] = new MenuItemContainer($name, $properties);
        }

        return $this->menuItems[$name];
    }

    /**
     * Register module
     *
     * @return bool
     */
    public function register()
    {
        if ($this->registered) {
            return true;
        }

        $this->registerAutoloader();
        try {
            $this->launchRunScript();
        } catch (Exception $e) {
            Logger::warning(
                'Launching the run script %s for module %s failed with the following exception: %s',
                $this->runScript,
                $this->name,
                $e->getMessage()
            );
            return false;
        }
        $this->registerWebIntegration();
        $this->registered = true;

        return true;
    }

    /**
     * Get whether this module has been registered
     *
     * @return bool
     */
    public function isRegistered()
    {
        return $this->registered;
    }

    /**
     * Test for an enabled module by name
     *
     * @param   string $name
     *
     * @return  bool
     */
    public static function exists($name)
    {
        return Icinga::app()->getModuleManager()->hasEnabled($name);
    }

    /**
     * Get a module by name
     *
     * @param string $name
     * @param bool   $autoload
     *
     * @return  self
     *
     * @throws ProgrammingError When the module is not yet loaded
     */
    public static function get($name, $autoload = false)
    {
        $manager = Icinga::app()->getModuleManager();
        if (!$manager->hasLoaded($name)) {
            if ($autoload === true && $manager->hasEnabled($name)) {
                $manager->loadModule($name);
            }
        }
        // Throws ProgrammingError when the module is not yet loaded
        return $manager->getModule($name);
    }

    /**
     * Provide an additional CSS/LESS file
     *
     * @param   string  $path   The path to the file, relative to self::$cssdir
     *
     * @return  $this
     */
    protected function provideCssFile($path)
    {
        $this->cssFiles[] = $this->cssdir . DIRECTORY_SEPARATOR . $path;
        return $this;
    }

    /**
     * Test if module provides css
     *
     * @return bool
     */
    public function hasCss()
    {
        if (file_exists($this->getCssFilename())) {
            return true;
        }

        $this->launchConfigScript();
        return !empty($this->cssFiles);
    }

    /**
     * Returns the complete less file name
     *
     * @return string
     */
    public function getCssFilename()
    {
        return $this->cssdir . '/module.less';
    }

    /**
     * Return the CSS/LESS files this module provides
     *
     * @return  array
     */
    public function getCssFiles()
    {
        $this->launchConfigScript();
        $files = $this->cssFiles;
        if (file_exists($this->getCssFilename())) {
            $files[] = $this->getCssFilename();
        }
        return $files;
    }

    /**
     * Provide an additional Javascript file
     *
     * @param   string  $path   The path to the file, relative to self::$jsdir
     *
     * @return  $this
     */
    protected function provideJsFile($path)
    {
        $this->jsFiles[] = $this->jsdir . DIRECTORY_SEPARATOR . $path;
        return $this;
    }

    /**
     * Test if module provides js
     *
     * @return bool
     */
    public function hasJs()
    {
        if (file_exists($this->getJsFilename())) {
            return true;
        }

        $this->launchConfigScript();
        return !empty($this->jsFiles);
    }

    /**
     * Returns the complete js file name
     *
     * @return string
     */
    public function getJsFilename()
    {
        return $this->jsdir . '/module.js';
    }

    /**
     * Return the Javascript files this module provides
     *
     * @return  array
     */
    public function getJsFiles()
    {
        $this->launchConfigScript();
        $files = $this->jsFiles;
        $files[] = $this->getJsFilename();
        return $files;
    }

    /**
     * Get the module name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the module namespace
     *
     * @return string
     */
    public function getNamespace()
    {
        return 'Icinga\\Module\\' . ucfirst($this->getName());
    }

    /**
     * Get the module version
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->metadata()->version;
    }

    /**
     * Get the module description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->metadata()->description;
    }

    /**
     * Get the module title (short description)
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->metadata()->title;
    }

    /**
     * Get the module dependencies
     *
     * @return array
     * @deprecated Use method getRequiredModules() instead
     */
    public function getDependencies()
    {
        return $this->metadata()->depends;
    }

    /**
     * Get required libraries
     *
     * @return array
     */
    public function getRequiredLibraries()
    {
        $requiredLibraries = $this->metadata()->libraries;

        // Register module requirements for ipl and reactbundle as library requirements
        $requiredModules = $this->metadata()->modules ?: $this->metadata()->depends;
        if (isset($requiredModules['ipl']) && ! isset($requiredLibraries['icinga-php-library'])) {
            $requiredLibraries['icinga-php-library'] = $requiredModules['ipl'];
        }

        if (isset($requiredModules['reactbundle']) && ! isset($requiredLibraries['icinga-php-thirdparty'])) {
            $requiredLibraries['icinga-php-thirdparty'] = $requiredModules['reactbundle'];
        }

        return $requiredLibraries;
    }

    /**
     * Get required modules
     *
     * @return array
     */
    public function getRequiredModules()
    {
        $requiredModules = $this->metadata()->modules ?: $this->metadata()->depends;

        $hasIcingadb = isset($requiredModules['icingadb']);
        if (isset($requiredModules['monitoring']) && ($this->isSupportingIcingadb() || $hasIcingadb)) {
            $requiredMods = [];
            $icingadbVersion = true;
            if ($hasIcingadb) {
                $icingadbVersion = isset($requiredModules['icingadb']) ? $requiredModules['icingadb'] : true;
                unset($requiredModules['icingadb']);
            }

            foreach ($requiredModules as $name => $version) {
                $requiredMods[$name] = $version;
                if ($name === 'monitoring') {
                    $requiredMods['icingadb'] = $icingadbVersion;
                }
            }

            $requiredModules = $requiredMods;
        }

        // Both modules are deprecated and their successors are now dependencies of web itself
        unset($requiredModules['ipl'], $requiredModules['reactbundle']);

        return $requiredModules;
    }

    /**
     * Check whether module supports icingadb
     *
     * @return bool
     */
    protected function isSupportingIcingadb()
    {
        $icingadbSupportingModules = [
            'cube'              => '1.2.0',
            'jira'              => '1.2.0',
            'graphite'          => '1.2.0',
            'director'          => '1.9.0',
            'toplevelview'      => '0.4.0',
            'businessprocess'   => '2.4.0'
        ];

        return array_key_exists($this->getName(), $icingadbSupportingModules)
            && version_compare($this->getVersion(), $icingadbSupportingModules[$this->getName()], '>=');
    }

    /**
     * Fetch module metadata
     *
     * @return object
     */
    protected function metadata()
    {
        if ($this->metadata === null) {
            $metadata = (object) [
                'name'          => $this->getName(),
                'version'       => '0.0.0',
                'title'         => null,
                'description'   => '',
                'depends'       => [],
                'libraries'     => [],
                'modules'       => []
            ];

            if (file_exists($this->metadataFile)) {
                $key = null;
                $simpleRequires = false;
                $file = new File($this->metadataFile, 'r');
                foreach ($file as $lineno => $line) {
                    $line = rtrim($line);

                    if ($key === 'description') {
                        if (empty($line)) {
                            $metadata->description .= "\n";
                            continue;
                        } elseif ($line[0] === ' ') {
                            $metadata->description .= $line;
                            continue;
                        }
                    } elseif (empty($line)) {
                        continue;
                    }

                    if (strpos($line, ':') === false) {
                        Logger::debug(
                            "Can't process line %d in %s: Line does not specify a key:value pair"
                            . " nor is it part of the description (indented with a single space)",
                            $lineno,
                            $this->metadataFile
                        );

                        break;
                    }

                    $parts = preg_split('/:\s+/', $line, 2);
                    if (count($parts) === 1) {
                        $parts[] = '';
                    }

                    list($key, $val) = $parts;

                    $key = strtolower($key);
                    switch ($key) {
                        case 'requires':
                            if ($val) {
                                $simpleRequires = true;
                                $key = 'libraries';
                            } else {
                                break;
                            }

                            // Shares the syntax with `Depends`
                        case '  libraries':
                        case '  modules':
                            if ($simpleRequires && $key[0] === ' ') {
                                Logger::debug(
                                    'Can\'t process line %d in %s: Requirements already registered by a previous line',
                                    $lineno,
                                    $this->metadataFile
                                );
                                break;
                            }

                            $key = ltrim($key);
                            // Shares the syntax with `Depends`
                        case 'depends':
                            if (strpos($val, ' ') === false) {
                                $metadata->{$key}[$val] = true;
                                continue 2;
                            }

                            $parts = preg_split('/,\s+/', $val);
                            foreach ($parts as $part) {
                                if (preg_match('/^([\w\-\/]+)\s+\((.+)\)$/', $part, $m)) {
                                    $metadata->{$key}[$m[1]] = $m[2];
                                } else {
                                    $metadata->{$key}[$part] = true;
                                }
                            }

                            break;
                        case 'description':
                            if ($metadata->title === null) {
                                $metadata->title = $val;
                            } else {
                                $metadata->description = $val;
                            }
                            break;

                        default:
                            $metadata->{$key} = $val;
                    }
                }
            }

            if ($metadata->title === null) {
                $metadata->title = $this->getName();
            }

            if ($metadata->description === '') {
                $metadata->description = t(
                    'This module has no description'
                );
            }

            $this->metadata = $metadata;
        }
        return $this->metadata;
    }

    /**
     * Get the module's CSS directory
     *
     * @return string
     */
    public function getCssDir()
    {
        return $this->cssdir;
    }

    /**
     * Get the module's JS directory
     *
     * @return string
     */
    public function getJsDir()
    {
        return $this->jsdir;
    }

    /**
     * Get the module's controller directory
     *
     * @return string
     */
    public function getControllerDir()
    {
        return $this->controllerdir;
    }

    /**
     * Get the module's base directory
     *
     * @return string
     */
    public function getBaseDir()
    {
        return $this->basedir;
    }

    /**
     * Get the module's application directory
     *
     * @return string
     */
    public function getApplicationDir()
    {
        return $this->appdir;
    }

    /**
     * Get the module's library directory
     *
     * @return string
     */
    public function getLibDir()
    {
        return $this->libdir;
    }

    /**
     * Get the module's configuration directory
     *
     * @return string
     */
    public function getConfigDir()
    {
        return $this->configdir;
    }

    /**
     * Get the module's form directory
     *
     * @return string
     */
    public function getFormDir()
    {
        return $this->formdir;
    }

    /**
     * Get the module config
     *
     * @param   string $file
     *
     * @return  Config
     */
    public function getConfig($file = 'config')
    {
        return $this->app->getConfig()->module($this->name, $file);
    }

    /**
     * Get provided permissions
     *
     * @return array
     */
    public function getProvidedPermissions()
    {
        $this->launchConfigScript();
        return $this->permissionList;
    }

    /**
     * Get provided restrictions
     *
     * @return array
     */
    public function getProvidedRestrictions()
    {
        $this->launchConfigScript();
        return $this->restrictionList;
    }

    /**
     * Whether the module provides the given restriction
     *
     * @param   string $name Restriction name
     *
     * @return  bool
     */
    public function providesRestriction($name)
    {
        $this->launchConfigScript();
        return array_key_exists($name, $this->restrictionList);
    }

    /**
     * Whether the module provides the given permission
     *
     * @param   string $name Permission name
     *
     * @return  bool
     */
    public function providesPermission($name)
    {
        $this->launchConfigScript();
        return array_key_exists($name, $this->permissionList);
    }

    /**
     * Get the module configuration tabs
     *
     * @return \Icinga\Web\Widget\Tabs
     */
    public function getConfigTabs()
    {
        $this->launchConfigScript();
        $tabs = Widget::create('tabs');
        /** @var \Icinga\Web\Widget\Tabs $tabs */
        $tabs->add('info', array(
            'url'       => 'config/module',
            'urlParams' => array('name' => $this->getName()),
            'label'     => 'Module: ' . $this->getName()
        ));

        if ($this->app->getModuleManager()->hasEnabled($this->name)) {
            foreach ($this->configTabs as $name => $config) {
                $tabs->add($name, $config);
            }
        }

        return $tabs;
    }

    /**
     * Whether the module provides a setup wizard
     *
     * @return bool
     */
    public function providesSetupWizard()
    {
        $this->launchConfigScript();
        if ($this->setupWizard && class_exists($this->setupWizard)) {
            $wizard = new $this->setupWizard;
            return $wizard instanceof SetupWizard;
        }

        return false;
    }

    /**
     * Get the module's setup wizard
     *
     * @return SetupWizard
     */
    public function getSetupWizard()
    {
        return new $this->setupWizard;
    }

    /**
     * Get the module's user backends
     *
     * @return array
     */
    public function getUserBackends()
    {
        $this->launchConfigScript();
        return $this->userBackends;
    }

    /**
     * Get the module's user group backends
     *
     * @return array
     */
    public function getUserGroupBackends()
    {
        $this->launchConfigScript();
        return $this->userGroupBackends;
    }

    /**
     * Return this module's configurable navigation items
     *
     * @return  array
     */
    public function getNavigationItems()
    {
        $this->launchConfigScript();
        return $this->navigationItems;
    }

    /**
     * Provide a named permission
     *
     * @param   string $name        Unique permission name
     * @param   string $description Permission description
     *
     * @throws  IcingaException     If the permission is already provided
     */
    protected function providePermission($name, $description)
    {
        if ($this->providesPermission($name)) {
            throw new IcingaException(
                'Cannot provide permission "%s" twice',
                $name
            );
        }
        $this->permissionList[$name] = (object) array(
            'name'        => $name,
            'description' => $description
        );
    }

    /**
     * Provide a named restriction
     *
     * @param   string $name        Unique restriction name
     * @param   string $description Restriction description
     *
     * @throws  IcingaException     If the restriction is already provided
     */
    protected function provideRestriction($name, $description)
    {
        if ($this->providesRestriction($name)) {
            throw new IcingaException(
                'Cannot provide restriction "%s" twice',
                $name
            );
        }
        $this->restrictionList[$name] = (object) array(
            'name'        => $name,
            'description' => $description
        );
    }

    /**
     * Provide a module config tab
     *
     * @param   string  $name       Unique tab name
     * @param   array   $config     Tab config
     *
     * @return  $this
     * @throws  ProgrammingError    If $config lacks the key 'url'
     */
    protected function provideConfigTab($name, $config = array())
    {
        if (! array_key_exists('url', $config)) {
            throw new ProgrammingError('A module config tab MUST provide a "url"');
        }
        $config['url'] = $this->getName() . '/' . ltrim($config['url'], '/');
        $this->configTabs[$name] = $config;
        return $this;
    }

    /**
     * Provide a setup wizard
     *
     * @param   string $className The name of the class
     *
     * @return  $this
     */
    protected function provideSetupWizard($className)
    {
        $this->setupWizard = $className;
        return $this;
    }

    /**
     * Provide a user backend capable of authenticating users
     *
     * @param   string $identifier  The identifier of the new backend type
     * @param   string $className   The name of the class
     *
     * @return  $this
     */
    protected function provideUserBackend($identifier, $className)
    {
        $this->userBackends[strtolower($identifier)] = $className;
        return $this;
    }

    /**
     * Provide a user group backend
     *
     * @param   string $identifier  The identifier of the new backend type
     * @param   string $className   The name of the class
     *
     * @return  $this
     */
    protected function provideUserGroupBackend($identifier, $className)
    {
        $this->userGroupBackends[strtolower($identifier)] = $className;
        return $this;
    }

    /**
     * Provide a new type of configurable navigation item with a optional label and config filename
     *
     * @param   string  $type
     * @param   string  $label
     * @param   string  $config
     *
     * @return  $this
     */
    protected function provideNavigationItem($type, $label = null, $config = null)
    {
        $this->navigationItems[$type] = array(
            'label'     => $label,
            'config'    => $config
        );

        return $this;
    }

    /**
     * Register module namespaces on our class loader
     *
     * @return $this
     */
    protected function registerAutoloader()
    {
        if ($this->registeredAutoloader) {
            return $this;
        }

        $moduleName = ucfirst($this->getName());

        $this->app->getLoader()->registerNamespace(
            'Icinga\\Module\\' . $moduleName,
            $this->getLibDir() . '/'. $moduleName,
            $this->getApplicationDir()
        );

        $this->registeredAutoloader = true;

        return $this;
    }

    /**
     * Bind text domain for i18n
     *
     * @return $this
     */
    protected function registerLocales()
    {
        if ($this->hasLocales() && StaticTranslator::$instance instanceof GettextTranslator) {
            StaticTranslator::$instance->addTranslationDirectory($this->localedir, $this->name);
        }

        return $this;
    }

    /**
     * Get whether the module has translations
     */
    public function hasLocales()
    {
        return file_exists($this->localedir) && is_dir($this->localedir);
    }

    /**
     * List all available locales
     *
     * @return array Locale list
     */
    public function listLocales()
    {
        $locales = array();
        if (! $this->hasLocales()) {
            return $locales;
        }

        $dh = opendir($this->localedir);
        while (false !== ($file = readdir($dh))) {
            $filename = $this->localedir . DIRECTORY_SEPARATOR . $file;
            if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $file) && is_dir($filename)) {
                $locales[] = $file;
            }
        }
        closedir($dh);
        sort($locales);
        return $locales;
    }

    /**
     * Register web integration
     *
     * Add controller directory to mvc
     *
     * @return $this
     */
    protected function registerWebIntegration()
    {
        if (! $this->app->isWeb()) {
            return $this;
        }

        return $this
            ->registerLocales()
            ->registerRoutes();
    }

    /**
     * Add routes for static content and any route added via {@link addRoute()} to the route chain
     *
     * @return $this
     */
    protected function registerRoutes()
    {
        $router = $this->app->getFrontController()->getRouter();

        // TODO: We should not be required to do this. Please check dispatch()
        $this->app->getfrontController()->addControllerDirectory(
            $this->getControllerDir(),
            $this->getName()
        );

        /** @var \Zend_Controller_Router_Rewrite $router */
        foreach ($this->routes as $name => $route) {
            $router->addRoute($name, $route);
        }
        $router->addRoute(
            $this->name . '_jsprovider',
            new Zend_Controller_Router_Route(
                'js/' . $this->name . '/:file',
                array(
                    'action'        => 'javascript',
                    'controller'    => 'static',
                    'module'        => 'default',
                    'module_name'   => $this->name
                )
            )
        );
        $router->addRoute(
            $this->name . '_img',
            new Zend_Controller_Router_Route_Regex(
                'img/' . $this->name . '/(.+)',
                array(
                    'action'        => 'img',
                    'controller'    => 'static',
                    'module'        => 'default',
                    'module_name'   => $this->name
                ),
                array(
                    1 => 'file'
                )
            )
        );
        return $this;
    }

    /**
     * Run module bootstrap script
     *
     * @return $this
     */
    protected function launchRunScript()
    {
        return $this->includeScript($this->runScript);
    }

    /**
     * Include a php script if it is readable
     *
     * @param   string $file File to include
     *
     * @return  $this
     */
    protected function includeScript($file)
    {
        if (file_exists($file) && is_readable($file)) {
            include $file;
        }

        return $this;
    }

    /**
     * Run module config script
     *
     * @return $this
     */
    protected function launchConfigScript()
    {
        if ($this->triedToLaunchConfigScript) {
            return $this;
        }
        $this->triedToLaunchConfigScript = true;
        $this->registerAutoloader();
        return $this->includeScript($this->configScript);
    }

    protected function slashesToNamespace($class)
    {
        $list = explode('/', $class);
        foreach ($list as &$part) {
            $part = ucfirst($part);
        }

        return implode('\\', $list);
    }

    /**
     * Provide a hook implementation
     *
     * @param   string  $name           Name of the hook for which to provide an implementation
     * @param   string  $implementation Fully qualified name of the class providing the hook implementation.
     *                                  Defaults to the module's ProvidedHook namespace plus the hook's name for the
     *                                  class name
     * @param   bool    $alwaysRun      To run the hook always (e.g. without permission check)
     *
     * @return  $this
     */
    protected function provideHook($name, $implementation = null, $alwaysRun = false)
    {
        if ($implementation === null) {
            $implementation = $name;
        }

        if (strpos($implementation, '\\') === false) {
            $class = $this->getNamespace()
                   . '\\ProvidedHook\\'
                   . $this->slashesToNamespace($implementation);
        } else {
            $class = $implementation;
        }

        Hook::register($name, $class, $class, $alwaysRun);
        return $this;
    }

    /**
     * Add a route which will be added to the route chain
     *
     * @param   string                                  $name   Name of the route
     * @param   Zend_Controller_Router_Route_Abstract   $route  Instance of the route
     *
     * @return  $this
     * @see     registerRoutes()
     */
    protected function addRoute($name, Zend_Controller_Router_Route_Abstract $route)
    {
        $this->routes[$name] = $route;
        return $this;
    }
}
