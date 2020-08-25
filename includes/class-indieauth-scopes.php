<?php

/**
 * Container Class used to hold all of the registed scopes
 */
class IndieAuth_Scopes {
	private $scopes; // Stores All Registered Scopes

	public function __construct() {
		$this->scopes = array();
		$this->register_builtin_scopes();
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 20, 4 );
	}

	/**
	 * Offers a list of caps to be checked in the function below. Allows custom capabilities to not be filtered
	 */
	public function map_caps() {
		return apply_filters( 'indieauth_meta_caps', array( 'publish_posts', 'delete_users', 'edit_users', 'remove_users', 'promote_users', 'delete_posts', 'delete_pages', 'edit_posts', 'edit_pages', 'read_posts', 'read_pages', 'unfiltered_html' ) );
	}

	/**
	 * Filters a user's capabilities depending on token scope if in the list of appropriate ones.
	 *
	 * @param string[] $caps    Array of the user's capabilities.
	 * @param string   $cap     Capability name.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Adds the context to the cap. Typically the object ID.
	 * @return string[] $caps    Filtered array of user capabilities after factoring in the token permissions.
	 */

	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		// If this is not null this is an indieauth response
		$response = indieauth_get_response();
		if ( ! empty( $response ) ) {
			$scopes = indieauth_get_scopes();
			if ( empty( $scopes ) ) {
				return array( 'do_not_allow' );
			}

			// This check is only for certain capabilities
			if ( ! in_array( $cap, $this->map_caps(), true ) ) {
				if ( WP_DEBUG ) {
					error_log( sprintf( __( 'Unknown cap: %1s', 'indieauth' ), $cap ) ); // phpcs:ignore
				}
				return $caps;
			}
			foreach ( $caps as $c ) {
				// If the capability is not in any of the scopes then do not allow
				if ( ! $this->has_cap( $c, $scopes ) ) {
					return array( 'do_not_allow' );
				}
			}
		}
		return $caps;
	}

	/**
	 * Register Scopes. Built-In scopes are the ones documented in https://indieweb.org/scope.
	 * Scopes for Microsub are provided for benefit of Aperture plugin for WordPress which has this as a dependency.
	 */
	public function register_builtin_scopes() {
		$this->register_scope(
			new IndieAuth_Scope(
				'create',
				__( 'Allows the application to create posts', 'indieauth' ),
				array(
					'edit_posts',
					'edit_published_posts',
					'delete_posts',
					'publish_posts',
					'read',
					'unfiltered_html',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'draft',
				__( 'Allows the application to create draft posts only.', 'indieauth' ),
				array(
					'edit_posts',
					'unfiltered_html',
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'update',
				__( 'Allows the application to update posts', 'indieauth' ),
				array(
					'edit_published_posts',
					'edit_others_posts',
					'read',
					'unfiltered_html',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'delete',
				__( 'Allows the application to delete or undelete posts', 'indieauth' ),
				array(
					'delete_posts',
					'delete_published_posts',
					'delete_others_posts',
					'read',
				)
			)
		);
		$this->register_scope(
			new IndieAuth_Scope(
				'media',
				__( 'Allows the application to upload to the media endpoint', 'indieauth' ),
				array(
					'upload_files',
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'read',
				__( 'Allows the application read access to Microsub channels', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'follow',
				__( 'Allows the application to manage a Microsub following list', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'mute',
				__( 'Allows the application to mute and unmute users in a Microsub channel', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'block',
				__( 'Allows the application to block users in a Microsub channel', 'indieauth' ),
				array(
					'read',
				)
			)
		);
		$this->register_scope(
			new IndieAuth_Scope(
				'channels',
				__( 'Allows the application to manage Microsub channels', 'indieauth' ),
				array(
					'read',
				)
			)
		);
		$this->register_scope(
			new IndieAuth_Scope(
				'save',
				__( 'Allows the application to save content for later retrieval', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->register_scope(
			new IndieAuth_Scope(
				'profile',
				__( 'Returns a complete profile to the application as part of the IndieAuth response. Without this only a display name, avatar, and url will be returned', 'indieauth' ),
				array(
					'read',
				)
			)
		);
	}

	/* Register a scope
	 *
	 * @param IndieAuth_Scope $scope
	 *
	 * @return boolean Wheterh successful or not
	 */

	public function register_scope( $scope ) {
		if ( ! $scope instanceof IndieAuth_Scope ) {
			return false;
		}
		$this->scopes[ $scope->name ] = $scope;
		return true;
	}

	/* Deregister scope by name
	 *
	 * @param string $name
	 *
	 */
	public function deregister_scope( $name ) {
		unset( $this->scopes['name'] );
	}

	/* Retrieve a scope by name
	 *
	 * @param string Scope Name
	 *
	 * @return null|IndieAuth_Scope
	 */
	public function get_scope( $name ) {
		if ( array_key_exists( $name, $this->scopes ) ) {
			return $this->scopes[ $name ];
		}
		return null;
	}

	/* Return a list of scope names
	 *
	 * @return Array of Scope Names
	 *
	 */
	public function get_names() {
		return array_keys( $this->scopes );
	}

	/* Confirm if a scope exists by that name
	 *
	 * @param string $name Name Being Checked.
	 *
	 * @return boolean If exists.
	 */
	public function is_scope( $name ) {
		return array_key_exists( $name, $this->scopes );
	}

	/* Confirm if a capability is in one of the scopes provided.
	 *
	 * @param string $cap Capability
	 * @param string|string[] $scopes Array of scopes or single scope.
	 *
	 * @return boolean If one of the capabilities is in the scope
	 *
	 */
	public function has_cap( $cap, $scopes ) {
		if ( is_string( $scopes ) ) {
			$scopes = array( $scopes );
		}
		foreach ( $scopes as $name ) {
			$scope = $this->get_scope( $name );
			if ( $scope && $scope->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

new IndieAuth_Scopes();
