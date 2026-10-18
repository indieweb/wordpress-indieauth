<?php

use IndieAuth\Rest\Metadata_Controller;

class Test_Metadata_Controller extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	// Resolve a path the way WP::parse_request() does and return the query of the first matching rule.
	public function resolve( $path ) {
		foreach ( (array) get_option( 'rewrite_rules' ) as $match => $query ) {
			if ( preg_match( "#^$match#", $path ) ) {
				return $query;
			}
		}
		return null;
	}

	// Drop any well-known rule an earlier test registered.
	public function remove_well_known_rules() {
		global $wp_rewrite;
		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $match ) {
			if ( false !== strpos( $match, 'oauth-authorization-server' ) ) {
				unset( $wp_rewrite->extra_rules_top[ $match ] );
			}
		}
	}

	/**
	 * RFC 8414 section 3: the well-known string goes between the host and the
	 * path of the issuer identifier.
	 */
	public function test_serves_metadata_at_rfc8414_well_known_url() {
		Metadata_Controller::add_rewrite_rules();
		flush_rewrite_rules( false );

		$path = '.well-known/oauth-authorization-server/' . rest_get_url_prefix() . '/indieauth/1.0';

		$this->assertSame( 'index.php?rest_route=/indieauth/1.0/metadata', $this->resolve( $path ) );
		$this->assertSame( 'index.php?rest_route=/indieauth/1.0/metadata', $this->resolve( $path . '/' ) );
	}

	// The bare well-known URL belongs to an issuer without a path, often another plugin.
	public function test_does_not_claim_the_bare_well_known_url() {
		Metadata_Controller::add_rewrite_rules();
		flush_rewrite_rules( false );

		$this->assertNotSame( 'index.php?rest_route=/indieauth/1.0/metadata', $this->resolve( '.well-known/oauth-authorization-server' ) );
	}

	// Without pretty permalinks the issuer has no path, so the rule would be the bare URL.
	public function test_adds_no_rule_with_plain_permalinks() {
		global $wp_rewrite;
		$this->remove_well_known_rules();
		$this->set_permalink_structure( '' );

		Metadata_Controller::add_rewrite_rules();

		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $match ) {
			$this->assertStringNotContainsString( 'oauth-authorization-server', $match );
		}
	}

	// A WordPress in a subdirectory cannot answer at the root of the host.
	public function test_adds_no_rule_in_a_subdirectory_install() {
		global $wp_rewrite;
		$this->remove_well_known_rules();
		$subdirectory = function ( $url ) {
			return str_replace( '://example.org', '://example.org/blog', $url );
		};
		add_filter( 'home_url', $subdirectory );
		add_filter( 'rest_url', $subdirectory );

		Metadata_Controller::add_rewrite_rules();

		remove_filter( 'home_url', $subdirectory );
		remove_filter( 'rest_url', $subdirectory );
		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $match ) {
			$this->assertStringNotContainsString( 'oauth-authorization-server', $match );
		}
	}
}
