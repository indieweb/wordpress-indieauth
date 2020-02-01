<?php

/**
 * Container Class used to hold all of the registed scopes
 */
class IndieAuth_Scopes {
	private static $scopes; // Stores All Registered Scopes

	public function __construct() {
		$this->scopes = array();
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

