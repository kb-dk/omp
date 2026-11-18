<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/MonographDocumentBuilder.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MonographDocumentBuilder
 *
 * @brief Turns a published monograph into a Solr document (see solr/conf/schema.xml).
 *
 * Only publicly visible content is indexed: the submission and its current
 * publication must be published and the press must be enabled. Anything
 * else returns null, which tells the indexer to remove the monograph.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\context\Context;

class MonographDocumentBuilder
{
    /** ONIX product identifier types: 02 = ISBN-10, 15 = ISBN-13 */
    private const ISBN_CODES = ['02', '15'];

    /** @var array<int, ?Context> Press cache, by id */
    private array $presses = [];

    /**
     * Whether the monograph may appear in public search results.
     */
    public function isIndexable(Submission $submission): bool
    {
        if ($submission->getData('status') !== Submission::STATUS_PUBLISHED) {
            return false;
        }
        $publication = $submission->getCurrentPublication();
        if (!$publication || $publication->getData('status') !== Submission::STATUS_PUBLISHED) {
            return false;
        }
        $press = $this->getPress((int) $submission->getData('contextId'));
        return $press !== null && (bool) $press->getEnabled();
    }

    /**
     * @return ?array The Solr document, or null if the monograph must not be in the index.
     */
    public function build(Submission $submission): ?array
    {
        if (!$this->isIndexable($submission)) {
            return null;
        }
        $publication = $submission->getCurrentPublication();
        $locale = (string) ($publication->getData('locale') ?: $submission->getData('locale'));

        $doc = [
            'id' => (string) $submission->getId(),
            'submission_id' => (int) $submission->getId(),
            'publication_id' => (int) $publication->getId(),
            'press_id' => (int) $submission->getData('contextId'),
            'locale' => $locale,
        ];
        if ($publication->getData('seriesId')) {
            $doc['series_id'] = (int) $publication->getData('seriesId');
        }
        if ($submission->getData('workType') !== null) {
            $doc['work_type'] = (int) $submission->getData('workType');
        }
        $datePublished = $this->toSolrDate($publication->getData('datePublished'));
        if ($datePublished) {
            $doc['date_published'] = $datePublished;
        }

        // Titles: prefix ("The", "A") is searchable but left out of the sort key.
        foreach ((array) $publication->getData('title') as $titleLocale => $title) {
            $prefix = (string) ($publication->getData('prefix', $titleLocale) ?? '');
            $this->addLocalized($doc, 'title', $titleLocale, trim($prefix . ' ' . $title));
        }
        $this->addLocalizedData($doc, 'subtitle', $publication->getData('subtitle'));
        $this->addLocalizedData($doc, 'abstract', $publication->getData('abstract'));
        foreach (['keywords', 'subjects', 'disciplines'] as $vocabulary) {
            foreach ((array) $publication->getData($vocabulary) as $termLocale => $terms) {
                foreach ((array) $terms as $term) {
                    $this->addLocalized($doc, $vocabulary, $termLocale, $term);
                }
            }
        }
        $this->addLocalizedData($doc, 'coverage', $publication->getData('coverage'));
        $this->addLocalizedData($doc, 'type', $publication->getData('type'));

        $sortTitle = $this->clean((string) ($publication->getData('title', $locale) ?? ''));
        if ($sortTitle === '') {
            $sortTitle = $this->clean((string) $publication->getLocalizedData('title'));
        }
        if ($sortTitle !== '') {
            $doc['title_sort'] = mb_strtolower($sortTitle);
        }

        $this->addAuthors($doc, $publication);
        $this->addSeries($doc, $publication);
        $this->addChapters($doc, $publication);
        $this->addIdentifiers($doc, $publication);

        return $doc;
    }

    private function addAuthors(array &$doc, Publication $publication): void
    {
        foreach ($publication->getData('authors') ?? [] as $author) {
            foreach ($this->authorNames($author) as $name) {
                $doc['authors'][] = $name;
                $doc['authors_facet'][] = $name;
            }
            $this->addLocalizedData($doc, 'affiliation', $author->getData('affiliation'));
        }
        foreach (['authors', 'authors_facet'] as $field) {
            if (isset($doc[$field])) {
                $doc[$field] = array_values(array_unique($doc[$field]));
            }
        }
    }

    private function addSeries(array &$doc, Publication $publication): void
    {
        $seriesId = (int) $publication->getData('seriesId');
        if (!$seriesId) {
            return;
        }
        foreach ($this->getSeriesTitles($seriesId) as $seriesLocale => $title) {
            $this->addLocalized($doc, 'series_title', $seriesLocale, $title);
        }
    }

    /**
     * @return array<string, string> Series titles including prefix, by locale
     */
    protected function getSeriesTitles(int $seriesId): array
    {
        $series = Repo::section()->get($seriesId);
        if (!$series) {
            return [];
        }
        $titles = [];
        foreach ((array) $series->getData('title') as $locale => $title) {
            $prefix = (string) ($series->getData('prefix', $locale) ?? '');
            $titles[$locale] = trim($prefix . ' ' . $title);
        }
        return $titles;
    }

    private function addChapters(array &$doc, Publication $publication): void
    {
        foreach ($publication->getData('chapters') ?? [] as $chapter) {
            $this->addLocalizedData($doc, 'chapter_title', $chapter->getData('title'));
            $this->addLocalizedData($doc, 'chapter_subtitle', $chapter->getData('subtitle'));
            $this->addLocalizedData($doc, 'chapter_abstract', $chapter->getData('abstract'));
            foreach ($chapter->getAuthors() as $author) {
                foreach ($this->authorNames($author) as $name) {
                    $doc['chapter_authors'][] = $name;
                }
            }
            if ($chapter->getDoi()) {
                $doc['doi'][] = $chapter->getDoi();
            }
        }
        if (isset($doc['chapter_authors'])) {
            $doc['chapter_authors'] = array_values(array_unique($doc['chapter_authors']));
        }
    }

    /**
     * ISBNs and DOIs. Only approved publication formats are used, so
     * identifiers of formats that are not public yet stay out of the index.
     */
    private function addIdentifiers(array &$doc, Publication $publication): void
    {
        if ($publication->getDoi()) {
            $doc['doi'][] = $publication->getDoi();
        }
        foreach ($publication->getData('publicationFormats') ?? [] as $format) {
            if (!$format->getIsApproved()) {
                continue;
            }
            foreach ($format->getIdentificationCodes()->toArray() as $code) {
                if (in_array($code->getCode(), self::ISBN_CODES, true) && trim((string) $code->getValue()) !== '') {
                    $doc['isbn'][] = trim($code->getValue());
                }
            }
            if ($format->getDoi()) {
                $doc['doi'][] = $format->getDoi();
            }
        }
        foreach (['isbn', 'doi'] as $field) {
            if (isset($doc[$field])) {
                $doc[$field] = array_values(array_unique($doc[$field]));
            }
        }
    }

    /**
     * All distinct names of an author: as entered and the preferred public name, in every locale.
     *
     * @return string[]
     */
    private function authorNames($author): array
    {
        $names = array_merge(
            array_values($author->getFullNames(false)),
            array_values($author->getFullNames(true))
        );
        return array_values(array_unique(array_filter(array_map([$this, 'clean'], $names), 'strlen')));
    }

    /**
     * Add every locale of a multilingual value, e.g. ['da' => '...', 'en' => '...'].
     */
    private function addLocalizedData(array &$doc, string $name, $values): void
    {
        foreach ((array) $values as $locale => $value) {
            if (is_string($locale)) {
                $this->addLocalized($doc, $name, $locale, $value);
            }
        }
    }

    private function addLocalized(array &$doc, string $name, string $locale, $value): void
    {
        $value = $this->clean((string) $value);
        if ($value === '') {
            return;
        }
        $field = LocaleFields::field($name, $locale);
        if (!in_array($value, $doc[$field] ?? [], true)) {
            $doc[$field][] = $value;
        }
    }

    /**
     * Plain text: no HTML, decoded entities, single spaces.
     */
    public function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function toSolrDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        $timestamp = strtotime($date . (strlen($date) === 10 ? ' 00:00:00 UTC' : ''));
        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    protected function getPress(int $pressId): ?Context
    {
        if (!array_key_exists($pressId, $this->presses)) {
            $this->presses[$pressId] = Application::getContextDAO()->getById($pressId);
        }
        return $this->presses[$pressId];
    }
}
