 
<p class="notice notice-error"><?php _e( 'Authorization has Failed', 'indieauth' ); ?></p>

<p> <?php _e( 'The authorization header was not returned on this test, which means that your server may be stripping the Authorization header. If you are on Apache, try adding this line to your .htaccess file:', 'indieauth' ); ?></p>
<pre>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1'</pre></p>

<p><?php _e( 'If that doesnt work, try this:', 'indieauth' ); ?></p>
<pre>RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</pre>
<p>
<?php _e( 'If that does not work either, you may need to ask your hosting provider to whitelist the Authorization header for your account. If they refuse, you can pass it through Apache with an alternate name. The plugin searches for the header in REDIRECT_HTTP_AUTHORIZATION, as some FastCGI implementations store the header in this location.', 'indieauth' ); ?> </p>

