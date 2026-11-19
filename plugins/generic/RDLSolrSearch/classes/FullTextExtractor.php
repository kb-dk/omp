<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/FullTextExtractor.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullTextExtractor
 *
 * @brief Extracts plain text from book files (PDF, EPUB, HTML, XML, text).
 *
 * Files are read through OMP's file storage (Flysystem, rooted in the files
 * directory). PDFs are converted with pdftotext. Extracted text is cached,
 * gzipped, in the files directory under rdlSolrSearch/fulltext/, keyed by
 * file id: OMP never changes a stored file (a new revision gets a new id),
 * so the cache never goes stale and reindexing does not rerun pdftotext.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use League\Flysystem\FilesystemOperator;
use Throwable;
use ZipArchive;

class FullTextExtractor
{
    /** Longest text kept per file, in characters */
    public const MAX_FILE_CHARS = 2000000;

    /** pdftotext is stopped after this many seconds */
    public const TIMEOUT_SECONDS = 120;

    public const CACHE_DIR = 'rdlSolrSearch/fulltext';

    /** Largest uncompressed EPUB entry read, in bytes (zip bomb guard) */
    private const MAX_EPUB_ENTRY_BYTES = 50000000;

    private const PDF_TYPES = ['application/pdf', 'application/x-pdf'];
    private const EPUB_TYPES = ['application/epub+zip'];
    private const MARKUP_TYPES = ['text/html', 'application/xhtml+xml', 'text/xml', 'application/xml'];
    private const PLAIN_TYPES = ['text/plain'];

    private FilesystemOperator $fs;
    private string $pdftotext;
    private bool $useCache;

    public function __construct(FilesystemOperator $fs, string $pdftotext = '/usr/bin/pdftotext', bool $useCache = true)
    {
        $this->fs = $fs;
        $this->pdftotext = $pdftotext;
        $this->useCache = $useCache;
    }

    public static function isSupported(string $mimetype): bool
    {
        return in_array(strtolower($mimetype), array_merge(self::PDF_TYPES, self::EPUB_TYPES, self::MARKUP_TYPES, self::PLAIN_TYPES), true);
    }

    /**
     * Get the plain text of a stored file.
     *
     * @param string $path Path in OMP's file storage (SubmissionFile "path")
     * @param int $fileId OMP file id (SubmissionFile "fileId"), the cache key
     *
     * @return ?string The text ('' for a file without text, e.g. a scanned PDF),
     *  or null if the type is not supported or extraction failed (not cached).
     */
    public function extract(string $path, string $mimetype, int $fileId): ?string
    {
        $mimetype = strtolower($mimetype);
        if (!self::isSupported($mimetype)) {
            return null;
        }
        $cachePath = self::CACHE_DIR . '/' . intdiv($fileId, 1000) . '/' . $fileId . '.txt.gz';
        if ($this->useCache && $fileId > 0) {
            $cached = $this->readCache($cachePath);
            if ($cached !== null) {
                return $cached;
            }
        }

        $text = $this->extractFromStorage($path, $mimetype);
        if ($text === null) {
            return null;
        }
        if ($this->useCache && $fileId > 0) {
            try {
                $this->fs->write($cachePath, gzencode($text, 6));
            } catch (Throwable $e) {
                error_log('RDLSolrSearch: could not cache full text of file ' . $fileId . ': ' . $e->getMessage());
            }
        }
        return $text;
    }

    private function readCache(string $cachePath): ?string
    {
        try {
            if (!$this->fs->fileExists($cachePath)) {
                return null;
            }
            $text = gzdecode($this->fs->read($cachePath));
            return $text === false ? null : $text;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Copy the file to a local temporary file (storage may not be local) and extract.
     */
    private function extractFromStorage(string $path, string $mimetype): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rdlsolr');
        if ($tmp === false) {
            error_log('RDLSolrSearch: could not create a temporary file for "' . $path . '"');
            return null;
        }
        try {
            $in = $this->fs->readStream($path);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
            return $this->extractLocalFile($tmp, $mimetype, $path);
        } catch (Throwable $e) {
            error_log('RDLSolrSearch: could not read "' . $path . '" for full-text indexing: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param string $label File name used in log messages
     */
    public function extractLocalFile(string $file, string $mimetype, string $label = ''): ?string
    {
        $mimetype = strtolower($mimetype);
        if (in_array($mimetype, self::PDF_TYPES, true)) {
            $text = $this->pdfToText($file, $label);
        } elseif (in_array($mimetype, self::EPUB_TYPES, true)) {
            $text = $this->epubToText($file, $label);
        } elseif (in_array($mimetype, self::MARKUP_TYPES, true)) {
            $text = $this->markupToText((string) file_get_contents($file, false, null, 0, self::MAX_FILE_CHARS * 4));
        } elseif (in_array($mimetype, self::PLAIN_TYPES, true)) {
            $text = (string) file_get_contents($file, false, null, 0, self::MAX_FILE_CHARS * 4);
        } else {
            return null;
        }
        return $text === null ? null : $this->clean($text);
    }

    private function pdfToText(string $file, string $label): ?string
    {
        $command = ['timeout', (string) self::TIMEOUT_SECONDS, $this->pdftotext, '-q', '-enc', 'UTF-8', '-nopgbrk', $file, '-'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            error_log('RDLSolrSearch: could not run pdftotext');
            return null;
        }
        // Read at most ~4 bytes per kept character; drain the rest so pdftotext can finish.
        $text = (string) stream_get_contents($pipes[1], self::MAX_FILE_CHARS * 4);
        while (!feof($pipes[1]) && fread($pipes[1], 65536) !== false);
        fclose($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2], 2000));
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            error_log('RDLSolrSearch: pdftotext failed for "' . $label . '" (exit ' . $status . ($status === 124 ? ', timeout' : '') . ')' . ($stderr !== '' ? ': ' . $stderr : ''));
            return null;
        }
        return $text;
    }

    /**
     * Text of all (X)HTML documents in an EPUB, in archive order.
     */
    private function epubToText(string $file, string $label): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            error_log('RDLSolrSearch: could not open EPUB "' . $label . '"');
            return null;
        }
        $parts = [];
        $length = 0;
        for ($i = 0; $i < $zip->numFiles && $length < self::MAX_FILE_CHARS; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || !preg_match('/\.(x?html?|xml)$/i', $stat['name']) || preg_match('/(^|\/)(META-INF\/|.*\.opf$|toc\.ncx$)/i', $stat['name'])) {
                continue;
            }
            if ($stat['size'] > self::MAX_EPUB_ENTRY_BYTES) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $part = $this->markupToText($content);
            $parts[] = $part;
            $length += strlen($part);
        }
        $zip->close();
        return implode("\n", $parts);
    }

    private function markupToText(string $markup): string
    {
        $markup = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $markup) ?? $markup;
        // Keep words in adjacent elements apart ("<p>a</p><p>b</p>" -> "a b").
        $markup = str_replace('<', ' <', $markup);
        return html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Valid UTF-8 without control characters, single spaces, capped length.
     */
    private function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text) ?? '';
        // Rejoin words hyphenated across line breaks ("biblio-\nteket").
        $text = preg_replace('/(\p{L})-\s*\n\s*(\p{Ll})/u', '$1$2', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, self::MAX_FILE_CHARS);
    }
}
