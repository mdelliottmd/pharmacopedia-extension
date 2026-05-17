<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Utilities for safely emitting CSV from user-controlled values.
 *
 * Excel / LibreOffice / Numbers treat any cell whose first character is
 *   =  +  -  @  \t  \r
 * as a formula, which can be turned into RCE via DDE, IMPORT, HYPERLINK, etc.
 * Prepend a leading apostrophe so the spreadsheet renders the value as text.
 *
 * See: https://owasp.org/www-community/attacks/CSV_Injection
 */
class CsvHelper {

    /** Sanitize a single cell. Non-strings pass through unchanged. */
    public static function safeCell( $value ) {
        if ( !is_string( $value ) || $value === '' ) {
            return $value;
        }
        $first = $value[0];
        if ( $first === '=' || $first === '+' || $first === '-' || $first === '@'
             || $first === "\t" || $first === "\r" ) {
            return "'" . $value;
        }
        return $value;
    }

    /** Sanitize every cell of a row. */
    public static function safeRow( array $row ): array {
        return array_map( [ self::class, 'safeCell' ], $row );
    }
}
