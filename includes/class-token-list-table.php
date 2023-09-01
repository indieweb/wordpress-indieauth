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
			'iat'           => __( 'Issue Date', 'indieauth' ),
			'last_accessed' => __( 'Last Accessed', 'indieauth' ),
			'exp'           => __( 'Expires', 'indieauth' ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'revoke'   => __( 'Revoke', 'indieauth' ),
			'renew'    => __( 'Renew', 'indieauth' ),
			'noexpire' => __( 'Disable Expiry', 'indieauth' ),
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
		// Always refresh the list of token users while loading this page.
		$t->find_token_users( true );
		$tokens      = $t->get_all();
		$this->items = array();
		$this->set_pagination_args(
			array(
				'total_items' => count( $tokens ),
				'total_pages' => 1,
				'per_page'    => count( $tokens ),
			)
		);
		foreach ( $tokens as $key => $value ) {
			$value['token'] = $key;
			$this->items[]  = $value;
		}
	}

	public function column_default( $item, $column_name ) {
		return $item[ $column_name ];
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="tokens[]" value="%s" />', esc_attr( $item['token'] ) );
	}

	public function process_action() {
		$tokens = isset( $_REQUEST['tokens'] ) ? $_REQUEST['tokens'] : array(); // phpcs:ignore
		$t      = new Token_User( '_indieauth_token_', get_current_user_id() );
		switch ( $this->current_action() ) {
			case 'revoke':
				if ( is_string( $tokens ) ) {
					$t->destroy( $tokens );
				} elseif ( is_array( $tokens ) ) {
					foreach ( $tokens as $token ) {
						$t->destroy( $token );
					}
				}
				break;
			case 'renew':
				if ( is_string( $tokens ) ) {
					$t->renew( $tokens );
				} elseif ( is_array( $tokens ) ) {
					foreach ( $tokens as $token ) {
						$t->renew( $token );
					}
				}
				break;
			case 'noexpire':
				if ( is_string( $tokens ) ) {
					$t->noexpire( $tokens );
				} elseif ( is_array( $tokens ) ) {
					foreach ( $tokens as $token ) {
						$t->noexpire( $token );
					}
				}
				break;
			case 'retrieve':
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
				break;
		}
	}

	public function destroy_older_than( $t, $older_than = 'day' ) {
		switch ( strtolower( $older_than ) ) {
			case 'year':
				$diff = YEAR_IN_SECONDS;
				break;
			case 'month':
				$diff = MONTH_IN_SECONDS;
				break;
			case 'week':
				$diff = WEEK_IN_SECONDS;
				break;
			case 'day':
				$diff = DAY_IN_SECONDS;
				break;
			default:
				$diff = HOUR_IN_SECONDS;
		}
		$tokens = $t->get_all();
		foreach ( $tokens as $key => $token ) {
			if ( ! isset( $token['last_accessed'] ) ) {
				$t->destroy( $key );
			} else {
				$time      = (int) $token['last_accessed'];
				$time_diff = time() - $time;
				if ( $time_diff > 0 && $time_diff > $diff ) {
					$t->destroy( $key );
				}
			}
		}
	}

	public function column_client_name( $item ) {
		$uri     = wp_doing_ajax() ? wp_get_referer() : $_SERVER['REQUEST_URI'];
		$uri     = urlencode( wp_unslash( $uri ) );
		$actions = array(
			'retrieve' => sprintf( '<a href="?page=indieauth_user_token&action=retrieve&tokens=%1$s&wp_http_referer=%2$s">%3$s</a>', $item['token'], $uri, __( 'Retrieve Information', 'indieauth' ) ),
		);
		if ( ! isset( $item['client_name'] ) ) {
			if ( isset( $item['client_id'] ) ) {
				$client              = IndieAuth_Client_Taxonomy::get_client( $item['client_id'] );
				$item['client_name'] = $client['name'];
			} else {
				$item['client_name'] = __( 'Not Provided', 'indieauth' );
			}
		}
		return sprintf( '%1$s  %2$s', $item['client_name'], $this->row_actions( $actions ) );
	}

	public function column_client_icon( $item ) {
		if ( empty( $item['client_icon'] ) ) {
			if ( isset( $item['client_id'] ) ) {
				$client = IndieAuth_Client_Taxonomy::get_client( $item['client_id'] );
				if ( is_wp_error( $client ) ) {
					return __( 'None', 'indieauth' );
				}
				$item['client_icon'] = $client['icon'];
			} else {
				return __( 'None', 'indieauth' );
			}
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


	public function column_exp( $item ) {
		// Check for old property.
		if ( isset( $item['expiration'] ) ) {
			$item['exp'] = $item['expiration'];
		}

		if ( ! isset( $item['exp'] ) ) {
			return __( 'Never', 'indieauth' );
		}
		$time      = (int) $item['exp'];
		$time_diff = time() - $time;
		if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
			// translators: Human time difference ago
			return sprintf( __( '%s ago', 'indieauth' ), human_time_diff( $time ) );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}


	public function column_iat( $item ) {
		// Check for old property.
		if ( isset( $item['issued_at'] ) ) {
			$item['iat'] = $item['issued_at'];
		}
		return wp_date( get_option( 'date_format' ), $item['iat'] );
	}

	public function column_client_id( $item ) {
		return wp_parse_url( $item['client_id'], PHP_URL_HOST );
	}
}
