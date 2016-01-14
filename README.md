# Sellaporter WordPress Plugin

The WordPress plugin companion to [Sellaporter](https://github.com/jlengstorf/sellaporter). This plugin uses [Advanced Custom Fields](http://www.advancedcustomfields.com/) and the [WP REST API](http://v2.wp-api.org/) to generate JSON data for time-aware, heavily customizable sales pages.

## Warning: Must Be This Nerdy to Ride

Please note that this plugin _does not_ include a WordPress template for displaying pages. The intention of this plugin is to create an API endpoint for Sellaporter to hit and generate a static site from.

If you want to use a standard WordPress template, that is totally your call. Just add a new directory in this plugin called `templates/`, and create a file inside called `sellaporter.php` with your template code.

