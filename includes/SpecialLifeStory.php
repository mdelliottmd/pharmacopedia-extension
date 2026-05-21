<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Read-only view of another user's life story, visibility-gated.
 *   Special:LifeStory/<username>
 * Sysop sees everything. Self sees everything (and gets a link back to MyLifeStory).
 */
class SpecialLifeStory extends SpecialPage {

    public function __construct() {
        parent::__construct( 'LifeStory' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.datepicker.styles' ] );

        $par = trim( (string)$par );
        if ( $par === '' ) {
            $out->setPageTitle( 'Life story' );
            $out->addHTML( '<p>No username specified. Use <code>Special:LifeStory/&lt;username&gt;</code>.</p>' );
            return;
        }

        $profileStore = new UserProfileStore();
        $profile = $profileStore->getByUsername( $par );
        if ( !$profile ) {
            $out->setPageTitle( "Life story: $par" );
            $out->addHTML( '<p>No profile found for <strong>' . htmlspecialchars( $par ) . '</strong>.</p>' );
            return;
        }

        $viewer = $this->getUser();
        $isSelf  = $viewer->isRegistered() && (int)$profile->prof_user_id === $viewer->getId();
        $isSysop = $viewer->isAllowed( 'pharmacopedia-profile-view-others-full' );
        $canSeePrivate = $isSelf || $isSysop;
        $minVis = $canSeePrivate ? 0 : 1;

        $displayHeader = $profileStore->publicDisplayName( $profile, UserProfileStore::VIS_PUBLIC_DEFAULT );
        $out->setPageTitle( 'Life story: ' . ( $canSeePrivate ? $par : $displayHeader ) );

        $out->addHTML( '<div class="pcp-banner">' );
        $out->addHTML( '<span class="pcp-banner__title">' . htmlspecialchars( $displayHeader ) . '</span>' );
        if ( $isSelf ) {
            $url = htmlspecialchars( SpecialPage::getTitleFor( 'MyLifeStory' )->getLocalURL() );
            $out->addHTML( '<span class="pcp-banner__body">This is your life story. <a href="' . $url . '">Edit it on Special:MyLifeStory</a>.</span>' );
        } elseif ( $isSysop ) {
            $out->addHTML( '<span class="pcp-banner__body"><strong>Viewing as sysop</strong> — all events shown including private ones.</span>' );
        } else {
            $out->addHTML( '<span class="pcp-banner__body">Public view. Only events the user has chosen to share are displayed.</span>' );
        }
        $out->addHTML( '</div>' );

        $store = new LifeStoryStore();

        // Phase 2: charts — visible to self/sysop only (keyframe values are sensitive)
        if ( $canSeePrivate ) {
            $series = $store->getKeyframeTimeseries( (int)$profile->prof_id );
            if ( $series ) {
                $scales = [
                    'pid5bf' => [ 'min' => 0,  'max' => 3 ],
                    'catq'   => [ 'min' => 0,  'max' => 175 ],
                    'raadsr' => [ 'min' => 0,  'max' => 240 ],
                    'ocean'  => [ 'min' => 0,  'max' => 100 ],
                ];
                $titles = [
                    'pid5bf' => 'PID-5-BF trajectory',
                    'catq'   => 'CAT-Q trajectory',
                    'raadsr' => 'RAADS-R trajectory',
                    'ocean'  => 'OCEAN trajectory',
                ];
                $out->addHTML( '<details class="pcp-life-charts" open><summary>📈 Personality trajectory</summary>' );
                foreach ( $series as $ns => $traits ) {
                    $scale = $scales[ $ns ] ?? null;
                    $title = $titles[ $ns ] ?? ( $ns . ' trajectory' );
                    if ( $ns === 'custom' ) {
                        $normalized = [];
                        foreach ( $traits as $key => $pts ) {
                            $vals = array_column( $pts, 'value' );
                            $mins = array_filter( array_column( $pts, 'min' ), function ( $v ) { return $v !== null; } );
                            $maxs = array_filter( array_column( $pts, 'max' ), function ( $v ) { return $v !== null; } );
                            $lo = $mins ? min( $mins ) : min( $vals );
                            $hi = $maxs ? max( $maxs ) : max( $vals );
                            if ( abs( $hi - $lo ) < 0.0001 ) $hi = $lo + 1;
                            $np = [];
                            foreach ( $pts as $p ) {
                                $np[] = [
                                    'date'      => $p['date'],
                                    'value'     => ( $p['value'] - $lo ) / ( $hi - $lo ),
                                    'label'     => $p['label'] . ' (norm)',
                                    'estimated' => $p['estimated'],
                                ];
                            }
                            $normalized[ $key ] = $np;
                        }
                        $svg = LifeStoryStore::renderTrajectorySvg( 'Custom traits (normalized 0–1)',
                            $normalized, [ 'min' => 0, 'max' => 1 ] );
                    } else {
                        $svg = LifeStoryStore::renderTrajectorySvg( $title, $traits, $scale );
                    }
                    $out->addHTML( '<div class="pcp-life-chart-wrap">' . $svg . '</div>' );
                }
                $out->addHTML( '</details>' );
            }
        }

        // Events, optionally merged with derived (self/sysop only)
        $events = $store->getEventsForProfile( (int)$profile->prof_id, $minVis );
        $derivedOn = $canSeePrivate && $this->getRequest()->getBool( 'derived', true );
        if ( $derivedOn ) {
            $derived = $store->getDerivedEvents( (int)$profile->prof_id, (string)$profile->prof_voter_hash );
            $events = array_merge( $events, $derived );
            usort( $events, function ( $a, $b ) {
                $da = (string)( $a->le_date_iso ?? '' );
                $db = (string)( $b->le_date_iso ?? '' );
                if ( $da === '' && $db !== '' ) return 1;
                if ( $db === '' && $da !== '' ) return -1;
                return strcmp( $da, $db );
            } );
        }
        if ( !$events ) {
            $out->addHTML( '<p><em>No events to show.</em></p>' );
            return;
        }

        $lastYear = null;
        $myls = new SpecialMyLifeStory();
        foreach ( $events as $e ) {
            $year = $e->le_date_iso ? (int)substr( (string)$e->le_date_iso, 0, 4 ) : null;
            if ( $year !== $lastYear ) {
                $out->addHTML( '<h2 class="pcp-life-year">' .
                    ( $year !== null ? (int)$year : 'undated' ) . '</h2>' );
                $lastYear = $year;
            }
            $out->addHTML( $this->renderReadOnlyCard( $store, $e, $isSysop ) );
        }
    }

    private function renderReadOnlyCard( $store, $event, bool $isSysop ): string {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        if ( !empty( $event->_is_derived ?? false ) ) {
            $source = (string)( $event->_source ?? '' );
            $sourceLabel = [ 'diagnosis' => 'diagnosis', 'med' => 'medicine', 'xr' => 'experience report' ][ $source ] ?? $source;
            $dateText = $event->le_date_display ?: (string)$event->le_date_iso;
            $out  = '<div class="pcp-life-card pcp-life-derived">';
            $out .= '<div class="pcp-life-card-header">';
            $out .= '<span class="pcp-life-date">' . $h( $dateText ) . '</span> ';
            $out .= '<span class="pcp-life-type-badge pcp-life-derived-badge">derived &middot; ' . $h( $sourceLabel ) . '</span>';
            $out .= '</div>';
            $out .= '<h3 class="pcp-life-title">' . $h( $event->le_title ) . '</h3>';
            if ( $event->le_body !== null && $event->le_body !== '' ) {
                $out .= '<div class="pcp-life-body">' . nl2br( $h( (string)$event->le_body ) ) . '</div>';
            }
            $out .= '</div>';
            return $out;
        }
        $typeLabel = [ 0=>'story', 1=>'event', 2=>'keyframe', 3=>'observation', 4=>'episode' ][ (int)$event->le_type ] ?? 'story';
        $visIcon   = [ 0=>'🔒', 1=>'👁', 2=>'🆔', 3=>'🎭' ][ (int)$event->le_visibility ] ?? '🔒';

        // Prefer structured payload when present, fall back to legacy columns
        $dateText = '';
        if ( !empty( $event->le_date_struct ) ) {
            $sd = json_decode( (string)$event->le_date_struct, true );
            if ( is_array( $sd ) ) {
                $dateText = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $sd );
            }
        }
        if ( $dateText === '' ) {
            if ( $event->le_date_display !== null && (string)$event->le_date_display !== '' ) {
                $dateText = (string)$event->le_date_display;
            } else {
                $iso = (string)$event->le_date_iso;
                $prec = (int)$event->le_date_precision;
                if ( $iso === '' ) $dateText = 'undated';
                elseif ( $prec === LifeStoryStore::DP_YEAR )   $dateText = substr( $iso, 0, 4 );
                elseif ( $prec === LifeStoryStore::DP_MONTH )  $dateText = substr( $iso, 0, 7 );
                elseif ( $prec === LifeStoryStore::DP_DECADE ) $dateText = substr( $iso, 0, 3 ) . '0s';
                else $dateText = $iso;
            }
        }

        $out = '<div class="pcp-life-card pcp-life-type-' . $h( $typeLabel ) . '">';
        $out .= '<div class="pcp-life-card-header">';
        $out .= '<span class="pcp-life-date">' . $h( $dateText ) . '</span> ';
        $out .= '<span class="pcp-life-type-badge">' . $h( $typeLabel ) . '</span>';
        if ( $isSysop ) {
            $out .= ' <span class="pcp-life-vis-badge" title="visibility">' . $visIcon . '</span>';
        }
        $out .= '</div>';
        $out .= '<h3 class="pcp-life-title">' . $h( $event->le_title ) . '</h3>';
        if ( $event->le_body !== null && $event->le_body !== '' ) {
            $out .= '<div class="pcp-life-body">' . nl2br( $h( (string)$event->le_body ) ) . '</div>';
        }

        $images = $store->getImagesForEvent( (int)$event->le_id );
        if ( $images ) {
            $out .= '<div class="pcp-life-images">';
            foreach ( $images as $im ) {
                $url = htmlspecialchars( SpecialPage::getTitleFor( 'LifeImage',
                    (int)$event->le_id . '/' . (int)$im->li_id )->getLocalURL() );
                $alt = $h( $im->li_caption ?? $im->li_orig_name );
                $out .= '<figure class="pcp-life-img"><img src="' . $url . '" alt="' . $alt . '">';
                if ( $im->li_caption ) {
                    $out .= '<figcaption>' . $h( $im->li_caption ) . '</figcaption>';
                }
                $out .= '</figure>';
            }
            $out .= '</div>';
        }

        if ( (int)$event->le_type === LifeStoryStore::TYPE_KEYFRAME ) {
            $traits = $store->getTraitsForEvent( (int)$event->le_id );
            if ( $traits ) {
                $out .= '<table class="pcp-pa-table pcp-life-traits"><tbody>';
                foreach ( $traits as $t ) {
                    $label = $t->lt_label ? (string)$t->lt_label : (string)$t->lt_namespace . '/' . (string)$t->lt_key;
                    $v = rtrim( rtrim( (string)$t->lt_value_num, '0' ), '.' );
                    $est = (int)$t->lt_estimated ? ' <em>(estimated)</em>' : '';
                    $out .= '<tr><th>' . $h( $label ) . '</th><td>' . $h( $v ) . $est . '</td></tr>';
                }
                $out .= '</tbody></table>';
            }
        }

        if ( $event->le_tags ) {
            $tags = array_filter( array_map( 'trim', explode( ',', (string)$event->le_tags ) ) );
            if ( $tags ) {
                $out .= '<div class="pcp-life-tags">';
                foreach ( $tags as $t ) {
                    $out .= '<span class="pcp-life-tag">' . $h( $t ) . '</span> ';
                }
                $out .= '</div>';
            }
        }

        $out .= '</div>';
        return $out;
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'users'; }
}
