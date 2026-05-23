/**
 * ext.pharmacopedia.administer.js
 *
 * Progressive enhancement for the "Administer to others" respondent
 * take-flow (Special:RespondToAssessment). CSP-clean: no inline handlers.
 *
 * Behaviours:
 *   - every slider gets a live numeric readout in its <output>;
 *   - ticking an item's "Not sure" box disables that item's answer inputs;
 *   - on a gated_count item (mixed-model EDE-Q-PCP and similar), the count
 *     field reveals when the gate radio is set to yes, and the count field
 *     is required only in that state.
 *
 * The form works without this script: sliders carry a value, radios are
 * required, and submitted gated counts default to "no" as zero on the
 * server side.
 */
( function () {
	'use strict';

	function init() {
		// Live numeric readout for every slider. A slider may carry an
		// optional data-precision (decimal places) and data-unit (suffix);
		// both default to the raw slider value without a suffix.
		var sliders = document.querySelectorAll( '.pcp-adm-slider' );
		Array.prototype.forEach.call( sliders, function ( slider ) {
			var out = slider.parentNode
				? slider.parentNode.querySelector( '.pcp-adm-sliderval' )
				: null;
			var precRaw = slider.getAttribute( 'data-precision' );
			var unit    = slider.getAttribute( 'data-unit' ) || '';
			var prec    = ( precRaw === null || precRaw === '' )
				? null
				: parseInt( precRaw, 10 );
			function sync() {
				if ( !out ) {
					return;
				}
				var text;
				if ( prec !== null && !isNaN( prec ) ) {
					text = parseFloat( slider.value ).toFixed( prec );
				} else {
					text = slider.value;
				}
				if ( unit ) {
					text += ' ' + unit;
				}
				out.textContent = text;
			}
			sync();
			slider.addEventListener( 'input', sync );
		} );

		// "Not sure" disables that item's answer inputs (ranges, radios,
		// numbers). A disabled required field is skipped by form validation.
		var unsures = document.querySelectorAll(
			'.pcp-adm-unsure input[type=checkbox]'
		);
		Array.prototype.forEach.call( unsures, function ( cb ) {
			function apply() {
				var item = cb.closest ? cb.closest( '.pcp-assess-item' ) : null;
				if ( !item ) {
					return;
				}
				var inputs = item.querySelectorAll(
					'input[type=range], input[type=radio], input[type=number]'
				);
				Array.prototype.forEach.call( inputs, function ( inp ) {
					// Never disable the "Not sure" checkbox itself.
					if ( inp.type === 'checkbox' ) {
						return;
					}
					inp.disabled = cb.checked;
				} );
				item.classList.toggle( 'pcp-adm-item-unsure', cb.checked );
			}
			cb.addEventListener( 'change', apply );
			apply();
		} );

		// height items (std vs metric): show the matching std-or-metric
		// group on the active unit, hide the other. Std mode has two int
		// fields (feet, inches); metric has one cm field.
		var heights = document.querySelectorAll( '.pcp-adm-height' );
		Array.prototype.forEach.call( heights, function ( item ) {
			var unitRadios = item.querySelectorAll(
				'input[type=radio][name^="r_unit["]'
			);
			var groups = item.querySelectorAll( '.pcp-adm-height-group' );
			function apply() {
				var picked = 'std';
				Array.prototype.forEach.call( unitRadios, function ( r ) {
					if ( r.checked ) {
						picked = r.value;
					}
				} );
				Array.prototype.forEach.call( groups, function ( g ) {
					g.hidden = ( g.getAttribute( 'data-height-unit' ) !== picked );
				} );
			}
			Array.prototype.forEach.call( unitRadios, function ( r ) {
				r.addEventListener( 'change', apply );
			} );
			apply();
		} );

		// gated_count items: reveal the count field on yes, hide on no.
		// The count field is required only while visible; when hidden, it
		// is unrequired and emptied so the form does not block on it.
		var gated = document.querySelectorAll( '.pcp-adm-gated' );
		Array.prototype.forEach.call( gated, function ( item ) {
			var gateRadios = item.querySelectorAll(
				'input[type=radio][name^="r_gate["]'
			);
			var reveal = item.querySelector( '.pcp-adm-gated-reveal' );
			var countInput = reveal
				? reveal.querySelector( 'input[type=number]' )
				: null;
			if ( !reveal || !countInput ) {
				return;
			}

			function apply() {
				var picked = null;
				Array.prototype.forEach.call( gateRadios, function ( r ) {
					if ( r.checked ) {
						picked = r.value;
					}
				} );
				if ( picked === 'yes' ) {
					reveal.hidden = false;
					countInput.required = true;
				} else {
					reveal.hidden = true;
					countInput.required = false;
					countInput.value = '';
				}
			}
			Array.prototype.forEach.call( gateRadios, function ( r ) {
				r.addEventListener( 'change', apply );
			} );
			apply();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
