<?php

namespace App\Http\Controllers\Admin\Traits;

/**
 * Provides a standardized list of European language-country locales.
 * This trait can be used in any CrudController to easily add a
 * language dropdown, ensuring consistency and reducing errors.
 */
trait ProvidesLanguageOptions
{
    /**
     * Returns an array of European countries and their primary language codes.
     * Includes all EU members plus the UK, Norway, and Switzerland.
     * The array is keyed by locale code (e.g., 'fi-FI') and valued by a
     * human-readable name (e.g., 'Finnish - Finland').
     *
     * @return array
     */
    protected function getLanguageOptions(): array
    {
        return [
            'sq-AL' => 'Albanian - Albania',
            'ca-AD' => 'Catalan - Andorra',
            'de-AT' => 'German - Austria',
            'be-BY' => 'Belarusian - Belarus',
            'fr-BE' => 'French - Belgium',
            'nl-BE' => 'Dutch - Belgium',
            'bs-BA' => 'Bosnian - Bosnia and Herzegovina',
            'bg-BG' => 'Bulgarian - Bulgaria',
            'hr-HR' => 'Croatian - Croatia',
            'cs-CZ' => 'Czech - Czech Republic',
            'da-DK' => 'Danish - Denmark',
            'et-EE' => 'Estonian - Estonia',
            'fi-FI' => 'Finnish - Finland',
            'fr-FR' => 'French - France',
            'de-DE' => 'German - Germany',
            'el-GR' => 'Greek - Greece',
            'hu-HU' => 'Hungarian - Hungary',
            'is-IS' => 'Icelandic - Iceland',
            'ga-IE' => 'Irish - Ireland',
            'it-IT' => 'Italian - Italy',
            'lv-LV' => 'Latvian - Latvia',
            'de-LI' => 'German - Liechtenstein',
            'lt-LT' => 'Lithuanian - Lithuania',
            'fr-LU' => 'French - Luxembourg',
            'de-LU' => 'German - Luxembourg',
            'mt-MT' => 'Maltese - Malta',
            'ro-MD' => 'Romanian - Moldova',
            'fr-MC' => 'French - Monaco',
            'sr-ME' => 'Serbian - Montenegro',
            'nl-NL' => 'Dutch - Netherlands',
            'mk-MK' => 'Macedonian - North Macedonia',
            'nb-NO' => 'Norwegian (Bokmål) - Norway',
            'pl-PL' => 'Polish - Poland',
            'pt-PT' => 'Portuguese - Portugal',
            'ro-RO' => 'Romanian - Romania',
            'ru-RU' => 'Russian - Russia',
            'it-SM' => 'Italian - San Marino',
            'sr-RS' => 'Serbian - Serbia',
            'sk-SK' => 'Slovak - Slovakia',
            'sl-SI' => 'Slovenian - Slovenia',
            'es-ES' => 'Spanish - Spain',
            'sv-SE' => 'Swedish - Sweden',
            'de-CH' => 'German - Switzerland',
            'fr-CH' => 'French - Switzerland',
            'it-CH' => 'Italian - Switzerland',
            'tr-TR' => 'Turkish - Turkey',
            'uk-UA' => 'Ukrainian - Ukraine',
            'en-GB' => 'English - United Kingdom',
        ];
    }
}