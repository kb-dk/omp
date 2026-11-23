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

    /** Field used for full text: fulltext_ft_<locale> */
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

    /** Facets: request parameter => Solr field */
    public const FACET_FIELDS = [
        'press' => 'press_id',
        'series' => 'series_id',
        'year' => 'year',
    ];

    /** Snippet sources, in order of preference. Metadata first, then full text. */
    private const SNIPPET_METADATA_FIELDS = ['abstract', 'chapter_abstract', 'chapter_title'];

    /** Author fields highlighted, so a result can show which author matched. */
    private const AUTHOR_FIELDS = ['authors', 'chapter_authors'];

    /** Title fields highlighted, so the theme can mark the words in the shown title. */
    private const TITLE_FIELDS = ['title', 'subtitle'];

    /** Most matching author names shown in an author snippet */
    private const MAX_AUTHOR_NAMES = 3;

    /** Longest snippet shown, in characters */
    public const MAX_SNIPPET_CHARS = 300;

    /** Marks around highlighted words in Solr's answer; replaced by <mark> after escaping. */
    private const MARK_START = "\u{2}";
    private const MARK_END = "\u{3}";

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
        int $itemsPerPage = 20,
        array $options = []
    ): array {
        $params = $this->buildParams($keywords, $pressId, $localeSuffixes, $publishedFrom, $publishedTo, $orderBy, $orderDir, $exclude, $page, $itemsPerPage, $options);
        if ($params === null) {
            return ['ids' => [], 'total' => 0, 'highlights' => [], 'authorHighlights' => [], 'titleHighlights' => [], 'facets' => []];
        }
        $response = $this->solr->coreRequest('POST', 'search', [], ['params' => $params]);
        $ids = [];
        foreach ($response['response']['docs'] ?? [] as $doc) {
            $ids[] = (int) $doc['submission_id'];
        }
        return [
            'ids' => $ids,
            'total' => (int) ($response['response']['numFound'] ?? 0),
            'highlights' => $this->parseHighlights($response['highlighting'] ?? []),
            'authorHighlights' => $this->parseAuthorHighlights($response['highlighting'] ?? []),
            'titleHighlights' => $this->parseTitleHighlights($response['highlighting'] ?? []),
            'facets' => $this->parseFacets($response['facet_counts']['facet_fields'] ?? []),
        ];
    }

    /**
     * Build the Solr request parameters, or null if there is nothing to search for.
     *
     * The first non-empty query (preferring "all fields") is the scored main
     * query; queries for other search types must also match (filters).
     *
     * @param array{filters?: array<string, int>, highlight?: bool, facets?: bool} $options
     *  filters: facet filters by FACET_FIELDS key, e.g. ['year' => 2020];
     *  highlight: return snippets; facets: return facet counts
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
        int $itemsPerPage = 20,
        array $options = []
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
        foreach ($options['filters'] ?? [] as $name => $value) {
            if (isset(self::FACET_FIELDS[$name]) && (int) $value > 0) {
                // Tagged, so the facet still lists the other values of this field.
                $filters[] = '{!tag=f' . $name . '}' . self::FACET_FIELDS[$name] . ':' . (int) $value;
            }
        }
        if ($filters) {
            $params['fq'] = $filters;
        }
        if (!empty($options['highlight'])) {
            $params += $this->highlightParams();
        }
        if (!empty($options['facets'])) {
            $params += $this->facetParams();
        }
        return $params;
    }

    /**
     * Unified highlighter: one snippet per field, words marked with control
     * characters (escaped and turned into <mark> in parseHighlights()).
     */
    private function highlightParams(): array
    {
        $fields = array_map(fn ($name) => $name . '_*', self::SNIPPET_METADATA_FIELDS);
        $fields[] = self::FULL_TEXT_FIELD . '_ft_*';
        $fields = array_merge($fields, self::AUTHOR_FIELDS);
        foreach (self::TITLE_FIELDS as $name) {
            $fields[] = $name . '_*';
        }
        $params = [
            'hl' => 'true',
            'hl.method' => 'unified',
            'hl.fl' => implode(',', $fields),
            'hl.snippets' => 1,
            'hl.fragsize' => 200,
            // Break at words: book text often has long runs without full stops.
            'hl.bs.type' => 'WORD',
            'hl.requireFieldMatch' => 'false',
            'hl.defaultSummary' => 'false',
            // Look through the whole book, not only the first 51 200 characters
            // (Solr's default). Offsets are in the index, so this stays fast.
            'hl.maxAnalyzedChars' => FullTextProvider::MAX_BOOK_CHARS,
            'hl.tag.pre' => self::MARK_START,
            'hl.tag.post' => self::MARK_END,
        ];
        foreach (self::AUTHOR_FIELDS as $field) {
            // One snippet per matching name (each value of the field is whole).
            $params['f.' . $field . '.hl.bs.type'] = 'WHOLE';
            $params['f.' . $field . '.hl.snippets'] = 20;
        }
        return $params;
    }

    private function facetParams(): array
    {
        $params = [
            'facet' => 'true',
            'facet.mincount' => 1,
            'facet.limit' => 30,
            'facet.field' => [],
            'f.year.facet.sort' => 'index',
            'f.year.facet.limit' => 200,
        ];
        foreach (self::FACET_FIELDS as $name => $field) {
            $params['facet.field'][] = '{!ex=f' . $name . '}' . $field;
        }
        return $params;
    }

    /**
     * @return array<int, array<int, array{type: string, html: string}>> Snippets by submission id:
     *  at most one from metadata and one from the full text. "html" is escaped
     *  text in which only <mark> elements are markup.
     */
    public function parseHighlights(array $highlighting): array
    {
        $result = [];
        foreach ($highlighting as $id => $fields) {
            $metadata = $fullText = null;
            foreach (self::SNIPPET_METADATA_FIELDS as $name) {
                foreach ($fields as $field => $snippets) {
                    if ($metadata === null && preg_match('/^' . $name . '_(?!ft_)[A-Za-z_]+$/', $field) && !empty($snippets[0])) {
                        $metadata = $snippets[0];
                    }
                }
            }
            foreach ($fields as $field => $snippets) {
                if ($fullText === null && str_starts_with($field, self::FULL_TEXT_FIELD . '_ft_') && !empty($snippets[0])) {
                    $fullText = $snippets[0];
                }
            }
            $list = [];
            if ($metadata !== null) {
                $list[] = ['type' => 'metadata', 'html' => $this->snippetHtml($metadata)];
            }
            if ($fullText !== null) {
                $list[] = ['type' => 'fulltext', 'html' => $this->snippetHtml($fullText)];
            }
            if ($list) {
                $result[(int) $id] = $list;
            }
        }
        return $result;
    }

    /**
     * Matches in author names. The theme marks the words in the author line
     * it already shows, and falls back to "html" when no shown name matched
     * (e.g. a chapter author, or an author hidden behind "et al.").
     *
     * @return array<int, array{pattern: string, type: string, html: string}> By submission id:
     *  pattern: regex (with one group) for the matched words in HTML-escaped text;
     *  type: authors|chapterAuthors, the field "html" comes from;
     *  html: matching names, escaped, with <mark> around the matched words
     */
    public function parseAuthorHighlights(array $highlighting): array
    {
        $result = [];
        foreach ($highlighting as $id => $fields) {
            $terms = [];
            $names = [];
            foreach (self::AUTHOR_FIELDS as $field) {
                foreach ((array) ($fields[$field] ?? []) as $name) {
                    if ($this->addMarkedTerms($terms, (string) $name)) {
                        $names[$field][] = $name;
                    }
                }
            }
            if (!$terms) {
                continue;
            }
            $field = isset($names['authors']) ? 'authors' : 'chapter_authors';
            $html = array_map([$this, 'snippetHtml'], array_slice(array_unique($names[$field]), 0, self::MAX_AUTHOR_NAMES));
            $result[(int) $id] = [
                'pattern' => $this->markPattern(array_values($terms)),
                'type' => $field === 'authors' ? 'authors' : 'chapterAuthors',
                'html' => implode('; ', $html),
            ];
        }
        return $result;
    }

    /**
     * Matched words in the book's title and subtitle, for marking the title
     * the theme shows (see markPattern()).
     *
     * @return array<int, string> Regex by submission id
     */
    public function parseTitleHighlights(array $highlighting): array
    {
        $result = [];
        foreach ($highlighting as $id => $fields) {
            $terms = [];
            foreach ($fields as $field => $snippets) {
                foreach (self::TITLE_FIELDS as $name) {
                    if (preg_match('/^' . $name . '_(?!ft_)[A-Za-z_]+$/', (string) $field)) {
                        foreach ((array) $snippets as $snippet) {
                            $this->addMarkedTerms($terms, (string) $snippet);
                        }
                    }
                }
            }
            if ($terms) {
                $result[(int) $id] = $this->markPattern(array_values($terms));
            }
        }
        return $result;
    }

    /**
     * Add the words between highlight marks to $terms (keyed case-insensitively).
     *
     * @return bool Whether the text had any marked word
     */
    private function addMarkedTerms(array &$terms, string $text): bool
    {
        if (!preg_match_all('/' . self::MARK_START . '([^' . self::MARK_START . self::MARK_END . ']+)' . self::MARK_END . '/u', $text, $matches)) {
            return false;
        }
        foreach ($matches[1] as $term) {
            $terms[mb_strtolower($term)] = $term;
        }
        return true;
    }

    /**
     * Case-insensitive regex for whole words in HTML, e.g. for Smarty's
     * regex_replace: {$html|regex_replace:$pattern:'<mark>$1</mark>'}.
     * Words are HTML-escaped the same way as {$text|escape}, and tags and
     * entities (<i>, &amp;) are skipped, so the result stays well formed.
     *
     * @param string[] $terms
     */
    public function markPattern(array $terms): string
    {
        $terms = array_map(fn ($term) => htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $terms);
        // Longest first, so "Hansen" wins over "Han".
        usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = implode('|', array_map(fn ($term) => preg_quote($term, '/'), $terms));
        return '/(?:<[^>]*>|&#?\\w+;)(*SKIP)(*FAIL)|(?<![\\p{L}\\p{N}])(' . $alternatives . ')(?![\\p{L}\\p{N}])/iu';
    }

    /**
     * Escape everything, then turn the highlight marks into <mark>. Marks are
     * only kept in matching pairs, so the HTML is always well formed.
     */
    public function snippetHtml(string $snippet): string
    {
        $snippet = trim(preg_replace('/\s+/u', ' ', $snippet) ?? '');
        // Keep long snippets short, around the first highlighted word.
        if (mb_strlen($snippet) > self::MAX_SNIPPET_CHARS) {
            $start = max(0, (int) mb_strpos($snippet, self::MARK_START) - 100);
            $snippet = mb_substr($snippet, $start, self::MAX_SNIPPET_CHARS);
        }
        $html = htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace('/' . self::MARK_START . '([^' . self::MARK_START . self::MARK_END . ']*)' . self::MARK_END . '/u', '<mark>$1</mark>', $html) ?? $html;
        return str_replace([self::MARK_START, self::MARK_END], '', $html);
    }

    /**
     * @return array<string, array<int, int>> Counts by facet (FACET_FIELDS key) and value
     */
    public function parseFacets(array $facetFields): array
    {
        $result = [];
        foreach (self::FACET_FIELDS as $name => $field) {
            $flat = $facetFields[$field] ?? [];
            $result[$name] = [];
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $result[$name][(int) $flat[$i]] = (int) $flat[$i + 1];
            }
        }
        // Newest year first.
        krsort($result['year']);
        return $result;
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

    /** Shortest input that gets suggestions, in characters */
    public const SUGGEST_MIN_CHARS = 2;

    /**
     * Book titles for type-ahead: every word typed must begin a word of the
     * title or an author name ("kong bø" finds "Kongernes bøger").
     *
     * @param string $locale UI locale; its title is preferred
     *
     * @return array<int, array{submissionId: int, pressId: int, title: string, authors: string[]}>
     *
     * @throws SolrException
     */
    public function suggest(string $input, ?int $pressId, string $locale, int $rows = 8): array
    {
        $input = $this->normalizeQuery($input);
        // Only letters, digits and spaces: no query syntax at all.
        $input = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $input) ?? '');
        if (mb_strlen($input) < self::SUGGEST_MIN_CHARS) {
            return [];
        }
        $params = [
            'q' => mb_substr($input, 0, 100),
            'rows' => max(1, min(20, $rows)),
            'sort' => 'score desc,date_published desc',
        ];
        if ($pressId) {
            $params['fq'] = 'press_id:' . (int) $pressId;
        }
        $response = $this->solr->coreRequest('POST', 'suggest', [], ['params' => $params]);

        $preferred = 'title_' . LocaleFields::suffix($locale);
        $suggestions = [];
        foreach ($response['response']['docs'] ?? [] as $doc) {
            $title = $doc[$preferred][0] ?? null;
            if ($title === null) {
                foreach ($doc as $field => $values) {
                    if (str_starts_with($field, 'title_') && !empty($values[0])) {
                        $title = $values[0];
                        break;
                    }
                }
            }
            if ($title === null) {
                continue;
            }
            $suggestions[] = [
                'submissionId' => (int) $doc['submission_id'],
                'pressId' => (int) $doc['press_id'],
                'title' => (string) $title,
                'authors' => array_slice(array_map('strval', $doc['authors'] ?? []), 0, 3),
            ];
        }
        return $suggestions;
    }
}
