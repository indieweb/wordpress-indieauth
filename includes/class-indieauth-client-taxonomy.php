<?php
/**
 * IndieAuth Client Taxonomy Class
 *
 * Registers the taxonomy and sets its behavior.
 *
 */

add_action( 'init', array( 'IndieAuth_Client_Taxonomy', 'init' ) );

/**
 * Class that handles the client taxonomy functions.
 */
final class IndieAuth_Client_Taxonomy {

	public static function init() {
		self::register();

		add_filter( 'terms_clauses', array( __CLASS__, 'terms_clauses' ), 11, 3 );

	}

	public static function terms_clauses( $clauses, $taxonomies, $args ) {
		global $wpdb;

		// This allows for using an exact search instead of a LIKE search by adding a straight description argument.
		if ( array_key_exists( 'description', $args ) ) {
			$clauses['where'] .= $wpdb->prepare( ' AND tt.description = %s', $args['description'] );
		}
		return $clauses;
	}

	/**
	 * Register the custom taxonomy for location.
	 */
	public static function register() {
		$labels = array(
			'name'          => _x( 'IndieAuth Applications', 'taxonomy general name', 'indieauth' ),
			'singular_name' => _x( 'IndieAuth Applications', 'taxonomy singular name', 'indieauth' ),
			'search_items'  => _x( 'Search IndieAuth Applications', 'search locations', 'indieauth' ),
			'popular_items' => _x( 'Popular Applications', 'popular locations', 'indieauth' ),
			'all_items'     => _x( 'All Applications', 'all taxonomy items', 'indieauth' ),
			'edit_item'     => _x( 'Edit Application', 'edit taxonomy item', 'indieauth' ),
			'view_item'     => _x( 'View Application Archive', 'view taxonomy item', 'indieauth' ),
			'update_item'   => _x( 'Update Application', 'update taxonomy item', 'indieauth' ),
			'add_new_item'  => _x( 'Add New Application', 'add taxonomy item', 'indieauth' ),
			'new_item_name' => _x( 'New Application', 'new taxonomy item', 'indieauth' ),
			'not found'     => _x( 'No applications found', 'no clients found', 'indieauth' ),
			'no_terms'      => _x( 'No applications', 'no locations', 'indieauth' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'hierarchical'       => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_nav_menus'  => true,
			'show_in_rest'       => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => false,
			'description'        => __( 'Stores information in IndieAuth Client Applications', 'indieauth' ),
		);

		$object_types = apply_filters( 'indieauth_client_taxonomy_object_types', array( 'post', 'page', 'attachment' ) );

		register_taxonomy( 'indieauth_client', $object_types, $args );

		register_meta(
			'term',
			'icon',
			array(
				'object_subtype'    => 'indieauth_client',
				'type'              => 'string',
				'description'       => __( 'IndieAuth Client Application Icon', 'indieauth' ),
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Add Client from Discovery
	 */
	public static function add_client_with_discovery( $url ) {
		$client = new IndieAuth_Client_Discovery( $url );
		return self::add_client( $url, $client->get_name(), $client->get_icon() );
	}

	/**
	 * Update Client Icon from Discovery
	 */
	public static function update_client_icon_from_discovery( $url ) {
		$current = self::get_client( $url );
		if ( ! $current ) {
			return false;
		}

		$client = new IndieAuth_Client_Discovery( $url );
		if ( ! $client->get_icon() ) {
			return false;
		}

		return self::sideload_icon( $client->get_icon(), $url );

	}



	/**
	 * Add a client as a term and return.
	 */
	public static function add_client( $url, $name = null, $icon = null ) {
		$exists = self::get_client( $url );

		// Do Not Overwrite if Already Exists.
		if ( ! is_wp_error( $exists ) ) {
			return $exists;
		}

		if ( empty( $name ) ) {
			$client = new IndieAuth_Client_Discovery( $url );
			if ( defined( 'INDIEAUTH_UNIT_TESTS' ) ) {
				return array(
					'client_id' => $url,
				);
			}
			return self::add_client( $url, $client->get_name(), $client->get_icon() );
		}

		$icon = self::sideload_icon( $icon, $url );

		$term = wp_insert_term(
			$name,
			'indieauth_client',
			array(
				'slug'        => sanitize_title( $name ),
				'description' => esc_url_raw( $url ),
			)
		);
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		add_term_meta( $term['term_id'], 'icon', $icon );
		return array_filter(
			array(
				'url'  => $url,
				'name' => $name,
				'id'   => $term['term_id'],
				'icon' => $icon,
			)
		);
	}

	 /**
	  * Get Client
	  */
	public static function get_client( $url = null ) {
		// If url is null retrieve all clients.
		if ( is_null( $url ) ) {
			$terms   = get_terms(
				array(
					'taxonomy'   => 'indieauth_client',
					'hide_empty' => false,
				)
			);
			$clients = array();
			foreach ( $terms as $term ) {
				$clients[] = array(
					'url'  => $term->description,
					'name' => $term->name,
					'id'   => $term->term_id,
					'icon' => get_term_meta( $term->term_id, 'icon', true ),
				);
			}
			return $clients;
		}

		if ( is_numeric( $url ) ) {
			$terms = array( get_term( $url, 'indieauth_client' ) );
			return $terms;
		} else {
			$terms = get_terms(
				array(
					'taxonomy'    => 'indieauth_client',
					'description' => $url,
					'hide_empty'  => false,
				)
			);
		}
		if ( empty( $terms ) ) {
			return new WP_Error( 'not_found', __( 'No Term Found', 'indieauth' ) );
		}

		if ( 1 !== count( $terms ) ) {
			return new WP_Error( 'multiples', __( 'Multiple Terms Found', 'indieauth' ), $terms );
		}

		$term = $terms[0];

		return array(
			'url'  => $term->description,
			'name' => $term->name,
			'id'   => $term->term_id,
			'icon' => get_term_meta( $term->term_id, 'icon', true ),
		);
	}

	 /**
	  * Delete a client
	  */
	public static function delete_client( $url ) {
		$client = self::get_client( $url );
		if ( ! $client ) {
			return false;
		}

		self::delete_icon_file( $client['icon'] );

		return wp_delete_term(
			$client['id'],
			'indieauth_client'
		);
	}

	/**
	 * Return upload directory.
	 *
	 * @param string $filepath File Path. Optional
	 * @param boolean $url Return a URL if true, otherwise the directory.
	 * @return string URL of upload directory.
	 */
	public static function upload_directory( $filepath = '', $url = false ) {
		$upload_dir  = wp_get_upload_dir();
		$upload_dir  = $url ? $upload_dir['baseurl'] : $upload_dir['basedir'];
		$upload_dir .= '/indieauth/icons/';
		$upload_dir  = apply_filters( 'indieauth_client_icon_directory', $upload_dir, $url );
		return $upload_dir . $filepath;
	}

	/**
	 * Given an Icon URL return the filepath.
	 *
	 * @param string $url URL.
	 * @return string Filepath.
	 */
	public static function icon_url_to_filepath( $url ) {
		if ( ! str_contains( self::upload_directory( '', true ), $url ) ) {
			return false;
		}
		$path = str_replace( self::upload_directory( '', true ), '', $url );
		return self::upload_directory( $path );
	}

	/**
	 * Delete Icon File.
	 *
	 * @param string $url Icon to Delete.
	 * @return boolean True if successful. False if not.
	 *
	 */
	public static function delete_icon_file( $url ) {
		$filepath = self::icon_url_to_filepath( $url );
		if ( empty( $filepath ) ) {
			return false;
		}
		if ( file_exists( $filepath ) ) {
			wp_delete_file( $filepath );
			return true;
		}
		return false;
	}


	/**
	 * Sideload Icon
	 *
	 * @param string $url URL for the client icon.
	 * @param string $client_id Client ID
	 * @return string URL to Downloaded Image.
	 *
	 */
	public static function sideload_icon( $url, $client_id ) {
		// If the URL is inside the upload directory.
		if ( str_contains( self::upload_directory( '', true ), $url ) ) {
			return $url;
		}

		// Load dependencies.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . WPINC . '/media.php';

		$filehandle = md5( $client_id ) . '.jpg';
		$filepath   = self::upload_directory( $filehandle );

		// Allow for common query parameters in image APIs to get a better quality image.
		$query = array();
		wp_parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( array_key_exists( 's', $query ) && is_numeric( $query['s'] ) ) {
			$url = str_replace( 's=' . $query['s'], 's=' . INDIEAUTH_ICON_SIZE, $url );
		}
		if ( array_key_exists( 'width', $query ) && array_key_exists( 'height', $query ) ) {
			$url = str_replace( 'width=' . $query['width'], 'width=' . INDIEAUTH_ICON_SIZE, $url );
			$url = str_replace( 'height=' . $query['height'], 'height=' . INDIEAUTH_ICON_SIZE, $url );
		}

		// Download Profile Picture and add as attachment
		$file = wp_get_image_editor( download_url( $url, 300 ) );
		if ( is_wp_error( $file ) ) {
			return false;
		}
		$file->resize( null, INDIEAUTH_ICON_SIZE, true );
		$file->set_quality( INDIEAUTH_ICON_QUALITY );
		$file->save( $filepath, 'image/jpg' );

		return self::upload_directory( $filehandle, true );
	}



} // End Class


