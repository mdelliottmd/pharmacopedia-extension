/**
 * ext.pharmacopedia.administer.js
 *
 * Progressive enhancement for the "Administer to others" respondent
 * take-flow (Special:RespondToAssessment). CSP-clean: no inline handlers.
 *
 * Two behaviours:
 *   - every slider gets a live numeric readout in its <output>;
 *   - ticking an item's "Not sure" box disables that item's slider or
 *     radios (a disabled required field is skipped by form validation).
 *
 * The form works without this script: sliders carry a value and radios
 * are required; this only adds the readout and the Not-sure convenience.
 */
( function () {
	'use strict';

	function init() {
		// Live numeric readout for every slider.
		var sliders = document.querySelectorAll( '.pcp-adm-slider' );
		Array.prototype.forEach.call( sliders, function ( slider ) {
			var out = slider.parentNode
				? slider.parentNode.querySelector( '.pcp-adm-sliderval' )
				: null;
			function sync() {
				if ( out ) {
					out.textContent = slider.value;
				}
			}
			sync();
			slider.addEventListener( 'input', sync );
		} );

		// "Not sure" disables that item's slider or radio buttons.
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
					'input[type=range], input[type=radio]'
				);
				Array.prototype.forEach.call( inputs, function ( inp ) {
					inp.disabled = cb.checked;
				} );
				item.classList.toggle( 'pcp-adm-item-unsure', cb.checked );
			}
			cb.addEventListener( 'change', apply );
			apply();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
