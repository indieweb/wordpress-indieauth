=== IndieAuth ===
Contributors: indieweb, pfefferle, dshanske
Tags: IndieAuth, IndieWeb, IndieWebCamp, login
Requires at least: 4.7
Tested up to: 5.1
Stable tag: 3.2
License: MIT
License URI: http://opensource.org/licenses/MIT
Donate link: https://opencollective.com/indieweb

IndieAuth is a way to allow users to use their own domain to sign into other websites and services.

== Description ==

The plugin turns WordPress into an IndieAuth endpoint. This can be used to act as an authentication mechanism for WordPress and its REST API,
as well as an identity mechanism for other sites. It uses the URL from the profile page to identify the blog user or your author url.

You can also install this plugin to enable web sign-in for your site using your domain.

== Installation ==

1. Upload the `indieauth` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

== Frequently Asked Questions ==

= What is IndieAuth? =

[IndieAuth](https://indieauth.net) is a way for doing Web sign-in, where you use your own homepage to sign in to other places. It is built on top of OAuth 2.0,
which is used by many websites.

= What is IndieAuth.com? =

[Indieauth.com](https://indieauth.com) is the reference implementation of the IndieAuth Protocol and available for public use.

= Why IndieAuth? =

IndieAuth was built on ideas and technology from existing proven technologies like OAuth and OpenID but aims at making it easier for users as well as developers. It also decentralises
much of the process so completely separate implementations and services can be used for each part.

IndieAuth was developed as part of the [Indie Web movement](http://indieweb.org/why) to take back control of your online identity.

= How is Web Sign In different from OpenID? =

The goals of OpenID and Web Sign In are similar. Both encourage you to sign in to a website using your own domain name.
However, OpenID has failed to gain wide adoption, at least in part due to the complexities of the protocol.

= How is IndieAuth different from OAuth? =

IndieAuth was built on top of the OAuth 2.0 Framework and differs in that users and clients are represented by URLs.  Clients can verify the identity of
a user and obtain an OAuth 2.0 Bearer token that can be used to access user resources.

= Does this require users to have their own domain name? =

No. You can use your author profile URL to login if you do not have a domain name. However how the Indieauth server authenticates you depends on that server.

= How do I authenticate myself to an Indieauth server? =

That, as mentioned, depends on the server. By default, the built-in IndieAuth server uses the WordPress login.

By adding Indieauth support, you can log into sites simply by providing your URL. We recommend your site uses SSL to ensure your credentials are not sent
in cleartext.

= What is a token endpoint? =

Once you have proven your identity, the token endpoint issues a token, which applications can use to authenticate as you to your site.
The plugin supports you using an external token endpoint if you want, but by having it built into your WordPress site, it is under your control.

You can manage and revoke tokens under User->Manage Tokens. You will only see tokens for the currently logged in user.

= How do I incorporate this into my plugin? =

The WordPress function, `get_current_user_id` works to retrieve the current user ID if logged in via IndieAuth. The plugin offers the following functions
to assist you in using IndieAuth for your service. We suggest you check on activation for the IndieAuth plugin by asking `if ( class_exists( 'IndieAuth_Plugin') )`

* `indieauth_get_scopes()` - Retrieves an array of scopes for the auth request.
* `indieauth_check_scope( $scope )` - Checks if the provided scope is in the current available scopes
* `indieauth_get_response()` - Returns the entire IndieAuth token response
* `indieauth_get_client_id()` - Returns the client ID
* `indieauth_get_me()` - Return the me property for the current session.

If any of these return null, the value was not set, and IndieAuth is not being used. Scopes and user permissions are not enforced by the IndieAuth plugin and must be enforced by
whatever is using them. The plugin does contain a list of permission descriptions to display when authorizing, but this is solely to aid the user in understanding what the
scope is for.

The scope description can be customized with the filter `indieauth_scope_description( $description, $scope )`

= I keep getting the response that my request is Unauthorized =

Many server configurations will not pass bearer tokens. The plugin attempts to work with this as best possible, but there may be cases we have not encountered. The first step is to try running the diagnostic script linked to in the settings page. It will tell you whether tokens can be passed.

Temporarily enable [WP_DEBUG](https://codex.wordpress.org/Debugging_in_WordPress) which will surface some errors in your logs.

If you feel comfortable with command line entries, you can request a token under Users->Manage Tokens and use curl or similar to test logins. Replace example.com with your site and TOKEN with
your bearer token.

`curl -i -H 'Authorization: Bearer TOKEN' 'https://example.com/wp-json/indieauth/1.0/test'
`curl -i -H 'Authorization: Bearer test' 'https://tiny.n9n.us/wp-json/indieauth/1.0/test?access_token=TOKEN'`

This will quickly test your ability to authenticate to the server. Additional diagnostic tools may be available in future.

If this does not work, you can add `define( 'INDIEAUTH_TOKEN_ERROR', true );` to your wp-config.php file. The `INDIEAUTH_TOKEN_ERROR` flag will return an error if there is not a token passed
allowing you to troubleshoot this issue, however it will require authentication for all REST API functions even those that do not require them, therefore this is off by default.

If your Micropub client includes an `Authorization` HTTP request header but you still get an HTTP 401 response with body `missing access token`, your server may be stripping the `Authorization` header. If you're on Apache, [try adding this line to your `.htaccess` file](https://github.com/indieweb/wordpress-micropub/issues/56#issuecomment-299202820):

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    If that doesn't work, [try this line](https://github.com/georgestephanis/application-passwords/wiki/Basic-Authorization-Header----Missing):

        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

	If that doesn't work either, you may need to ask your hosting provider to whitelist the `Authorization` header for your account. If they refuse, you can [pass it through Apache with an alternate name](https://github.com/indieweb/wordpress-micropub/issues/56#issuecomment-299569822). The plugin searches for the header in REDIRECT_HTTP_AUTHORIZATION, as some FastCGI implementations store the header in this location.

= I get an error that parameter redirect_uri is missing but I see it in the URL =

Some hosting providers filter this out using mod_security. For one user, they needed [Rule 340162](https://wiki.atomicorp.com/wiki/index.php/WAF_340162) whitelisted as it detects the use of a URL as an argument.

== Upgrade Notice ==

= 3.0.0 =

In version 2.0, we added an IndieAuth endpoint to this plugin, which previously only supported IndieAuth for web sign-in. Version 3.0.0 separates
the endpoint code from the web sign-in code and removes the ability to use a third-party IndieAuth endpoint with your site. If you use the sign-in
feature, it will look for the IndieAuth endpoint for the URL you provide. If you use Micropub for WordPress, enabling the plugin will use the built-in
endpoint for WordPress. If you wish to use Indieauth.com or another endpoint, you can disable this plugin and Micropub will use Indieauth.com by default.

== Changelog ==

Project and support maintained on github at [indieweb/wordpress-indieauth](https://github.com/indieweb/wordpress-indieauth).

= 3.2 = 
* Only add headers to front page and author archive pages
* Return basic profile data in returns so the client can display the name and avatar of the user

= 3.1.11 =
* Fix issue with silent conversion when not array
* Add client name and icon automatically on setting token

= 3.1.10 =
* Fixed PHP notice with icon determination
* Silently convert requests for the post scope to the create update scope
* Update tagline

= 3.1.9 =
* Fixed PHP warnings

= 3.1.8 =
* When local verification is performed the code was not updating the profile URL and passing through the URL from the original request. This code was in
the remote verification portion of the token endpoint and is now mirrored in the verify local code.

= 3.1.7 =
* Add authdiag.php script written by @Zegnat

= 3.1.6 =
* Add ability to generate a token on the backend
* Added a test endpoint that tests whether the authentication provider for the REST API is working and tries to return useful errors

= 3.1.5 =
* Add Client Information Discovery to search for names and icon for clients
* Add icon and client name to Manage Token page
* Add action to refresh icon and other information in the Manage Token interface

= 3.1.4 =
* Rearrange token logic so that if a token is provided the system will fail if it is invalid
* Add last accessed field to token and add that to token management table

= 3.1.3 =
* Allow selection of scopes and add stock descriptions
* Update Manage Token Page to use WP_List_Table

= 3.1.2 =

* Fix issue with scope encoding
* Fix issue where function returned differently than parent function

= 3.1.1 =

* Fixed PHP error with version < PHP 5.4

= 3.1.0 =

* Fixed `state` param handling

= 3.0.4 =

* Fixed admin settings

= 3.0.3 =

* Verify user ID directly from the token endpoint rather than mapping URL.
* Display $me parameter instead of user_url on authenticate screen
* Remove deprecated functions and parameters

= 3.0.2 =

* Automatically rewrite local URLs to https if the local site is site to SSL

= 3.0.1 =

* In previous version fixed issue where error message was not returned if there was a missing bearer token. This was needed due fact that some servers filter tokens. However, this meant that it would do this for all API requests, even ones not requiring authentication such as webmentions. Reverted change with flag
* Added constant `INDIEAUTH_TOKEN_ERROR` which if set to true will return an error if it cannot find a token.

= 3.0.0 =

* Major refactor to abstract out and improve token generation code
* Set one cookie with the state instead of multiple cookies.
* Store other parameters as a transient
* Remove extra settings

= 2.1.1 =

* Bug Fix

= 2.1.0 =

* Refactor to change load order
* Textual fix
* Add defaults when core functions not yet enabled
* Rework of the admin-interface

= 2.0.3 =

* Add improved getallheaders polyfill
* Check for missing cookie
* Check for alternate authorization location

= 2.0.2 =

* If using local endpoint verify token locally without making remote call
* Add filters for scope and response so they can be accessed elsewhere
* urlencode state as some encode information into state that was being lost
* Switch from failure to warning message for different domains for redirect
* Hide token endpoint management page if local endpoint not enabled

= 2.0.1 =

* Improve error handling if null endpoint sent through
* Adjust cookie to GMT
* Add whitepace to form

= 2.0.0 =

* Support author profiles in addition to user URLs
* Change token verification method to match current Indieauth specification
* Add support for token verification to act as a WordPress authentication mechanism.
* Add ability to set any token or authorization endpoint
* Add authorization and token endpoint headers to the site
* Discover and use authorization endpoint for provided URL when logging in
* Allow login using URL
* Add built-in token endpoint ( props to @aaronpk for support on this )
* Add built-in authorization endpoint ( props to @aaronpk for support on this )
* Hide option to login with your domain by default
* Option to sign into your domain is now a separate form
* Automatically add trailing slash to user_url

= 1.1.3 =

* update README

= 1.1.2 =

* fixed redirect URL

= 1.1.1 =

* WordPress coding style

= 1.1.0 =

* fixed critical bug

= 1.0.0 =

* initial
