<?php

/**
 * Container Class used to hold all of the registed scopes
 */
class IndieAuth_Scopes {
	private static $scopes; // Stores All Registered Scopes

	public function __construct() {
		$this->scopes = array();
		$this->register_builtin_scopes();
	}

	/**
	 * Register Scopes
	 */
	public function register_builtin_scopes() {
		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'create',
				__( 'Allows the application to create posts and upload to the Media Endpoint', 'indieauth' ),
				array(
					'edit_posts',
					'edit_published_posts',
					'delete_posts',
					'publish_posts',
					'upload_files',
					'read',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'draft',
				__( 'Allows the application to create draft posts only.', 'indieauth' ),
				array(
					'edit_posts',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'update',
				__( 'Allows the application to update posts', 'indieauth' ),
				array(
					'edit_published_posts',
					'edit_others_posts',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'delete',
				__( 'Allows the application to delete or undelete posts', 'indieauth' ),
				array(
					'delete_posts',
					'delete_published_posts',
					'delete_others_posts',
				)
			)
		);
		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'media',
				__( 'Allows the application to upload to the media endpoint', 'indieauth' ),
				array(
					'upload_files',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'read',
				__( 'Allows the application read access to Microsub channels', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'follow',
				__( 'Allows the application to manage a Microsub following list', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'mute',
				__( 'Allows the application to mute and unmute users in a Microsub channel', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'block',
				__( 'Allows the application to block users in a Microsub channel', 'indieauth' ),
				array(
					'read',
				)
			)
		);
		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'channels',
				__( 'Allows the application to manage Microsub channels', 'indieauth' ),
				array(
					'read',
				)
			)
		);
		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'save',
				__( 'Allows the application to save content for later retrieval', 'indieauth' ),
				array(
					'read',
				)
			)
		);

		$this->scopes->register_scope(
			new IndieAuth_Scope(
				'profile',
				__( 'Returns a complete profile to the application as part of the IndieAuth response. Without this only a display name, avatar, and url will be returned', 'indieauth' ),
				array(
					'read',
				)
			)
		);
	}

	public function register_scope( $scope ) {
		if ( ! $scope instanceof IndieAuth_Scope ) {
			return false;
		}
		$this->scopes[ $scope->name ] = $scope;
		return true;
	}

	public function deregister_scope( $name ) {
		unset( $this->scopes['name'] );
	}

	public function get_scope( $name ) {
		if ( array_key_exists( $name, $this->scopes ) ) {
			return $this->scopes[ $name ];
		}
		return null;
	}

	public function get_names() {
		return array_keys( $this->scopes );
	}

	public function is_scope( $name ) {
		return array_key_exists( $name, $this->scopes );
	}
}

