<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * OAuth Response class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

/**
 * OAuth Response class extending WP_REST_Response.
 *
 * @since 1.0.0
 */
class OAuth_Response extends \WP_REST_Response {

	/**
	 * Whether the error was reported already.
	 *
	 * @var bool
	 */
	protected $logged = false;

	/**
	 * Constructor.
	 *
	 * @param string     $error             Error code.
	 * @param string     $error_description Error description.
	 * @param int        $code              HTTP status code.
	 * @param array|null $debug             Optional debug information.
	 * @param bool       $log               Whether to report the error right away, see log().
	 */
	public function __construct( $error, $error_description, $code = 200, $debug = null, $log = true ) {
		$this->set_status( $code );
		$this->set_data(
			array(
				'error'             => $error,
				'error_description' => $error_description,
			)
		);
		if ( is_array( $debug ) && ! empty( $debug ) ) {
			$this->set_debug( $debug );
		}
		if ( $log ) {
			$this->log();
		}
	}

	/**
	 * Report the error, at most once.
	 *
	 * An error that may not be sent, like a default handed to a filter, is
	 * created with `$log` set to false and reported here once it is returned.
	 */
	public function log() {
		if ( $this->logged ) {
			return;
		}
		$this->logged = true;

		/**
		 * Fires when IndieAuth answers a request with an error.
		 *
		 * Covers the IndieAuth endpoints and requests with an IndieAuth token. It
		 * also fires for a request to any other REST route that fails with a
		 * bearer token IndieAuth does not know, if no other plugin authenticated
		 * the user. Anonymous requests can trigger it, so a listener that stores
		 * the errors should limit how many it keeps.
		 *
		 * @param OAuth_Response $response The error response. Its data holds `error` and `error_description`.
		 */
		\do_action( 'indieauth_error', $this );

		if ( WP_DEBUG ) {
			\error_log( $this->to_log() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Set debug information.
	 *
	 * @param array $debug_data Debug data to merge.
	 */
	public function set_debug( $debug_data ) {
		$data = $this->get_data();
		$this->set_data( array_merge( $data, $debug_data ) );
	}

	/**
	 * Convert to WP_Error.
	 *
	 * @return \WP_Error The error object.
	 */
	public function to_wp_error() {
		$data              = $this->get_data();
		$error             = $data['error'];
		$error_description = $data['error_description'];
		unset( $data['error'] );
		unset( $data['error_description'] );
		$status = $this->get_status();
		return new \WP_Error(
			$error,
			$error_description,
			array(
				'status' => $status,
				'data'   => $data,
			)
		);
	}

	/**
	 * Convert to log string.
	 *
	 * @return string Log message.
	 */
	public function to_log() {
		$data   = $this->get_data();
		$status = $this->get_status();
		return sprintf( 'IndieAuth Error: %1$s %2$s - %3$s %4$s', $status, $data['error'], $data['error_description'], \wp_json_encode( $data ) );
	}
}
