/* WP License Manager — Admin JavaScript */
/* global jQuery, wplmAdmin */

( function ( $ ) {
	'use strict';

	// ── Product panel: toggle licensing fields ────────────────────────────
	function toggleLicenseFields() {
		var enabled = $( '#_wplm_is_licensed' ).is( ':checked' );
		$( '.wplm-license-conditional' ).toggle( enabled );
	}

	$( document ).on( 'change', '#_wplm_is_licensed', toggleLicenseFields );

	// Run on load.
	if ( $( '#_wplm_is_licensed' ).length ) {
		toggleLicenseFields();
	}

	// ── Confirm dangerous actions (terminate, bulk-revoke) ───────────────
	$( document ).on( 'click', '.wplm-confirm-action', function ( e ) {
		var msg = $( this ).data( 'confirm' ) || wplmAdmin.confirmText;
		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
			return false;
		}
	} );

	// ── License list: copy key to clipboard ──────────────────────────────
	$( document ).on( 'click', '.wplm-copy-key', function () {
		var key = $( this ).data( 'key' );
		if ( navigator.clipboard && key ) {
			navigator.clipboard.writeText( key ).then( function () {
				alert( wplmAdmin.copiedText );
			} );
		}
	} );

	// ── Settings: warn before keypair re-roll ────────────────────────────
	$( '#wplm-reroll-keypair' ).on( 'click', function ( e ) {
		var msg = wplmAdmin.rerollWarning ||
			'Re-rolling the keypair will invalidate all previously signed license tokens. Are you sure?';
		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	} );

	// ── License edit form: searchable ID fields (product / order / user) ─
	$( '.wplm-id-search' ).each( function () {
		var $txt    = $( this );
		var type    = $txt.data( 'type' );
		var $hidden = $( '#' + $txt.data( 'hidden' ) );

		$txt.autocomplete( {
			minLength: 2,
			delay: 300,
			source: function ( req, respond ) {
				$.ajax( {
					url:    wplmAdmin.ajaxUrl,
					method: 'GET',
					data: {
						action: 'wplm_search_' + type,
						nonce:  wplmAdmin.searchNonce,
						q:      req.term
					},
					success: function ( res ) {
						respond( res.success ? res.data : [] );
					},
					error: function () {
						respond( [] );
					}
				} );
			},
			select: function ( _e, ui ) {
				$txt.val( ui.item.label );
				$hidden.val( ui.item.value );
				return false;
			},
			change: function ( _e, ui ) {
				if ( ! ui.item ) {
					// If user typed a plain number, accept it as the ID directly.
					var raw = $.trim( $txt.val() );
					if ( /^\d+$/.test( raw ) ) {
						$hidden.val( raw );
					} else if ( raw === '' ) {
						$hidden.val( '' );
					}
				}
			}
		} );
	} );

} )( jQuery );
