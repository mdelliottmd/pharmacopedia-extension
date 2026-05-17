<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:DatePickerTest — sandbox for the pcp-date-input widget.
 *
 *   Empty widget                        Point with seeded value
 *   Range with seeded start+end         Possibility with seeded options
 *
 * Form submits to itself; POST shows the validated / normalized struct +
 * the formatForDisplay() output + the sortKeyIso() result.
 */
class SpecialDatePickerTest extends SpecialPage {

    public function __construct() {
        parent::__construct( 'DatePickerTest' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Date picker test' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.datepicker.styles' ] );
        $out->addModules( [ 'ext.pharmacopedia.datepicker' ] );

        $user = $this->getUser();
        $request = $this->getRequest();

        // Handle POST: show parsed/sanitized output for each widget
        if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $out->addHTML( '<h2>Submitted</h2>' );
            foreach ( [ 'empty', 'point', 'range', 'possibility' ] as $key ) {
                $json = (string)$request->getVal( $key, '' );
                $struct = DatePicker::parseSubmitted( $json );
                $out->addHTML( '<h3>' . htmlspecialchars( $key ) . '</h3>' );
                $out->addHTML( '<dl>' );
                $out->addHTML( '<dt>raw JSON</dt><dd><code>' . htmlspecialchars( $json ?: '(empty)' ) . '</code></dd>' );
                $out->addHTML( '<dt>parsed struct</dt><dd><pre>' .
                    htmlspecialchars( $struct ? json_encode( $struct, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '(null — invalid or empty)' ) .
                    '</pre></dd>' );
                $out->addHTML( '<dt>formatForDisplay</dt><dd><code>' .
                    htmlspecialchars( DatePicker::formatForDisplay( $struct ) ?: '(empty)' ) . '</code></dd>' );
                $out->addHTML( '<dt>sortKeyIso</dt><dd><code>' .
                    htmlspecialchars( DatePicker::sortKeyIso( $struct ) ?? '(null)' ) . '</code></dd>' );
                $out->addHTML( '</dl>' );
            }
            $out->addHTML( '<hr>' );
        }

        // Seed values that demonstrate pre-fill from server data
        $seedPoint = [
            'kind'  => 'point',
            'point' => [
                'raw_text' => '2015-03-04',
                'parsed'   => [ 'kind' => 'point', 'precision' => 'day',
                                'display' => null, 'year' => 2015, 'month' => 3, 'day' => 4,
                                'iso' => '2015-03-04' ],
                'time'     => null,
                'timezone' => null,
                'effective_iso' => '2015-03-04',
            ],
        ];
        $seedRange = [
            'kind'  => 'range',
            'start' => [
                'raw_text' => 'summer 2008',
                'parsed'   => [ 'kind' => 'fuzzy', 'precision' => 'season',
                                'display' => 'Summer 2008', 'year' => 2008, 'month' => 7, 'day' => 1,
                                'iso' => '2008-07-01' ],
                'time' => null, 'timezone' => null,
                'effective_iso' => '2008-07-01',
            ],
            'end'   => [
                'raw_text' => '2015',
                'parsed'   => [ 'kind' => 'point', 'precision' => 'year',
                                'display' => null, 'year' => 2015, 'month' => 1, 'day' => 1,
                                'iso' => '2015-01-01' ],
                'time' => null, 'timezone' => null,
                'effective_iso' => '2015-01-01',
            ],
        ];
        $seedPoss = [
            'kind' => 'possibility',
            'options' => [
                [ 'raw_text' => '2007', 'parsed' => [ 'kind'=>'point','precision'=>'year','display'=>null,'year'=>2007,'month'=>1,'day'=>1,'iso'=>'2007-01-01' ],
                  'time'=>null,'timezone'=>null,'effective_iso'=>'2007-01-01' ],
                [ 'raw_text' => '2008', 'parsed' => [ 'kind'=>'point','precision'=>'year','display'=>null,'year'=>2008,'month'=>1,'day'=>1,'iso'=>'2008-01-01' ],
                  'time'=>null,'timezone'=>null,'effective_iso'=>'2008-01-01' ],
            ],
        ];

        $action = $this->getPageTitle()->getLocalURL();
        $token  = htmlspecialchars( $user->getEditToken() );
        $out->addHTML(
            '<form method="post" action="' . htmlspecialchars( $action ) . '">' .
            '<input type="hidden" name="title" value="' . htmlspecialchars( $this->getPageTitle()->getPrefixedDBkey() ) . '">' .
            '<input type="hidden" name="wpEditToken" value="' . $token . '">'
        );

        $out->addHTML( '<h2>Empty widget</h2>' );
        $out->addHTML( DatePicker::renderWidget( 'empty' ) );

        $out->addHTML( '<h2>Pre-filled: point</h2>' );
        $out->addHTML( DatePicker::renderWidget( 'point', $seedPoint ) );

        $out->addHTML( '<h2>Pre-filled: range</h2>' );
        $out->addHTML( DatePicker::renderWidget( 'range', $seedRange ) );

        $out->addHTML( '<h2>Pre-filled: possibility</h2>' );
        $out->addHTML( DatePicker::renderWidget( 'possibility', $seedPoss ) );

        $out->addHTML(
            '<div style="margin:1em 0;">' .
            '<button type="submit" class="pcp-btn pcp-btn-primary">Submit all four</button>' .
            '</div></form>'
        );
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'pharmacopedia'; }
}
