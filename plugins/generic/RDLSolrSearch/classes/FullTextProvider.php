<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/FullTextProvider.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullTextProvider
 *
 * @brief Finds the book files anyone may read and returns their text.
 *
 * A file is used only when an anonymous visitor could download it for free,
 * mirroring CatalogBookHandler::download():
 *  - it is a proof file of a publication format of the current, published publication,
 *  - the format is approved and available and not a remote URL,
 *  - the file is open access (direct sales price '0'),
 *  - the press does not require a login to read monographs.
 * Files for sale, unapproved files and dependent files are never indexed.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\submissionFile\SubmissionFile;

class FullTextProvider
{
    /** Longest full text per monograph, in characters (all files together) */
    public const MAX_BOOK_CHARS = 5000000;

    private FullTextExtractor $extractor;

    public function __construct(FullTextExtractor $extractor)
    {
        $this->extractor = $extractor;
    }

    /**
     * Whether a publication format is shown to the public.
     */
    public static function isPublicFormat($format): bool
    {
        return (bool) $format->getIsApproved() && (bool) $format->getIsAvailable();
    }

    /**
     * @return array<int, array{locale: string, text: string}> Text per file
     */
    public function getTexts(Submission $submission, Publication $publication, Context $press): array
    {
        if ($press->getData('restrictMonographAccess')) {
            return [];
        }
        $formatIds = [];
        foreach ($publication->getData('publicationFormats') ?? [] as $format) {
            if (self::isPublicFormat($format) && !$format->getRemoteURL()) {
                $formatIds[] = (int) $format->getId();
            }
        }
        if (!$formatIds) {
            return [];
        }

        $texts = [];
        $length = 0;
        foreach ($this->getFormatFiles((int) $submission->getId(), $formatIds) as $file) {
            if (!$this->isOpenAccess($file, $formatIds)) {
                continue;
            }
            $text = $this->extractor->extract(
                (string) $file->getData('path'),
                (string) $file->getData('mimetype'),
                (int) $file->getData('fileId')
            );
            if ($text === null || $text === '') {
                continue;
            }
            $text = mb_substr($text, 0, self::MAX_BOOK_CHARS - $length);
            $length += mb_strlen($text);
            $texts[] = [
                'locale' => (string) ($file->getData('locale') ?: $publication->getData('locale') ?: $submission->getData('locale')),
                'text' => $text,
            ];
            if ($length >= self::MAX_BOOK_CHARS) {
                break;
            }
        }
        return $texts;
    }

    /**
     * Same checks as CatalogBookHandler::download() for an anonymous visitor.
     */
    public function isOpenAccess($file, array $publicFormatIds): bool
    {
        return (int) $file->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_PROOF
            && (int) $file->getData('assocType') === Application::ASSOC_TYPE_PUBLICATION_FORMAT
            && in_array((int) $file->getData('assocId'), $publicFormatIds, true)
            && $file->getDirectSalesPrice() === '0';
    }

    /**
     * @param int[] $formatIds
     *
     * @return iterable<SubmissionFile>
     */
    protected function getFormatFiles(int $submissionId, array $formatIds): iterable
    {
        return Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION_FORMAT, $formatIds)
            ->getMany();
    }
}
