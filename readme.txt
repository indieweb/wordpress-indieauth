=== Plugin Name ===
Contributors: pfefferle
Tags: pubsubhubbub
Requires at least: 3.6
Tested up to: 3.6
Stable tag: 1.0.0

An IndieAuth plugin for WordPress

== Description ==

IndieAuth is a way to use your own domain name to sign in to websites. It's like OpenID, but simpler!
It works by linking your website to one or more authentication providers such as Twitter or Google, 
then entering your domain name in the login form on websites that support IndieAuth. You can find 
out more about IndieAuth on [IndieAuth.com](https://indieauth.com))

The plugin matches the `user_url` to authenticate the user. If there is no User with the used URL
it will throw an error. Signup with IndieAuth is not supported yet

== Installation ==

1. Upload the `indieauth` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

== Frequently Asked Questions ==

none

== Changelog ==

= 1.0.0 =
* initial