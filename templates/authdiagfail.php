<?php

echo '<strong>';
_e( 'Authorization has Failed', 'indieauth' );
echo '</strong>';
echo '<p>';
_e( 'The authorization header was not returned on this test, which means that your server may be stripping the Authorization header. If youre on Apache, try adding this line to your .htaccess file:', 'indieauth' );
echo '</p>';
echo 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1';
echo '<p>';
_e( 'If that doesnt work, [try this line](https://github.com/georgestephanis/application-passwords/wiki/Basic-Authorization-Header----Missing):', 'indieauth' );
echo '</p>';
echo 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]';
echo '<p>';
_e( 'If that doesnt work either, you may need to ask your hosting provider to whitelist the Authorization header for your account. If they refuse, you can [pass it through Apache with an alternate name](https://github.com/indieweb/wordpress-micropub/issues/56#issuecomment-299569822). The plugin searches for the header in REDIRECT_HTTP_AUTHORIZATION, as some FastCGI implementations store the header in this location.', 'indieauth' );
echo '</p>';

