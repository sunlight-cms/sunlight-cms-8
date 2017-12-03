<?php

namespace Sunlight\Plugin;

use Sunlight\Core;

class LanguagePlugin extends Plugin
{
    protected static $typeDefinition = array(
        'type' => 'language',
        'dir' => 'plugins/languages',
        'class' => __CLASS__,
        'default_base_namespace' => 'SunlightLanguage',
        'options' => array(),
    );

    public function canBeDisabled()
    {
        return !$this->isFallback() && parent::canBeDisabled();
    }
    
    public function canBeRemoved()
    {
        return !$this->isFallback() && parent::canBeRemoved();
    }

    /**
     * See if this is the fallback language
     *
     * @return bool
     */
    public function isFallback()
    {
        return $this->id === Core::$fallbackLang;
    }

    /**
     * Get localization entries
     *
     * @param bool|null $admin load administration dictionary as well 1/0 (null = auto)
     * @return array|bool false on failure
     */
    public function getLocalizationEntries($admin = null)
    {
        if ($admin === null) {
            $admin = _env === Core::ENV_ADMIN;
        }

        // base dictionary
        $fileName = sprintf('%s/dictionary.php', $this->dir);
        if (is_file($fileName)) {
            $data = (array) include $fileName;

            // admin dictionary
            if ($admin) {
                $adminFileName = sprintf('%s/admin_dictionary.php', $this->dir);
                if (is_file($adminFileName)) {
                    $data += (array) include $adminFileName;
                } else {
                    // use the fallback language plugin's admin dictionary
                    if ($this->manager->has($this->type, Core::$fallbackLang)) {
                        $adminFileName = sprintf(
                            '%s/admin_dictionary.php',
                            $this->manager->getLanguage(Core::$fallbackLang)->getDirectory()
                        );
                        if (is_file($adminFileName)) {
                            $data += (array) include $adminFileName;
                        }
                    }
                }
            }

            return $data;
        } else {
            return false;
        }
    }
}
