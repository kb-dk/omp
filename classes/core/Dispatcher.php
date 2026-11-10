<?php

/**
 * @file classes/core/Dispatcher.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Dispatcher
 *
 * @ingroup core
 *
 * @brief KB: redirects every frontend 404 to the general "not found" page.
 *
 * Core OMP answers 404s with a plain-text "404 Not Found". This covers unknown
 * presses (/au9), unknown pages (/au/catalog2), unknown operations
 * (/au/catalog/foo) and 404s raised inside handlers. Frontend page requests are
 * redirected to /index/notfound (pages/notfound), which renders the theme's
 * frontend/pages/404.tpl with status 404. API and component (AJAX) requests keep
 * the plain 404.
 */

namespace APP\core;

use PKP\core\PKPPageRouter;
use PKP\core\PKPRequest;

class Dispatcher extends \PKP\core\Dispatcher
{
    /** Site-level path of the general "not found" page */
    public const NOT_FOUND_PATH = 'index/notfound';

    /**
     * @copydoc \PKP\core\Dispatcher::handle404()
     */
    public static function handle404()
    {
        $request = Application::get()->getRequest();
        $router = $request->getRouter();

        if ($router instanceof PKPPageRouter && !self::isNotFoundPage($request, $router)) {
            $request->redirectUrl($router->getIndexUrl($request) . '/' . self::NOT_FOUND_PATH);
        }

        parent::handle404();
    }

    /**
     * Whether this request is for the "not found" page itself (prevents a redirect loop).
     */
    protected static function isNotFoundPage(PKPRequest $request, PKPPageRouter $router): bool
    {
        return $router->getRequestedContextPath($request) === 'index'
            && $router->getRequestedPage($request) === 'notfound'
            && in_array($router->getRequestedOp($request), ['', 'index'], true);
    }
}
