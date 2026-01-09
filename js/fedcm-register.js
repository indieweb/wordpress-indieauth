/**
 * FedCM IdP Registration
 *
 * Automatically registers this site as a FedCM Identity Provider.
 *
 * @see https://indieweb.org/FedCM_for_IndieAuth
 * @see https://fedidcg.github.io/FedCM/#idp-registration
 */
( function() {
	'use strict';

	var configUrl = typeof indieAuthFedCM !== 'undefined' ? indieAuthFedCM.configUrl : null;

	if ( ! configUrl ) {
		return;
	}

	if ( typeof IdentityProvider === 'undefined' || typeof IdentityProvider.register !== 'function' ) {
		return;
	}

	IdentityProvider.register( configUrl ).catch( function() {
		// Registration failed or was rejected - this is fine, fail silently.
	} );
} )();
