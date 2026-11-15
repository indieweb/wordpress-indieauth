<?php
/**
 * IndieAuth Site Health.
 *
 * @package IndieAuth
 */

namespace IndieAuth\WP_Admin;

use IndieAuth\Rest\Metadata_Controller;

use function IndieAuth\add_query_params_to_url;
use function IndieAuth\indieauth_get_issuer;
use function IndieAuth\indieauth_get_metadata_endpoint;
use function IndieAuth\indieauth_get_root_user;

/**
 * Site Health tests and debug information for IndieAuth.
 */
class Health_Check {
	/**
	 * Register the hooks.
	 */
	public static function init() {
		\add_filter( 'site_status_tests', array( self::class, 'add_tests' ) );
		\add_filter( 'debug_information', array( self::class, 'debug_information' ) );
		\add_action( 'wp_ajax_health-check-indieauth-header', array( self::class, 'ajax_header_test' ) );
	}

	/**
	 * Add the IndieAuth tests.
	 *
	 * @param array $tests Existing tests.
	 * @return array Modified tests.
	 */
	public static function add_tests( $tests ) {
		/*
		 * Asynchronous, like every core test that makes an HTTP request, so
		 * the loopback never holds up the Site Health screen. Core builds the
		 * admin-ajax action from the test name by replacing the first
		 * underscore, so the name must carry exactly one. The cron run is
		 * kept, so a stripped header counts in the Site Health menu bubble.
		 */
		$tests['async']['indieauth_header']     = array(
			'label'             => \__( 'IndieAuth Test', 'indieauth' ),
			'test'              => 'indieauth_header',
			'async_direct_test' => array( self::class, 'site_health_header_test' ),
		);
		$tests['direct']['indieauth_https']     = array(
			'label' => \__( 'SSL Test', 'indieauth' ),
			'test'  => array( self::class, 'site_health_https_test' ),
		);
		$tests['direct']['indieauth_mbstring']  = array(
			'label' => \__( 'IndieAuth mbstring Test', 'indieauth' ),
			'test'  => array( self::class, 'site_health_mbstring_test' ),
		);
		$tests['direct']['indieauth_metadata']  = array(
			'label' => \__( 'IndieAuth Metadata Test', 'indieauth' ),
			'test'  => array( self::class, 'site_health_metadata_test' ),
		);
		$tests['direct']['indieauth_root_user'] = array(
			'label' => \__( 'IndieAuth Site User Test', 'indieauth' ),
			'test'  => array( self::class, 'site_health_root_user_test' ),
		);
		return $tests;
	}

	/**
	 * Add IndieAuth debug information.
	 *
	 * @param array $info Debug information.
	 * @return array Modified debug information.
	 */
	public static function debug_information( $info ) {
		$root_user  = \get_userdata( indieauth_get_root_user() );
		$expires_in = (int) \get_option( 'indieauth_expires_in' );
		$issuer     = \wp_parse_url( Metadata_Controller::get_issuer(), PHP_URL_PATH );

		$info['indieauth'] = array(
			'label'  => \__( 'IndieAuth', 'indieauth' ),
			'fields' => array(
				'root_user'         => array(
					'label' => \__( 'User represented by the site URL', 'indieauth' ),
					'value' => $root_user ? $root_user->display_name : \__( 'None', 'indieauth' ),
				),
				'expires_in'        => array(
					'label' => \__( 'Token expiration (seconds)', 'indieauth' ),
					'value' => $expires_in ? $expires_in : \__( 'Never', 'indieauth' ),
				),
				'show_login_form'   => array(
					'label' => \__( 'Web Sign-In on the login form', 'indieauth' ),
					'value' => \get_option( 'indieauth_show_login_form' ) ? \__( 'Yes', 'indieauth' ) : \__( 'No', 'indieauth' ),
				),
				'ticket_endpoint'   => array(
					'label' => \__( 'Ticket endpoint', 'indieauth' ),
					'value' => INDIEAUTH_TICKET_ENDPOINT ? \__( 'Enabled', 'indieauth' ) : \__( 'Disabled', 'indieauth' ),
				),
				'issuer'            => array(
					'label' => \__( 'Issuer', 'indieauth' ),
					'value' => indieauth_get_issuer(),
				),
				'metadata_endpoint' => array(
					'label' => \__( 'Metadata endpoint', 'indieauth' ),
					'value' => indieauth_get_metadata_endpoint(),
				),
				'well_known_url'    => array(
					'label' => \__( 'RFC 8414 well-known URL', 'indieauth' ),
					'value' => Metadata_Controller::has_well_known_url() ? \home_url( '/.well-known/oauth-authorization-server/' . \trim( $issuer, '/' ) ) : \__( 'Not available', 'indieauth' ),
				),
			),
		);

		return $info;
	}

	/**
	 * Run the header test for the Site Health screen.
	 */
	public static function ajax_header_test() {
		\check_ajax_referer( 'health-check-site-status' );

		if ( ! \current_user_can( 'view_site_health_checks' ) ) {
			\wp_send_json_error( null, 403 );
		}

		\wp_send_json_success( self::site_health_header_test() );
	}

	/**
	 * Site health test for HTTPS.
	 *
	 * @return array Test result.
	 */
	public static function site_health_https_test() {
		$result = array(
			'label'       => \__( 'HTTPS Check Passed', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'You are using HTTPS and IndieAuth will be secure', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_https',
		);

		if ( ! \is_ssl() ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = \__( 'HTTPS Test Failed', 'indieauth' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\__( 'You are not serving your site via HTTPS. This is a security risk if running IndieAuth.', 'indieauth' )
			);
			$result['actions']        = \__( 'We recommend you acquire an SSL Certificate. You can do this for free through Lets Encrypt', 'indieauth' );
		}

		return $result;
	}

	/**
	 * Site health test for authorization headers.
	 *
	 * @return array Test result.
	 */
	public static function site_health_header_test() {
		$result = array(
			'label'       => \__( 'Authorization Header Passed', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'Your hosting provider allows authorization headers to pass so IndieAuth should work', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_header',
		);

		$state = self::test_header();

		if ( 'missing' === $state ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = \__( 'Authorization Test Failed', 'indieauth' );
			\ob_start();
			include INDIEAUTH_PLUGIN_DIR . 'templates/authdiagfail.php';
			$result['description'] = \ob_get_contents();
			\ob_end_clean();
			$result['actions'] = \sprintf( '<a href="%1$s" >%2$s</a>', 'https://github.com/indieweb/wordpress-indieauth/issues', \__( 'If contacting your hosting provider does not work you can open an issue on GitHub and we will try to assist', 'indieauth' ) );
		} elseif ( 'untested' === $state ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = \__( 'IndieAuth could not test the Authorization header', 'indieauth' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\__( 'The test request to wp-login.php on your site got no answer from IndieAuth, so it was not possible to test if the Authorization header is passed. Your site may not be able to reach itself, or a security plugin or firewall may block requests to wp-login.php.', 'indieauth' )
			);
		}

		return $result;
	}

	/**
	 * Test if authorization headers pass through.
	 *
	 * A failed loopback request says nothing about the header, so it is
	 * reported apart from a missing header.
	 *
	 * @return string 'passed', 'missing' or 'untested'.
	 */
	public static function test_header() {
		$url = add_query_params_to_url(
			array(
				'action' => 'authdiag',
			),
			\wp_login_url()
		);

		$response = \wp_remote_post(
			$url,
			array(
				'method'    => 'POST',
				'timeout'   => 10,
				'headers'   => array(
					'Authorization' => 'Bearer SjdWwSPRi9rdNzyKVDiZRkXhm0fxP0lAmksJXNOgwc7SYREqJnDpXky1MCbIW6UNAFqCwXHswKGaps2lSZfwpYEZnIdREikjiKKSE6UJNlJ3NLXyvyFSQdzUiRg531uG',
					'Accept'        => 'application/json',
				),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => \apply_filters( 'https_local_ssl_verify', false, $url ),
			)
		);

		if ( \is_wp_error( $response ) || 200 !== \wp_remote_retrieve_response_code( $response ) ) {
			return 'untested';
		}

		$json = \json_decode( \wp_remote_retrieve_body( $response ) );

		if ( ! \is_object( $json ) || ! isset( $json->success ) ) {
			return 'untested';
		}

		return true === $json->success ? 'passed' : 'missing';
	}

	/**
	 * Site health test for the PHP mbstring extension.
	 *
	 * Reading the information of a client uses mb_convert_encoding().
	 *
	 * @return array Test result.
	 */
	public static function site_health_mbstring_test() {
		$result = array(
			'label'       => \__( 'The PHP mbstring extension is available', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'IndieAuth can read the name and icon of the apps you authorize.', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_mbstring',
		);

		if ( ! \function_exists( 'mb_convert_encoding' ) ) {
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']          = \__( 'The PHP mbstring extension is missing', 'indieauth' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\__( 'IndieAuth needs the PHP mbstring extension to read the information of the apps you authorize. Without it, authorizing an app fails with an error.', 'indieauth' )
			);
			$result['actions']        = \__( 'Ask your hosting provider to enable the PHP mbstring extension.', 'indieauth' );
		}

		return $result;
	}

	/**
	 * Site health test for the RFC 8414 well-known metadata URL.
	 *
	 * @return array Test result.
	 */
	public static function site_health_metadata_test() {
		$result = array(
			'label'       => \__( 'The IndieAuth metadata is available at the well-known URL', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'Clients can find the IndieAuth metadata through the link on your site and at the well-known URL defined by RFC 8414.', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_metadata',
		);

		if ( ! Metadata_Controller::has_well_known_url() ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = \__( 'The IndieAuth metadata is not available at the well-known URL', 'indieauth' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\__( 'Your site uses plain permalinks, or WordPress is installed in a subdirectory, so the metadata cannot be served at the well-known URL defined by RFC 8414. IndieAuth clients still find it through the link on your site, but clients that only check the well-known URL do not.', 'indieauth' )
			);
			$result['actions']        = \sprintf(
				'<a href="%1$s">%2$s</a>',
				\esc_url( \admin_url( 'options-permalink.php' ) ),
				\__( 'Change the permalink settings', 'indieauth' )
			);
		}

		return $result;
	}

	/**
	 * Site health test for the user that represents the site URL.
	 *
	 * @return array Test result.
	 */
	public static function site_health_root_user_test() {
		$result = array(
			'label'       => \__( 'IndieAuth knows who the site URL represents', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'A user is set to represent the site URL, or you chose that nobody does.', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_root_user',
		);

		// A saved "None" is stored as '0' and is a choice; only a never saved setting is a problem.
		if ( null === \get_option( 'indieauth_root_user', null ) && 0 === indieauth_get_root_user() ) {
			$path = \class_exists( 'IndieWeb_Plugin' ) ? 'admin.php?page=indieauth' : 'options-general.php?page=indieauth';

			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = \__( 'No user represents the site URL', 'indieauth' );
			$result['description']    = \sprintf(
				'<p>%s</p>',
				\__( 'Your site has more than one author, so IndieAuth cannot tell who signs in with the site URL. Signing in with the URL of your site does not work until you choose a user.', 'indieauth' )
			);
			$result['actions']        = \sprintf(
				'<a href="%1$s">%2$s</a>',
				\esc_url( \admin_url( $path ) ),
				\__( 'Choose a user in the IndieAuth settings', 'indieauth' )
			);
		}

		return $result;
	}
}
