# IndieAuth #
**Contributors:** [indieweb](https://profiles.wordpress.org/indieweb), [pfefferle](https://profiles.wordpress.org/pfefferle), [dshanske](https://profiles.wordpress.org/dshanske)  
**Tags:** IndieAuth, IndieWeb, IndieWebCamp, login  
**Requires at least:** 4.7  
**Tested up to:** 4.9.1  
**Stable tag:** 1.2.0  
**License:** MIT  
**License URI:** http://opensource.org/licenses/MIT  
**Donate link:** http://14101978.de  

An IndieAuth plugin for WordPress. This allows you to login to your website using an IndieAuth server, defaultly IndieAuth.com.

## Description ##

The plugin lets you login to the WordPress backend via IndieAuth.com and allows an Indieauth.com to act as an authentication mechanism for WordPress and its REST API.
It uses the URL from the profile page to identify the blog user or your author url.

## Installation ##

1. Upload the `indieauth` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

To be able to actually log in using IndieAuth.com, see [IndieAuth.com](https://indieauth.com/setup). Supported providers include: Twitter, Github, GNUPG, email among others.

## Frequently Asked Questions ##

### What is IndieAuth? ###
[IndieAuth](https://indieauth.net) is a way for doing Web sign-in, where you use your own homepage to sign in to other places. 

### What is IndieAuth.com? ###

[Indieauth.com](https://indieauth.com) is the reference implementation of the IndieAuth Protocol and available for public use.

### Why IndieAuth? ###

IndieAuth was built on ideas and technology from existing proven technologies like OAuth and OpenID but aims at making it easier for users as well as developers. It also decentralises 
much of the process so completely separate implementations and services can be used for each part. 

IndieAuth was developed as part of the [Indie Web movement](http://indieweb.org/why) to take back control of your online identity.

### How is this different from OpenID? ###
The goals of OpenID and IndieAuth are similar. Both encourage you to sign in to a website using your own domain name. 
However, OpenID has failed to gain wide adoption, at least in part due to the complexities of the protocol. 

### How is this different from OAuth? ###

IndieAuth was built on top of the OAuth 2.0 Framework and is a simplified way of verifying the identity of an end user and obtaining an OAuth 2.0 Bearer token.

### Does this require users to have their own domain name? ###
No. You can use your author profile URL to login if you do not have a domain name. However how the Indieauth server authenticates you depends on that server.

### How do I authenticate myself to an Indieauth server? ###

That, as mentioned, depends on the server. By default, the plugin uses IndieAuth.com which works by linking your website to one or more authentication providers 
such as Twitter or Github, then entering your domain name in the login form on websites that support IndieAuth. 

You can link your website to these providers add ['rel-me'](https://indieweb.org/rel-me) links to your site, which can be done manually or by installing 
the [Indieweb plugin](https://wordpress.org/plugins/indieweb)


## Changelog ##

### 1.2.0 ###
* Support author profiles in addition to user URLs
* Change token verification method to match current Indieauth specification
* Add support for token verification to act as a WordPress authentication mechanism.
* Add discovery of authorization endpoint and token endpoint from provided URL
* Add settings for default authorization and token endpoint
* Automatically add token and authorization endpoint to page headers

### 1.1.3 ###
* update README

### 1.1.2 ###

* fixed redirect URL

### 1.1.1 ###

* WordPress coding style

### 1.1.0 ###

* fixed critical bug

### 1.0.0 ###

* initial
