<?php
/**
 * This file is part of the PPI Framework.
 *
 * @copyright  Copyright (c) 2011-2014 Paul Dragoonis <paul@ppi.io>
 * @license    http://opensource.org/licenses/mit-license.php MIT
 * @link       http://www.ppi.io
 */

namespace PPI\Module\Listener;

use PPI\ServiceManager\ServiceManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Loader\ModuleAutoloader;
use Zend\ModuleManager\Listener\AutoloaderListener;
use Zend\ModuleManager\Listener\DefaultListenerAggregate as ZendDefaultListenerAggregate;
use Zend\ModuleManager\Listener\InitTrigger;
use Zend\ModuleManager\Listener\ModuleDependencyCheckerListener;
use Zend\ModuleManager\Listener\ModuleLoaderListener;
use Zend\ModuleManager\Listener\ModuleResolverListener;
use Zend\ModuleManager\ModuleEvent;
use Zend\Stdlib\ArrayUtils;

$x = 0;

/**
 * DefaultListenerAggregate class.
 *
 * @author     Paul Dragoonis <paul@ppi.io>
 * @author     Vítor Brandão <vitor@ppi.io>
 *
 * @package    PPI
 * @subpackage Module
 */
class DefaultListenerAggregate extends ZendDefaultListenerAggregate
{
    /**
     * The routes registered for our
     *
     * @var array
     */
    protected $_routes = array();

    /**
     * Services for the ServiceLocator
     *
     * @var array
     */
    protected $_services = array();

    /**
     * The Service Manager
     *
     * @var type
     */
    protected $_serviceManager;

    /**
     * Set the service manager
     *
     * @param ServiceManager $sm
     *
     * @return void
     */
    public function setServiceManager(ServiceManager $sm)
    {
        $this->_serviceManager = $sm;
    }

    /**
     * Override of attach(). Customising the events to be triggered upon the
     * 'loadModule' event.
     *
     * @param EventManagerInterface $events
     *
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $options                     = $this->getOptions();
        $configListener              = $this->getConfigListener();
        $moduleAutoloader            = new ModuleAutoloader($options->getModulePaths());

        // High priority, we assume module autoloading (for FooNamespace\Module classes) should be available before anything else
        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULES, array($moduleAutoloader, 'register'), 9000);
        $this->listeners[] = $events->attach(new ModuleLoaderListener($options));
        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE_RESOLVE, new ModuleResolverListener());
        // High priority, because most other loadModule listeners will assume the module's classes are available via autoloading
        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, new AutoloaderListener($options), 9000);

        if ($options->getCheckDependencies()) {
            $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, new ModuleDependencyCheckerListener, 8000);
        }

        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, new InitTrigger($options));
        $this->listeners[] = $events->attach($configListener);

        // This process can be expensive and affect perf if enabled. So we have
        // the flexibility to skip it.
        //if ($options->routingEnabled) {
            $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, array($this, 'routesTrigger'), 3000);
        //}

        $this->listeners[] = $events->attach(ModuleEvent::EVENT_LOAD_MODULE, array($this, 'getServicesTrigger'), 3000);

        return $this;
    }

    /**
     * Event callback for 'routesTrigger'
     *
     * @param ModuleEvent $e
     *
     * @return $this
     */
    public function routesTrigger(ModuleEvent $e)
    {
        $module = $e->getModule();

        if (is_callable(array($module, 'getRoutes'))) {
            $this->_routes[$e->getModuleName()] = $module->getRoutes();
        }

        return $this;
    }

    /**
     * Event callback for 'initServicesTrigger'
     *
     * @param ModuleEvent $e
     *
     * @return $this
     */
    public function getServicesTrigger(ModuleEvent $e)
    {
        $module = $e->getModule();

        if (is_callable(array($module, 'getServiceConfig'))) {
            $services = $module->getServiceConfig();
            if (is_array($services) && isset($services['factories'])) {
                $this->_services[$e->getModuleName()] = $services['factories'];
            }
        }

        return $this;
    }

    /**
     * Get the registered routes
     *
     * @return array
     */
    public function getRoutes()
    {
        return $this->_routes;
    }

    /**
     * Get the registered services
     *
     * @return mixed
     */
    public function getServices()
    {
        $mergedModuleServices = array();

        foreach ($this->_services as $services) {
            $mergedModuleServices = ArrayUtils::merge($mergedModuleServices, $services);
        }

        return $mergedModuleServices;
    }

}
