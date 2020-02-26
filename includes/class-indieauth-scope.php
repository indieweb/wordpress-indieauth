<?php

/**
 * Class used to define a scope
 */
class IndieAuth_Scope {
	public $name; // Name of the Scope

	public $label; // Description of the Scope

	public $capabilities; // List of capabilities the scope contains.

	public function __construct( $name, $label, $capabilities ) {
		$this->name         = $name;
		$this->label        = $label;
		$this->capabilities = $capabilities;
	}

	/**
	 * Assign a capability.
	 *
	 * @param string $cap Capability name.
	 */
	public function add_cap( $cap ) {
		$this->capabilities[] = $cap;
	}

	/**
	 * Removes a capability from a role.
	 *
	 *
	* @param string $cap Capability name.                                      */
	public function remove_cap( $cap ) {
		unset( $this->capabilities[ $cap ] );
	}

	/**
	 * Determines whether the scope has the given capability.
	 *
	 * The capabilities is passed through the {@see 'scope_has_cap'} filter.
	 * The first parameter for the hook is the list of capabilities the class
	 * has assigned. The second parameter is the capability name to look for.
	 * The third and final parameter for the hook is the scope name.
	 *
	 * @param string $cap Capability name.
	 * @return bool True if the scope has the given capability. False otherwise.
											*/
	public function has_cap( $cap ) {
		/**
		 * Filters which capabilities a scope has.
		 *
		 * @param array $capabilities Array of capabilities for the role.
		 * @param string $cap          Capability name.
		 * @param string $name         Scope name.
		 */
		$capabilities = apply_filters( 'scope_has_cap', $this->capabilities, $cap, $this->name );
		return in_array( $cap, $capabilities, true );
	}
}

