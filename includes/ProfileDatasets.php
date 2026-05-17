<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Curated picklists for the demographics chip-pickers.
 *
 * Each list is "common entries" — not exhaustive. The chip-picker widget
 * allows custom (free-text) values for everything except COUNTRIES and
 * LANGUAGES, which use ISO codes to keep aggregation clean.
 */
class ProfileDatasets {

    /**
     * ISO 3166-1 alpha-2. Format: [ code, English name, [ alt names ], native name? ].
     * Top ~80 by population / wiki user likelihood; chip-picker allows
     * typing-in any string for the long tail. Full list expandable.
     */
    public static function countries(): array {
        return [
            [ 'US', 'United States',           [ 'USA', 'America', 'U.S.', 'U.S.A.', 'United States of America' ] ],
            [ 'GB', 'United Kingdom',          [ 'UK', 'Britain', 'Great Britain', 'England' ] ],
            [ 'CA', 'Canada',                  [] ],
            [ 'AU', 'Australia',               [] ],
            [ 'NZ', 'New Zealand',             [ 'Aotearoa' ] ],
            [ 'IE', 'Ireland',                 [ 'Éire' ] ],
            [ 'DE', 'Germany',                 [ 'Deutschland', 'Deutsch' ] ],
            [ 'FR', 'France',                  [] ],
            [ 'ES', 'Spain',                   [ 'España' ] ],
            [ 'IT', 'Italy',                   [ 'Italia' ] ],
            [ 'PT', 'Portugal',                [] ],
            [ 'NL', 'Netherlands',             [ 'Holland', 'Nederland' ] ],
            [ 'BE', 'Belgium',                 [] ],
            [ 'CH', 'Switzerland',             [ 'Schweiz', 'Suisse' ] ],
            [ 'AT', 'Austria',                 [ 'Österreich' ] ],
            [ 'SE', 'Sweden',                  [ 'Sverige' ] ],
            [ 'NO', 'Norway',                  [ 'Norge' ] ],
            [ 'DK', 'Denmark',                 [ 'Danmark' ] ],
            [ 'FI', 'Finland',                 [ 'Suomi' ] ],
            [ 'IS', 'Iceland',                 [ 'Ísland' ] ],
            [ 'PL', 'Poland',                  [ 'Polska' ] ],
            [ 'CZ', 'Czechia',                 [ 'Czech Republic', 'Česko' ] ],
            [ 'SK', 'Slovakia',                [] ],
            [ 'HU', 'Hungary',                 [ 'Magyarország' ] ],
            [ 'RO', 'Romania',                 [] ],
            [ 'BG', 'Bulgaria',                [] ],
            [ 'GR', 'Greece',                  [ 'Ελλάδα' ] ],
            [ 'HR', 'Croatia',                 [] ],
            [ 'RS', 'Serbia',                  [] ],
            [ 'SI', 'Slovenia',                [] ],
            [ 'EE', 'Estonia',                 [] ],
            [ 'LV', 'Latvia',                  [] ],
            [ 'LT', 'Lithuania',               [] ],
            [ 'UA', 'Ukraine',                 [] ],
            [ 'RU', 'Russia',                  [ 'Russian Federation' ] ],
            [ 'BY', 'Belarus',                 [] ],
            [ 'TR', 'Türkiye',                 [ 'Turkey' ] ],
            [ 'IL', 'Israel',                  [] ],
            [ 'PS', 'Palestine',               [] ],
            [ 'LB', 'Lebanon',                 [] ],
            [ 'SY', 'Syria',                   [] ],
            [ 'JO', 'Jordan',                  [] ],
            [ 'SA', 'Saudi Arabia',            [] ],
            [ 'AE', 'United Arab Emirates',    [ 'UAE' ] ],
            [ 'QA', 'Qatar',                   [] ],
            [ 'KW', 'Kuwait',                  [] ],
            [ 'IR', 'Iran',                    [] ],
            [ 'IQ', 'Iraq',                    [] ],
            [ 'EG', 'Egypt',                   [ 'مصر' ] ],
            [ 'MA', 'Morocco',                 [] ],
            [ 'TN', 'Tunisia',                 [] ],
            [ 'DZ', 'Algeria',                 [] ],
            [ 'LY', 'Libya',                   [] ],
            [ 'SD', 'Sudan',                   [] ],
            [ 'ZA', 'South Africa',            [] ],
            [ 'NG', 'Nigeria',                 [] ],
            [ 'KE', 'Kenya',                   [] ],
            [ 'ET', 'Ethiopia',                [] ],
            [ 'GH', 'Ghana',                   [] ],
            [ 'TZ', 'Tanzania',                [] ],
            [ 'UG', 'Uganda',                  [] ],
            [ 'IN', 'India',                   [ 'भारत', 'Bharat' ] ],
            [ 'PK', 'Pakistan',                [] ],
            [ 'BD', 'Bangladesh',              [] ],
            [ 'LK', 'Sri Lanka',               [] ],
            [ 'NP', 'Nepal',                   [] ],
            [ 'BT', 'Bhutan',                  [] ],
            [ 'MV', 'Maldives',                [] ],
            [ 'CN', 'China',                   [ '中国' ] ],
            [ 'HK', 'Hong Kong',               [ '香港' ] ],
            [ 'TW', 'Taiwan',                  [ '台灣' ] ],
            [ 'JP', 'Japan',                   [ '日本' ] ],
            [ 'KR', 'South Korea',             [ 'Korea', '대한민국', '한국' ] ],
            [ 'KP', 'North Korea',             [ 'DPRK' ] ],
            [ 'MN', 'Mongolia',                [] ],
            [ 'VN', 'Vietnam',                 [] ],
            [ 'TH', 'Thailand',                [] ],
            [ 'KH', 'Cambodia',                [] ],
            [ 'LA', 'Laos',                    [] ],
            [ 'MM', 'Myanmar',                 [ 'Burma' ] ],
            [ 'MY', 'Malaysia',                [] ],
            [ 'SG', 'Singapore',               [] ],
            [ 'ID', 'Indonesia',               [] ],
            [ 'PH', 'Philippines',             [] ],
            [ 'MX', 'Mexico',                  [ 'México' ] ],
            [ 'GT', 'Guatemala',               [] ],
            [ 'CR', 'Costa Rica',              [] ],
            [ 'PA', 'Panama',                  [] ],
            [ 'CU', 'Cuba',                    [] ],
            [ 'DO', 'Dominican Republic',      [] ],
            [ 'PR', 'Puerto Rico',             [] ],
            [ 'JM', 'Jamaica',                 [] ],
            [ 'HT', 'Haiti',                   [] ],
            [ 'BR', 'Brazil',                  [ 'Brasil' ] ],
            [ 'AR', 'Argentina',               [] ],
            [ 'CL', 'Chile',                   [] ],
            [ 'PE', 'Peru',                    [] ],
            [ 'CO', 'Colombia',                [] ],
            [ 'VE', 'Venezuela',               [] ],
            [ 'EC', 'Ecuador',                 [] ],
            [ 'BO', 'Bolivia',                 [] ],
            [ 'PY', 'Paraguay',                [] ],
            [ 'UY', 'Uruguay',                 [] ],
        ];
    }

    /**
     * ISO 639-1. Format: [ code, English name, endonym, [ alt names ] ].
     */
    public static function languages(): array {
        return [
            [ 'en', 'English',              'English',          [] ],
            [ 'es', 'Spanish',              'Español',          [ 'Castilian', 'Castellano' ] ],
            [ 'fr', 'French',               'Français',         [] ],
            [ 'de', 'German',               'Deutsch',          [] ],
            [ 'it', 'Italian',              'Italiano',         [] ],
            [ 'pt', 'Portuguese',           'Português',        [] ],
            [ 'nl', 'Dutch',                'Nederlands',       [ 'Flemish' ] ],
            [ 'sv', 'Swedish',              'Svenska',          [] ],
            [ 'no', 'Norwegian',            'Norsk',            [] ],
            [ 'da', 'Danish',               'Dansk',            [] ],
            [ 'fi', 'Finnish',              'Suomi',            [] ],
            [ 'is', 'Icelandic',            'Íslenska',         [] ],
            [ 'ga', 'Irish',                'Gaeilge',          [ 'Gaelic' ] ],
            [ 'cy', 'Welsh',                'Cymraeg',          [] ],
            [ 'pl', 'Polish',               'Polski',           [] ],
            [ 'cs', 'Czech',                'Čeština',          [] ],
            [ 'sk', 'Slovak',               'Slovenčina',       [] ],
            [ 'hu', 'Hungarian',            'Magyar',           [] ],
            [ 'ro', 'Romanian',             'Română',           [] ],
            [ 'bg', 'Bulgarian',            'Български',        [] ],
            [ 'el', 'Greek',                'Ελληνικά',         [] ],
            [ 'hr', 'Croatian',             'Hrvatski',         [] ],
            [ 'sr', 'Serbian',              'Српски',           [] ],
            [ 'sl', 'Slovenian',            'Slovenščina',      [] ],
            [ 'et', 'Estonian',             'Eesti',            [] ],
            [ 'lv', 'Latvian',              'Latviešu',         [] ],
            [ 'lt', 'Lithuanian',           'Lietuvių',         [] ],
            [ 'uk', 'Ukrainian',            'Українська',       [] ],
            [ 'ru', 'Russian',              'Русский',          [] ],
            [ 'be', 'Belarusian',           'Беларуская',       [] ],
            [ 'tr', 'Turkish',              'Türkçe',           [] ],
            [ 'he', 'Hebrew',               'עברית',            [] ],
            [ 'ar', 'Arabic',               'العربية',          [] ],
            [ 'fa', 'Persian',              'فارسی',            [ 'Farsi', 'Dari' ] ],
            [ 'ur', 'Urdu',                 'اردو',             [] ],
            [ 'hi', 'Hindi',                'हिन्दी',             [] ],
            [ 'bn', 'Bengali',              'বাংলা',             [] ],
            [ 'pa', 'Punjabi',              'ਪੰਜਾਬੀ',             [] ],
            [ 'gu', 'Gujarati',             'ગુજરાતી',           [] ],
            [ 'ta', 'Tamil',                'தமிழ்',             [] ],
            [ 'te', 'Telugu',               'తెలుగు',            [] ],
            [ 'kn', 'Kannada',              'ಕನ್ನಡ',             [] ],
            [ 'ml', 'Malayalam',            'മലയാളം',           [] ],
            [ 'mr', 'Marathi',              'मराठी',             [] ],
            [ 'ne', 'Nepali',               'नेपाली',            [] ],
            [ 'si', 'Sinhala',              'සිංහල',            [] ],
            [ 'th', 'Thai',                 'ไทย',              [] ],
            [ 'vi', 'Vietnamese',           'Tiếng Việt',       [] ],
            [ 'km', 'Khmer',                'ខ្មែរ',             [] ],
            [ 'lo', 'Lao',                  'ລາວ',             [] ],
            [ 'my', 'Burmese',              'မြန်မာ',            [] ],
            [ 'zh', 'Chinese (Mandarin)',   '中文',              [ 'Mandarin', 'Putonghua' ] ],
            [ 'yue', 'Cantonese',           '粵語',              [] ],
            [ 'ja', 'Japanese',             '日本語',             [] ],
            [ 'ko', 'Korean',               '한국어',             [] ],
            [ 'id', 'Indonesian',           'Bahasa Indonesia', [] ],
            [ 'ms', 'Malay',                'Bahasa Melayu',    [] ],
            [ 'tl', 'Tagalog',              'Tagalog',          [ 'Filipino' ] ],
            [ 'sw', 'Swahili',              'Kiswahili',        [] ],
            [ 'am', 'Amharic',              'አማርኛ',             [] ],
            [ 'ha', 'Hausa',                'Hausa',            [] ],
            [ 'yo', 'Yoruba',               'Yorùbá',           [] ],
            [ 'ig', 'Igbo',                 'Igbo',             [] ],
            [ 'zu', 'Zulu',                 'isiZulu',          [] ],
            [ 'xh', 'Xhosa',                'isiXhosa',         [] ],
            [ 'af', 'Afrikaans',            'Afrikaans',        [] ],
            [ 'eu', 'Basque',               'Euskara',          [] ],
            [ 'ca', 'Catalan',              'Català',           [] ],
            [ 'gl', 'Galician',             'Galego',           [] ],
            [ 'la', 'Latin',                'Latina',           [] ],
            [ 'eo', 'Esperanto',            'Esperanto',        [] ],
            [ 'sgn', 'Sign language',       'Sign',             [ 'ASL', 'BSL', 'Auslan' ] ],
        ];
    }

    /** Common gender identities (chip-picker allows free-text custom). */
    public static function genders(): array {
        return [
            'Woman', 'Man', 'Nonbinary', 'Agender', 'Genderfluid',
            'Genderqueer', 'Demigirl', 'Demiboy', 'Transgender woman',
            'Transgender man', 'Transfeminine', 'Transmasculine',
            'Bigender', 'Pangender', 'Two-spirit', 'Intersex',
            'Questioning', 'Butch', 'Femme', 'Stud', 'Boi',
            'Cisgender woman', 'Cisgender man', 'Androgynous',
            'Neutrois', 'Aporagender', 'Polygender',
        ];
    }

    /** Common pronoun sets (chip-picker allows free-text custom). */
    public static function pronouns(): array {
        return [
            'she/her', 'he/him', 'they/them',
            'she/they', 'he/they', 'they/she', 'they/he',
            'ze/hir', 'ze/zir', 'xe/xem', 'fae/faer',
            'e/em', 'per/per', 'it/its',
            'any pronouns', 'no pronouns / use my name', 'ask me',
        ];
    }

    /** Broad ethnicity / race categories. Multi-select; supplement with free text. */
    public static function ethnicities(): array {
        return [
            'White / European',
            'Black / African',
            'Black / African American',
            'Black / Afro-Caribbean',
            'Hispanic / Latino / Latinx',
            'Indigenous American (US / Canada)',
            'Indigenous Mexican / Central American',
            'Indigenous South American',
            'East Asian',
            'South Asian',
            'Southeast Asian',
            'Central Asian',
            'Pacific Islander',
            'Native Hawaiian',
            'Middle Eastern / North African (MENA)',
            'Jewish (Ashkenazi)',
            'Jewish (Sephardi)',
            'Jewish (Mizrahi)',
            'Roma',
            'Mixed / Multiracial',
            'Aboriginal / First Nations Australian',
            'Māori',
            'Other',
        ];
    }

    /** Religion / spirituality traditions + secular stances. */
    public static function religions(): array {
        return [
            'Christian — Catholic',
            'Christian — Protestant',
            'Christian — Eastern Orthodox',
            'Christian — Coptic',
            'Christian — Mormon (LDS)',
            'Christian — Jehovah\'s Witness',
            'Christian — non-denominational',
            'Jewish — Orthodox',
            'Jewish — Conservative',
            'Jewish — Reform',
            'Jewish — Reconstructionist',
            'Jewish — secular / cultural',
            'Muslim — Sunni',
            'Muslim — Shia',
            'Muslim — Sufi',
            'Muslim — Ahmadiyya',
            'Hindu',
            'Buddhist — Theravada',
            'Buddhist — Mahayana',
            'Buddhist — Vajrayana / Tibetan',
            'Buddhist — Zen',
            'Sikh',
            'Jain',
            'Baháʼí',
            'Zoroastrian',
            'Taoist',
            'Shinto',
            'Pagan / Neopagan',
            'Wiccan',
            'Animist / Indigenous tradition',
            'Spiritual but not religious',
            'Agnostic',
            'Atheist',
            'Secular humanist',
            'Questioning / exploring',
            'None',
        ];
    }

    /** Relationship / marital status. */
    public static function marital(): array {
        return [
            'Single',
            'Dating',
            'In a relationship',
            'Partnered',
            'Engaged',
            'Married',
            'Married — open',
            'Polyamorous',
            'In a queerplatonic relationship',
            'Long-distance relationship',
            'Separated',
            'Divorced',
            'Widowed',
            'It\'s complicated',
            'Prefer not to say',
        ];
    }

    /** Housing situation. */
    public static function housing(): array {
        return [
            'Own home',
            'Own condo / apartment',
            'Rent house',
            'Rent apartment',
            'Live with family',
            'Live with roommates',
            'Dorm / student housing',
            'Military housing',
            'Transitional housing',
            'RV / van / vehicle dwelling',
            'Unhoused / no stable residence',
            'Other',
        ];
    }

    /** Currencies for income. */
    public static function currencies(): array {
        return [
            'USD' => 'US Dollar (USD)',
            'EUR' => 'Euro (EUR)',
            'GBP' => 'British Pound (GBP)',
            'CAD' => 'Canadian Dollar (CAD)',
            'AUD' => 'Australian Dollar (AUD)',
            'NZD' => 'New Zealand Dollar (NZD)',
            'JPY' => 'Japanese Yen (JPY)',
            'CNY' => 'Chinese Yuan (CNY)',
            'INR' => 'Indian Rupee (INR)',
            'BRL' => 'Brazilian Real (BRL)',
            'MXN' => 'Mexican Peso (MXN)',
            'CHF' => 'Swiss Franc (CHF)',
            'SEK' => 'Swedish Krona (SEK)',
            'NOK' => 'Norwegian Krone (NOK)',
            'DKK' => 'Danish Krone (DKK)',
            'KRW' => 'South Korean Won (KRW)',
            'SGD' => 'Singapore Dollar (SGD)',
            'HKD' => 'Hong Kong Dollar (HKD)',
            'ZAR' => 'South African Rand (ZAR)',
            'OTHER' => 'Other / not listed',
        ];
    }

    /** Encode a dataset shape to a JS-ready array (used by chip-picker). */
    public static function toJsCountries(): array {
        $out = [];
        foreach ( self::countries() as [ $code, $name, $alts ] ) {
            $native = $alts && count( $alts ) >= 2 ? $alts[ count( $alts ) - 1 ] : '';
            $out[] = [
                'code'   => $code,
                'label'  => $name,
                'alts'   => $alts,
                'native' => $native,
            ];
        }
        return $out;
    }

    public static function toJsLanguages(): array {
        $out = [];
        foreach ( self::languages() as [ $code, $name, $endonym, $alts ] ) {
            $out[] = [
                'code'   => $code,
                'label'  => $name,
                'native' => $endonym,
                'alts'   => $alts,
            ];
        }
        return $out;
    }

    public static function toJsSimpleList( array $items ): array {
        $out = [];
        foreach ( $items as $s ) {
            $out[] = [ 'code' => $s, 'label' => $s, 'native' => '', 'alts' => [] ];
        }
        return $out;
    }

    /** Bundle everything for the inline window.PCP_DATASETS = {...} script. */
    public static function bundleForJs(): array {
        return [
            'countries'  => self::toJsCountries(),
            'languages'  => self::toJsLanguages(),
            'genders'    => self::toJsSimpleList( self::genders() ),
            'pronouns'   => self::toJsSimpleList( self::pronouns() ),
            'ethnicities'=> self::toJsSimpleList( self::ethnicities() ),
            'religions'  => self::toJsSimpleList( self::religions() ),
            'marital'    => self::toJsSimpleList( self::marital() ),
            'housing'    => self::toJsSimpleList( self::housing() ),
        ];
    }
}
