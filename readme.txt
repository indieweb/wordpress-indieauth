=== Plugin Name ===
Contributors: pfefferle
Tags: IndieAuth, IndieWeb, IndieWebCamp, login
Requires at least: 3.6
Tested up to: 3.6
Stable tag: 1.1.0

An IndieAuth plugin for WordPress

== Description ==

The plugin lets you login to the WordPress backend via IndieAuth. It uses the URL from the profile page to identify the blog user.

Registration with IndieAuth is not supported yet.

== Installation ==

1. Upload the `indieauth` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

== Frequently Asked Questions ==

Taken from [IndieAuth.com](https://indieauth.com)

= What is IndieAuth? =
IndieAuth is a way to use your own domain name to sign in to websites. It's like OpenID, but simpler! It works by linking your website to one or more authentication providers such as Twitter or Google, then entering your domain name in the login form on websites that support IndieAuth.

= Why IndieAuth? =
IndieAuth is part of the [Indie Web movement](http://indiewebcamp.com/why) to take back control of your online identity. Instead of logging in to websites as "you on Twitter" or "you on Facebook", you should be able to log in as just "you". We should not be relying on Twitter or Facebook to provide our authenticated identities, we should be able to use our own domain names to log in to sites everywhere.

IndieAuth was built to make it as easy as possible for users and for developers to start using this new way of signing in on the web, without the complexities of OpenID.

= How is this different from OpenID? =
The goals of OpenID and IndieAuth are similar. Both encourage you to sign in to a website using your own domain name. However, OpenID has failed to gain wide adoption, at least in part due to the complexities of the protocol. IndieAuth is a simpler implementation of a similar goal, by leveraging other OAuth providers and behaviors that people are already accustomed to.

= Can my rel="me" links be hidden on my home page? =
Yes, your rel="me" links do not need to be visible, but the html does need to be on your home page. You can hide the links with CSS.

= Does this require users to have their own domain name? =
Yes, the assumption is that people are willing to [own their online identities](http://indiewebcamp.com/why) in the form of a domain name. It is getting easier and easier to host content on your own domain name. See "[Getting Started on the Indie Web](http://indiewebcamp.com/Getting_Started)" for some suggestions, including mapping your domain to a Tumblr blog, or signing up for a simple web hosting service like Dreamhost.

== Changelog ==

= 1.1.0 =
* fixed critical bug

= 1.0.0 =
* initial