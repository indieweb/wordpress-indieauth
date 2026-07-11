<?php
/**
 * Tests for the backward-compatibility class aliases for pre-4.7.0 class names.
 *
 * @package IndieAuth
 */
class Test_Compat extends WP_UnitTestCase {

	/**
	 * The IndieAuth_Plugin alias must exist without triggering the autoloader,
	 * so that `class_exists( 'IndieAuth_Plugin', false )` checks also work.
	 */
	public function test_indieauth_plugin_alias_is_registered_eagerly() {
		$this->assertTrue( class_exists( 'IndieAuth_Plugin', false ) );
	}

	/**
	 * Map of pre-4.7.0 global class names to their namespaced replacements.
	 *
	 * @return array
	 */
	public function legacy_class_provider() {
		return array(
			array( 'External_Token_Page', \IndieAuth\Ticket\External_Token_Page::class ),
			array( 'External_Token_Table', \IndieAuth\WP_Admin\External_Token_List_Table::class ),
			array( 'External_User_Token', \IndieAuth\Ticket\External_User_Token::class ),
			array( 'IndieAuth_Admin', \IndieAuth\WP_Admin\Admin::class ),
			array( 'IndieAuth_Authorization_Endpoint', \IndieAuth\Rest\Authorization_Controller::class ),
			array( 'IndieAuth_Authorize', \IndieAuth\Authorize::class ),
			array( 'IndieAuth_Client', \IndieAuth\Client::class ),
			array( 'IndieAuth_Client_Discovery', \IndieAuth\Client_Discovery::class ),
			array( 'IndieAuth_Client_Taxonomy', \IndieAuth\Client_Taxonomy::class ),
			array( 'IndieAuth_Debug', \IndieAuth\Debug::class ),
			array( 'IndieAuth_FedCM_Endpoint', \IndieAuth\Rest\FedCM_Controller::class ),
			array( 'IndieAuth_Introspection_Endpoint', \IndieAuth\Rest\Introspection_Controller::class ),
			array( 'IndieAuth_Metadata_Endpoint', \IndieAuth\Rest\Metadata_Controller::class ),
			array( 'IndieAuth_Plugin', \IndieAuth\IndieAuth::class ),
			array( 'IndieAuth_Revocation_Endpoint', \IndieAuth\Rest\Revocation_Controller::class ),
			array( 'IndieAuth_Scope', \IndieAuth\Scope\Scope::class ),
			array( 'IndieAuth_Scopes', \IndieAuth\Scopes::class ),
			array( 'IndieAuth_Ticket_Endpoint', \IndieAuth\Rest\Ticket_Controller::class ),
			array( 'IndieAuth_Token_Endpoint', \IndieAuth\Rest\Token_Controller::class ),
			array( 'IndieAuth_Token_UI', \IndieAuth\WP_Admin\Token_UI::class ),
			array( 'IndieAuth_Userinfo_Endpoint', \IndieAuth\Rest\Userinfo_Controller::class ),
			array( 'IndieAuth_WebIdentity', \IndieAuth\WebIdentity::class ),
			array( 'Token_Generic', \IndieAuth\Token\Generic::class ),
			array( 'Token_List_Table', \IndieAuth\WP_Admin\Token_List_Table::class ),
			array( 'Token_Transient', \IndieAuth\Token\Transient::class ),
			array( 'Token_User', \IndieAuth\Token\User::class ),
			array( 'WP_OAuth_Response', \IndieAuth\OAuth_Response::class ),
			array( 'Web_Signin', \IndieAuth\Web_Signin::class ),
		);
	}

	/**
	 * Every legacy class name must resolve, via autoloading, to its namespaced replacement.
	 *
	 * @dataProvider legacy_class_provider
	 *
	 * @param string $legacy  Pre-4.7.0 global class name.
	 * @param string $current Namespaced replacement class.
	 */
	public function test_legacy_class_alias( $legacy, $current ) {
		/*
		 * Lazily aliased classes must trigger a deprecation notice on first use.
		 * `IndieAuth_Plugin` is aliased eagerly and stays notice-free, because
		 * plugins like Micropub use it for feature detection. ClassicPress does
		 * not have `_deprecated_class()`, so no notice is triggered there.
		 */
		if ( ! class_exists( $legacy, false ) && function_exists( '_deprecated_class' ) ) {
			$this->setExpectedDeprecated( $legacy );
		}

		$this->assertTrue( class_exists( $legacy ), "Legacy class {$legacy} does not exist." );

		$reflection = new ReflectionClass( $legacy );
		$this->assertSame( $current, $reflection->getName(), "Legacy class {$legacy} is not an alias of {$current}." );
	}

	/**
	 * The Micropub plugin gates its whole initialization on this check.
	 *
	 * @see https://github.com/indieweb/wordpress-indieauth/issues/319
	 */
	public function test_micropub_indieauth_detection() {
		$this->assertTrue( \class_exists( 'IndieAuth_Plugin' ) );
	}
}
