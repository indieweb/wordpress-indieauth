=== IndieAuth ===
Contributors: pfefferle
Tags: IndieAuth, IndieWeb, IndieWebCamp, login
Requires at least: 3.6
Tested up to: 4.9.1
Stable tag: 1.1.2
License: MIT
License URI: http://opensource.org/licenses/MIT
Donate link: http://14101978.de

An IndieAuth plugin for WordPress. This allows you to login to your website using an IndieAuth server, defaultly IndieAuth.com.

== Description ==

The plugin lets you login to the WordPress backend via IndieAuth.com. It uses the URL from the profile page to identify the blog user.

== Installation ==

1. Upload the `indieauth` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

For additional minor set up to be able to actually log in using IndieAuth.com, see [IndieAuth.com](https://indieauth.com/setup). Supported providers include: Twitter, Github, GNUPG, email among others.

== Frequently Asked Questions ==

Taken from [IndieAuth.com](https://indieauth.com)

= What is IndieAuth? =
IndieAuth is a way to use your own domain name to sign in to websites. IndieAuth.com works by linking your website to one or more authentication providers such as Twitter or Github, then entering your domain name in the login form on websites that support IndieAuth. You can link your website to these providers using 'rel-me', which is built into the [Indieweb plugin](https://wordpress.org/plugins/indieweb)

= Why IndieAuth? =
IndieAuth is part of the [Indie Web movement](http://indieweb.org/why) to take back control of your online identity. Instead of logging in to websites as "you on Twitter" or "you on Facebook", you should be able to log in as just "you". We should not be relying on other sites to provide our authenticated identities, we should be able to use our own domain names to log in to sites everywhere.

IndieAuth was built to make it as easy as possible for users and for developers to start using this new way of signing in on the web, without the .

= How is this different from OpenID? =
The goals of OpenID and IndieAuth are similar. Both encourage you to sign in to a website using your own domain name. However, OpenID has failed to gain wide adoption, at least in part due to the complexities of the protocol. IndieAuth is a simpler implementation of a similar goal, and IndieAuth.com leverages other OAuth providers and behaviors that people are already accustomed to.

= Does this require users to have their own domain name? =
Yes, the assumption is that people are willing to [own their online identities](http://indiewebcamp.com/why) in the form of a domain name. It is getting easier and easier to host content on your own domain name. See "[Getting Started on the Indie Web](http://indieweb.org/Getting_Started)" for some suggestions, including mapping your domain to a Tumblr blog, or signing up for a simple web hosting service like Dreamhost.

== Changelog ==

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
