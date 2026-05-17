<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

class SpecialMyProfile extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyProfile' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'My profile' );
        $out->setSubtitle(
            '<a href="#" class="pcp-obs-trigger pcp-share-trigger">📝 Quick observation</a>'
        );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.datepicker.styles', 'ext.pharmacopedia.timepicker.styles', 'ext.pharmacopedia.share', 'ext.pharmacopedia.observation' ] );
        $out->addModules( [ 'ext.pharmacopedia', 'ext.pharmacopedia.datepicker', 'ext.pharmacopedia.timepicker', 'ext.pharmacopedia.kitsync', 'ext.pharmacopedia.share', 'ext.pharmacopedia.observation', 'ext.pharmacopedia.blocksave' ] );

        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $out->addHTML( '<p>You must <a href="' .
                htmlspecialchars( SpecialPage::getTitleFor( 'UserLogin' )->getLocalURL() ) .
                '">log in</a> to view or edit your profile.</p>' );
            return;
        }

        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $request = $this->getRequest();

        // Handle POST
        if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $this->save( $store, $profile, $request );
            $out->redirect( $this->getPageTitle()->getLocalURL( [ 'saved' => 1 ] ) );
            return;
        }

        if ( $request->getVal( 'saved' ) ) {
            $out->addHTML( '<div class="pcp-banner" style="margin-bottom:1em;">' .
                '<span class="pcp-banner__title">Saved</span>' .
                '<span class="pcp-banner__body">Your profile has been updated.</span></div>' );
        }

        $this->renderHeader( $out, $store, $profile );
        $this->renderForm( $out, $store, $profile, $user );
    }

    // ===== Header / status =====

    private function renderHeader( $out, $store, $profile ) {
        $out->addHTML(
            '<div class="pcp-banner">' .
            '<span class="pcp-banner__title">Your profile</span>' .
            '<span class="pcp-banner__body">You. And your story. With unparalleled fidelity. Private as you need. Share as you please. ' .
            'Every entry can be private (user+sysop only) ' .
            '<span class="pcp-vis-key pcp-vis-private">🔒</span>, ' .
            'default public (public under your default alias) ' .
            '<span class="pcp-vis-key pcp-vis-default">👁</span>, ' .
            'public with your real username ' .
            '<span class="pcp-vis-key pcp-vis-username">🆔</span>, ' .
            'or totally anonymous (no byline) ' .
            '<span class="pcp-vis-key pcp-vis-anonymous">🎭</span>.<br><br>' .
            '<strong>Heads up:</strong> your public profile lives at <code>Special:UserProfile/' .
            htmlspecialchars( $this->getUser()->getName() ) . '</code>, so anything you mark ' .
            'public is associated with your username via the URL <em>regardless of the byline you choose</em>. ' .
            'The "no byline" option only hides the inline attribution line; it does not anonymize the page itself.' .
            '</span>' .
            '</div>'
        );
    }

    // ===== Form =====

    private function renderForm( $out, $store, $profile, $user ) {
        $action = $this->getPageTitle()->getLocalURL();
        $token  = htmlspecialchars( $user->getEditToken() );

        // Pull existing field values into a lookup
        $byKey = [];
        foreach ( $store->getFields( (int)$profile->prof_id ) as $f ) {
            $byKey[ $f->pf_namespace . ':' . $f->pf_key ] = $f;
        }

        ob_start();
        ?>
        <form method="post" action="<?= htmlspecialchars( $action ) ?>" class="pcp-prof-form">
            <input type="hidden" name="wpEditToken" value="<?= $token ?>">

            <fieldset class="pcp-prof-section is-collapsed">
                <legend>Public identity</legend>
                <div data-pcp-save-block="identity">

                <div class="pcp-prof-row">
                    <label for="public_alias">Public alias <small>(optional pseudonym)</small></label>
                    <input type="text" id="public_alias" name="public_alias"
                           maxlength="255"
                           autocomplete="off"
                           data-1p-ignore="true"
                           data-lpignore="true"
                           data-bwignore="true"
                           data-form-type="other"
                           value="<?= htmlspecialchars( (string)( $profile->prof_public_alias ?? '' ) ) ?>">
                </div>

                <div class="pcp-prof-row">
                    <label for="show_default">Default attribution for public fields</label>
                    <select name="show_default" id="show_default">
                        <?php $sd = (int)$profile->prof_show_default; ?>
                        <option value="0" <?= $sd === 0 ? 'selected' : '' ?>>Anonymous (no name shown)</option>
                        <option value="1" <?= $sd === 1 ? 'selected' : '' ?>>Show alias (if set)</option>
                        <option value="2" <?= $sd === 2 ? 'selected' : '' ?>>Show real username</option>
                        <option value="3" <?= $sd === 3 ? 'selected' : '' ?>>Always anonymous</option>
                    </select>
                </div>

                <div class="pcp-prof-row">
                    <?php $sx = (int)( $profile->prof_show_xr_on_profile ?? 0 ); ?>
                    <label for="show_xr_on_profile">Show my experience reports on my public profile?</label>
                    <select name="show_xr_on_profile" id="show_xr_on_profile">
                        <option value="0" <?= $sx === 0 ? 'selected' : '' ?>>No, keep my approved experience reports off my profile (default)</option>
                        <option value="1" <?= $sx === 1 ? 'selected' : '' ?>>Yes, list all my approved experience reports on my profile</option>
                    </select>
                    <small class="pcp-prof-help">When set to "No", approved experience reports remain visible on each medicine page (per the report's own approval) but are <em>not</em> aggregated under your profile URL. When "Yes", anyone visiting Special:UserProfile/<your name> can see the full list of medicines you have reported on plus efficacy/burden ratings.</small>
                </div>
                </div>
            </fieldset>

            <?php $this->renderDemographics( $byKey ); ?>
            <?php $this->renderOcean( $byKey ); ?>

            <?php $this->renderDiagnoses( $store, (int)$profile->prof_id ); ?>
            <?php $this->renderMeds( $store, $profile ); ?>

            <div class="pcp-prof-save">
                <button type="submit" class="mw-ui-button mw-ui-progressive">Save profile</button>
            </div>
        </form>
        <?php
        $out->addHTML( ob_get_clean() );
    }

    // ===== Helpers to render one field with visibility toggle =====

    /** Render a row with input + visibility toggle. */
    private function field( string $namespace, string $key, string $label, callable $inputRenderer, array $byKey, ?string $help = null ) {
        $existing = $byKey[ $namespace . ':' . $key ] ?? null;
        $vis = $existing ? (int)$existing->pf_visibility : 0;
        $domKey = $namespace . '__' . $key;
        echo '<div class="pcp-prof-field" data-key="' . htmlspecialchars( $namespace . ':' . $key ) . '">';
        echo '<label for="' . htmlspecialchars( $domKey ) . '">' . htmlspecialchars( $label ) . '</label>';
        echo '<div class="pcp-prof-input-wrap">';
        $inputRenderer( $domKey, $namespace, $key, $existing );
        echo '<button type="button" class="pcp-vis-toggle pcp-vis-' . $this->visClass( $vis ) .
             '" data-vis="' . $vis . '" data-name="v[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']"' .
             ' title="Privacy, click to cycle">' . $this->visIcon( $vis ) . '</button>';
        echo '<input type="hidden" name="v[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . $vis . '" class="pcp-vis-hidden">';
        echo '</div>';
        if ( $help ) {
            echo '<div class="pcp-prof-help"><small>' . htmlspecialchars( $help ) . '</small></div>';
        }
        echo '</div>';
    }

    private function visClass( int $v ): string {
        return [ 0 => 'private', 1 => 'default', 2 => 'username', 3 => 'anonymous' ][ $v ] ?? 'private';
    }
    private function visIcon( int $v ): string {
        return [ 0 => '🔒', 1 => '👁', 2 => '🆔', 3 => '🎭' ][ $v ] ?? '🔒';
    }


    /**
     * Render one assessment as a <details> block consistent with BFI-10's inline style.
     * Items POST as t[<key>][<n>]. Saving happens via the main "Save profile" button.
     */
    private function renderInlineAssessment( string $cls ) {
        $store = new UserProfileStore();
        $user = $this->getUser();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;
        $key = $cls::KEY;

        // Load existing raw + scores + meta
        $rawByN = []; $scores = []; $takenAt = null; $vis = 0;
        foreach ( $store->getFields( $profileId, $key . '_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) === 0 ) {
                $rawByN[ (int)substr( $k, 5 ) ] = [
                    'num'  => $f->pf_value_num,
                    'text' => $f->pf_value_text,
                ];
            }
        }
        foreach ( $store->getFields( $profileId, $key, 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )       { $vis = (int)( $f->pf_value_num ?? 0 ); continue; }
            if ( $fk === 'taken_at' )   { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num;
        }

        $totalItems = count( $cls::ITEMS );
        $statusBits = [];
        if ( $takenAt ) {
            // MW binary timestamp YYYYMMDDHHMMSS -> ISO date YYYY-MM-DD
            $statusBits[] = 'last taken ' . substr( $takenAt, 0, 4 ) . '-' . substr( $takenAt, 4, 2 ) . '-' . substr( $takenAt, 6, 2 );
        }
        if ( isset( $scores['total'] ) ) {
            $statusBits[] = 'total ' . $scores['total'];
        }
        // Escape each bit individually; the &middot; joiner is intentional HTML entity (renders as middle dot)
        $escapedBits = array_map( 'htmlspecialchars', $statusBits );
        $statusTxt = $statusBits ? ' &middot; ' . implode( ' &middot; ', $escapedBits ) : ' &middot; <em>not yet taken</em>';

        $summary = '&#x1F4DD; Take the ' . htmlspecialchars( $cls::NAME ) . ' test (' . $totalItems . ' items)' . $statusTxt;

        echo '<details class="pcp-assess-inline">';
        echo '<summary class="pcp-assess-summary">' . $summary . '</summary>';
        echo '<div class="pcp-assess-body" data-pcp-save-block="assessment-' . htmlspecialchars( $key ) . '">';
        echo '<p class="pcp-prof-help"><small>' . htmlspecialchars( $cls::DESCRIPTION )
             . ' <em>Source: ' . htmlspecialchars( $cls::CITATION ) . '</em>';
        // Rich-report link + share chip folded into the description paragraph (mirrors Enneagram/MBTI style).
        if ( in_array( $cls::KEY, [ 'cati', 'catq', 'pid5bf', 'nfcs', 'bpns', 'whoqolbref' ], true ) ) {
            $reportUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', $cls::KEY )->getLocalURL();
            $shareUrl  = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', $cls::KEY )->getFullURL( [ 'user' => $this->getUser()->getName() ] );
            echo ' <a class="pcp-cati-report-link" href="' . htmlspecialchars( $reportUrl ) . '">View full ' . htmlspecialchars( $cls::NAME ) . ' report →</a>';
            echo ' <button type="button" class="pcp-share-chip" data-share-url="' . htmlspecialchars( $shareUrl ) . '" data-assessment-key="' . htmlspecialchars( $cls::KEY ) . '" title="Copy a shareable link to this report">🔗 Share</button>';
        }
        echo '</small></p>';

        if ( $cls::WARNING ) {
            echo '<p class="pcp-prof-help"><small><strong>Heads up:</strong> '
                 . htmlspecialchars( $cls::WARNING ) . '</small></p>';
        }

        // Current scores summary (if any)
        if ( $scores ) {
            echo '<div class="pcp-assess-scores-current">';
            echo '<table class="pcp-pa-table"><tbody>';
            foreach ( $cls::SUBSCALES as $sk => $def ) {
                $v = $scores[ 'subscale_' . $sk ] ?? null;
                echo '<tr><th>' . htmlspecialchars( $def["label"] ) . '</th>'
                     . '<td>' . ( $v === null ? "-" : htmlspecialchars( (string)$v ) ) . '</td></tr>';
            }
            if ( isset( $scores["total"] ) ) {
                echo '<tr><th>Total</th><td>' . htmlspecialchars( (string)$scores["total"] ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><em>' . htmlspecialchars( $cls::interpret( $scores ) ) . '</em></p>';
            // (Rich-report link is now rendered at the top of the body, see above.)
            echo '</div>';
        }

        // Per-test visibility dropdowns (summary scores + raw responses).
        // Read existing raw _vis (defaults to private when missing).
        $visRaw = 0;
        foreach ( $store->getFields( $profileId, $key . '_raw', 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $visRaw = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        echo '<div class="pcp-assess-vis">';
        echo '<label>Visibility for ' . htmlspecialchars( $cls::NAME ) . ' <strong>summary scores</strong>: ';
        echo '<select name="tv[' . htmlspecialchars( $key ) . ']">';
        foreach ( [ 0 => "Private", 1 => "Public (default attribution)", 2 => "Public (username)", 3 => "Public (anonymous)" ] as $vv => $lab ) {
            $sel = $vis === $vv ? " selected" : "";
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '<br><label class="pcp-assess-vis-raw">Visibility for <strong>raw item responses</strong> (used in the report\'s top-items + full response table): ';
        echo '<select name="tvr[' . htmlspecialchars( $key ) . ']">';
        foreach ( [ 0 => "Private", 1 => "Public (default attribution)", 2 => "Public (username)", 3 => "Public (anonymous)" ] as $vv => $lab ) {
            $sel = $visRaw === $vv ? " selected" : "";
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';

        // Items: PID-5-BF and CAT-Q use continuous sliders + Not sure; others use radio buttons
        $sliderTests = [
            \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class => [
                'min' => 0, 'max' => 3, 'step' => 0.01, 'default' => 1.5,
                'lo' => 'Completely false', 'hi' => 'Completely true',
            ],
            \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class => [
                'min' => 1, 'max' => 7, 'step' => 0.01, 'default' => 4,
                'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
            ],
            \MediaWiki\Extension\Pharmacopedia\Assessments\Cati::class => [
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'lo' => 'Definitely Disagree', 'hi' => 'Definitely Agree',
            ],
            \MediaWiki\Extension\Pharmacopedia\Assessments\Nfcs::class => [
                'min' => 1, 'max' => 6, 'step' => 0.01, 'default' => 3.5,
                'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
            ],
            \MediaWiki\Extension\Pharmacopedia\Assessments\Bpns::class => [
                'min' => 1, 'max' => 7, 'step' => 0.01, 'default' => 4,
                'lo' => 'Not at all true', 'hi' => 'Very true',
            ],
            \MediaWiki\Extension\Pharmacopedia\Assessments\WhoqolBref::class => [
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'lo' => 'Very poor / dissatisfied / not at all', 'hi' => 'Very good / satisfied / completely',
            ],
        ];
        if ( isset( $sliderTests[ $cls ] ) ) {
            $sp = $sliderTests[ $cls ];
            echo '<ol class="pcp-assess-items">';
            foreach ( $cls::ITEMS as $n => $text ) {
                $entry    = $rawByN[ $n ] ?? null;
                $isUnsure = is_array( $entry ) && (string)( $entry['text'] ?? '' ) === 'unsure';
                $hasNum   = is_array( $entry ) && $entry['num'] !== null;
                $val      = $hasNum && !$isUnsure ? (float)$entry['num'] : (float)$sp['default'];
                $valStr   = number_format( $val, 2 );
                $unsureCls = $isUnsure ? ' pcp-unsure' : '';
                $disabled  = $isUnsure ? ' disabled' : '';
                $checkAttr = $isUnsure ? ' checked' : '';
                echo '<li class="pcp-pid-item' . $unsureCls . '" data-itemnum="' . (int)$n . '">';
                echo '<div class="pcp-assess-item-text">' . (int)$n . '. ' . htmlspecialchars( $text ) . '</div>';
                echo '<div class="pcp-pid-slider-row">';
                echo '<span class="pcp-pid-anchor pcp-pid-anchor-low">' . htmlspecialchars( $sp['lo'] ) . '</span>';
                echo '<input type="range" class="pcp-pid-slider" name="t[' . $key . '][' . $n . ']" '
                    . 'min="' . $sp['min'] . '" max="' . $sp['max'] . '" step="' . $sp['step'] . '" '
                    . 'value="' . $valStr . '"' . $disabled . '>';
                echo '<output class="pcp-pid-out">' . $valStr . '</output>';
                echo '<span class="pcp-pid-anchor pcp-pid-anchor-high">' . htmlspecialchars( $sp['hi'] ) . '</span>';
                echo '</div>';
                echo '<label class="pcp-pid-unsure">';
                echo '<input type="checkbox" name="t_unsure[' . $key . '][' . $n . ']" value="1"' . $checkAttr . '> Not sure';
                echo '</label>';
                echo '</li>';
            }
            echo '</ol>';
        } else {
            echo '<ol class="pcp-assess-items">';
            foreach ( $cls::ITEMS as $n => $text ) {
                $entry = $rawByN[ $n ] ?? null;
                $cur   = is_array( $entry ) && $entry['num'] !== null ? (string)(int)$entry['num'] : '';
                echo '<li class="pcp-assess-item-inline" data-itemnum="' . (int)$n . '">';
                echo '<div class="pcp-assess-item-text">' . (int)$n . '. ' . htmlspecialchars( $text ) . '</div>';
                echo '<div class="pcp-assess-item-choices">';
                foreach ( $cls::RESPONSE_LABELS as $val => $label ) {
                    $id = 'inl_' . $key . '_' . $n . '_' . $val;
                    $checked = ( (string)$cur === (string)$val ) ? ' checked' : '';
                    echo '<label for="' . $id . '">'
                        . '<input type="radio" id="' . $id . '" name="t[' . $key . '][' . $n . ']" value="' . $val . '"' . $checked . '> '
                        . htmlspecialchars( $label ) . '</label>';
                }
                echo '</div>';
                echo '</li>';
            }
            echo '</ol>';
        }
        echo '<p class="pcp-prof-help"><small>Responses are saved when you click <strong>Save profile</strong> at the bottom of the page. Partial responses are allowed; subscale scores are computed from whatever items you have answered.</small></p>';
        echo '</div></details>';
    }

    /**
     * Compute integer age in years from a birthday value. Accepts either a legacy
     * YYYY-MM-DD string OR a JSON-encoded pcp-date-input struct. For range/possibility
     * structs, uses the earliest ISO (sortKeyIso), i.e. the oldest possible age.
     */
    public static function computeAge( $birthday ): ?int {
        if ( $birthday === null || $birthday === '' ) return null;
        if ( is_string( $birthday ) && $birthday !== '' && $birthday[0] === '{' ) {
            $struct = json_decode( $birthday, true );
            if ( is_array( $struct ) ) {
                $iso = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $struct );
                return $iso ? self::computeAgeFromIso( $iso ) : null;
            }
        }
        if ( is_array( $birthday ) ) {
            $iso = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $birthday );
            return $iso ? self::computeAgeFromIso( $iso ) : null;
        }
        return self::computeAgeFromIso( (string)$birthday );
    }
    private static function computeAgeFromIso( string $iso ): ?int {
        if ( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) return null;
        try {
            $b = new \DateTimeImmutable( $iso );
            $today = new \DateTimeImmutable( 'today' );
            if ( $b > $today ) return null;
            return (int)$today->diff( $b )->y;
        } catch ( \Exception $e ) { return null; }
    }

    // ===== Demographics section =====

    /**
     * Helper: render a chip-picker widget that submits a JSON array.
     */
    private function chipPickerRenderer( string $source, bool $multi, bool $allowCustom, bool $allowPrimary, ?string $browserFill = null, string $placeholder = 'Type to add…' ): callable {
        return function ( $domKey, $namespace, $key, $existing ) use ( $source, $multi, $allowCustom, $allowPrimary, $browserFill, $placeholder ) {
            $val = $existing ? (string)$existing->pf_value_text : '';
            if ( $val === '' ) $val = '';
            echo '<span class="pcp-chip-picker"'
                . ' data-source="' . htmlspecialchars( $source ) . '"'
                . ' data-multi="' . ( $multi ? '1' : '0' ) . '"'
                . ' data-allow-custom="' . ( $allowCustom ? '1' : '0' ) . '"'
                . ' data-allow-primary="' . ( $allowPrimary ? '1' : '0' ) . '"'
                . ' data-browser-fill="' . htmlspecialchars( $browserFill ?? '0' ) . '">';
            echo '<span class="pcp-chip-list"></span>';
            echo '<input type="text" class="pcp-chip-input" placeholder="' . htmlspecialchars( $placeholder ) . '">';
            echo '<span class="pcp-chip-suggest" style="display:none;"></span>';
            echo '<input type="hidden" class="pcp-chip-value" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $val ) . '">';
            echo '</span>';
        };
    }

    private function renderDemographics( array $byKey ) {
        echo '<fieldset class="pcp-prof-section is-collapsed"><legend>Demographics <a href="#" class="pcp-share-trigger" data-ns="demographics" data-label="Demographics">🔗 Share</a></legend>';
        echo '<div data-pcp-save-block="demographics">';

        // Inline JS datasets (countries, languages, picklists)
        $datasets = \MediaWiki\Extension\Pharmacopedia\ProfileDatasets::bundleForJs();
        echo '<script>window.PCP_DATASETS = '
            . json_encode( $datasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            . ';</script>';

        $text = function ( $domKey, $namespace, $key, $existing ) {
            $val = $existing ? htmlspecialchars( (string)$existing->pf_value_text ) : '';
            echo '<input type="text" id="' . htmlspecialchars( $domKey ) . '" name="f[' . $namespace . '][' . $key . ']" value="' . $val . '">';
        };
        $num = function ( $domKey, $namespace, $key, $existing, $min = null, $max = null, $step = 1 ) {
            $val = ( $existing && $existing->pf_value_num !== null ) ? (float)$existing->pf_value_num : '';
            $extra = '';
            if ( $min !== null ) $extra .= ' min="' . $min . '"';
            if ( $max !== null ) $extra .= ' max="' . $max . '"';
            $extra .= ' step="' . $step . '"';
            echo '<input type="number" id="' . htmlspecialchars( $domKey ) . '" name="f[' . $namespace . '][' . $key . ']" value="' . ($val === '' ? '' : htmlspecialchars((string)$val)) . '"' . $extra . '>';
        };
        $select = function ( $options ) {
            return function ( $domKey, $namespace, $key, $existing ) use ( $options ) {
                $val = $existing ? (string)$existing->pf_value_text : '';
                echo '<select id="' . htmlspecialchars( $domKey ) . '" name="f[' . $namespace . '][' . $key . ']">';
                echo '<option value="">-</option>';
                foreach ( $options as $optVal => $optLabel ) {
                    $sel = $val === (string)$optVal ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars( (string)$optVal ) . '"' . $sel . '>' . htmlspecialchars( $optLabel ) . '</option>';
                }
                echo '</select>';
            };
        };

        $birthday = function ( $domKey, $namespace, $key, $existing ) {
            $val = $existing ? (string)$existing->pf_value_text : '';
            $initial = null;
            if ( $val !== '' ) {
                if ( $val[0] === '{' ) {
                    $decoded = json_decode( $val, true );
                    if ( is_array( $decoded ) ) $initial = $decoded;
                } else {
                    $initial = \MediaWiki\Extension\Pharmacopedia\DatePicker::structFromIso( $val );
                }
            }
            echo \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget(
                'f[' . $namespace . '][' . $key . ']',
                $initial
            );
            if ( $val !== '' ) {
                $age = \MediaWiki\Extension\Pharmacopedia\SpecialMyProfile::computeAge( $val );
                if ( $age !== null ) {
                    echo ' <span class="pcp-prof-help"><small>current age: ' . (int)$age . '</small></span>';
                }
            }
        };

        $this->field( 'demographics', 'birthday', 'Birthday', $birthday, $byKey,
            'Stored as date; age is computed automatically on display.' );

        $this->field( 'demographics', 'sex_at_birth', 'Sex assigned at birth',
            $select( [
                'female' => 'Female',
                'male' => 'Male',
                'intersex' => 'Intersex',
                'prefer_not_say' => 'Prefer not to say',
            ] ), $byKey );

        $this->field( 'demographics', 'gender_identity', 'Gender identity',
            $this->chipPickerRenderer( 'genders', true, true, false, null, 'Type or pick a gender identity…' ),
            $byKey,
            'Pick from the list, or type your own and press Enter. Multiple allowed.' );

        $this->field( 'demographics', 'pronouns', 'Pronouns',
            $this->chipPickerRenderer( 'pronouns', true, true, false, null, 'Type or pick a pronoun set…' ),
            $byKey,
            'e.g., she/her, they/them, she/they. Custom sets allowed.' );

        $this->field( 'demographics', 'ethnicity', 'Ethnicity / race',
            $this->chipPickerRenderer( 'ethnicities', true, true, false, null, 'Type or pick an ethnicity / race category…' ),
            $byKey,
            'Multiple allowed. Use the free-text option (press Enter on what you typed) for sub-group specification.' );

        $this->field( 'demographics', 'country', 'Country of residence',
            $this->chipPickerRenderer( 'countries', false, true, false, 'country', 'Type a country name or code…' ),
            $byKey,
            'Auto-suggested from your browser locale; pick from the list or type your own.' );

        $this->field( 'demographics', 'languages', 'Languages',
            $this->chipPickerRenderer( 'languages', true, true, true, 'language', 'Type a language or code…' ),
            $byKey,
            'Multiple allowed; ★ marks your primary. Auto-suggested from your browser languages.' );

        // ----- Units preference + height + weight (from prior install) -----
        $unitsExisting = $byKey['demographics:units'] ?? null;
        $unitsVal = ( $unitsExisting && $unitsExisting->pf_value_text )
            ? (string)$unitsExisting->pf_value_text : 'metric';

        $unitsRenderer = function ( $domKey, $namespace, $key, $existing ) use ( $unitsVal ) {
            echo '<select id="' . htmlspecialchars( $domKey ) . '" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" class="pcp-units-select">';
            foreach ( [ 'metric' => 'Metric (cm / kg)', 'us' => 'US Standard (ft+in / lb)' ] as $v => $l ) {
                $sel = $unitsVal === $v ? ' selected' : '';
                echo '<option value="' . htmlspecialchars( $v ) . '"' . $sel . '>' . htmlspecialchars( $l ) . '</option>';
            }
            echo '</select>';
        };
        $this->field( 'demographics', 'units', 'Units', $unitsRenderer, $byKey,
            'How height and weight are entered on this form. Stored internally as cm and kg either way.' );

        $heightWidget = function ( $domKey, $namespace, $key, $existing ) use ( $unitsVal ) {
            $cm = ( $existing && $existing->pf_value_num !== null ) ? (float)$existing->pf_value_num : null;
            $cmStr = $cm === null ? '' : rtrim( rtrim( number_format( $cm, 2, '.', '' ), '0' ), '.' );
            $ft = ''; $inches = '';
            if ( $cm !== null ) {
                $totalIn = $cm / 2.54;
                $ft = (int)floor( $totalIn / 12 );
                $inches = number_format( $totalIn - ( $ft * 12 ), 2 );
            }
            echo '<span class="pcp-units-widget" data-units="' . htmlspecialchars( $unitsVal ) . '" data-kind="height">';
            echo '<span class="pcp-units-metric"><input type="number" class="pcp-units-cm" min="0" max="300" step="0.01" value="' . htmlspecialchars( $cmStr ) . '"> cm</span>';
            echo '<span class="pcp-units-us"><input type="number" class="pcp-units-ft" min="0" max="9" step="1" value="' . htmlspecialchars( (string)$ft ) . '"> ft <input type="number" class="pcp-units-in" min="0" max="11.99" step="0.01" value="' . htmlspecialchars( (string)$inches ) . '"> in</span>';
            echo '<input type="hidden" class="pcp-units-hidden" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $cmStr ) . '">';
            echo '</span>';
        };
        $this->field( 'demographics', 'height_cm', 'Height', $heightWidget, $byKey );

        $weightWidget = function ( $domKey, $namespace, $key, $existing ) use ( $unitsVal ) {
            $kg = ( $existing && $existing->pf_value_num !== null ) ? (float)$existing->pf_value_num : null;
            $kgStr = $kg === null ? '' : rtrim( rtrim( number_format( $kg, 2, '.', '' ), '0' ), '.' );
            $lb = '';
            if ( $kg !== null ) {
                $lb = number_format( $kg * 2.20462, 2 );
            }
            echo '<span class="pcp-units-widget" data-units="' . htmlspecialchars( $unitsVal ) . '" data-kind="weight">';
            echo '<span class="pcp-units-metric"><input type="number" class="pcp-units-kg" min="0" max="500" step="0.01" value="' . htmlspecialchars( $kgStr ) . '"> kg</span>';
            echo '<span class="pcp-units-us"><input type="number" class="pcp-units-lb" min="0" max="1102" step="0.01" value="' . htmlspecialchars( (string)$lb ) . '"> lb</span>';
            echo '<input type="hidden" class="pcp-units-hidden" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $kgStr ) . '">';
            echo '</span>';
        };
        $this->field( 'demographics', 'weight_kg', 'Weight', $weightWidget, $byKey );

        $this->field( 'demographics', 'handedness', 'Handedness',
            $select( [
                'right' => 'Right-handed',
                'left' => 'Left-handed',
                'mixed' => 'Mixed / ambidextrous',
            ] ), $byKey );

        // ----- Smoking, structured widget (status + cigs/day + years + quit date; computes pack-years) -----
        $smokingWidget = function ( $domKey, $namespace, $key, $existing ) {
            $cur = [ 'status' => '', 'cigs_per_day' => '', 'years_smoked' => '', 'quit_date' => '' ];
            if ( $existing && $existing->pf_value_text ) {
                $j = json_decode( (string)$existing->pf_value_text, true );
                if ( is_array( $j ) ) { foreach ( $cur as $k => $v ) if ( isset( $j[ $k ] ) ) $cur[ $k ] = $j[ $k ]; }
            }
            $jsonRaw = $existing ? (string)$existing->pf_value_text : '';
            echo '<span class="pcp-smoking-widget" data-status="' . htmlspecialchars( (string)$cur['status'] ) . '">';
            echo '<span class="pcp-smoking-row"><label>Status</label>';
            echo '<select class="pcp-smoking-status">';
            foreach ( [ '' => '-', 'never' => 'Never smoker', 'former' => 'Former smoker', 'current' => 'Current smoker' ] as $v => $l ) {
                $sel = (string)$cur['status'] === (string)$v ? ' selected' : '';
                echo '<option value="' . htmlspecialchars( (string)$v ) . '"' . $sel . '>' . htmlspecialchars( $l ) . '</option>';
            }
            echo '</select></span>';
            echo '<span class="pcp-smoking-row pcp-smoking-conditional">';
            echo '<label>Cigarettes/day</label><input type="number" class="pcp-smoking-cigs" min="0" max="100" step="1" value="' . htmlspecialchars( (string)$cur['cigs_per_day'] ) . '"> ';
            echo '<label>Years smoked</label><input type="number" class="pcp-smoking-years" min="0" max="100" step="0.5" value="' . htmlspecialchars( (string)$cur['years_smoked'] ) . '">';
            echo '<span class="pcp-smoking-packyears"></span>';
            echo '</span>';
            echo '<span class="pcp-smoking-row pcp-smoking-conditional pcp-smoking-quit-row">';
            $quitIso = htmlspecialchars( (string)$cur['quit_date'] );
            $quitInit = $quitIso !== '' ? htmlspecialchars( json_encode( [ 'kind' => 'point', 'point' => [ 'raw_text' => $cur['quit_date'], 'parsed' => [ 'kind' => 'point', 'precision' => 'day', 'iso' => $cur['quit_date'] ] ] ] ) ) : '';
            echo '<label>Quit date</label>';
            echo '<div class="pcp-date-input pcp-smoking-quit-picker" data-name="_smoking_quit_picker" data-lock-mode="point"' . ( $quitInit !== '' ? ' data-initial=\''.$quitInit.'\'' : '' ) . '></div>';
            echo '<input type="hidden" class="pcp-smoking-quit" value="' . $quitIso . '">';
            echo '</span>';
            echo '<input type="hidden" class="pcp-smoking-hidden" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $jsonRaw ) . '">';
            echo '</span>';
        };
        $this->field( 'demographics', 'smoking', 'Smoking', $smokingWidget, $byKey,
            'Pack-years auto-computed as (cigs/day ÷ 20) × years smoked.' );

        // ----- Alcohol, structured widget (drinks/week + typical type + max one occasion) -----
        $alcoholWidget = function ( $domKey, $namespace, $key, $existing ) {
            $cur = [ 'drinks_per_week' => '', 'typical_drink' => '', 'max_one_occasion' => '' ];
            if ( $existing && $existing->pf_value_text ) {
                $j = json_decode( (string)$existing->pf_value_text, true );
                if ( is_array( $j ) ) { foreach ( $cur as $k => $v ) if ( isset( $j[ $k ] ) ) $cur[ $k ] = $j[ $k ]; }
            }
            $jsonRaw = $existing ? (string)$existing->pf_value_text : '';
            echo '<span class="pcp-alc-widget">';
            echo '<span class="pcp-alc-row"><label>Drinks per week</label><input type="number" class="pcp-alc-week" min="0" max="200" step="0.5" value="' . htmlspecialchars( (string)$cur['drinks_per_week'] ) . '"></span>';
            echo '<span class="pcp-alc-row"><label>Typical drink</label><select class="pcp-alc-typical">';
            foreach ( [ '' => '-', 'beer' => 'Beer', 'wine' => 'Wine', 'spirits' => 'Spirits', 'mixed' => 'Mixed' ] as $v => $l ) {
                $sel = (string)$cur['typical_drink'] === (string)$v ? ' selected' : '';
                echo '<option value="' . htmlspecialchars( (string)$v ) . '"' . $sel . '>' . htmlspecialchars( $l ) . '</option>';
            }
            echo '</select></span>';
            echo '<span class="pcp-alc-row"><label>Max one occasion (drinks)</label><input type="number" class="pcp-alc-max" min="0" max="50" step="1" value="' . htmlspecialchars( (string)$cur['max_one_occasion'] ) . '"></span>';
            echo '<input type="hidden" class="pcp-alc-hidden" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $jsonRaw ) . '">';
            echo '</span>';
        };
        $this->field( 'demographics', 'alcohol', 'Alcohol use', $alcoholWidget, $byKey,
            '1 standard drink ≈ 14 g pure ethanol (12 oz beer, 5 oz wine, 1.5 oz spirits).' );

        // ----- Education: existing select + years + field -----
        $this->field( 'demographics', 'education', 'Education, highest completed',
            $select( [
                'less_high_school' => 'Less than high school',
                'high_school' => 'High school / GED',
                'some_college' => 'Some college',
                'associates' => 'Associate degree',
                'bachelors' => 'Bachelor\'s degree',
                'masters' => 'Master\'s degree',
                'doctorate' => 'Doctorate / PhD / professional',
            ] ), $byKey );

        $this->field( 'demographics', 'education_years', 'Years of formal schooling',
            function ( $a, $b, $c, $d ) use ( $num ) { $num( $a, $b, $c, $d, 0, 40, 1 ); },
            $byKey, 'Total years of school, kindergarten through highest degree.' );

        $this->field( 'demographics', 'education_field', 'Field of study', $text, $byKey,
            'Free text, e.g., Neuroscience, Industrial Design, Pre-med.' );

        // ----- Employment: existing select + occupation + hours -----
        $this->field( 'demographics', 'employment', 'Employment status',
            $select( [
                'employed_full' => 'Employed full-time',
                'employed_part' => 'Employed part-time',
                'self_employed' => 'Self-employed',
                'student' => 'Student',
                'unemployed_seeking' => 'Unemployed, seeking',
                'unemployed_not_seeking' => 'Unemployed, not seeking',
                'disabled' => 'On disability',
                'retired' => 'Retired',
                'homemaker' => 'Homemaker / caregiver',
            ] ), $byKey );

        $this->field( 'demographics', 'occupation', 'Occupation / title', $text, $byKey,
            'Free text, e.g., Software engineer, Pediatric nurse, Bartender, PhD candidate.' );

        $this->field( 'demographics', 'hours_per_week', 'Typical hours per week',
            function ( $a, $b, $c, $d ) use ( $num ) { $num( $a, $b, $c, $d, 0, 168, 1 ); },
            $byKey, 'Across all paid + unpaid work.' );

        // ----- Income: numeric + currency + scope -----
        $currencies = \MediaWiki\Extension\Pharmacopedia\ProfileDatasets::currencies();
        $this->field( 'demographics', 'income_amount', 'Income (annual)',
            function ( $a, $b, $c, $d ) use ( $num ) { $num( $a, $b, $c, $d, 0, null, 1 ); },
            $byKey, 'Numeric. Pair with currency + scope below.' );

        $this->field( 'demographics', 'income_currency', 'Income currency',
            $select( $currencies ), $byKey );

        $this->field( 'demographics', 'income_scope', 'Income scope',
            $select( [
                'individual' => 'Individual',
                'household'  => 'Household',
            ] ), $byKey );

        // ----- New fields -----
        $this->field( 'demographics', 'marital_status', 'Relationship status',
            $this->chipPickerRenderer( 'marital', false, true, false, null, 'Type or pick a status…' ),
            $byKey );

        $this->field( 'demographics', 'religion', 'Religion / spirituality',
            $this->chipPickerRenderer( 'religions', false, true, false, null, 'Type or pick a tradition / stance…' ),
            $byKey );

        $this->field( 'demographics', 'housing', 'Housing situation',
            $this->chipPickerRenderer( 'housing', false, true, false, null, 'Type or pick a housing situation…' ),
            $byKey );

        $this->field( 'demographics', 'children_count', 'Number of children',
            function ( $a, $b, $c, $d ) use ( $num ) { $num( $a, $b, $c, $d, 0, 30, 1 ); },
            $byKey );

        $this->field( 'demographics', 'time_zone', 'Time zone', $text, $byKey,
            'Auto-detected from your browser if empty. IANA name, e.g. "America/New_York".' );

        // ----- Chronotype -----
        $chronotypeWidget = function ( $domKey, $namespace, $key, $existing ) {
            $cur = [ 'bedtime' => '', 'waketime' => '' ];
            if ( $existing && $existing->pf_value_text ) {
                $j = json_decode( (string)$existing->pf_value_text, true );
                if ( is_array( $j ) ) { foreach ( $cur as $k => $v ) if ( isset( $j[ $k ] ) ) $cur[ $k ] = $j[ $k ]; }
            }
            // Two visible time inputs + hidden JSON aggregated on input change via a small inline JS handler
            $bed = htmlspecialchars( (string)$cur['bedtime'] );
            $wake = htmlspecialchars( (string)$cur['waketime'] );
            $jsonRaw = $existing ? (string)$existing->pf_value_text : '';
            echo '<span class="pcp-chronotype-widget">';
            echo '<span class="pcp-chronotype-row"><label>Typical bedtime</label>';
            echo '<div class="pcp-time-input pcp-chronotype-bed-picker" data-name="_chronotype_bed_picker"' . ( $bed !== '' ? ' data-initial="' . $bed . '"' : '' ) . '></div>';
            echo '<input type="hidden" class="pcp-chronotype-bed" value="' . $bed . '">';
            echo '<label>Typical wake</label>';
            echo '<div class="pcp-time-input pcp-chronotype-wake-picker" data-name="_chronotype_wake_picker"' . ( $wake !== '' ? ' data-initial="' . $wake . '"' : '' ) . '></div>';
            echo '<input type="hidden" class="pcp-chronotype-wake" value="' . $wake . '">';
            echo '</span>';
            echo '<input type="hidden" class="pcp-chronotype-hidden" name="f[' . htmlspecialchars( $namespace ) . '][' . htmlspecialchars( $key ) . ']" value="' . htmlspecialchars( $jsonRaw ) . '">';
            echo '</span>';
        };
        $this->field( 'demographics', 'chronotype', 'Sleep schedule', $chronotypeWidget, $byKey,
            'Free-day (not workday) typical times. Used later for chronotype derivation.' );

        // ----- Political compass (2 axes, -100 to +100) -----
        $politicalWidget = function ( $domKey, $namespace, $key, $existing ) use ( $byKey ) {
            $econRow = $byKey['demographics:political_economic'] ?? null;
            $socRow  = $byKey['demographics:political_social']   ?? null;
            $econ = $econRow && $econRow->pf_value_num !== null ? (float)$econRow->pf_value_num : 0.0;
            $soc  = $socRow  && $socRow->pf_value_num  !== null ? (float)$socRow->pf_value_num  : 0.0;
            echo '<span class="pcp-political-widget">';
            echo '<span class="pcp-political-axis">';
            echo '<span class="pcp-political-anchor pcp-political-anchor-left">Left / state</span>';
            echo '<input type="range" name="f[demographics][political_economic]" min="-100" max="100" step="1" value="' . htmlspecialchars( (string)$econ ) . '" oninput="this.nextElementSibling.value=this.value">';
            echo '<output>' . htmlspecialchars( (string)$econ ) . '</output>';
            echo '<span class="pcp-political-anchor pcp-political-anchor-right">Right / market</span>';
            echo '</span>';
            echo '<span class="pcp-political-axis">';
            echo '<span class="pcp-political-anchor pcp-political-anchor-left">Libertarian</span>';
            echo '<input type="range" name="f[demographics][political_social]" min="-100" max="100" step="1" value="' . htmlspecialchars( (string)$soc ) . '" oninput="this.nextElementSibling.value=this.value">';
            echo '<output>' . htmlspecialchars( (string)$soc ) . '</output>';
            echo '<span class="pcp-political-anchor pcp-political-anchor-right">Authoritarian</span>';
            echo '</span>';
            echo '<input type="hidden" name="f[demographics][' . htmlspecialchars( $key ) . ']" value="political-compass">';
            echo '</span>';
        };
        $this->field( 'demographics', 'political', 'Political orientation', $politicalWidget, $byKey,
            'Two-axis self-placement (Political Compass). Economic: state-driven ↔ market-driven. Social: libertarian ↔ authoritarian.' );

        echo '</div></fieldset>';
    }

    // ===== OCEAN section =====

    private function renderOcean( array $byKey ) {
        echo '<fieldset class="pcp-prof-section is-collapsed"><legend>Personality</legend>';
        echo '<p class="pcp-prof-help"><small>Every assessment shares the same privacy model: a <strong>summary scores</strong> visibility (controls the public summary card) and a <strong>raw item responses</strong> visibility (controls the per-item breakdown in the full report). Defaults are private until you opt in.</small></p>';

        // OCEAN trait sliders: kept in DOM (hidden via CSS) so the BFI-10
        // 'Compute & fill OCEAN sliders' button can still write to them and
        // the form save still includes f[ocean][O..N]. User-facing chrome
        // lives inside the BFI-10 block below.
        $slider = function ( $domKey, $namespace, $key, $existing ) {
            $val = ( $existing && $existing->pf_value_num !== null ) ? (int)$existing->pf_value_num : 50;
            echo '<input type="range" id="' . htmlspecialchars( $domKey ) . '" name="f[' . $namespace . '][' . $key . ']" min="0" max="100" step="1" value="' . $val . '" oninput="this.nextElementSibling.value=this.value">';
            echo '<output>' . $val . '</output>';
        };
        echo '<div class="pcp-ocean-hidden" style="display:none;" data-pcp-save-block="ocean">';
        $this->field( 'ocean', 'O', 'Openness, curiosity, imagination, willingness to try new', $slider, $byKey );
        $this->field( 'ocean', 'C', 'Conscientiousness, organization, discipline, dependability', $slider, $byKey );
        $this->field( 'ocean', 'E', 'Extraversion, sociability, energy from being around people', $slider, $byKey );
        $this->field( 'ocean', 'A', 'Agreeableness, empathy, cooperation, trust', $slider, $byKey );
        $this->field( 'ocean', 'N', 'Neuroticism, emotional reactivity, tendency toward negative affect', $slider, $byKey );
        echo '</div>';

        // BFI-10 personality test (Rammstedt & John 2007), public domain.
        // Sibling of OCEAN inside the Assessments fieldset.
        $bfi10Answered = 0;
        for ( $i = 0; $i < 10; $i++ ) {
            $k = 'bfi10:item_' . $i;
            if ( isset( $byKey[ $k ] ) ) $bfi10Answered++;
        }
        $bfi10Status = $bfi10Answered > 0 ? ' &middot; ' . $bfi10Answered . '/10 items answered' : ' &middot; <em>not yet taken</em>';
        // Existing raw _vis for bfi10
        $bfi10RawVis = 0;
        $bfi10Store = new UserProfileStore();
        $bfi10Profile = $bfi10Store->getOrCreateForUser( $this->getUser()->getId() );
        foreach ( $bfi10Store->getFields( (int)$bfi10Profile->prof_id, 'bfi10_raw', 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $bfi10RawVis = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        // OCEAN status (number of derived traits set) feeds the summary line.
        $oceanFilled = 0;
        foreach ( [ 'O', 'C', 'E', 'A', 'N' ] as $tr ) {
            if ( isset( $byKey[ 'ocean:' . $tr ] ) ) $oceanFilled++;
        }
        $oceanStatus = $oceanFilled === 5 ? '5/5 traits set' : ( $oceanFilled > 0 ? $oceanFilled . '/5 traits set' : 'not yet computed' );
        $oceanReportUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'ocean' )->getLocalURL();
        $oceanShareUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'ocean' )->getFullURL( [ 'user' => $this->getUser()->getName() ] );
        // Summary scores visibility for the OCEAN-derived values (uses tv[ocean]).
        $oceanSumVis = 0;
        foreach ( $bfi10Store->getFields( (int)$bfi10Profile->prof_id, 'ocean', 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $oceanSumVis = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        echo '<details class="pcp-assess-inline"><summary class="pcp-assess-summary">&#x1F4DD; Big Five (OCEAN) / BFI-10 (10 items, ~1 min, auto-fills your Big Five) &middot; ' . $bfi10Status . ' &middot; ' . $oceanStatus . '</summary>';
        echo '<div class="pcp-assess-body pcp-bfi10" data-pcp-save-block="bfi10">';
        echo '<p class="pcp-prof-help pcp-report-toplink"><small><a href="' . htmlspecialchars( $oceanReportUrl ) . '">View full Big Five report &rarr;</a></small> ';
        echo '<button type="button" class="pcp-share-chip" data-share-url="' . htmlspecialchars( $oceanShareUrl ) . '" data-assessment-key="ocean" title="Copy a shareable link to this report">🔗 Share</button>';
        echo '</p>';
        echo '<p class="pcp-bfi10-instructions">I see myself as someone who&hellip;</p>';
        $items = [
            [ "is reserved",                                           "E", true  ],
            [ "is generally trusting",                                 "A", false ],
            [ "tends to be lazy",                                      "C", true  ],
            [ "is relaxed, handles stress well",                       "N", true  ],
            [ "has few artistic interests",                            "O", true  ],
            [ "is outgoing, sociable",                                 "E", false ],
            [ "tends to find fault with others",                       "A", true  ],
            [ "does a thorough job",                                   "C", false ],
            [ "gets nervous easily",                                   "N", false ],
            [ "has an active imagination",                             "O", false ],
        ];
        echo '<ol class="pcp-bfi10-items">';
        foreach ( $items as $idx => $item ) {
            [ $stem, $dim, $reverse ] = $item;
            echo '<li class="pcp-bfi10-item" data-idx="' . $idx . '" data-dim="' . $dim . '" data-reverse="' . ($reverse ? 1 : 0) . '">';
            echo '<div class="pcp-bfi10-stem">' . htmlspecialchars( $stem ) . '</div>';
            echo '<div class="pcp-bfi10-scale">';
            echo '<span class="pcp-bfi10-scale-end pcp-bfi10-scale-low">totally disagree</span>';
            $bfiKey = 'bfi10:item_' . $idx;
            $bfiVal = ( isset( $byKey[ $bfiKey ] ) && $byKey[ $bfiKey ]->pf_value_num !== null )
                ? (int)$byKey[ $bfiKey ]->pf_value_num : 50;
            echo '<input type="range" class="pcp-bfi10-slider" name="bfi10[' . $idx . ']" min="0" max="100" step="1" value="' . $bfiVal . '" oninput="this.nextElementSibling.value=this.value">';
            echo '<output class="pcp-bfi10-out">' . $bfiVal . '</output>';
            echo '<span class="pcp-bfi10-scale-end pcp-bfi10-scale-high">totally agree</span>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ol>';
        // Compute happens automatically on any slider change; status line stays.
        echo '<div class="pcp-bfi10-status"></div>';
        // Summary scores visibility (controls all 5 ocean.* values).
        echo '<div class="pcp-assess-vis">';
        echo '<label>Visibility for Big Five (OCEAN) <strong>summary scores</strong>: ';
        echo '<select name="tv[ocean]">';
        foreach ( [ 0 => "Private", 1 => "Public (default attribution)", 2 => "Public (username)", 3 => "Public (anonymous)" ] as $vv => $lab ) {
            $sel = $oceanSumVis === $vv ? " selected" : "";
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        // Raw-responses visibility for the 10 BFI-10 items.
        echo '<br><label class="pcp-assess-vis-raw">Visibility for BFI-10 <strong>raw item responses</strong>: ';
        echo '<select name="tvr[bfi10]">';
        foreach ( [ 0 => "Private", 1 => "Public (default attribution)", 2 => "Public (username)", 3 => "Public (anonymous)" ] as $vv => $lab ) {
            $sel = $bfi10RawVis === $vv ? " selected" : "";
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
        echo '</div></details>';

        // ---- Remaining assessments (simple to complex) ----
        $this->renderMbti( $byKey );
        $this->renderEnneagram( $byKey );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\Cati::class );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\Nfcs::class );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\Bpns::class );
        $this->renderInlineAssessment( \MediaWiki\Extension\Pharmacopedia\Assessments\WhoqolBref::class );

        echo '</fieldset>';
    }


    // ===== MBTI (dimensional, no lumping) =====

    private function renderMbti( array $byKey ) {
        $store = new UserProfileStore();
        $user = $this->getUser();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // Existing dichotomy scores
        $dichScores = [];
        foreach ( [ 'EI', 'SN', 'TF', 'JP' ] as $d ) {
            $row = $byKey[ 'mbti:' . $d ] ?? null;
            $dichScores[ $d ] = $row ? (float)$row->pf_value_num : 0.0;
        }
        // Existing raw item responses
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'mbti_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) === 0 ) {
                $rawByN[ (int)substr( $k, 5 ) ] = [
                    'num' => $f->pf_value_num, 'text' => $f->pf_value_text,
                ];
            }
        }
        $hasScores = $byKey[ 'mbti:EI' ] ?? null;
        $vis = $hasScores ? (int)$hasScores->pf_visibility : 0;

        $totalItems = count( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::ITEMS );
        $itemsAnswered = 0;
        foreach ( $rawByN as $e ) {
            if ( $e['num'] !== null || (string)( $e['text'] ?? '' ) === 'unsure' ) $itemsAnswered++;
        }
        $statusBits = [];
        if ( $itemsAnswered > 0 ) {
            $statusBits[] = $itemsAnswered . '/' . $totalItems . ' items answered';
        }
        if ( $hasScores ) {
            $derivedType = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::letterTypeFromScores( $dichScores );
            if ( $derivedType ) {
                $typeName = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::TYPES[ $derivedType ][0] ?? '';
                $statusBits[] = htmlspecialchars( $derivedType . ( $typeName ? ' · ' . $typeName : '' ) );
            }
        }
        $statusTxt = $statusBits ? ' &middot; ' . implode( ' &middot; ', $statusBits ) : ' &middot; <em>not yet taken</em>';

        echo '<details class="pcp-assess-inline">';
        echo '<summary class="pcp-assess-summary">&#x1F4DD; Take the MBTI / Jungian Type test (' . $totalItems . ' items, 4 bipolar dimensions)' . $statusTxt . '</summary>';
        echo '<div class="pcp-assess-body" data-pcp-save-block="mbti">';
        echo '<p class="pcp-prof-help"><small>Four bipolar dimensions. Treated <em>dimensionally</em> here, your score is a continuous position on each axis, not a forced category. The 4-letter type is just a label for which side of midpoint each axis sits on.';
        if ( $hasScores ) {
            $derivedType = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::letterTypeFromScores( $dichScores );
            if ( $derivedType ) {
                $reportUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'mbti' )->getLocalURL();
                $mbtiShareUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'mbti' )->getFullURL( [ 'user' => $this->getUser()->getName() ] );
                echo ' <a href="' . htmlspecialchars( $reportUrl ) . '">View full report →</a>';
                echo ' <button type="button" class="pcp-share-chip" data-share-url="' . htmlspecialchars( $mbtiShareUrl ) . '" data-assessment-key="mbti" title="Copy a shareable link to this report">🔗 Share</button>';
            }
        }
        echo '</small></p>';

        // Read existing raw _vis
        $mbtiRawVis = 0;
        foreach ( $store->getFields( $profileId, 'mbti_raw', 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $mbtiRawVis = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        // Per-block visibility dropdowns (summary scores + raw item responses)
        echo '<div class="pcp-assess-vis">';
        echo '<label>Visibility for MBTI <strong>summary scores</strong>: ';
        echo '<select name="v[mbti][_vis]">';
        foreach ( [ 0 => 'Private', 1 => 'Public (default attribution)', 2 => 'Public (username)', 3 => 'Public (anonymous)' ] as $vv => $lab ) {
            $sel = $vis === $vv ? ' selected' : '';
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '<br><label class="pcp-assess-vis-raw">Visibility for MBTI <strong>raw item responses</strong>: ';
        echo '<select name="tvr[mbti]">';
        foreach ( [ 0 => 'Private', 1 => 'Public (default attribution)', 2 => 'Public (username)', 3 => 'Public (anonymous)' ] as $vv => $lab ) {
            $sel = $mbtiRawVis === $vv ? ' selected' : '';
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';

        // OEJTS items, always visible. (Direct dichotomy sliders removed:
        // the items drive scoring server-side on save.)
        echo '<div class="pcp-mbti-items-block">';
        echo '<p class="pcp-prof-help"><small>32-item OEJTS test (Jorgenson 2014, openpsychometrics.org, public-source items). Slide each item toward whichever pole feels more accurate. Dichotomy scores compute automatically when you save.</small></p>';
        echo '<ol class="pcp-mbti-items">';
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::ITEMS as $n => [ $left, $right, $dich, $rightPole ] ) {
            $entry    = $rawByN[ $n ] ?? null;
            $isUnsure = is_array( $entry ) && (string)( $entry['text'] ?? '' ) === 'unsure';
            $hasNum   = is_array( $entry ) && $entry['num'] !== null;
            $val      = $hasNum && !$isUnsure ? (float)$entry['num'] : 3.0;
            $valStr   = number_format( $val, 2 );
            $unsureCls = $isUnsure ? ' pcp-unsure' : '';
            $disabled  = $isUnsure ? ' disabled' : '';
            $checkAttr = $isUnsure ? ' checked' : '';
            echo '<li class="pcp-mbti-item' . $unsureCls . '" data-itemnum="' . $n . '" data-dich="' . $dich . '" data-right-pole="' . $rightPole . '">';
            echo '<div class="pcp-mbti-item-row">';
            echo '<span class="pcp-mbti-anchor pcp-mbti-anchor-left">' . htmlspecialchars( $left ) . '</span>';
            echo '<input type="range" class="pcp-mbti-item-slider" name="t[mbti][' . $n . ']" min="1" max="5" step="0.01" value="' . $valStr . '"' . $disabled . ' oninput="this.nextElementSibling.value=Number(this.value).toFixed(2)">';
            echo '<output class="pcp-mbti-item-out">' . $valStr . '</output>';
            echo '<span class="pcp-mbti-anchor pcp-mbti-anchor-right">' . htmlspecialchars( $right ) . '</span>';
            echo '</div>';
            echo '<label class="pcp-mbti-unsure"><input type="checkbox" name="t_unsure[mbti][' . $n . ']" value="1"' . $checkAttr . '> Not sure</label>';
            echo '</li>';
        }
        echo '</ol>';
        echo '</div>';

        echo '</div></details>';
    }

    // ===== Enneagram (dimensional, no lumping) =====

    private function renderEnneagram( array $byKey ) {
        $store = new UserProfileStore();
        $user = $this->getUser();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // Existing computed type scores (auto-derived from items at save time)
        $typeScores = [];
        for ( $t = 1; $t <= 9; $t++ ) {
            $row = $byKey[ 'enneagram:type_' . $t ] ?? null;
            $typeScores[ $t ] = $row ? (float)$row->pf_value_num : null;
        }
        // Existing raw item responses
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'enneagram_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) === 0 ) {
                $rawByN[ (int)substr( $k, 5 ) ] = [
                    'num' => $f->pf_value_num, 'text' => $f->pf_value_text,
                ];
            }
        }
        $hasScores = ( $byKey[ 'enneagram:type_1' ] ?? null ) !== null;
        $vis = $hasScores ? (int)$byKey[ 'enneagram:type_1' ]->pf_visibility : 0;

        $TYPES = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::TYPES;
        $ITEMS = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::ITEMS;
        $totalItems = count( $ITEMS );

        // Compute status line for the collapsed summary
        $itemsAnswered = 0;
        foreach ( $rawByN as $e ) {
            if ( $e['num'] !== null || (string)( $e['text'] ?? '' ) === 'unsure' ) $itemsAnswered++;
        }
        $statusBits = [];
        if ( $itemsAnswered > 0 ) {
            $statusBits[] = $itemsAnswered . '/' . $totalItems . ' items answered';
        }
        if ( $hasScores ) {
            $scoresForCalc = [];
            for ( $t = 1; $t <= 9; $t++ ) $scoresForCalc[ 'type_' . $t ] = $typeScores[ $t ];
            $primary = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::primaryType( $scoresForCalc );
            if ( $primary ) {
                $wing = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::wingFor( $primary, $scoresForCalc );
                $statusBits[] = htmlspecialchars( $wing['label'] . ' ' . $TYPES[ $primary ]['name'] );
            }
        }
        $statusTxt = $statusBits ? ' &middot; ' . implode( ' &middot; ', $statusBits ) : ' &middot; <em>not yet taken</em>';

        echo '<details class="pcp-assess-inline">';
        echo '<summary class="pcp-assess-summary">&#x1F4DD; Take the Enneagram (' . $totalItems . ' items, 9 type patterns)' . $statusTxt . '</summary>';
        echo '<div class="pcp-assess-body" data-pcp-save-block="enneagram">';
        echo '<p class="pcp-prof-help"><small>Nine interrelated personality patterns. Treated <em>dimensionally</em> here, every type carries a continuous 0-100 score, not just one primary label. 45 items (5 per type) on a 5-point Strongly disagree ↔ Strongly agree slider; per-type scores are the mean of each type\'s answered items, mapped to 0-100, auto-computed when you save.';
        if ( $hasScores ) {
            $reportUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'enneagram' )->getLocalURL();
            $ennShareUrl = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', 'enneagram' )->getFullURL( [ 'user' => $this->getUser()->getName() ] );
            echo ' <a href="' . htmlspecialchars( $reportUrl ) . '">View full report →</a>';
            echo ' <button type="button" class="pcp-share-chip" data-share-url="' . htmlspecialchars( $ennShareUrl ) . '" data-assessment-key="enneagram" title="Copy a shareable link to this report">🔗 Share</button>';
        }
        echo '</small></p>';

        // Current computed scores summary (if any), read-only
        if ( $hasScores ) {
            echo '<div class="pcp-enn-scores-summary"><strong>Current scores:</strong> ';
            $bits = [];
            for ( $t = 1; $t <= 9; $t++ ) {
                $v = $typeScores[ $t ];
                $bits[] = '<span class="pcp-enn-score-chip">T' . $t . ' ' . ( $v === null ? '-' : number_format( $v, 1 ) ) . '</span>';
            }
            echo implode( ' ', $bits );
            echo '</div>';
        }

        // Per-block visibility (summary scores + raw item responses), matches CATI style.
        $ennRawVis = 0;
        foreach ( $store->getFields( $profileId, 'enneagram_raw', 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $ennRawVis = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        echo '<div class="pcp-assess-vis">';
        echo '<label>Visibility for Enneagram <strong>summary scores</strong>: ';
        echo '<select name="v[enneagram][_vis]">';
        foreach ( [ 0 => 'Private', 1 => 'Public (default attribution)', 2 => 'Public (username)', 3 => 'Public (anonymous)' ] as $vv => $lab ) {
            $sel = $vis === $vv ? ' selected' : '';
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '<br><label class="pcp-assess-vis-raw">Visibility for Enneagram <strong>raw item responses</strong>: ';
        echo '<select name="tvr[enneagram]">';
        foreach ( [ 0 => 'Private', 1 => 'Public (default attribution)', 2 => 'Public (username)', 3 => 'Public (anonymous)' ] as $vv => $lab ) {
            $sel = $ennRawVis === $vv ? ' selected' : '';
            echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';

        // The 45 items, always visible
        echo '<ol class="pcp-enn-items">';
        foreach ( $ITEMS as $n => [ $stmt, $type ] ) {
            $entry    = $rawByN[ $n ] ?? null;
            $isUnsure = is_array( $entry ) && (string)( $entry['text'] ?? '' ) === 'unsure';
            $hasNum   = is_array( $entry ) && $entry['num'] !== null;
            $val      = $hasNum && !$isUnsure ? (float)$entry['num'] : 3.0;
            $valStr   = number_format( $val, 2 );
            $unsureCls = $isUnsure ? ' pcp-unsure' : '';
            $disabled  = $isUnsure ? ' disabled' : '';
            $checkAttr = $isUnsure ? ' checked' : '';
            $centerCls = 'pcp-enn-ci-body';
            if ( in_array( $type, [ 5, 6, 7 ], true ) ) $centerCls = 'pcp-enn-ci-head';
            elseif ( in_array( $type, [ 2, 3, 4 ], true ) ) $centerCls = 'pcp-enn-ci-heart';
            echo '<li class="pcp-enn-item ' . $centerCls . $unsureCls . '" data-itemnum="' . $n . '" data-type="' . $type . '">';
            echo '<div class="pcp-enn-item-stem"><span class="pcp-enn-item-tag">T' . $type . '</span> ' . $n . '. ' . htmlspecialchars( $stmt ) . '</div>';
            echo '<div class="pcp-enn-item-row">';
            echo '<span class="pcp-enn-anchor pcp-enn-anchor-low">Strongly disagree</span>';
            echo '<input type="range" class="pcp-enn-item-slider" name="t[enneagram][' . $n . ']" min="1" max="5" step="0.01" value="' . $valStr . '"' . $disabled . ' oninput="this.nextElementSibling.value=Number(this.value).toFixed(2)">';
            echo '<output class="pcp-enn-item-out">' . $valStr . '</output>';
            echo '<span class="pcp-enn-anchor pcp-enn-anchor-high">Strongly agree</span>';
            echo '</div>';
            echo '<label class="pcp-enn-unsure"><input type="checkbox" name="t_unsure[enneagram][' . $n . ']" value="1"' . $checkAttr . '> Not sure</label>';
            echo '</li>';
        }
        echo '</ol>';
        echo '<p class="pcp-prof-help"><small>Type scores are auto-computed from your responses on save. Partial responses are allowed; unanswered items are skipped.</small></p>';

        echo '</div></details>';
    }

    // ===== Save handler =====

    private function save( $store, $profile, $request ) {
        $profileId = (int)$profile->prof_id;

        // Identity
        $alias = trim( (string)$request->getVal( 'public_alias', '' ) );
        $showDefault = max( 0, min( 3, (int)$request->getVal( 'show_default', 0 ) ) );
        $showXrOnProfile = (int)$request->getVal( 'show_xr_on_profile', 0 ) === 1 ? 1 : 0;
        $store->updateProfileMeta( $profileId, $alias === '' ? null : $alias, $showDefault, $showXrOnProfile );

        // Per-test visibility for summary scores (tv[test_key]).
        $tv = $request->getArray( 'tv' ) ?: [];
        $allowedTests = [ 'pid5bf', 'raadsr', 'catq', 'cati', 'mbti', 'enneagram', 'ocean', 'bfi10', 'nfcs', 'bpns', 'whoqolbref' ];
        foreach ( $tv as $testKey => $visVal ) {
            $visVal = max( 0, min( 3, (int)$visVal ) );
            if ( !in_array( $testKey, $allowedTests, true ) ) continue;
            $store->setField( $profileId, $testKey, '_vis', null, (float)$visVal, $visVal );
            foreach ( $store->getFields( $profileId, $testKey, 0 ) as $row ) {
                $k = (string)$row->pf_key;
                if ( $k === '_vis' ) continue;
                $store->setField( $profileId, $testKey, $k,
                    $row->pf_value_text, $row->pf_value_num, $visVal );
            }
        }
        // Per-test visibility for RAW item responses (tvr[test_key]).
        $tvr = $request->getArray( 'tvr' ) ?: [];
        foreach ( $tvr as $testKey => $visVal ) {
            $visVal = max( 0, min( 3, (int)$visVal ) );
            if ( !in_array( $testKey, $allowedTests, true ) ) continue;
            $rawNs = $testKey . '_raw';
            $store->setField( $profileId, $rawNs, '_vis', null, (float)$visVal, $visVal );
            foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $row ) {
                $k = (string)$row->pf_key;
                if ( $k === '_vis' ) continue;
                $store->setField( $profileId, $rawNs, $k,
                    $row->pf_value_text, $row->pf_value_num, $visVal );
            }
        }

        // Generic fields
        $allowedNs = [ 'demographics', 'ocean', 'mbti', 'enneagram' ];
        $f = $request->getArray( 'f' ) ?: [];
        $v = $request->getArray( 'v' ) ?: [];
        foreach ( $f as $ns => $keys ) {
            if ( !in_array( $ns, $allowedNs, true ) ) continue;
            if ( !is_array( $keys ) ) continue;
            foreach ( $keys as $key => $val ) {
                $vis = isset( $v[$ns][$key] ) ? max( 0, min( 3, (int)$v[$ns][$key] ) ) : 0;
                $raw = (string)$val;
                // Normalize pcp-date-input JSON payloads (currently only demographics/birthday)
                if ( $ns === 'demographics' && $key === 'birthday' && $raw !== '' && $raw[0] === '{' ) {
                    $struct = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $raw );
                    if ( !$struct ) {
                        $store->deleteField( $profileId, $ns, $key );
                        continue;
                    }
                    $raw = json_encode( $struct, JSON_UNESCAPED_UNICODE );
                }
                if ( $raw === '' ) {
                    $store->deleteField( $profileId, $ns, $key );
                    continue;
                }
                // Decide numeric vs text storage
                if ( is_numeric( $raw ) ) {
                    $store->setField( $profileId, $ns, $key, null, (float)$raw, $vis );
                } else {
                    $store->setField( $profileId, $ns, $key, $raw, null, $vis );
                }
            }
        }

        // Inline assessment responses: t[<key>][<itemN>] = response value
        $t = $request->getArray( 't' ) ?: [];
        $clsMap = [
            'pid5bf' => \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            'raadsr' => \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            'catq'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
            'cati'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Cati::class,
            'mbti'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::class,
            'enneagram' => \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::class,
            'nfcs'      => \MediaWiki\Extension\Pharmacopedia\Assessments\Nfcs::class,
            'bpns'      => \MediaWiki\Extension\Pharmacopedia\Assessments\Bpns::class,
            'whoqolbref' => \MediaWiki\Extension\Pharmacopedia\Assessments\WhoqolBref::class,
        ];
        $tUnsureAll = $request->getArray( 't_unsure' ) ?: [];
        foreach ( $t as $testKey => $items ) {
            if ( !is_array( $items ) ) continue;
            if ( !isset( $clsMap[ $testKey ] ) ) continue;
            $cls = $clsMap[ $testKey ];
            $rawNs = $testKey . '_raw';
            $touched = false;
            // Pick the visibility to apply to each saved raw row. Prefer
            // a value just submitted in tvr[], then fall back to the stored
            // _vis for this raw namespace, defaulting to 0 (private).
            $rawVis = 0;
            if ( isset( $tvr[ $testKey ] ) ) {
                $rawVis = max( 0, min( 3, (int)$tvr[ $testKey ] ) );
            } else {
                foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $f ) {
                    if ( (string)$f->pf_key === '_vis' ) {
                        $rawVis = (int)( $f->pf_value_num ?? 0 );
                        break;
                    }
                }
            }

            $sliderBounds = [
                'pid5bf' => [ 0.0, 3.0 ],
                'catq'   => [ 1.0, 7.0 ],
                'cati'   => [ 1.0, 5.0 ],
                'mbti'   => [ 1.0, 5.0 ],
                'enneagram' => [ 1.0, 5.0 ],
                'nfcs'      => [ 1.0, 6.0 ],
                'bpns'      => [ 1.0, 7.0 ],
                'whoqolbref' => [ 1.0, 5.0 ],
            ];
            if ( isset( $sliderBounds[ $testKey ] ) ) {
                [ $lo, $hi ] = $sliderBounds[ $testKey ];
                $unsureFlags = $tUnsureAll[ $testKey ] ?? [];
                foreach ( $cls::ITEMS as $itemN => $_ ) {
                    $isUnsure = isset( $unsureFlags[ $itemN ] ) && (string)$unsureFlags[ $itemN ] === '1';
                    if ( $isUnsure ) {
                        $store->setField( $profileId, $rawNs, 'item_' . $itemN, 'unsure', null, $rawVis );
                        $touched = true;
                        continue;
                    }
                    if ( !array_key_exists( $itemN, $items ) ) continue;
                    $valStr = trim( (string)$items[ $itemN ] );
                    if ( $valStr === '' ) continue;
                    $f = (float)$valStr;
                    if ( $f < $lo ) $f = $lo;
                    if ( $f > $hi ) $f = $hi;
                    $store->setField( $profileId, $rawNs, 'item_' . $itemN, null, $f, $rawVis );
                    $touched = true;
                }
            } else {
                // Radio (discrete), existing behavior
                foreach ( $items as $itemN => $val ) {
                    $itemN = (int)$itemN;
                    $valStr = trim( (string)$val );
                    if ( $valStr === '' ) continue;
                    if ( !array_key_exists( $itemN, $cls::ITEMS ) ) continue;
                    $store->setField( $profileId, $rawNs, 'item_' . $itemN, null, (float)$valStr, $rawVis );
                    $touched = true;
                }
            }
            if ( !$touched ) continue;

            // Re-score from full raw set; skip 'unsure' rows
            $rawAll = [];
            foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $f ) {
                $k = (string)$f->pf_key;
                if ( strpos( $k, 'item_' ) !== 0 ) continue;
                if ( $f->pf_value_num === null ) continue;
                if ( (string)$f->pf_value_text === 'unsure' ) continue;
                $rawAll[ (int)substr( $k, 5 ) ] = (float)$f->pf_value_num;
            }
            $scores = $cls::scoreResponses( $rawAll );

            // Look up per-test visibility (tv[] may have just been written above)
            $vis = 0;
            foreach ( $store->getFields( $profileId, $testKey, 0 ) as $row ) {
                if ( (string)$row->pf_key === '_vis' ) {
                    $vis = (int)( $row->pf_value_num ?? 0 ); break;
                }
            }
            foreach ( $scores as $sk => $sv ) {
                if ( $sv === null ) {
                    $store->deleteField( $profileId, $testKey, $sk );
                } else {
                    $store->setField( $profileId, $testKey, $sk, null, (float)$sv, $vis );
                }
            }
            $now = \MediaWiki\MediaWikiServices::getInstance()
                ->getConnectionProvider()->getPrimaryDatabase()->timestamp();
            $store->setField( $profileId, $testKey, 'taken_at', $now, null, $vis );

            // Auto-create / update a private Life Story keyframe for this assessment.
            try {
                $lifeStore = new \MediaWiki\Extension\Pharmacopedia\LifeStoryStore();
                $lifeStore->upsertAssessmentKeyframe( $profileId, $testKey, $cls, $scores );
            } catch ( \Throwable $e ) {
                wfDebugLog( 'pharmacopedia', 'auto-keyframe failed: ' . $e->getMessage() );
            }
        }

        // Process diagnoses
        self::saveDiagnoses( $store, $profileId, $request );

        // Process manually-added meds
        self::saveMeds( $store, $profileId, $request );
        echo '</div>';

    }


    // ===== Personality / autism assessments =====

    private function renderAssessments( $store, int $profileId ) {
        $classes = [
            \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
        ];
        echo '<fieldset class="pcp-prof-section is-collapsed"><legend>Personality &amp; autism assessments</legend>';
        echo '<p class="pcp-prof-help"><small>Each test is optional. Raw responses are stored privately; only computed subscale &amp; total scores respect the visibility toggle.</small></p>';
        foreach ( $classes as $cls ) {
            $key = $cls::KEY;
            // Load scores + meta
            $scores = []; $takenAt = null; $vis = 0;
            foreach ( $store->getFields( $profileId, $key, 0 ) as $f ) {
                $fk = (string)$f->pf_key;
                if ( $fk === '_vis' )       { $vis = (int)( $f->pf_value_num ?? 0 ); continue; }
                if ( $fk === 'taken_at' )   { $takenAt = (string)$f->pf_value_text; continue; }
                $scores[ $fk ] = $f->pf_value_num;
            }
            $taken = $takenAt !== null;

            echo '<div class="pcp-assess-card">';
            echo '<h4>' . htmlspecialchars( $cls::FULL_NAME ) . ' <small>(' . htmlspecialchars( $cls::NAME ) . ')</small></h4>';
            echo '<p class="pcp-prof-help"><small>' . htmlspecialchars( $cls::DESCRIPTION ) . '</small></p>';
            if ( $cls::WARNING ) {
                echo '<p class="pcp-prof-help"><small><em>' . htmlspecialchars( $cls::WARNING ) . '</em></small></p>';
            }
            if ( $taken ) {
                echo '<div class="pcp-assess-result">';
                echo '<table class="pcp-pa-table"><tbody>';
                foreach ( $cls::SUBSCALES as $k => $def ) {
                    $v = $scores[ 'subscale_' . $k ] ?? null;
                    echo '<tr><th>' . htmlspecialchars( $def["label"] ) . '</th><td>' . ( $v === null ? "-" : htmlspecialchars( (string)$v ) ) . '</td></tr>';
                }
                if ( isset( $scores["total"] ) ) {
                    echo '<tr><th>Total</th><td>' . htmlspecialchars( (string)$scores["total"] ) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<p><em>' . htmlspecialchars( $cls::interpret( $scores ) ) . '</em></p>';
                echo '<p><small>Last completed: ' . htmlspecialchars( $takenAt ) . '</small></p>';
                echo '</div>';
            } else {
                echo '<p><em>Not yet taken.</em></p>';
            }
            // Take / Retake link
            $url = htmlspecialchars( \MediaWiki\SpecialPage\SpecialPage::getTitleFor( "TakeAssessment", $key )->getLocalURL() );
            $label = $taken ? "Retake" : "Take this assessment";
            echo '<p><a href="' . $url . '" class="pcp-btn pcp-btn-primary">' . $label . '</a></p>';

            // Per-test visibility toggle
            echo '<div class="pcp-assess-vis">';
            echo '<label>Visibility for ' . htmlspecialchars( $cls::NAME ) . ' scores: ';
            echo '<select name="tv[' . htmlspecialchars( $key ) . ']">';
            foreach ( [ 0 => "Private", 1 => "Public (default attribution)", 2 => "Public (username)", 3 => "Public (anonymous)" ] as $vv => $lab ) {
                $sel = $vis === $vv ? " selected" : "";
                echo '<option value="' . $vv . '"' . $sel . '>' . htmlspecialchars( $lab ) . '</option>';
            }
            echo '</select></label>';
            echo '</div>';

            echo '</div>'; // .pcp-assess-card
        }
        echo '</fieldset>';
    }

    public function doesWrites() { return true; }
    protected function getGroupName() { return 'users'; }

    // ===== Diagnoses section =====

    private function renderDiagnoses( $store, int $profileId ) {
        $rows = $store->getDiagnoses( $profileId );
        echo '<fieldset class="pcp-prof-section is-collapsed"><legend>Diagnoses <a href="#" class="pcp-share-trigger" data-ns="diagnoses" data-label="Diagnoses">🔗 Share</a></legend>';
        echo '<div data-pcp-save-block="diagnoses">';
        echo '<p class="pcp-prof-help"><small>Mental, somatic, or self-described. Type any abbreviation or name , autocomplete will suggest matches but you can enter anything. Each diagnosis has its own privacy toggle.</small></p>';

        echo '<div class="pcp-dx-list">';
        foreach ( $rows as $r ) {
            $this->renderDxRow( $r );
        }
        echo '</div>';

        echo '<div class="pcp-dx-add">';
        echo '<h4>Add a diagnosis</h4>';
        $this->renderDxRow( null );
        echo '<button type="button" class="pcp-add-commit mw-ui-button mw-ui-progressive" data-add-kind="dx">+ Add diagnosis</button>';
        echo '<span class="pcp-add-hint"><small>This slot does <em>not</em> autosave, click <strong>+ Add diagnosis</strong> when ready.</small></span>';
        echo '</div>';

        // JS: reveal "pro date" only when Origin = Both (3)
        echo '<script>(function(){function sync(s){var row=s.closest(".pcp-dx-row");if(!row)return;var p=row.querySelector(".pcp-dx-date-pro");var ls=row.querySelector(".pcp-dx-date-self-label");if(!p)return;var isBoth=(s.value==="3");p.style.display=isBoth?"":"none";if(ls)ls.textContent=isBoth?"When first noticed (self)":"When first noticed";}document.querySelectorAll(".pcp-dx-origin").forEach(function(s){s.addEventListener("change",function(){sync(s);});sync(s);});})();</script>';
        echo '</div>'; // /data-pcp-save-block="diagnoses"
        echo '</fieldset>';
    }

    /** Render one diagnosis row. Pass null for the empty-add form. */
    private function renderDxRow( $r ) {
        $id        = $r ? (int)$r->pd_id : '';
        $system    = $r ? htmlspecialchars( (string)$r->pd_system ) : '';
        $code      = $r ? htmlspecialchars( (string)$r->pd_code ) : '';
        $desc      = $r ? htmlspecialchars( (string)$r->pd_description ) : '';
        $status    = $r ? (int)$r->pd_status : 0;
        $origin    = $r ? (int)$r->pd_origin : 0;
        $severity  = $r && $r->pd_severity !== null ? (float)$r->pd_severity : null;
        $disability = $r && $r->pd_disability !== null ? (float)$r->pd_disability : null;
        $year      = $r ? ( $r->pd_year_first ? (int)$r->pd_year_first : '' ) : '';
        $notes     = $r ? htmlspecialchars( (string)$r->pd_notes ) : '';
        $vis       = $r ? (int)$r->pd_visibility : 0;
        $rowClass  = $r ? 'pcp-dx-row pcp-dx-existing' : 'pcp-dx-row pcp-dx-new';
        $prefix    = $r ? "dx[$id]" : 'dx_new[]';

        echo '<div class="' . $rowClass . '">';
        echo '<input type="hidden" name="' . $prefix . '[id]" value="' . htmlspecialchars( (string)$id ) . '">';

        echo '<div class="pcp-dx-row-main">';
        echo '<input type="text" name="' . $prefix . '[description]" class="pcp-dx-desc-input" placeholder="e.g. ADHD, MDD, stroke, htn, T2DM, ICD-10-CM autocomplete" value="' . $desc . '" autocomplete="off">';
        echo '<input type="text" name="' . $prefix . '[code]" class="pcp-dx-code-input" placeholder="code (auto)" value="' . $code . '" size="10">';

        $systems = [ '' => '-', 'ICD-10-CM' => 'ICD-10-CM', 'ICD-10' => 'ICD-10', 'ICD-11' => 'ICD-11', 'DSM-5' => 'DSM-5', 'unofficial' => 'Unofficial', 'somatic' => 'Somatic', 'other' => 'Other' ];
        echo '<select name="' . $prefix . '[system]" class="pcp-dx-sys-input">';
        foreach ( $systems as $v => $l ) {
            $sel = $system === $v ? ' selected' : '';
            echo '<option value="' . htmlspecialchars( $v ) . '"' . $sel . '>' . htmlspecialchars( $l ) . '</option>';
        }
        echo '</select>';

        // Visibility toggle
        echo '<button type="button" class="pcp-vis-toggle pcp-vis-' . $this->visClass( $vis ) .
             '" data-vis="' . $vis . '" title="Privacy , click to cycle">' . $this->visIcon( $vis ) . '</button>';
        echo '<input type="hidden" name="' . $prefix . '[visibility]" value="' . $vis . '" class="pcp-vis-hidden">';

        if ( $r ) {
            echo '<button type="submit" name="dx_delete" value="' . (int)$id . '" class="pcp-dx-del" title="Delete">&#x2716;</button>';
        }
        echo '</div>';

        // Expandable detail
        echo '<details class="pcp-dx-detail">';
        echo '<summary>More detail (status, year, severity, notes)</summary>';
        echo '<div class="pcp-dx-detail-grid">';

        $statuses = [ 0 => '-', 1 => 'Current', 2 => 'Past', 3 => 'In remission', 4 => 'Suspected' ];
        echo '<label>Status</label><select name="' . $prefix . '[status]">';
        foreach ( $statuses as $v => $l ) {
            $sel = $status === $v ? ' selected' : '';
            echo '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
        }
        echo '</select>';

        $origins = [ 0 => '-', 1 => 'Self-identified', 2 => 'Professional diagnosis', 3 => 'Both' ];
        echo '<label>Origin</label><select name="' . $prefix . '[origin]" class="pcp-dx-origin">';
        foreach ( $origins as $v => $l ) {
            $sel = $origin === $v ? ' selected' : '';
            echo '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
        }
        echo '</select>';

        $severityVal = '';
        if ( $severity !== null && (float)$severity > 0 ) {
            $severityVal = rtrim( rtrim( number_format( (float)$severity, 1, '.', '' ), '0' ), '.' );
        }
        $sevSlider = $severityVal !== '' ? $severityVal : '0';
        echo '<label>Severity (0–100)</label>';
        echo '<span class="pcp-dx-sev-wrap">';
        echo '<input type="range" name="' . $prefix . '[severity]" min="0" max="100" step="0.1" value="' . htmlspecialchars( $sevSlider ) . '" class="pcp-dx-sev-slider" oninput="this.nextElementSibling.value=Number(this.value).toFixed(1)">';
        echo '<output class="pcp-dx-sev-out">' . htmlspecialchars( $severityVal !== '' ? $severityVal : '0.0' ) . '</output>';
        echo '</span>';

        $disabilityVal = '';
        if ( $disability !== null && (float)$disability > 0 ) {
            $disabilityVal = rtrim( rtrim( number_format( (float)$disability, 1, '.', '' ), '0' ), '.' );
        }
        $disSlider = $disabilityVal !== '' ? $disabilityVal : '0';
        echo '<label>Disability (0–100)</label>';
        echo '<span class="pcp-dx-sev-wrap">';
        echo '<input type="range" name="' . $prefix . '[disability]" min="0" max="100" step="0.1" value="' . htmlspecialchars( $disSlider ) . '" class="pcp-dx-sev-slider" oninput="this.nextElementSibling.value=Number(this.value).toFixed(1)">';
        echo '<output class="pcp-dx-sev-out">' . htmlspecialchars( $disabilityVal !== '' ? $disabilityVal : '0.0' ) . '</output>';
        echo '</span>';
        echo '<div class="pcp-dx-date-self">';
        echo '<label class="pcp-dx-date-self-label">When first noticed</label>';
        $dxStruct = null;
        if ( $r && !empty( $r->pd_date_struct ) ) {
            $dec = json_decode( (string)$r->pd_date_struct, true );
            if ( is_array( $dec ) ) $dxStruct = $dec;
        } elseif ( $r && $r->pd_year_first ) {
            $dxStruct = \MediaWiki\Extension\Pharmacopedia\DatePicker::structFromIso(
                sprintf( '%04d-01-01', (int)$r->pd_year_first ), 'year', null
            );
        }
        echo \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget(
            $prefix . '[date_struct]', $dxStruct
        );
        echo '</div>';

        // Second date: when professionally diagnosed (shown only when Origin = Both)
        echo '<div class="pcp-dx-date-pro" style="display:none">';
        echo '<label>When professionally diagnosed</label>';
        $dxStructPro = null;
        if ( $r && !empty( $r->pd_date_struct_pro ) ) {
            $decPro = json_decode( (string)$r->pd_date_struct_pro, true );
            if ( is_array( $decPro ) ) $dxStructPro = $decPro;
        }
        echo \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget(
            $prefix . '[date_struct_pro]', $dxStructPro
        );
        echo '</div>';

        echo '<label>Notes</label><textarea name="' . $prefix . '[notes]" rows="2">' . $notes . '</textarea>';
        echo '</div></details>';

        echo '</div>';
    }

    /**
     * Pull date payload from a posted dx[] row. Returns ['year_first' => int|null, 'date_struct' => json|null].
     * Falls back to legacy year_first if the widget JSON is missing/invalid.
     */
    private static function parseDxDateStruct( array $f ): array {
        $struct = null;
        if ( !empty( $f['date_struct'] ) ) {
            $raw = (string)$f['date_struct'];
            if ( $raw !== '' && $raw[0] === '{' ) {
                $parsed = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $raw );
                if ( $parsed ) $struct = $parsed;
            }
        }
        $structPro = null;
        if ( !empty( $f['date_struct_pro'] ) ) {
            $rawPro = (string)$f['date_struct_pro'];
            if ( $rawPro !== '' && $rawPro[0] === '{' ) {
                $parsedPro = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $rawPro );
                if ( $parsedPro ) $structPro = $parsedPro;
            }
        }
        $year = null;
        if ( $struct ) {
            $iso = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $struct );
            if ( $iso ) $year = (int)substr( $iso, 0, 4 );
        }
        if ( $year === null && $structPro ) {
            $iso = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $structPro );
            if ( $iso ) $year = (int)substr( $iso, 0, 4 );
        }
        if ( $year === null && isset( $f['year_first'] ) && $f['year_first'] !== '' ) {
            $year = (int)$f['year_first'];
        }
        return [
            'year_first'      => $year,
            'date_struct'     => $struct    ? json_encode( $struct,    JSON_UNESCAPED_UNICODE ) : null,
            'date_struct_pro' => $structPro ? json_encode( $structPro, JSON_UNESCAPED_UNICODE ) : null,
        ];
    }

        /** Process diagnosis submissions from POST. */
    public static function saveDiagnoses( $store, int $profileId, $request ) {
        // Delete
        $deleteId = (int)$request->getVal( 'dx_delete', 0 );
        if ( $deleteId > 0 ) {
            $store->deleteDiagnosis( $deleteId, $profileId );
            // Don't process other dx changes on a delete submit
            return;
        }
        // Updates to existing rows
        $existing = $request->getArray( 'dx' ) ?: [];
        foreach ( $existing as $id => $f ) {
            $id = (int)$id;
            if ( $id <= 0 ) continue;
            if ( empty( $f['description'] ) ) {
                $store->deleteDiagnosis( $id, $profileId );
                continue;
            }
            $dxDate = self::parseDxDateStruct( $f );
            $store->updateDiagnosis( $id, $profileId, [
                'system'      => $f['system']      ?? null,
                'code'        => $f['code']        ?? null,
                'description' => $f['description'],
                'status'      => isset( $f['status'] )    && $f['status']    !== '' ? (int)$f['status']    : null,
                'origin'      => isset( $f['origin'] )    && $f['origin']    !== '' ? (int)$f['origin']    : null,
                'severity'    => isset( $f['severity'] )  && $f['severity']  !== '' ? max( 0.0, min( 100.0, (float)$f['severity'] ) )  : null,
                'disability'  => isset( $f['disability'] ) && $f['disability'] !== '' ? max( 0.0, min( 100.0, (float)$f['disability'] ) ) : null,
                'year_first'      => $dxDate['year_first'],
                'date_struct'     => $dxDate['date_struct'],
                'date_struct_pro' => $dxDate['date_struct_pro'],
                'notes'           => $f['notes']       ?? null,
                'visibility'  => isset( $f['visibility'] )? (int)$f['visibility'] : 0,
            ] );
        }
        // New rows
        $news = $request->getArray( 'dx_new' ) ?: [];
        foreach ( $news as $f ) {
            if ( empty( $f['description'] ) ) continue;
            $dxDate = self::parseDxDateStruct( $f );
            $store->addDiagnosis( $profileId, [
                'system'      => $f['system']      ?: 'other',
                'code'        => $f['code']        ?: null,
                'description' => $f['description'],
                'status'      => isset( $f['status'] )    && $f['status']    !== '' ? (int)$f['status']    : null,
                'origin'      => isset( $f['origin'] )    && $f['origin']    !== '' ? (int)$f['origin']    : null,
                'severity'    => isset( $f['severity'] )  && $f['severity']  !== '' ? max( 0.0, min( 100.0, (float)$f['severity'] ) )  : null,
                'disability'  => isset( $f['disability'] ) && $f['disability'] !== '' ? max( 0.0, min( 100.0, (float)$f['disability'] ) ) : null,
                'year_first'      => $dxDate['year_first'],
                'date_struct'     => $dxDate['date_struct'],
                'date_struct_pro' => $dxDate['date_struct_pro'],
                'notes'       => $f['notes']       ?? null,
                'visibility'  => isset( $f['visibility'] )? (int)$f['visibility'] : 0,
            ] );
        }
    }


    // ===== Medicines section =====

    private function renderMeds( $store, $profile ) {
        $profileId = (int)$profile->prof_id;
        $voterHash = (string)$profile->prof_voter_hash;
        $xrs   = $store->getExperienceReports( $voterHash );
        $meds  = $store->getMeds( $profileId );

        echo '<fieldset class="pcp-prof-section is-collapsed"><legend>Medicines I have tried <a href="#" class="pcp-share-trigger" data-ns="meds" data-label="Medicines">🔗 Share</a></legend>';
        echo '<div data-pcp-save-block="meds">';

        // ---- Auto-pulled experience reports ----
        echo '<h4 class="pcp-meds-h">From your experience reports on wiki pages</h4>';
        if ( !$xrs ) {
            echo '<p class="pcp-prof-help"><small>No experience reports yet. As you submit reports via the <code>&lt;pharmaExperience/&gt;</code> block on medicine pages, they will appear here.</small></p>';
        } else {
            echo '<ul class="pcp-meds-xr-list">';
            foreach ( $xrs as $xr ) {
                $pageTitle = str_replace( "_", " ", (string)$xr->page_title );
                $url = htmlspecialchars( \Title::makeTitle( NS_MAIN, (string)$xr->page_title )->getLocalURL() );
                $perspective = (int)$xr->xr_perspective === 2 ? "clinical" : "personal";
                $eff = $xr->xr_efficacy !== null ? "efficacy " . (int)$xr->xr_efficacy . "/5" : null;
                $bur = $xr->xr_burden   !== null ? "burden "   . (int)$xr->xr_burden   . "/5" : null;
                $parts = array_filter( [ $perspective, $eff, $bur ] );
                echo '<li><a href="' . $url . '">' . htmlspecialchars( $pageTitle ) . '</a>';
                if ( $parts ) echo ' <span class="pcp-meds-xr-meta">&middot; ' . implode( " &middot; ", $parts ) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }

        // ---- Manually-added meds ----
        echo '<h4 class="pcp-meds-h">Other meds I have tried</h4>';
        echo '<p class="pcp-prof-help"><small>For meds you have used that you have not formally reported on a wiki page. Free text accepted; if the med has a wiki page, the autocomplete will link it.</small></p>';

        echo '<div class="pcp-meds-manual-list">';
        foreach ( $meds as $m ) { $this->renderMedRow( $m ); }
        echo '</div>';

        echo '<datalist id="pcp-schedule-suggest"><option value="QD"><option value="BID"><option value="TID"><option value="QID"><option value="q4h"><option value="q6h"><option value="q8h"><option value="q12h"><option value="qHS"><option value="qAM"><option value="qPM"><option value="PRN"></datalist>';
        echo '<div class="pcp-meds-add">';
        echo '<h5>Add a med</h5>';
        $this->renderMedRow( null );
        echo '<button type="button" class="pcp-add-commit mw-ui-button mw-ui-progressive" data-add-kind="med">+ Add med</button>';
        echo '<span class="pcp-add-hint"><small>This slot does <em>not</em> autosave, click <strong>+ Add med</strong> when ready.</small></span>';
        echo '</div>';

        // JS: + add another period / × remove period for each med row
        echo '<script>(function(){function renumber(list){list.querySelectorAll(".pcp-med-period-num").forEach(function(n,i){n.textContent="Period "+(i+1);});}function wireRemove(btn,list){btn.addEventListener("click",function(e){e.preventDefault();var item=btn.closest(".pcp-med-period");if(!item)return;if(list.querySelectorAll(".pcp-med-period").length<=1)return;item.remove();renumber(list);});}function addMedPeriod(btn){var wrap=btn.closest(".pcp-med-periods");if(!wrap)return;var list=wrap.querySelector(".pcp-med-period-list");if(!list)return;var template=list.querySelector(".pcp-med-period");if(!template)return;var clone=template.cloneNode(true);var widget=clone.querySelector(".pcp-date-input");if(widget){widget.innerHTML="";widget.removeAttribute("data-pcpdt-inited");widget.removeAttribute("data-initial");}list.appendChild(clone);renumber(list);if(widget&&window.PCPDatePicker&&window.PCPDatePicker.init){window.PCPDatePicker.init(widget);}var rm=clone.querySelector(".pcp-med-period-remove");if(rm)wireRemove(rm,list);}document.querySelectorAll(".pcp-med-period-add").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();addMedPeriod(b);});});document.querySelectorAll(".pcp-med-period-list").forEach(function(list){list.querySelectorAll(".pcp-med-period-remove").forEach(function(rm){wireRemove(rm,list);});});})();</script>';
        echo '</div>'; // /data-pcp-save-block="meds"
        echo '</fieldset>';
    }

    private function renderMedRow( $m ) {
        $id        = $m ? (int)$m->um_id : '';
        $name      = $m ? htmlspecialchars( (string)$m->um_med_name ) : '';
        $eff       = $m ? ( $m->um_efficacy !== null ? (int)$m->um_efficacy : '' ) : '';
        $bur       = $m ? ( $m->um_burden   !== null ? (int)$m->um_burden   : '' ) : '';
        $dur       = $m ? ( $m->um_duration_days !== null ? (int)$m->um_duration_days : '' ) : '';
        $dose      = $m ? ( $m->um_dose_mg  !== null ? (float)$m->um_dose_mg  : '' ) : '';
        $route     = $m ? ( $m->um_route    !== null ? (string)$m->um_route    : '' ) : '';
        $schedule  = $m ? ( $m->um_schedule !== null ? (string)$m->um_schedule : '' ) : '';
        $current   = $m ? (int)$m->um_current : 0;
        $notes     = $m ? htmlspecialchars( (string)$m->um_notes ) : '';
        $vis       = $m ? (int)$m->um_visibility : 0;
        $rowClass  = $m ? "pcp-med-row pcp-med-existing" : "pcp-med-row pcp-med-new";
        $prefix    = $m ? "um[$id]" : "um_new[]";

        echo '<div class="' . $rowClass . '">';
        echo '<input type="hidden" name="' . $prefix . '[id]" value="' . htmlspecialchars( (string)$id ) . '">';

        $pageId   = $m && $m->um_page_id ? (int)$m->um_page_id : "";
        echo '<input type="hidden" name="' . $prefix . '[page_id]" class="pcp-med-pageid" value="' . htmlspecialchars( (string)$pageId ) . '">';

        echo '<div class="pcp-med-row-main">';
        echo '<input type="text" name="' . $prefix . '[med_name]" class="pcp-med-name-input" placeholder="med name (e.g. Sertraline, Cannabis, ...)" value="' . $name . '" autocomplete="off">';

        echo '<button type="button" class="pcp-vis-toggle pcp-vis-' . $this->visClass( $vis ) .
             '" data-vis="' . $vis . '" title="Privacy , click to cycle">' . $this->visIcon( $vis ) . '</button>';
        echo '<input type="hidden" name="' . $prefix . '[visibility]" value="' . $vis . '" class="pcp-vis-hidden">';

        if ( $m ) {
            echo '<button type="submit" name="um_delete" value="' . (int)$id . '" class="pcp-med-del" title="Delete">&#x2716;</button>';
        }
        echo '</div>';

        echo '<details class="pcp-med-detail">';
        echo '<summary>Effects, dose, status, notes</summary>';
        echo '<div class="pcp-med-detail-grid">';

        $effSlider = ( $eff !== '' && $eff !== null ) ? (int)$eff : 50;
        echo '<label>Efficacy (0–100)</label>';
        echo '<span class="pcp-med-rate-wrap">';
        echo '<input type="range" name="' . $prefix . '[efficacy]" min="0" max="100" step="1" value="' . $effSlider . '" oninput="this.nextElementSibling.value=this.value">';
        echo '<output>' . $effSlider . '</output>';
        echo '</span>';
        $burSlider = ( $bur !== '' && $bur !== null ) ? (int)$bur : 50;
        echo '<label>Side-effect burden (0–100)</label>';
        echo '<span class="pcp-med-rate-wrap">';
        echo '<input type="range" name="' . $prefix . '[burden]" min="0" max="100" step="1" value="' . $burSlider . '" oninput="this.nextElementSibling.value=this.value">';
        echo '<output>' . $burSlider . '</output>';
        echo '</span>';

        echo '<label>Typical daily dose (mg)</label><input type="number" name="' . $prefix . '[dose_mg]" min="0" step="0.001" value="' . $dose . '">';

        $routes = [ '' => '-', 'PO' => 'PO (oral)', 'IV' => 'IV', 'IM' => 'IM', 'SC' => 'SC (subcutaneous)', 'SL' => 'SL (sublingual)', 'buccal' => 'Buccal', 'inhaled' => 'Inhaled', 'intranasal' => 'Intranasal', 'topical' => 'Topical', 'transdermal' => 'Transdermal', 'PR' => 'PR (rectal)', 'ophthalmic' => 'Ophthalmic', 'otic' => 'Otic (ear)', 'vaginal' => 'Vaginal', 'insufflated' => 'Insufflated', 'other' => 'Other' ];
        echo '<label>Route</label><select name="' . $prefix . '[route]" class="pcp-med-route">';
        foreach ( $routes as $rv => $rlab ) {
            $sel = (string)$route === (string)$rv ? ' selected' : '';
            echo '<option value="' . htmlspecialchars( (string)$rv ) . '"' . $sel . '>' . htmlspecialchars( $rlab ) . '</option>';
        }
        echo '</select>';
        echo '<label>Schedule</label><input type="text" name="' . $prefix . '[schedule]" class="pcp-med-schedule" value="' . htmlspecialchars( (string)$schedule ) . '" list="pcp-schedule-suggest" placeholder="e.g. BID, qHS, q6h, PRN, morning + bedtime">';

        $currents = [ 0 => "-", 1 => "Still taking", 2 => "Stopped", 3 => "Brief / rarely" ];
        echo '<label>Status</label><select name="' . $prefix . '[current]" class="pcp-med-status">';
        foreach ( $currents as $v => $l ) {
            $sel = $current === $v ? " selected" : "";
            echo '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
        }
        echo '</select>';

        // ----- Periods of use (list of range widgets) -----
        $umPeriods = self::loadMedPeriods( $m );
        $rowKey = $m ? ( 'um_' . (int)$m->um_id ) : ( 'umnew_' . uniqid() );
        echo '<div class="pcp-med-periods" data-row="' . htmlspecialchars( $rowKey ) . '">';
        echo '<label>Periods of use</label>';
        echo '<div class="pcp-med-period-list" data-prefix="' . htmlspecialchars( $prefix ) . '">';
        if ( !$umPeriods ) {
            $umPeriods = [ null ];
        }
        foreach ( $umPeriods as $idx => $periodStruct ) {
            $rangeStruct = [ 'kind' => 'range', 'start' => null, 'end' => null ];
            if ( is_array( $periodStruct ) ) {
                $rangeStruct[ 'start' ] = $periodStruct[ 'start' ] ?? null;
                $rangeStruct[ 'end' ]   = $periodStruct[ 'end' ]   ?? null;
            }
            echo '<div class="pcp-med-period">';
            echo '<div class="pcp-med-period-head"><span class="pcp-med-period-num">Period ' . ( $idx + 1 ) . '</span>';
            echo '<button type="button" class="pcp-med-period-remove" title="Remove this period">&#x2716;</button></div>';
            echo \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget(
                $prefix . '[periods][]',
                $rangeStruct,
                [ 'lock_mode' => 'range' ]
            );
            echo '</div>';
        }
        echo '</div>';
        echo '<button type="button" class="pcp-med-period-add">+ add another period</button>';
        echo '</div>';

        echo '<label>Notes</label><textarea name="' . $prefix . '[notes]" rows="2">' . $notes . '</textarea>';
        echo '</div></details>';

        echo '</div>';
    }

    /**
     * Parse start_struct + stop_struct from a posted um[] / um_new[] row.
     * Returns ['start_struct' => json|null, 'stop_struct' => json|null].
     */
    /** Load period list for a med row. Returns array of {start,end} or [] if none. */
    private static function loadMedPeriods( $m ): array {
        if ( !$m ) return [];
        if ( !empty( $m->um_periods ) ) {
            $d = json_decode( (string)$m->um_periods, true );
            if ( is_array( $d ) ) return $d;
        }
        $start = null;
        $end   = null;
        if ( !empty( $m->um_start_struct ) ) {
            $s = json_decode( (string)$m->um_start_struct, true );
            if ( is_array( $s ) && ( $s['kind'] ?? '' ) === 'point' ) $start = $s['point'] ?? null;
        }
        if ( !empty( $m->um_stop_struct ) ) {
            $s = json_decode( (string)$m->um_stop_struct, true );
            if ( is_array( $s ) && ( $s['kind'] ?? '' ) === 'point' ) $end = $s['point'] ?? null;
        }
        if ( $start || $end ) return [ [ 'start' => $start, 'end' => $end ] ];
        return [];
    }

        /**
     * Parse periods[] array from a posted um row. Returns JSON-encoded array of
     * {start, end} objects (each from a range-locked widget), or null if no periods.
     */
    private static function parseMedPeriods( array $f ): ?string {
        if ( empty( $f['periods'] ) || !is_array( $f['periods'] ) ) return null;
        $out = [];
        foreach ( $f['periods'] as $raw ) {
            if ( !is_string( $raw ) || $raw === '' || $raw[0] !== '{' ) continue;
            $parsed = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $raw );
            if ( !$parsed || ( $parsed['kind'] ?? '' ) !== 'range' ) continue;
            $start = $parsed['start'] ?? null;
            $end   = $parsed['end']   ?? null;
            if ( !$start && !$end ) continue;
            $out[] = [ 'start' => $start, 'end' => $end ];
        }
        return $out ? json_encode( $out, JSON_UNESCAPED_UNICODE ) : null;
    }

        private static function parseMedDateStructs( array $f ): array {
        $out = [ 'start_struct' => null, 'stop_struct' => null ];
        foreach ( [ 'start_struct', 'stop_struct' ] as $k ) {
            if ( empty( $f[ $k ] ) ) continue;
            $raw = (string)$f[ $k ];
            if ( $raw === '' || $raw[0] !== '{' ) continue;
            $parsed = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $raw );
            if ( $parsed ) {
                $out[ $k ] = json_encode( $parsed, JSON_UNESCAPED_UNICODE );
            }
        }
        return $out;
    }

    public static function saveMeds( $store, int $profileId, $request ) {
        // Delete
        $deleteId = (int)$request->getVal( "um_delete", 0 );
        if ( $deleteId > 0 ) {
            $store->deleteMed( $deleteId, $profileId );
            return;
        }
        // Updates
        $existing = $request->getArray( "um" ) ?: [];
        foreach ( $existing as $id => $f ) {
            $id = (int)$id;
            if ( $id <= 0 ) continue;
            if ( empty( $f["med_name"] ) ) { $store->deleteMed( $id, $profileId ); continue; }
            $umDates = self::parseMedDateStructs( $f );
            $umPeriods = self::parseMedPeriods( $f );
            $store->updateMed( $id, $profileId, [
                "med_name"      => $f["med_name"],
                "page_id"       => isset( $f["page_id"] ) && (int)$f["page_id"] > 0 ? (int)$f["page_id"] : null,
                "efficacy"      => isset( $f["efficacy"] )      && $f["efficacy"]      !== "" ? (int)$f["efficacy"]      : null,
                "burden"        => isset( $f["burden"] )        && $f["burden"]        !== "" ? (int)$f["burden"]        : null,
                "duration_days" => isset( $f["duration_days"] ) && $f["duration_days"] !== "" ? (int)$f["duration_days"] : null,
                "dose_mg"       => isset( $f["dose_mg"] )       && $f["dose_mg"]       !== "" ? (float)$f["dose_mg"]     : null,
                "route"         => isset( $f["route"] )         && $f["route"]         !== "" ? (string)$f["route"]      : null,
                "schedule"      => isset( $f["schedule"] )      && $f["schedule"]      !== "" ? trim( (string)$f["schedule"] ) : null,
                "current"       => isset( $f["current"] )       && (int)$f["current"]  > 0    ? (int)$f["current"]       : null,
                "start_struct"  => $umDates["start_struct"],
                "stop_struct"   => $umDates["stop_struct"],
                "periods"       => $umPeriods,
                "notes"         => $f["notes"] ?? null,
                "visibility"    => isset( $f["visibility"] )    ? (int)$f["visibility"]      : 0,
            ] );
        }
        // New rows
        $news = $request->getArray( "um_new" ) ?: [];
        foreach ( $news as $f ) {
            if ( empty( $f["med_name"] ) ) continue;
            $umDates = self::parseMedDateStructs( $f );
            $umPeriods = self::parseMedPeriods( $f );
            $store->addMed( $profileId, [
                "med_name"      => $f["med_name"],
                "page_id"       => isset( $f["page_id"] ) && (int)$f["page_id"] > 0 ? (int)$f["page_id"] : null,
                "efficacy"      => isset( $f["efficacy"] )      && $f["efficacy"]      !== "" ? (int)$f["efficacy"]      : null,
                "burden"        => isset( $f["burden"] )        && $f["burden"]        !== "" ? (int)$f["burden"]        : null,
                "duration_days" => isset( $f["duration_days"] ) && $f["duration_days"] !== "" ? (int)$f["duration_days"] : null,
                "dose_mg"       => isset( $f["dose_mg"] )       && $f["dose_mg"]       !== "" ? (float)$f["dose_mg"]     : null,
                "route"         => isset( $f["route"] )         && $f["route"]         !== "" ? (string)$f["route"]      : null,
                "schedule"      => isset( $f["schedule"] )      && $f["schedule"]      !== "" ? trim( (string)$f["schedule"] ) : null,
                "current"       => isset( $f["current"] )       && (int)$f["current"]  > 0    ? (int)$f["current"]       : null,
                "start_struct"  => $umDates["start_struct"],
                "stop_struct"   => $umDates["stop_struct"],
                "periods"       => $umPeriods,
                "notes"         => $f["notes"] ?? null,
                "visibility"    => isset( $f["visibility"] )    ? (int)$f["visibility"]      : 0,
            ] );
        }
    }

}
