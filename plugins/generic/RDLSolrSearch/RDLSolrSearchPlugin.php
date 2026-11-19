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
use APP\facades\Repo;
use APP\core\Services;
use APP\log\event\SubmissionEventLogEntry;
use APP\plugins\generic\RDLSolrSearch\classes\form\SettingsForm;
use APP\plugins\generic\RDLSolrSearch\classes\FullTextExtractor;
use APP\plugins\generic\RDLSolrSearch\classes\FullTextProvider;
use APP\plugins\generic\RDLSolrSearch\classes\MonographDocumentBuilder;
use APP\plugins\generic\RDLSolrSearch\classes\SolrClient;
use APP\plugins\generic\RDLSolrSearch\classes\SolrException;
use APP\plugins\generic\RDLSolrSearch\classes\SolrIndexer;
use APP\plugins\generic\RDLSolrSearch\classes\SolrSearcher;
use Illuminate\Support\Facades\Cache;
use PKP\context\Context;
use APP\submission\Submission;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\jobs\submissions\UpdateSubmissionSearchJob;
use PKP\log\event\EventLogEntry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class RDLSolrSearchPlugin extends GenericPlugin
{
    public const DEFAULT_SOLR_URL = 'http://127.0.0.1:8983/solr';
    public const DEFAULT_SOLR_CORE = 'omp';
    public const DEFAULT_SOLR_TIMEOUT = 10;

    /** After a failed search, use OMP's own search for this many seconds. */
    public const SEARCH_BACKOFF_SECONDS = 60;
    private const SEARCH_BACKOFF_KEY = 'rdlSolrSearch.searchUnavailable';

    /** Event log entries that change a published book's public formats or files */
    private const FORMAT_EVENTS = [
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_PUBLISH,
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_UNPUBLISH,
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_AVAILABLE,
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_UNAVAILABLE,
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_CREATE,
        SubmissionEventLogEntry::SUBMISSION_LOG_PUBLICATION_FORMAT_REMOVE,
    ];

    public const PDFTOTEXT = '/usr/bin/pdftotext';

    private ?SolrClient $solrClient = null;

    /** @var array<int, true> Submissions queued for reindexing in this request */
    private array $queuedReindex = [];

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
        // Site plugin: getEnabled() without a context reads the site-wide setting.
        if ($success && $this->getEnabled()) {
            // Indexing. OMP keeps updating its own database index as well
            // (the callbacks never abort), so its search remains a fallback.
            Hook::add('MonographSearchIndex::monographMetadataChanged', [$this, 'callbackMetadataChanged']);
            Hook::add('Publication::unpublish', [$this, 'callbackUnpublish']);
            Hook::add('Submission::delete', [$this, 'callbackSubmissionDeleted']);
            Hook::add('MonographSearchIndex::rebuildIndex', [$this, 'callbackRebuildIndex']);
            // OMP does not reindex when a published book's files or formats change.
            Hook::add('EventLog::add', [$this, 'callbackEventLogged']);

            // Searching. Falls back to OMP's own search if Solr fails.
            Hook::add('SubmissionSearch::retrieveResults', [$this, 'callbackRetrieveResults']);
        }
        return $success;
    }

    public function getIndexer(?FullTextExtractor $extractor = null): SolrIndexer
    {
        $extractor ??= $this->getFullTextExtractor();
        return new SolrIndexer($this->getSolrClient(), new MonographDocumentBuilder(new FullTextProvider($extractor)));
    }

    public function getFullTextExtractor(): FullTextExtractor
    {
        return new FullTextExtractor(Services::get('file')->fs, self::PDFTOTEXT);
    }

    /**
     * Files and publication formats are changed without a search job, but
     * OMP logs every change after saving it. Queue OMP's search job for a
     * published book whose proof files or formats changed, so its full text
     * and identifiers are updated (in the background, not in this request).
     *
     * @param array{0: EventLogEntry} $args
     */
    public function callbackEventLogged(string $hookName, array $args): bool
    {
        [$entry] = $args;
        try {
            $submissionId = $this->getChangedSubmissionId($entry);
            if (!$submissionId || isset($this->queuedReindex[$submissionId])) {
                return Hook::CONTINUE;
            }
            $this->queuedReindex[$submissionId] = true;
            $submission = Repo::submission()->get($submissionId);
            if ($submission && $submission->getData('status') === Submission::STATUS_PUBLISHED) {
                dispatch(new UpdateSubmissionSearchJob($submissionId));
            }
        } catch (Throwable $e) {
            $this->logIndexingError('queue reindexing after a file/format change', $e);
        }
        return Hook::CONTINUE;
    }

    /**
     * The submission whose public files may have changed, or null.
     */
    public function getChangedSubmissionId(EventLogEntry $entry): ?int
    {
        if ((int) $entry->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_PROOF && $entry->getData('submissionId')) {
            return (int) $entry->getData('submissionId');
        }
        if ((int) $entry->getData('assocType') === PKPApplication::ASSOC_TYPE_SUBMISSION
            && in_array((int) $entry->getData('eventType'), self::FORMAT_EVENTS, true)) {
            return (int) $entry->getData('assocId');
        }
        return null;
    }

    /**
     * OMP (re)indexes a monograph, e.g. after publishing or a metadata change.
     * Called for unpublished submissions too; the indexer removes those.
     *
     * @param array{0: Submission} $args
     */
    public function callbackMetadataChanged(string $hookName, array $args): bool
    {
        [$submission] = $args;
        try {
            $this->getIndexer()->indexSubmission($submission);
        } catch (Throwable $e) {
            $this->logIndexingError('index submission ' . $submission->getId(), $e);
        }
        return Hook::CONTINUE;
    }

    /**
     * A version was unpublished. The submission may still have an older
     * published version, so reindex rather than just delete.
     *
     * @param array{0: Publication, 1: Publication, 2: Submission} $args
     */
    public function callbackUnpublish(string $hookName, array $args): bool
    {
        $submissionId = (int) $args[2]->getId();
        try {
            $submission = Repo::submission()->get($submissionId);
            $submission
                ? $this->getIndexer()->indexSubmission($submission)
                : $this->getIndexer()->deleteSubmission($submissionId);
        } catch (Throwable $e) {
            $this->logIndexingError('update unpublished submission ' . $submissionId, $e);
        }
        return Hook::CONTINUE;
    }

    /**
     * @param array{0: Submission} $args
     */
    public function callbackSubmissionDeleted(string $hookName, array $args): bool
    {
        [$submission] = $args;
        try {
            $this->getIndexer()->deleteSubmission((int) $submission->getId());
        } catch (Throwable $e) {
            $this->logIndexingError('remove deleted submission ' . $submission->getId(), $e);
        }
        return Hook::CONTINUE;
    }

    /**
     * php tools/rebuildSearchIndex.php [-solr-only] [press path]
     *
     * Rebuilds the Solr index right away. Afterwards OMP rebuilds its own
     * database index too, unless -solr-only is given or a press is given
     * (OMP's database index cannot be rebuilt per press).
     *
     * @param array{0: bool, 1: ?Press, 2: string[]} $args
     */
    public function callbackRebuildIndex(string $hookName, array $args): bool
    {
        [$log, $press, $switches] = $args;
        $echo = fn (string $message) => $log ? print($message . "\n") : null;
        $solrOnly = in_array('-solr-only', (array) $switches, true);

        $echo('Rebuilding the Solr index (core "' . $this->getSolrCore() . '")');
        // Missing files are reported once, as a count, instead of one log line each.
        $extractor = $this->getFullTextExtractor();
        $extractor->setLogMissingFiles(false);
        try {
            $this->getIndexer($extractor)->rebuild($press, $echo);
        } catch (Throwable $e) {
            $this->logIndexingError('rebuild the index', $e);
            $echo('Solr rebuild FAILED: ' . $e->getMessage());
        }
        $missing = $extractor->getMissingFiles();
        if ($missing) {
            $message = count($missing) . ' book file(s) are missing from the files directory; no full text was indexed for them (e.g. "' . $missing[0] . '")';
            $echo('Warning: ' . $message);
            error_log('RDLSolrSearch: rebuild: ' . $message);
        }

        if ($press || $solrOnly) {
            return Hook::ABORT;
        }
        $echo('Rebuilding OMP\'s own search index');
        return Hook::CONTINUE;
    }

    /**
     * Answer an OMP search from Solr.
     *
     * Returns ABORT with the results filled in, or CONTINUE (results
     * untouched) so OMP's own database search runs, e.g. when Solr is down.
     *
     * @param array $args [&$context, &$keywords, $publishedFrom, $publishedTo, $orderBy,
     *  $orderDir, $exclude, $page, $itemsPerPage, &$totalResults, &$error, &$results]
     */
    public function callbackRetrieveResults(string $hookName, array $args): bool
    {
        [$context, $keywords, $publishedFrom, $publishedTo, $orderBy, $orderDir, $exclude, $page, $itemsPerPage] = $args;
        if ($this->isSearchBackingOff()) {
            return Hook::CONTINUE;
        }
        try {
            $found = $this->getSearcher()->search(
                (array) $keywords,
                $context ? (int) $context->getId() : null,
                $this->getSearchLocales($context),
                $publishedFrom,
                $publishedTo,
                (string) $orderBy,
                (string) $orderDir,
                (array) $exclude,
                (int) $page,
                (int) $itemsPerPage
            );
        } catch (Throwable $e) {
            error_log('RDLSolrSearch: search failed, using OMP\'s own search for ' . self::SEARCH_BACKOFF_SECONDS . ' seconds: ' . $e->getMessage()
                . ($e instanceof SolrException ? '' : "\n" . $e->getTraceAsString()));
            $this->startSearchBackoff();
            return Hook::CONTINUE;
        }
        // The array holds references to the caller's variables.
        $args[9] = $found['total'];
        $args[11] = $found['ids'];
        return Hook::ABORT;
    }

    public function getSearcher(): SolrSearcher
    {
        return new SolrSearcher($this->getSolrClient());
    }

    /**
     * Locales whose fields are searched: those of the press, or of all
     * enabled presses for a site-wide search.
     *
     * @return string[]
     */
    public function getSearchLocales(?Context $context): array
    {
        $contexts = $context
            ? [$context]
            : iterator_to_array(Application::getContextDAO()->getAll(true)->toIterator(), false);
        $locales = [];
        foreach ($contexts as $currentContext) {
            $locales = array_merge(
                $locales,
                [(string) $currentContext->getPrimaryLocale()],
                (array) $currentContext->getSupportedLocales(),
                (array) $currentContext->getSupportedFormLocales(),
                (array) $currentContext->getSupportedSubmissionLocales()
            );
        }
        return array_values(array_unique(array_filter($locales, 'strlen')));
    }

    private function isSearchBackingOff(): bool
    {
        try {
            return (bool) Cache::get(self::SEARCH_BACKOFF_KEY, false);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function startSearchBackoff(): void
    {
        try {
            Cache::put(self::SEARCH_BACKOFF_KEY, true, self::SEARCH_BACKOFF_SECONDS);
        } catch (Throwable $e) {
            // No cache: every search tries Solr again.
        }
    }

    /**
     * Indexing errors must never break publishing; they are logged and the
     * index can be repaired with a rebuild.
     */
    private function logIndexingError(string $action, Throwable $e): void
    {
        error_log('RDLSolrSearch: could not ' . $action . ': ' . $e->getMessage()
            . ($e instanceof SolrException ? '' : "\n" . $e->getTraceAsString()));
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
