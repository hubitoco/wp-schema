=== Schema Generator Pro ===
Contributors: haamed
Tags: schema, json-ld, seo, structured-data, schema.org
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dynamically generates JSON-LD Schema markup for all WordPress content types using the full Schema.org vocabulary.

== Description ==

Schema Generator Pro goes beyond the limited schemas offered by typical plugins. It fetches and stores the complete official Schema.org vocabulary, allowing you to map any WordPress data point to any Schema.org property.

**Key Features:**

* Full Schema.org Vocabulary: Access to every Schema.org type and property, not just the common ones.
* Post Type Mapping: Assign any Schema.org type to any WordPress post type.
* Field Mapping Engine: Link Schema.org properties to WordPress data (titles, excerpts, meta fields, WooCommerce functions).
* Per-Post Overrides: Override global schema mappings on individual posts via the Gutenberg meta box.
* Performance: Transient-based caching for generated JSON-LD output.
* WP-CLI Support: Update the schema dictionary from the command line.
* Secure: Nonces, capability checks, and data sanitization throughout.

== Installation ==

1. Upload the `schema-generator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Navigate to Schema Generator > Dictionary and click "Fetch / Update Schema Dictionary".
4. Configure your post type mappings under Schema Generator > Post Type Mapping.

== Changelog ==

= 1.0.0 =
* Initial release.
* Schema.org vocabulary parser and database storage.
* Admin UI for post type and field mapping.
* Frontend JSON-LD output with transient caching.
* WP-CLI command for dictionary updates.
* Per-post schema override meta box.
