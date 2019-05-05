<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Token_List_Table extends WP_List_Table {
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'client_name'   => __( 'Client Name', 'indieauth' ),
			'client_icon'   => __( 'Client Icon', 'indieauth' ),
			'client_id'     => __( 'Client ID', 'indieauth' ),
			'scope'         => __( 'Scope', 'indieauth' ),
			'issued_at'     => __( 'Issue Date', 'indieauth' ),
			'last_accessed' => __( 'Last Accessed', 'indieauth' ),
		);
	}

	public function get_bulk_actions() {
			  return array(
				  'revoke' => __( 'Revoke', 'indieauth' ),
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
		$t                     = new Token_User( '_indieauth_token_', get_current_user_id() );
		$tokens                = $t->get_all();
		$this->items           = array();
		foreach ( $tokens as $key => $value ) {
			$value['token'] = $key;
			$this->items[]  = $value;
		}

	}

	public function column_default( $item, $column_name ) {
		return $item[ $column_name ];
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="tokens[]" value="%s" />', $item['token'] );
	}

	public function process_action() {
		$tokens = isset( $_REQUEST['tokens'] ) ? $_REQUEST['tokens'] : array(); // phpcs:ignore
		$t      = new Token_User( '_indieauth_token_', get_current_user_id() );
		if ( 'revoke' === $this->current_action() ) {
			if ( is_string( $tokens ) ) {
				$t->destroy( $tokens );
			} elseif ( is_array( $tokens ) ) {
				foreach ( $tokens as $token ) {
					$t->destroy( $token );
				}
			}
		}
		if ( 'retrieve' === $this->current_action() ) {
			if ( is_string( $tokens ) ) {
				$token = $t->get( $tokens, false );
				$info  = new IndieAuth_Client_Discovery( $token['client_id'] );
				$name  = $info->get_name();
				if ( isset( $name ) ) {
					$token['client_name'] = $name;
				}
				$icon = $info->get_icon();
				if ( isset( $icon ) ) {
					$token['client_icon'] = $icon;
				}
				$t->update( $tokens, $token, true );
			}
		}
	}

	public function column_client_name( $item ) {
		$actions = array(
			'revoke'   => sprintf( '<a href="?page=%1$s&action=%2$s&tokens=%3$s">%4$s</a>', 'indieauth_user_token', 'revoke', $item['token'], __( 'Revoke', 'indieauth' ) ),
			'retrieve' => sprintf( '<a href="?page=%1$s&action=%2$s&tokens=%3$s">%4$s</a>', 'indieauth_user_token', 'retrieve', $item['token'], __( 'Retrieve Information', 'indieauth' ) ),
		);
		if ( ! isset( $item['client_name'] ) ) {
			$item['client_name'] = __( 'Not Provided', 'indieauth' );
		}
		return sprintf( '%1$s  %2$s', $item['client_name'], $this->row_actions( $actions ) );

	}

	public function column_client_icon( $item ) {
		if ( empty( $item['client_icon'] ) ) {
			return 'None';
		}
		return sprintf( '<img src="%1$s" height="48" width="48" />', $item['client_icon'] );
	}

	public function column_last_accessed( $item ) {
		if ( ! isset( $item['last_accessed'] ) ) {
			return __( 'Never', 'indieauth' );
		}
		$time      = (int) $item['last_accessed'];
		$time_diff = time() - $time;
		if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
			// translators: Human time difference ago
			return sprintf( __( '%s ago', 'indieauth' ), human_time_diff( $time ) );
		}
		return date_i18n( get_option( 'date_format' ), $time );
	}

	public function column_issued_at( $item ) {
		return date_i18n( get_option( 'date_format' ), $item['issued_at'] );
	}

	public function column_client_id( $item ) {
		return wp_parse_url( $item['client_id'], PHP_URL_HOST );
	}
}
