<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Wikitext helper for inserting content into a named parameter of the MedTemplate
 * call on a medicine page. Walks the wikitext respecting nested {{...}} and [[...]]
 * so embedded templates and links don't trip the search.
 */
class TemplateParamEditor {

    /**
     * Insert $newBlock into the value of $paramName inside the (first) MedTemplate call.
     * If the param already has content, append after it. If empty, place as the value.
     * If the param doesn't exist, add it just before the template's closing }}.
     * If MedTemplate isn't found, append to end of page (last-resort fallback).
     */
    public static function insertIntoMedTemplateParam( $wt, $paramName, $newBlock ) {
        $found = self::findParamValue( $wt, $paramName );
        if ( $found !== null ) {
            [ $valueStart, $valueEnd ] = $found;
            $existing = substr( $wt, $valueStart, $valueEnd - $valueStart );
            $existingTrimmed = rtrim( $existing );
            if ( trim( $existingTrimmed ) === '' ) {
                $insertion = "\n" . $newBlock . "\n";
            } else {
                $insertion = $existingTrimmed . "\n\n" . $newBlock . "\n";
            }
            return substr( $wt, 0, $valueStart ) . $insertion . substr( $wt, $valueEnd );
        }

        // Param doesn't exist — add it before the closing }} of MedTemplate
        $tplEnd = self::findMedTemplateClose( $wt );
        if ( $tplEnd !== null ) {
            $insertion = "| " . $paramName . " = " . $newBlock . "\n";
            return substr( $wt, 0, $tplEnd ) . $insertion . substr( $wt, $tplEnd );
        }

        // No MedTemplate found at all — append to end
        return rtrim( $wt ) . "\n\n" . $newBlock . "\n";
    }

    /**
     * Locate the value-substring offsets [start, end) for a top-level parameter
     * of the (first) MedTemplate call. Returns null if the parameter isn't present.
     */
    private static function findParamValue( $wt, $paramName ) {
        $len = strlen( $wt );
        // Find the MedTemplate call's opening
        if ( !preg_match( '/\{\{\s*MedTemplate\b/i', $wt, $m, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        $tplStart = $m[0][1];

        // Walk from tplStart, tracking brace/bracket depth, looking for a top-level
        // `| paramName` boundary (i.e. depth becomes 1 again after entering).
        $i = $tplStart + 2; // past {{
        $braceDepth = 1;    // we're inside MedTemplate
        $bracketDepth = 0;

        while ( $i < $len ) {
            // Nested template open
            if ( $i + 1 < $len && $wt[$i] === '{' && $wt[$i + 1] === '{' ) {
                $braceDepth++;
                $i += 2;
                continue;
            }
            // Template close
            if ( $i + 1 < $len && $wt[$i] === '}' && $wt[$i + 1] === '}' ) {
                $braceDepth--;
                if ( $braceDepth === 0 ) {
                    return null; // hit end of MedTemplate without finding param
                }
                $i += 2;
                continue;
            }
            // Link open
            if ( $i + 1 < $len && $wt[$i] === '[' && $wt[$i + 1] === '[' ) {
                $bracketDepth++;
                $i += 2;
                continue;
            }
            // Link close
            if ( $i + 1 < $len && $wt[$i] === ']' && $wt[$i + 1] === ']' ) {
                $bracketDepth = max( 0, $bracketDepth - 1 );
                $i += 2;
                continue;
            }
            // Top-level pipe — this introduces a parameter
            if ( $wt[$i] === '|' && $braceDepth === 1 && $bracketDepth === 0 ) {
                // Read the param name: skip whitespace, then alnum/_-
                $j = $i + 1;
                while ( $j < $len && ( $wt[$j] === ' ' || $wt[$j] === "\t" ) ) {
                    $j++;
                }
                $nameStart = $j;
                while ( $j < $len && preg_match( '/[A-Za-z0-9_]/', $wt[$j] ) ) {
                    $j++;
                }
                $name = substr( $wt, $nameStart, $j - $nameStart );
                // Skip whitespace then expect =
                while ( $j < $len && ( $wt[$j] === ' ' || $wt[$j] === "\t" ) ) {
                    $j++;
                }
                if ( $j < $len && $wt[$j] === '=' && $name === $paramName ) {
                    // Found our param. Value starts just after `=` + a single optional space.
                    $j++;
                    if ( $j < $len && $wt[$j] === ' ' ) { $j++; }
                    $valueStart = $j;
                    // Walk to find value end (next top-level | or this template's }})
                    $k = $valueStart;
                    $bd = 1;  // still inside MedTemplate
                    $kd = 0;
                    while ( $k < $len ) {
                        if ( $k + 1 < $len && $wt[$k] === '{' && $wt[$k + 1] === '{' ) {
                            $bd++; $k += 2; continue;
                        }
                        if ( $k + 1 < $len && $wt[$k] === '}' && $wt[$k + 1] === '}' ) {
                            $bd--;
                            if ( $bd === 0 ) {
                                return [ $valueStart, $k ];
                            }
                            $k += 2; continue;
                        }
                        if ( $k + 1 < $len && $wt[$k] === '[' && $wt[$k + 1] === '[' ) {
                            $kd++; $k += 2; continue;
                        }
                        if ( $k + 1 < $len && $wt[$k] === ']' && $wt[$k + 1] === ']' ) {
                            $kd = max( 0, $kd - 1 ); $k += 2; continue;
                        }
                        if ( $wt[$k] === '|' && $bd === 1 && $kd === 0 ) {
                            return [ $valueStart, $k ];
                        }
                        $k++;
                    }
                    return [ $valueStart, $len ];
                }
                // Not our param — fall through to keep scanning
                $i++;
                continue;
            }
            $i++;
        }
        return null;
    }

    /**
     * Return the offset of the `}}` that closes the (first) MedTemplate call, or null.
     */
    private static function findMedTemplateClose( $wt ) {
        $len = strlen( $wt );
        if ( !preg_match( '/\{\{\s*MedTemplate\b/i', $wt, $m, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        $i = $m[0][1] + 2;
        $braceDepth = 1;
        $bracketDepth = 0;
        while ( $i < $len ) {
            if ( $i + 1 < $len && $wt[$i] === '{' && $wt[$i + 1] === '{' ) {
                $braceDepth++; $i += 2; continue;
            }
            if ( $i + 1 < $len && $wt[$i] === '}' && $wt[$i + 1] === '}' ) {
                $braceDepth--;
                if ( $braceDepth === 0 ) {
                    return $i;
                }
                $i += 2; continue;
            }
            if ( $i + 1 < $len && $wt[$i] === '[' && $wt[$i + 1] === '[' ) {
                $bracketDepth++; $i += 2; continue;
            }
            if ( $i + 1 < $len && $wt[$i] === ']' && $wt[$i + 1] === ']' ) {
                $bracketDepth = max( 0, $bracketDepth - 1 ); $i += 2; continue;
            }
            $i++;
        }
        return null;
    }
}
