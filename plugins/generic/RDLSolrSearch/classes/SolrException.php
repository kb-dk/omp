<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/SolrException.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SolrException
 *
 * @brief Raised when Solr cannot be reached or returns an error.
 *
 * Messages are meant to be shown to administrators, so they never include
 * the request URL (which could carry credentials in the future).
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

class SolrException extends \RuntimeException
{
}
