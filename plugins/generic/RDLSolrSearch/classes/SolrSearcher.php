<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/SolrSearcher.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SolrSearcher
 *
 * @brief Runs OMP searches against the Solr "/search" handler.
 *
 * Takes the arguments of the SubmissionSearch::retrieveResults hook and
 * returns one page of submission ids, best match first, plus the total.
 *
 * Queries use edismax with all words required (q.op=AND, see solrconfig.xml)
 * and Solr's default sow=false, so every language field applies its own
 * stopwords and words may match in different fields (author + title word).
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use PKP\search\SubmissionSearch;

class SolrSearcher
{
    /** Longest query passed to Solr, in characters */
    public const MAX_QUERY_LENGTH = 500;

    /** Field used for full text (phase 5) */
    public const FULL_TEXT_FIELD = 'fulltext';

    /**
     * Searched fields and their boosts. Localized fields are expanded per
     * locale suffix (title -> title_da, title_en, title_txt, ...).
     */
    private const LOCALIZED_FIELDS = [
        'title' => 8,
        'subtitle' => 4,
        'keywords' => 4,
        'subjects' => 3,
        'disciplines' => 3,
        'chapter_title' => 3,
        'series_title' => 2,
        'abstract' => 2,
        'chapter_subtitle' => 2,
        'chapter_abstract' => 1,
        'coverage' => 1,
        'type' => 1,
        'affiliation' => 0.5,
    ];

    private const PLAIN_FIELDS = [
        'authors' => 6,
        'chapter_authors' => 3,
        'isbn' => 10,
        'doi' => 10,
    ];

    /** Full-text boost; full text matches easily, so it counts least. */
    private const FULL_TEXT_BOOST = 0.5;

    /** Fields searched for OMP's search types (SubmissionSearch::SUBMISSION_SEARCH_*). */
    private const TYPE_FIELDS = [
        SubmissionSearch::SUBMISSION_SEARCH_AUTHOR => ['authors', 'chapter_authors'],
        SubmissionSearch::SUBMISSION_SEARCH_TITLE => ['title', 'subtitle', 'chapter_title', 'chapter_subtitle'],
        SubmissionSearch::SUBMISSION_SEARCH_ABSTRACT => ['abstract', 'chapter_abstract'],
        SubmissionSearch::SUBMISSION_SEARCH_DISCIPLINE => ['disciplines'],
        SubmissionSearch::SUBMISSION_SEARCH_SUBJECT => ['subjects'],
        SubmissionSearch::SUBMISSION_SEARCH_KEYWORD => ['keywords'],
        SubmissionSearch::SUBMISSION_SEARCH_TYPE => ['type'],
        SubmissionSearch::SUBMISSION_SEARCH_COVERAGE => ['coverage'],
        SubmissionSearch::SUBMISSION_SEARCH_GALLEY_FILE => [self::FULL_TEXT_FIELD],
        SubmissionSearch::SUBMISSION_SEARCH_INDEX_TERMS => ['disciplines', 'subjects', 'keywords', 'type', 'coverage'],
    ];

    /** OMP's result orderings mapped to Solr sort fields. Others fall back to relevance. */
    private const SORT_FIELDS = [
        'publicationDate' => 'date_published',
        'title' => 'title_sort',
        'authors' => 'authors_sort',
        'pressTitle' => 'press_id',
    ];

    private SolrClient $solr;

    public function __construct(SolrClient $solr)
    {
        $this->solr = $solr;
    }

    /**
     * @param array<string|int, ?string> $keywords Query per search type; '' (or null) searches all fields
     * @param string[] $localeSuffixes Locale suffixes to search (see LocaleFields)
     * @param int[] $exclude Submission ids to leave out
     *
     * @return array{ids: int[], total: int}
     *
     * @throws SolrException
     */
    public function search(
        array $keywords,
        ?int $pressId,
        array $localeSuffixes,
        ?string $publishedFrom = null,
        ?string $publishedTo = null,
        string $orderBy = 'score',
        string $orderDir = 'desc',
        array $exclude = [],
        int $page = 1,
        int $itemsPerPage = 20
    ): array {
        $params = $this->buildParams($keywords, $pressId, $localeSuffixes, $publishedFrom, $publishedTo, $orderBy, $orderDir, $exclude, $page, $itemsPerPage);
        if ($params === null) {
            return ['ids' => [], 'total' => 0];
        }
        $response = $this->solr->coreRequest('POST', 'search', [], ['params' => $params]);
        $ids = [];
        foreach ($response['response']['docs'] ?? [] as $doc) {
            $ids[] = (int) $doc['submission_id'];
        }
        return ['ids' => $ids, 'total' => (int) ($response['response']['numFound'] ?? 0)];
    }

    /**
     * Build the Solr request parameters, or null if there is nothing to search for.
     *
     * The first non-empty query (preferring "all fields") is the scored main
     * query; queries for other search types must also match (filters).
     */
    public function buildParams(
        array $keywords,
        ?int $pressId,
        array $localeSuffixes,
        ?string $publishedFrom = null,
        ?string $publishedTo = null,
        string $orderBy = 'score',
        string $orderDir = 'desc',
        array $exclude = [],
        int $page = 1,
        int $itemsPerPage = 20
    ): ?array {
        $suffixes = array_values(array_unique(array_merge(
            array_map(fn ($suffix) => LocaleFields::suffix((string) $suffix), $localeSuffixes),
            [LocaleFields::FALLBACK_SUFFIX]
        )));

        $queries = [];
        foreach ($keywords as $type => $query) {
            $query = $this->normalizeQuery((string) $query);
            if ($query !== '') {
                $queries[] = [$this->isAllFields($type) ? null : (int) $type, $query];
            }
        }
        if (!$queries) {
            return null;
        }
        // "All fields" first, so it becomes the scored query.
        usort($queries, fn ($a, $b) => ($a[0] === null ? 0 : 1) <=> ($b[0] === null ? 0 : 1));

        [$mainType, $mainQuery] = array_shift($queries);
        $page = max(1, $page);
        $itemsPerPage = max(1, min(100, $itemsPerPage));
        $params = [
            'q' => $mainQuery,
            'qf' => $this->queryFields($mainType, $suffixes),
            'pf' => $this->phraseFields($suffixes),
            'fl' => 'submission_id',
            'start' => ($page - 1) * $itemsPerPage,
            'rows' => $itemsPerPage,
            'sort' => $this->sort($orderBy, $orderDir),
        ];

        $filters = [];
        foreach ($queries as $i => [$type, $query]) {
            // Extra search types: edismax filters over their own fields.
            $params['fq' . $i] = $query;
            $filters[] = '{!edismax q.op=AND uf=-* qf=\'' . $this->queryFields($type, $suffixes) . '\' v=$fq' . $i . '}';
        }
        if ($pressId) {
            $filters[] = 'press_id:' . (int) $pressId;
        }
        $dateFilter = $this->dateFilter($publishedFrom, $publishedTo);
        if ($dateFilter !== null) {
            $filters[] = $dateFilter;
        }
        $exclude = array_values(array_filter(array_map('intval', $exclude)));
        if ($exclude) {
            $filters[] = '-submission_id:(' . implode(' OR ', $exclude) . ')';
        }
        if ($filters) {
            $params['fq'] = $filters;
        }
        return $params;
    }

    private function isAllFields(int|string|null $type): bool
    {
        return $type === null || $type === '' || !isset(self::TYPE_FIELDS[(int) $type]);
    }

    /**
     * Trim, collapse whitespace and cap the length. Query syntax is left to
     * edismax, which tolerates broken syntax and (with uf=-*) ignores "field:".
     */
    private function normalizeQuery(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        return mb_substr($query, 0, self::MAX_QUERY_LENGTH);
    }

    /**
     * @param string[] $suffixes
     */
    public function queryFields(?int $type, array $suffixes): string
    {
        $fields = [];
        $wanted = $type === null ? null : self::TYPE_FIELDS[$type];
        foreach (self::LOCALIZED_FIELDS as $name => $boost) {
            if ($wanted === null || in_array($name, $wanted, true)) {
                foreach ($suffixes as $suffix) {
                    $fields[] = $name . '_' . $suffix . '^' . $boost;
                }
            }
        }
        foreach (self::PLAIN_FIELDS as $name => $boost) {
            if ($wanted === null || in_array($name, $wanted, true)) {
                $fields[] = $name . '^' . $boost;
            }
        }
        if ($wanted === null || in_array(self::FULL_TEXT_FIELD, $wanted, true)) {
            foreach ($suffixes as $suffix) {
                $fields[] = self::FULL_TEXT_FIELD . '_ft_' . $suffix . '^' . self::FULL_TEXT_BOOST;
            }
        }
        return implode(' ', $fields);
    }

    /**
     * Boost documents whose title contains the query words as a phrase.
     */
    private function phraseFields(array $suffixes): string
    {
        return implode(' ', array_map(fn ($suffix) => 'title_' . $suffix . '^10', $suffixes));
    }

    private function sort(string $orderBy, string $orderDir): string
    {
        $dir = strtolower($orderDir) === 'asc' ? 'asc' : 'desc';
        if (isset(self::SORT_FIELDS[$orderBy])) {
            return self::SORT_FIELDS[$orderBy] . ' ' . $dir . ',score desc,submission_id desc';
        }
        // Relevance (also used for popularity, which Solr does not know).
        return 'score desc,date_published desc,submission_id desc';
    }

    private function dateFilter(?string $from, ?string $to): ?string
    {
        $from = $this->toSolrDate($from);
        $to = $this->toSolrDate($to);
        if ($from === null && $to === null) {
            return null;
        }
        return 'date_published:[' . ($from ?? '*') . ' TO ' . ($to ?? '*') . ']';
    }

    private function toSolrDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $timestamp = strtotime($date . (strlen(trim($date)) === 10 ? ' 00:00:00 UTC' : ''));
        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
