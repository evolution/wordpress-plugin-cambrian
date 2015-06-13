=== Cambrian Explosion ===
Tags: export, backup, evolutionwordpress
Requires at least: 3.0
Tested up to: 4.2.2
License: MIT

Export the file and database contents of an existing wordpress installation, to be later imported into an Evolution Wordpress site

== Description ==

This plugin adds a submenu under Tools, that allows you to do the following in one click:

* Dump the existing database tables as SQL
* Recursively copy the wp-content directory (including plugins, themes, and uploads)
* Package it all into a zip archive
* Download said archive through your browser

The archive can then be imported into a site generated with [Evolution Wordpress](https://github.com/evolution/wordpress)

== Installation ==

1. Upload the plugin contents to a `cambrian` directory under `wp-content/plugins/`
1. Activate the plugin through the 'Plugins' admin menu
1. Look under the 'Tools' admin menu for a 'Cambrian Explosion' item

== Changelog ==

= 0.1.0-beta =
* First viable release!
