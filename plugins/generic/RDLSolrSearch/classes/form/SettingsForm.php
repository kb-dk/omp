<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/form/SettingsForm.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 *
 * @brief Site-wide settings for the Solr connection. Opening the form also
 *  tests the connection with the saved settings and shows the result.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes\form;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\RDLSolrSearch\RDLSolrSearchPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class SettingsForm extends Form
{
    private const FIELDS = ['solrUrl', 'solrCore', 'solrTimeout'];

    public RDLSolrSearchPlugin $plugin;

    public function __construct(RDLSolrSearchPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new FormValidatorCustom(
            $this,
            'solrUrl',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.rdlSolrSearch.settings.solrUrl.invalid',
            fn ($url) => self::isValidSolrUrl((string) $url)
        ));
        $this->addCheck(new FormValidatorRegExp(
            $this,
            'solrCore',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.rdlSolrSearch.settings.solrCore.invalid',
            '/^[A-Za-z0-9_-]{1,64}$/'
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'solrTimeout',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.rdlSolrSearch.settings.solrTimeout.invalid',
            fn ($timeout) => ctype_digit((string) $timeout) && (int) $timeout >= 1 && (int) $timeout <= 120
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Only plain http(s) URLs without credentials, query or fragment.
     */
    public static function isValidSolrUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['user'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->setData('solrUrl', $this->plugin->getSolrUrl());
        $this->setData('solrCore', $this->plugin->getSolrCore());
        $this->setData('solrTimeout', $this->plugin->getSolrTimeout());
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(self::FIELDS);
        $this->setData('solrUrl', trim((string) $this->getData('solrUrl')));
        $this->setData('solrCore', trim((string) $this->getData('solrCore')));
        $this->setData('solrTimeout', trim((string) $this->getData('solrTimeout')));
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'connection' => $this->plugin->getSolrClient()->testConnection(),
            'connectionCore' => $this->plugin->getSolrCore(),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(Application::CONTEXT_SITE, 'solrUrl', rtrim($this->getData('solrUrl'), '/'), 'string');
        $this->plugin->updateSetting(Application::CONTEXT_SITE, 'solrCore', $this->getData('solrCore'), 'string');
        $this->plugin->updateSetting(Application::CONTEXT_SITE, 'solrTimeout', (int) $this->getData('solrTimeout'), 'int');
        $this->plugin->resetSolrClient();

        $user = Application::get()->getRequest()->getUser();
        if ($user) {
            (new NotificationManager())->createTrivialNotification(
                $user->getId(),
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('common.changesSaved')]
            );
        }

        return parent::execute(...$functionArgs);
    }
}
