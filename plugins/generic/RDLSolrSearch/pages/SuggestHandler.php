<?php

/**
 * @file plugins/generic/RDLSolrSearch/pages/SuggestHandler.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SuggestHandler
 *
 * @brief Type-ahead for the search box: index.php/<press|index>/rdlsolrsuggest?q=...
 *
 * Returns published book titles (with authors and a link to the book) that
 * match the words typed so far. Within a press only that press's books are
 * suggested; on the site level all presses' books.
 */

namespace APP\plugins\generic\RDLSolrSearch\pages;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\RDLSolrSearch\RDLSolrSearchPlugin;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use Throwable;

class SuggestHandler extends Handler
{
    public function __construct(public RDLSolrSearchPlugin $plugin)
    {
        parent::__construct();
    }

    /**
     * @return JSONMessage content: {suggestions: [{title, authors, url}]}
     */
    public function index($args, $request)
    {
        header('Cache-Control: private, max-age=60');
        $context = $request->getContext();
        try {
            $found = $this->plugin->getSearcher()->suggest(
                (string) $request->getUserVar('q'),
                $context ? (int) $context->getId() : null,
                (string) \PKP\facades\Locale::getLocale()
            );
        } catch (Throwable $e) {
            // Suggestions are optional; never break the page.
            error_log('RDLSolrSearch: suggestions failed: ' . $e->getMessage());
            return new JSONMessage(true, ['suggestions' => []]);
        }

        $dispatcher = $request->getDispatcher();
        $pressPaths = [];
        $suggestions = [];
        foreach ($found as $suggestion) {
            $pressId = $suggestion['pressId'];
            if (!array_key_exists($pressId, $pressPaths)) {
                $press = Application::getContextDAO()->getById($pressId);
                $pressPaths[$pressId] = $press && $press->getEnabled() ? $press->getPath() : null;
            }
            if ($pressPaths[$pressId] === null) {
                continue;
            }
            $suggestions[] = [
                'title' => $suggestion['title'],
                'authors' => $suggestion['authors'],
                'url' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $pressPaths[$pressId], 'catalog', 'book', [$suggestion['submissionId']]),
            ];
        }
        return new JSONMessage(true, ['suggestions' => $suggestions]);
    }
}
