<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/SolrClient.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SolrClient
 *
 * @brief Minimal HTTP client for a single Solr core, using OMP's Guzzle client.
 *
 * All responses are requested as JSON. Any transport failure, non-2xx status
 * or non-JSON body is turned into a SolrException so callers only have one
 * failure type to handle (and can fall back to OMP's own search).
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SolrClient
{
    private string $baseUrl;
    private string $core;
    private float $timeout;
    private Client $http;

    /**
     * @param string $baseUrl Solr base URL, e.g. http://127.0.0.1:8983/solr
     * @param string $core Core name, e.g. omp
     * @param float $timeout Request timeout in seconds
     */
    public function __construct(string $baseUrl, string $core, float $timeout, Client $http)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->core = $core;
        $this->timeout = $timeout;
        $this->http = $http;
    }

    public function getCore(): string
    {
        return $this->core;
    }

    /**
     * Send a request to a path below the core, e.g. "select" or "update".
     *
     * @param array $query Query string parameters
     * @param array|null $json Request body to send as JSON (POST)
     *
     * @throws SolrException
     */
    public function coreRequest(string $method, string $path, array $query = [], ?array $json = null): array
    {
        return $this->request($method, $this->core . '/' . ltrim($path, '/'), $query, $json);
    }

    /**
     * Send a request to a path below the Solr base URL.
     *
     * @throws SolrException
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $options = [
            'timeout' => $this->timeout,
            'connect_timeout' => min($this->timeout, 5),
            'http_errors' => false,
            'query' => $query + ['wt' => 'json'],
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $this->baseUrl . '/' . ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            error_log('RDLSolrSearch: Solr request to "' . $path . '" failed: ' . $e->getMessage());
            throw new SolrException('Could not connect to Solr.', 0, $e);
        }

        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);

        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) ? ($data['error']['msg'] ?? '') : '';
            error_log('RDLSolrSearch: Solr returned HTTP ' . $status . ' for "' . $path . '"' . ($detail !== '' ? ': ' . $detail : ''));
            throw new SolrException('Solr returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '') . '.', $status);
        }
        if (!is_array($data)) {
            throw new SolrException('Solr returned a response that is not JSON.');
        }

        return $data;
    }

    /**
     * Check that the core is up and answering queries.
     *
     * @throws SolrException
     */
    public function ping(): bool
    {
        $data = $this->coreRequest('GET', 'admin/ping');
        return ($data['status'] ?? null) === 'OK';
    }

    /**
     * @throws SolrException
     */
    public function getDocumentCount(): int
    {
        $data = $this->coreRequest('GET', 'select', ['q' => '*:*', 'rows' => 0]);
        return (int) ($data['response']['numFound'] ?? 0);
    }

    /**
     * @throws SolrException
     */
    public function getSolrVersion(): ?string
    {
        $data = $this->request('GET', 'admin/info/system');
        return $data['lucene']['solr-spec-version'] ?? null;
    }

    /**
     * Add or replace documents. Changes become visible within $commitWithinMs.
     *
     * @param array[] $documents
     *
     * @throws SolrException
     */
    public function addDocuments(array $documents, int $commitWithinMs = 5000): void
    {
        if (!$documents) {
            return;
        }
        $this->coreRequest('POST', 'update', ['commitWithin' => $commitWithinMs], array_values($documents));
    }

    /**
     * @param string[] $ids
     *
     * @throws SolrException
     */
    public function deleteByIds(array $ids, int $commitWithinMs = 5000): void
    {
        if (!$ids) {
            return;
        }
        $this->coreRequest('POST', 'update', ['commitWithin' => $commitWithinMs], ['delete' => array_values($ids)]);
    }

    /**
     * Make all pending changes visible now.
     *
     * @throws SolrException
     */
    public function commit(): void
    {
        $this->coreRequest('POST', 'update', ['commit' => 'true'], []);
    }

    /**
     * Get the ids of all documents, optionally restricted by a filter query.
     * Uses cursor paging so it works for any index size.
     *
     * @return string[]
     *
     * @throws SolrException
     */
    public function getAllIds(?string $filterQuery = null, int $pageSize = 1000): array
    {
        $ids = [];
        $cursor = '*';
        do {
            $params = ['q' => '*:*', 'fl' => 'id', 'rows' => $pageSize, 'sort' => 'id asc', 'cursorMark' => $cursor];
            if ($filterQuery !== null) {
                $params['fq'] = $filterQuery;
            }
            $data = $this->coreRequest('GET', 'select', $params);
            foreach ($data['response']['docs'] ?? [] as $doc) {
                $ids[] = (string) $doc['id'];
            }
            $previous = $cursor;
            $cursor = $data['nextCursorMark'] ?? $previous;
        } while ($cursor !== $previous);
        return $ids;
    }

    /**
     * Run all checks and report the outcome without throwing.
     *
     * @return array{ok: bool, error: ?string, solrVersion: ?string, numDocs: ?int}
     */
    public function testConnection(): array
    {
        $result = ['ok' => false, 'error' => null, 'solrVersion' => null, 'numDocs' => null];
        try {
            $result['solrVersion'] = $this->getSolrVersion();
            if (!$this->ping()) {
                $result['error'] = 'The core "' . $this->core . '" did not answer the ping.';
                return $result;
            }
            $result['numDocs'] = $this->getDocumentCount();
            $result['ok'] = true;
        } catch (SolrException $e) {
            $result['error'] = $e->getCode() === 404
                ? 'The core "' . $this->core . '" was not found.'
                : $e->getMessage();
        }
        return $result;
    }
}
