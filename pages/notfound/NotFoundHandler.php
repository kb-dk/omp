<?php

/**
 * @file pages/notfound/NotFoundHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NotFoundHandler
 *
 * @ingroup pages_notfound
 *
 * @brief KB: the general "not found" page. Every frontend 404 is redirected here
 * by APP\core\Dispatcher::handle404().
 */

namespace APP\pages\notfound;

use APP\core\Dispatcher;
use APP\handler\Handler;
use APP\template\TemplateManager;

class NotFoundHandler extends Handler
{
    /** Provided by the theme, e.g. plugins/themes/RdlOMPTheme/templates/frontend/pages/404.tpl */
    public const TEMPLATE = 'frontend/pages/404.tpl';

    /**
     * Display the "not found" page with status 404.
     *
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function index($args, $request)
    {
        // Only one general page: /au/notfound etc. go to the site-level one.
        if ($request->getContext()) {
            $request->redirectUrl($request->getIndexUrl() . '/' . Dispatcher::NOT_FOUND_PATH);
        }

        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        http_response_code(404);

        if ($templateMgr->templateExists(self::TEMPLATE)) {
            $templateMgr->display(self::TEMPLATE);
            return;
        }

        // The active theme has no 404 template: use the core error page.
        $templateMgr->assign([
            'pageTitle' => 'common.error',
            'errorMsg' => 'api.404.resourceNotFound',
            'backLink' => $request->url(null, 'index'),
            'backLinkLabel' => 'navigation.home',
        ]);
        $templateMgr->display('frontend/pages/error.tpl');
    }
}
