<?php

/**
 * @file plugins/generic/RDLSolrSearch/classes/LocaleFields.php
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFields
 *
 * @brief Maps OMP locales to localized Solr field names (title_da, ...).
 *
 * SUFFIXES must match the "*_<locale>" dynamic fields in solr/conf/schema.xml.
 * A locale without its own analyzer falls back to the generic "*_txt" field.
 */

namespace APP\plugins\generic\RDLSolrSearch\classes;

class LocaleFields
{
    public const SUFFIXES = [
        'da', 'en', 'de', 'sv', 'nb', 'nn', 'fr_FR', 'fr_CA', 'es', 'it', 'nl', 'pt_BR', 'pt_PT',
        'ca', 'cs', 'el', 'eu', 'fa', 'gl', 'ro', 'ru', 'tr',
    ];

    public const FALLBACK_SUFFIX = 'txt';

    /**
     * Get the Solr field suffix for an OMP locale, e.g. "da" or "fr_CA".
     * Tries the exact locale, then its language (en_US -> en), then the fallback.
     */
    public static function suffix(string $locale): string
    {
        if (in_array($locale, self::SUFFIXES, true)) {
            return $locale;
        }
        $language = strtolower(preg_split('/[_@-]/', $locale)[0]);
        if (in_array($language, self::SUFFIXES, true)) {
            return $language;
        }
        // French without a region still gets the French analyzer.
        if ($language === 'fr') {
            return 'fr_FR';
        }
        if ($language === 'pt') {
            return 'pt_PT';
        }
        return self::FALLBACK_SUFFIX;
    }

    /**
     * Localized metadata field, e.g. field('title', 'da') = "title_da".
     */
    public static function field(string $name, string $locale): string
    {
        return $name . '_' . self::suffix($locale);
    }

    /**
     * Full-text field (indexed, not stored), e.g. fullTextField('galley', 'da') = "galley_ft_da".
     */
    public static function fullTextField(string $name, string $locale): string
    {
        return $name . '_ft_' . self::suffix($locale);
    }
}
