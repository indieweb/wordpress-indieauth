<?php
/*
Plugin Name: IndieAuth
Plugin URI: https://github.com/pfefferle/wordpress-indieauth/
Description: IndieAuth for WordPress
Version: 1.0.0
Author: pfefferle
Author URI: http://notizblog.org/
*/

class IndieAuthPlugin {
  
  public function __construct() {
    add_action( 'init', array($this, 'init') );
  }
  
  public function init() {
    add_action( 'login_form', array($this, 'login_form') );
    add_action( 'authenticate', array($this, 'authenticate') );
  }
  
  /**
   * Add IndieAuth input field to wp-login.php
   *
   * @action: login_form
   */
  public function login_form() {
    echo '
    	<p style="margin-bottom: 8px;">
    		<label for="indieauth_identifier">' . __('Or login with your Domain', 'indieauth') . '<br />
    		<input type="text" name="indieauth_identifier" placeholder="your-domain.com" id="indieauth_identifier" class="input indieauth_identifier" value="" /></label>
        <a href="https://indieauth.com/#faq" target="_blank">'.__('Learn about IndieAuth', 'indieauth').'</a>
    	</p>';
  }
  
  /**
   * Authenticate user to WordPress using IndieAuth.
   *
   * @action: authenticate
   * @param mixed $user authenticated user object, or WP_Error or null
   * @return mixed authenticated user object, or WP_Error or null
   */
  public function authenticate($user) {
    if ( array_key_exists('indieauth_identifier', $_POST) && $_POST['indieauth_identifier'] ) {
      $redirect_to = array_key_exists('redirect_to', $_REQUEST) ? $_REQUEST['redirect_to'] : null;
      // redirect to indieauth.com
      wp_redirect("http://indieauth.com/auth?me=".urlencode($_POST['indieauth_identifier'])."&redirect_uri=".urlencode(wp_login_url($redirect_to)));
    } else if ( array_key_exists('token', $_REQUEST) ) {
      $token = $_REQUEST['token'];

      $response = wp_remote_get( "http://indieauth.com/verify?token=".urlencode($token) );
      $response = wp_remote_retrieve_body($response);
      $response = @json_decode($response, true);      
      
      // check if response was json or not
      if (!is_array($response)) {
        $user = new WP_Error('indieauth_response_error', __('IndieAuth.com seems to have some hiccups, please try it again later.', 'indieauth'));
      }
      
      if ( array_key_exists('me', $response) ) {
        $user_id = $this->get_user_by_identifier( $response['me'] );
        if ( $user_id ) {
          $user = new WP_User($user_id);
        } else {
          $user = new WP_Error('indieauth_registration_failure', __('Your have entered a valid Domain, but you have no account on this blog.', 'indieauth'));
        }
      } else if ( array_key_exists('error', $response) ) {
        $user = new WP_Error('indieauth_'.$response['error'], htmlentities2($response['error_description']));
      }
    }

    return $user;
  }
  
  /**
   * Get the user associated with the specified Identifier-URI.
   *
   * @param string $$identifier identifier to match
   * @return int|null ID of associated user, or null if no associated user
   */
  private function get_user_by_identifier($identifier) {
    global $wpdb;
    
    $no_slash = untrailingslashit($identifier);
    $slash = trailingslashit($identifier);
    
    return $wpdb->get_var( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE user_url = %s OR user_url = %d", $no_slash, $slash ) );
  }
}

new IndieAuthPlugin();
