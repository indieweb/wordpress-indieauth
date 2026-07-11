<?php
/**
 * Backward compatibility aliases for pre-4.7.0 class names.
 *
 * Version 4.7.0 moved all classes into the `IndieAuth` namespace. Third-party
 * plugins (e.g. Micropub) check for the old global class names, so they are
 * kept available as aliases.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

/**
 * Map of pre-4.7.0 global class names to their namespaced replacements.
 *
 * The abstract `IndieAuth_Endpoint` class is the only pre-4.7.0 class without
 * an alias; its functionality moved to the `IndieAuth\Rest\Token_Management`
 * trait, which a class alias cannot represent.
 */
const COMPAT_CLASS_MAP = array(
	'External_Token_Page'              => Ticket\External_Token_Page::class,
	'External_Token_Table'             => WP_Admin\External_Token_List_Table::class,
	'External_User_Token'              => Ticket\External_User_Token::class,
	'IndieAuth_Admin'                  => WP_Admin\Admin::class,
	'IndieAuth_Authorization_Endpoint' => Rest\Authorization_Controller::class,
	'IndieAuth_Authorize'              => Authorize::class,
	'IndieAuth_Client'                 => Client::class,
	'IndieAuth_Client_Discovery'       => Client_Discovery::class,
	'IndieAuth_Client_Taxonomy'        => Client_Taxonomy::class,
	'IndieAuth_Debug'                  => Debug::class,
	'IndieAuth_FedCM_Endpoint'         => Rest\FedCM_Controller::class,
	'IndieAuth_Introspection_Endpoint' => Rest\Introspection_Controller::class,
	'IndieAuth_Metadata_Endpoint'      => Rest\Metadata_Controller::class,
	'IndieAuth_Plugin'                 => IndieAuth::class,
	'IndieAuth_Revocation_Endpoint'    => Rest\Revocation_Controller::class,
	'IndieAuth_Scope'                  => Scope\Scope::class,
	'IndieAuth_Scopes'                 => Scopes::class,
	'IndieAuth_Ticket_Endpoint'        => Rest\Ticket_Controller::class,
	'IndieAuth_Token_Endpoint'         => Rest\Token_Controller::class,
	'IndieAuth_Token_UI'               => WP_Admin\Token_UI::class,
	'IndieAuth_Userinfo_Endpoint'      => Rest\Userinfo_Controller::class,
	'IndieAuth_WebIdentity'            => WebIdentity::class,
	'Token_Generic'                    => Token\Generic::class,
	'Token_List_Table'                 => WP_Admin\Token_List_Table::class,
	'Token_Transient'                  => Token\Transient::class,
	'Token_User'                       => Token\User::class,
	'WP_OAuth_Response'                => OAuth_Response::class,
	'Web_Signin'                       => Web_Signin::class,
);

\spl_autoload_register(
	function ( $class_name ) {
		if ( ! isset( COMPAT_CLASS_MAP[ $class_name ] ) ) {
			return;
		}

		// `_deprecated_class()` requires WordPress 6.4.
		if ( \function_exists( '_deprecated_class' ) ) {
			\_deprecated_class( $class_name, '4.7.0', COMPAT_CLASS_MAP[ $class_name ] );
		}

		\class_alias( COMPAT_CLASS_MAP[ $class_name ], $class_name );
	}
);

/*
 * Micropub gates its whole initialization on `class_exists( 'IndieAuth_Plugin' )`,
 * and other plugins may check with autoloading disabled, so this alias is
 * registered eagerly instead of through the autoloader above.
 */
\class_alias( IndieAuth::class, 'IndieAuth_Plugin' );
