<?php

namespace Sunlight\Plugin;

use Composer\Semver\Semver;
use Sunlight\Core;
use Sunlight\Option\OptionSet;
use Sunlight\Util\Json;

class PluginLoader
{
    /** Plugin name pattern */
    const PLUGIN_NAME_PATTERN = '[a-zA-Z][a-zA-Z0-9_.\-]*';
    /** Name of the plugin definition file */
    const PLUGIN_FILE = 'plugin.json';
    /** Name of the plugin deactivating file */
    const PLUGIN_DEACTIVATING_FILE = 'DISABLED';

    /** @var array */
    private $types;
    private $pluginDirectoryPattern;

    /**
     * @param array $types
     */
    public function __construct(array $types)
    {
        $this->types = $types;
        $this->pluginDirectoryPattern = '/^' . static::PLUGIN_NAME_PATTERN . '$/';
    }

    /**
     * Load plugin data from the filesystem
     *
     * @param bool $checkDevMode
     * @param bool $resolveInstallationStatus
     * @return array plugins, plugin files
     */
    public function load($checkDevMode = true, $resolveInstallationStatus = true)
    {
        $plugins = array();
        $pluginFiles = array();

        $commonOptionSet = new OptionSet(Plugin::$commonOptions);
        $commonOptionSet->setIgnoreExtraIndexes(true);

        // load
        foreach ($this->types as $typeName => $type) {
            $plugins[$typeName] = array();

            $dir = _root . $type['dir'];

            $typeOptionSet = new OptionSet($type['options']);
            $typeOptionSet->addKnownIndexes($commonOptionSet->getIndexes());

            // scan directory
            foreach (scandir($dir) as $item) {
                // validate item
                if (
                    preg_match($this->pluginDirectoryPattern, $item) // skips dots and invalid names
                    && is_dir($pluginDir = $dir . '/' . $item)
                    && is_file($pluginFile = $pluginDir . '/' . static::PLUGIN_FILE)
                ) {
                    $pluginFiles[] = $pluginFile;

                    $plugin = $this->createPluginData($item, $type);
                    $context = $this->createPluginOptionContext($plugin, $type);

                    // check state
                    $isDisabled = is_file($plugin['dir'] . '/' . static::PLUGIN_DEACTIVATING_FILE);

                    // load options
                    try {
                        $options = Json::decode(file_get_contents($pluginFile));
                    } catch (\RuntimeException $e) {
                        $options = null;
                        $plugin['errors'][] = sprintf('could not parse %s - %s', static::PLUGIN_FILE, $e->getMessage());
                    }

                    // process options
                    if ($options !== null) {
                        // common options
                        $commonOptionSet->process($options, $context, $plugin['configuration_errors']);

                        // type-specific options
                        if (empty($plugin['configuration_errors'])) {
                            $typeOptionSet->process($options, $context, $plugin['configuration_errors']);
                        }

                        $this->validateOptions($options, $plugin['configuration_errors'], $checkDevMode, $plugin['errors']);
                    }

                    // handle result
                    if (empty($plugin['errors']) && empty($plugin['configuration_errors'])) {
                        // ok
                        $plugin['status'] = Plugin::STATUS_OK;
                        $plugin['options'] = $options;
                    } else {
                        // there are errors
                        $plugin['status'] = Plugin::STATUS_HAS_ERRORS;
                        if ($options !== null && empty($plugin['configuration_errors'])) {
                            $plugin['options'] = $options;
                        } else {
                            $options = array(
                                'id' => $item,
                                'name' => $item,
                                'version' => '0.0.0',
                                'api' => '0.0.0',
                            );
                            $commonOptionSet->process($options, $context);
                            $plugin['options'] = $options;
                        }
                    }

                    // resolve plugin class
                    $plugin['options']['class'] = $this->resolvePluginClass($plugin, $type);

                    // override status if the plugin is disabled
                    if ($isDisabled) {
                        $plugin['status'] = Plugin::STATUS_DISABLED;
                    }

                    // add plugin
                    $plugins[$typeName][$item] = $plugin;
                }
            }
        }

        // resolve dependencies
        foreach ($this->types as $typeName => $type) {
            $plugins[$typeName] = $this->resolveDependencies($plugins[$typeName]);
        }

        // resolve installation status
        if ($resolveInstallationStatus) {
            foreach ($this->types as $typeName => $type) {
                $this->resolveInstallationStatus($plugins[$typeName]);
            }
        }

        return array($plugins, $pluginFiles);
    }

    /**
     * Validate options
     *
     * @param array $options
     * @param array $configurationErrors
     * @param bool $checkDevMode
     * @param array &$errors
     */
    private function validateOptions(array $options, array $configurationErrors, $checkDevMode, array &$errors)
    {
        // api version
        if (!isset($configurationErrors['api']) && !$this->checkVersion($options['api'], Core::VERSION)) {
            $errors[] = sprintf('API version "%s" is not compatible with system version "%s"', $options['api'], Core::VERSION);
        }

        // PHP version
        if (!isset($configurationErrors['php']) && $options['php'] !== null && !version_compare($options['php'], PHP_VERSION, '<=')) {
            $errors[] = sprintf('PHP version "%s" or newer is required', $options['php']);
        }

        // extensions
        if (!isset($configurationErrors['extensions'])) {
            foreach ($options['extensions'] as $extension) {
                if (!extension_loaded($extension)) {
                    $errors[] = sprintf('PHP extension "%s" is required', $extension);
                }
            }
        }

        // dev mode
        if ($checkDevMode && !isset($configurationErrors['dev']) && $options['dev'] !== null && $options['dev'] !== _dev) {
            $errors[] = $options['dev']
                ? 'development mode is required'
                : 'production mode is required';
        }
    }

    /**
     * @param array $plugin
     * @param array $type
     * @return string
     */
    private function resolvePluginClass(array $plugin, array $type)
    {
        $specifiedClass = $plugin['options']['class'];

        if ($specifiedClass === null) {
            // no class specified - use default class of the given type
            return $type['class'];
        }

        if (strpos($specifiedClass, '\\') === false) {
            // plain (unnamespaced) class name specified - prefix by plugin namespace
            return $plugin['options']['namespace'] . '\\' . $specifiedClass;
        }

        // fully-qualified class name
        return $specifiedClass;
    }

    /**
     * Check version
     *
     * @param string $requiredVersion the required version pattern
     * @param string $actualVersion   the version to match the pattern against
     * @return bool
     */
    public function checkVersion($requiredVersion, $actualVersion)
    {
        return Semver::satisfies($actualVersion, $requiredVersion);
    }

    /**
     * @param string $id
     * @param array $type
     * @return array
     */
    private function createPluginData($id, array $type)
    {
        return array(
            'id' => $id,
            'camel_id' => _camelCase($id),
            'type' => $type,
            'status' => null,
            'installed' => null,
            'dir' => realpath(_root . $type['dir'] . '/' . $id),
            'web_path' => $type['dir'] . '/' . $id,
            'errors' => array(),
            'configuration_errors' => array(),
            'options' => null,
        );
    }

    /**
     * @param array $plugin
     * @param array $type
     * @return array
     */
    private function createPluginOptionContext(array &$plugin, array $type)
    {
        return array(
            'plugin' => &$plugin,
            'type' => $type,
        );
    }

    /**
     * Resolve plugin dependencies
     *
     * @param array $plugins
     * @throws \RuntimeException if the dependencies cannot be resolved
     * @return array
     */
    private function resolveDependencies(array $plugins)
    {
        $sorted = array();
        $circularDependencyMap = $this->findCircularDependencies($plugins);

        while (!empty($plugins)) {
            $numAdded = 0;
            foreach ($plugins as $name => $plugin) {
                $canBeAdded = true;
                $errors = array();

                if (Plugin::STATUS_OK === $plugin['status']) {
                    if (isset($circularDependencyMap[$name])) {
                        // the plugin is in a circular dependency chain
                        $errors[] = sprintf('circular dependency detected: "%s"', $circularDependencyMap[$name]);
                        $canBeAdded = false;
                    } elseif (!empty($plugin['options']['requires'])) {
                        foreach ($plugin['options']['requires'] as $dependency => $requiredVersion) {
                            if (isset($sorted[$dependency])) {
                                // the dependency is already in the sorted map
                                if (!$this->checkDependency($sorted[$dependency], $requiredVersion, $errors)) {
                                    $canBeAdded = false;
                                }
                            } else {
                                // not in the sorted map yet
                                if (isset($plugins[$dependency])) {
                                    $this->checkDependency($plugins[$dependency], $requiredVersion, $errors);
                                } else {
                                    $errors[] = sprintf('missing dependency "%s"', $dependency);
                                }

                                $canBeAdded = false;
                            }
                        }
                    }
                }

                // add if all dependencies are ok
                if ($canBeAdded) {
                    $sorted[$name] = $plugin;
                } elseif (!empty($errors)) {
                    $sorted[$name] = array(
                        'status' => Plugin::STATUS_HAS_ERRORS,
                        'errors' => $errors
                    ) + $plugin;
                }

                if ($canBeAdded || !empty($errors)) {
                    unset($plugins[$name]);
                    ++$numAdded;
                }
            }

            if ($numAdded === 0) {
                // this should not happen
                throw new \RuntimeException('Could not resolve plugin dependencies');
            }
        }

        return $sorted;
    }

    /**
     * Find circular dependencies
     *
     * Returns a map:
     *
     * array(
     *      name1 => dependency_path_string1,
     *      ...
     * )
     *
     * @param array $plugins
     * @return array
     */
    private function findCircularDependencies(array $plugins)
    {
        $circularDependencyMap = array();

        $checkQueue = array();
        foreach ($plugins as $name => $plugin) {
            if (Plugin::STATUS_OK === $plugin['status']) {
                foreach (array_keys($plugin['options']['requires']) as $dependency) {
                    $checkQueue[] = array($dependency, array($name => true, $dependency => true));
                }
            }
        }

        while (!empty($checkQueue)) {
            list($name, $pathMap) = array_pop($checkQueue);

            if (isset($plugins[$name])) {
                foreach (array_keys($plugins[$name]['options']['requires']) as $dependency) {
                    if (isset($pathMap[$dependency])) {
                        $pathString = "{$name}";
                        foreach (array_keys($pathMap) as $segment) {
                            $pathString .= " -> {$segment}";
                        }

                        $circularDependencyMap[$name] = $pathString;
                    } else {
                        $checkQueue[] = array($dependency, $pathMap + array($dependency => true));
                    }
                }
            }
        }

        return $circularDependencyMap;
    }

    /**
     * Check plugin dependency version
     *
     * @param array  $plugin
     * @param string $requiredVersion
     * @param array  &$errors
     * @return bool
     */
    private function checkDependency(array $plugin, $requiredVersion, array &$errors)
    {
        if (Plugin::STATUS_OK !== $plugin['status']) {
            $errors[] = sprintf('dependency "%s" is not available', $plugin['id']);
            
            return false;
        } elseif (!$this->checkVersion($requiredVersion, $plugin['options']['version'])) {
            $errors[] = sprintf(
                'dependency "%s" (version "%s") is not compatible, version "%s" is required',
                $plugin['id'],
                $plugin['options']['version'],
                $requiredVersion
            );

            return false;
        }

        return true;
    }

    /**
     * Resolve installation status
     *
     * @param array &$plugins
     */
    private function resolveInstallationStatus(array &$plugins)
    {
        foreach ($plugins as &$plugin) {
            if (Plugin::STATUS_HAS_ERRORS !== $plugin['status'] && $plugin['options']['installer']) {
                $installer = PluginInstaller::load(
                    $plugin['dir'],
                    $plugin['options']['namespace'],
                    $plugin['camel_id']
                );

                $isInstalled = $installer->isInstalled();

                if (!$isInstalled && Plugin::STATUS_OK === $plugin['status']) {
                    $plugin['status'] = Plugin::STATUS_NEEDS_INSTALLATION;
                }

                $plugin['installed'] = $isInstalled;
            }
        }
    }
}
