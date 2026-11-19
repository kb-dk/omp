<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/SolrIndexer.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SolrIndexer
 *
 * @brief Keeps the Solr core in sync with OMP: adds published monographs,
 *  removes everything else, and rebuilds the whole index on request.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use PKP\context\Context;

class SolrIndexer
{
    /** Number of documents sent to Solr per request during a rebuild */
    private const BATCH_SIZE = 50;

    /** A batch is also sent once its documents hold this much text (bytes) */
    private const BATCH_BYTES = 8000000;

    private SolrClient $solr;
    private MonographDocumentBuilder $builder;

    public function __construct(SolrClient $solr, MonographDocumentBuilder $builder)
    {
        $this->solr = $solr;
        $this->builder = $builder;
    }

    /**
     * Add, replace or remove one monograph, depending on whether it is published.
     *
     * @throws SolrException
     */
    public function indexSubmission(Submission $submission): void
    {
        $doc = $this->builder->build($submission);
        if ($doc === null) {
            $this->solr->deleteByIds([(string) $submission->getId()]);
        } else {
            $this->solr->addDocuments([$doc]);
        }
    }

    /**
     * @throws SolrException
     */
    public function deleteSubmission(int $submissionId): void
    {
        $this->solr->deleteByIds([(string) $submissionId]);
    }

    /**
     * Index all published monographs (of one press, or of all presses) and
     * remove documents that should no longer be in the index.
     *
     * Runs synchronously so it does not depend on the job queue.
     *
     * @param ?callable $log Receives progress messages
     *
     * @return array{indexed: int, withFullText: int, removed: int, failed: int}
     *
     * @throws SolrException
     */
    public function rebuild(?Context $press = null, ?callable $log = null): array
    {
        $log ??= fn (string $message) => null;
        $stats = ['indexed' => 0, 'withFullText' => 0, 'removed' => 0, 'failed' => 0];

        $presses = $press
            ? [$press]
            : iterator_to_array(Application::getContextDAO()->getAll()->toIterator(), false);

        $indexedIds = [];
        foreach ($presses as $currentPress) {
            $log('Indexing press "' . $currentPress->getLocalizedName() . '" (id ' . $currentPress->getId() . ')');
            $submissions = Repo::submission()
                ->getCollector()
                ->filterByContextIds([$currentPress->getId()])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getMany();

            $batch = [];
            $batchBytes = 0;
            foreach ($submissions as $submission) {
                try {
                    $doc = $this->builder->build($submission);
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $log('  Skipped submission ' . $submission->getId() . ': ' . $e->getMessage());
                    error_log('RDLSolrSearch: could not build document for submission ' . $submission->getId() . ': ' . $e);
                    continue;
                }
                if ($doc === null) {
                    continue;
                }
                $batch[] = $doc;
                $batchBytes += $this->textBytes($doc);
                if (preg_grep('/_ft_/', array_keys($doc))) {
                    $stats['withFullText']++;
                }
                $indexedIds[$doc['id']] = true;
                if (count($batch) >= self::BATCH_SIZE || $batchBytes >= self::BATCH_BYTES) {
                    $this->solr->addDocuments($batch, 60000);
                    $stats['indexed'] += count($batch);
                    $batch = [];
                    $batchBytes = 0;
                }
            }
            $this->solr->addDocuments($batch, 60000);
            $stats['indexed'] += count($batch);
        }

        // Remove documents of unpublished/deleted monographs (and of deleted presses on a full rebuild).
        $filter = $press ? 'press_id:' . (int) $press->getId() : null;
        $stale = array_values(array_filter(
            $this->solr->getAllIds($filter),
            fn (string $id) => !isset($indexedIds[$id])
        ));
        foreach (array_chunk($stale, 500) as $chunk) {
            $this->solr->deleteByIds($chunk, 60000);
        }
        $stats['removed'] = count($stale);

        $this->solr->commit();
        $log('Solr index: ' . $stats['indexed'] . ' indexed (' . $stats['withFullText'] . ' with full text), ' . $stats['removed'] . ' removed, ' . $stats['failed'] . ' failed');
        return $stats;
    }

    /**
     * Approximate size of a document's text.
     */
    private function textBytes(array $doc): int
    {
        $bytes = 0;
        array_walk_recursive($doc, function ($value) use (&$bytes) {
            $bytes += is_string($value) ? strlen($value) : 8;
        });
        return $bytes;
    }
}
