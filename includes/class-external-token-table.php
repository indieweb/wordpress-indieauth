<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class External_Token_Table extends WP_List_Table {
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'resource'      => __( 'Resource', 'indieauth' ),
			'refresh_token' => __( 'Refresh Token', 'indieauth' ),
			'issued_at'     => __( 'Issue Date', 'indieauth' ),
			'expiration'    => __( 'Expires', 'indieauth' ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'revoke' => __( 'Revoke', 'indieauth' ),
			'verify' => __( 'Verify', 'indieauth' ),
		);
	}

	public function get_sortable_columns() {
		return array();
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden  = array();
		$this->process_action();
		$this->_column_headers = array( $columns, $hidden, $this->get_sortable_columns() );
		$tokens                = new External_User_Token();
		$tokens                = $tokens->get_all();
		if ( empty( $tokens ) ) {
			return;
		}

		$this->items = array();
		$this->set_pagination_args(
			array(
				'total_items' => count( $tokens ),
				'total_pages' => 1,
				'per_page'    => count( $tokens ),
			)
		);
		foreach ( $tokens as $value ) {
			$this->items[] = $value;
		}
	}

	public function column_default( $item, $column_name ) {
		if ( ! array_key_exists( $column_name, $item ) ) {
			return __( 'None', 'indieauth' );
		}
		return $item[ $column_name ];
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="tokens[]" value="%s" />', esc_attr( $item['access_token'] ) );
	}

	public function process_action() {
		$t = isset( $_REQUEST['tokens'] ) ? $_REQUEST['tokens'] : array(); // phpcs:ignore
		$tokens = new External_User_Token();
		switch ( $this->current_action() ) {
			case 'revoke':
				$tokens->destroy( $t );
				break;
			case 'verify':
				$return = $tokens->verify( $t );
				error_log( 'Verify:' . wp_json_encode( $return ) );
				break;
			default:
		}
	}

	public function column_expiration( $item ) {
		if ( ! isset( $item['expiration'] ) ) {
			return __( 'Never', 'indieauth' );
		}
		$time      = (int) $item['expiration'];
		$time_diff = time() - $time;
		if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
			// translators: Human time difference ago
			return sprintf( __( '%s ago', 'indieauth' ), human_time_diff( $time ) );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	public function column_issued_at( $item ) {
		if ( ! array_key_exists( 'iat', $item ) ) {
			return __( 'None', 'indieauth' );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['iat'] );
	}

	public function column_refresh_token( $item ) {
		if ( ! array_key_exists( 'refresh_token', $item ) ) {
			return __( 'None', 'indieauth' );
		}

		return __( 'Set', 'indieauth' );
	}

	public function column_resource( $item ) {
		if ( ! array_key_exists( 'resource', $item ) ) {
			return __( 'None', 'indieauth' );
		}
		return wp_parse_url( $item['resource'], PHP_URL_HOST );
	}
}
