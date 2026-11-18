<?php

/**
 * @file plugins/generic/RDLSolrSearch/RDLSolrSearchPlugin.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RDLSolrSearchPlugin
 *
 * @brief Replaces OMP's database search with a standalone Solr core.
 *
 * Solr runs as its own service (by default on 127.0.0.1:8983) and is never
 * installed inside the OMP directory. The plugin is site-wide because one
 * Solr core serves all presses of this installation.
 */

namespace APP\plugins\generic\RDLSolrSearch;

use APP\core\Application;
use APP\plugins\generic\RDLSolrSearch\classes\form\SettingsForm;
use APP\plugins\generic\RDLSolrSearch\classes\SolrClient;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;

class RDLSolrSearchPlugin extends GenericPlugin
{
    public const DEFAULT_SOLR_URL = 'http://127.0.0.1:8983/solr';
    public const DEFAULT_SOLR_CORE = 'omp';
    public const DEFAULT_SOLR_TIMEOUT = 10;

    private ?SolrClient $solrClient = null;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            // Indexing and search hooks are added in later phases.
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.rdlSolrSearch.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.rdlSolrSearch.description');
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * Read a site-wide setting, falling back to a default when unset.
     */
    public function getSiteSetting(string $name, mixed $default = null): mixed
    {
        $value = $this->getSetting(Application::CONTEXT_SITE, $name);
        return ($value === null || $value === '') ? $default : $value;
    }

    public function getSolrUrl(): string
    {
        return (string) $this->getSiteSetting('solrUrl', self::DEFAULT_SOLR_URL);
    }

    public function getSolrCore(): string
    {
        return (string) $this->getSiteSetting('solrCore', self::DEFAULT_SOLR_CORE);
    }

    public function getSolrTimeout(): int
    {
        return (int) $this->getSiteSetting('solrTimeout', self::DEFAULT_SOLR_TIMEOUT);
    }

    /**
     * Get a client for the configured Solr core.
     */
    public function getSolrClient(): SolrClient
    {
        if ($this->solrClient === null) {
            $this->solrClient = new SolrClient(
                $this->getSolrUrl(),
                $this->getSolrCore(),
                (float) $this->getSolrTimeout(),
                Application::get()->getHttpClient()
            );
        }
        return $this->solrClient;
    }

    /**
     * Forget the cached client, e.g. after the settings have changed.
     */
    public function resetSolrClient(): void
    {
        $this->solrClient = null;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $form = new SettingsForm($this);
            if (!$request->getUserVar('save')) {
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            }
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                return new JSONMessage(true);
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}
