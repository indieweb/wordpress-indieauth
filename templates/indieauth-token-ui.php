<div class="wrap">
<h2><?php esc_html_e( 'IndieAuth Tokens', 'indieauth' ); ?></h2>
<hr />
<?php $tokens = IndieAuth_Token_UI::get_all_tokens( get_current_user_id() ); ?>

<form method="post" action="<?php rest_url( 'indieauth/1.0/token' ); ?>">
   <?php IndieAuth_Token_UI::token_form_table( $tokens ); ?>
<input type="hidden" name="action" value="revoke" />
   <?php submit_button( __( 'Revoke', 'indieauth' ) ); ?>
</form></div>

