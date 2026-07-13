<?php
/**
 * Public JSON-LD Output Handler
 *
 * Generates and outputs the JSON-LD script tag in the frontend.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator\Frontend;

use SchemaGenerator\SchemaDatabase;
use SchemaGenerator\SchemaValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaPublic
 *
 * Responsible for assembling and outputting Schema.org JSON-LD
 * on the frontend for each queried post/page.
 */
class SchemaPublic {

	/**
	 * Schema database instance.
	 *
	 * @var SchemaDatabase
	 */
	private SchemaDatabase $database;

	/**
	 * Constructor.
	 *
	 * @param SchemaDatabase $database The schema database instance.
	 */
	public function __construct( SchemaDatabase $database ) {
		$this->database = $database;
	}

	/**
	 * Initialize frontend hooks.
	 * We always hook, but decide inside output_json_ld whether to do anything.
	 */
	public function init(): void {
		$settings = get_option( SG_OPTION_PREFIX . 'settings', array() );
		$position = $settings['schema_output'] ?? 'head';
		$hook     = ( 'footer' === $position ) ? 'wp_footer' : 'wp_head';

		add_action( $hook, array( $this, 'output_json_ld' ), 5 );
	}

	/**
	 * Output the JSON-LD script tag for the current page.
	 */
	public function output_json_ld(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}

		$settings = get_option( SG_OPTION_PREFIX . 'settings', array() );
		$enabled  = $settings['enabled_post_types'] ?? array();

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			$this->output_website_schema();
			return;
		}

		// Check if post type is enabled.
		if ( ! empty( $enabled ) && ! in_array( $post->post_type, $enabled, true ) ) {
			return;
		}

		$cache_duration = absint( $settings['cache_duration'] ?? 3600 );
		$cache_key      = SG_OPTION_PREFIX . 'schema_output_' . $post->ID;

		if ( $cache_duration > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return;
			}
		}

		$all_schemas = [];

		// 1. Get per-post schemas (from metabox).
		$post_schema_manager = new \SchemaGenerator\PostSchemaManager();
		$post_schemas = $post_schema_manager->render_post_schemas( $post->ID, $post );
		foreach ( $post_schemas as $schema ) {
			if ( ! empty( $schema['@type'] ) ) {
				$all_schemas[] = $schema;
			}
		}

		// 2. Get template-based schemas for this post type.
		$tmpl_manager = new \SchemaGenerator\SchemaTemplateManager();
		$templates    = $tmpl_manager->get_templates_for_post_type( $post->post_type );
		foreach ( $templates as $template ) {
			$schema = $tmpl_manager->render_template_schema( $template, $post );
			if ( ! empty( $schema['@type'] ) ) {
				$all_schemas[] = $schema;
			}
		}

		// 3. Fallback: legacy single mapping.
		if ( empty( $all_schemas ) ) {
			$mappings     = get_option( SG_OPTION_PREFIX . 'post_type_mappings', array() );
			$schema_type  = $mappings[ $post->post_type ]['schema_type'] ?? '';
			$field_map    = $mappings[ $post->post_type ]['fields'] ?? array();

			if ( ! empty( $schema_type ) ) {
				$schema = $this->build_schema( $schema_type, $post, $field_map );
				$schema = $this->clean_schema( $schema );
				if ( ! empty( $schema['@type'] ) ) {
					$all_schemas[] = $schema;
				}
			}
		}

		if ( empty( $all_schemas ) ) {
			return;
		}

		// Validate and output each schema.
		$output   = '';
		$validator = new SchemaValidator();

		foreach ( $all_schemas as $schema ) {
			$schema = $validator->clean_schema( $schema );
			$schema = $this->clean_schema( $schema );

			if ( empty( $schema ) || empty( $schema['@type'] ) ) {
				continue;
			}

			$output .= sprintf(
				'<script type="application/ld+json">%s</script>' . "\n",
				wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}

		if ( empty( $output ) ) {
			return;
		}

		if ( $cache_duration > 0 ) {
			set_transient( $cache_key, $output, $cache_duration );
		}

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the schema array for a given post.
	 *
	 * @param string $schema_type The schema type slug.
	 * @param \WP_Post $post The post object.
	 * @param array $field_map The field mapping configuration.
	 * @return array The constructed schema array.
	 */
	private function build_schema( string $schema_type, \WP_Post $post, array $field_map ): array {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $this->get_type_label( $schema_type ),
		);

		// If no field mappings, use smart defaults for basic but useful output
		if ( empty( $field_map ) ) {
			$field_map = [
				'headline'       => ['source' => 'title'],
				'name'           => ['source' => 'title'], // fallback
				'description'    => ['source' => 'excerpt'],
				'url'            => ['source' => 'permalink'],
				'datePublished'  => ['source' => 'date_published'],
				'dateModified'   => ['source' => 'date_modified'],
				'image'          => ['source' => 'featured_image'],
				'author'         => ['source' => 'author'],
			];
		}

		foreach ( $field_map as $prop_id => $field ) {
			if ( empty( $field['source'] ) ) {
				continue;
			}

			$key   = $this->normalize_schema_property( $prop_id );
			$value = $this->resolve_field_value( $field, $post );

			if ( null !== $value && '' !== $value ) {
				// Avoid duplicating if both headline and name map to title
				if ( isset( $schema[ $key ] ) && $key === 'name' && ! empty( $schema['headline'] ) ) {
					continue;
				}
				$schema[ $key ] = $value;
			}
		}

		// Final safety: re-normalize all keys in case some slipped through (future-proof for all schema types)
		$final = [];
		foreach ( $schema as $k => $v ) {
			$nk = $this->normalize_schema_property( $k );
			$final[ $nk ] = $v;
		}
		return $final;
	}

	/**
	 * Clean a property key for use in schema output.
	 */
	private function clean_property_key( string $key ): string {
		$key = trim( $key );
		// Remove various schema prefixes (with or without colon, case insensitive)
		$key = preg_replace( '/^https?:\/\/schema\.org\//i', '', $key );
		$key = preg_replace( '/^schema:/i', '', $key );
		$key = preg_replace( '/^schema/i', '', $key );
		return $key;
	}

	/**
	 * Normalize a property key to the official Schema.org camelCase name.
	 * Uses the vocabulary database for accurate lookup across ALL schema types.
	 */
	private function normalize_schema_property( string $key ): string {
		return $this->database->normalize_property_name( $key );
	}

	/**
	 * Resolve the value for a mapped field.
	 * Supports rich objects for images, authors, etc.
	 */
	private function resolve_field_value( array $field, \WP_Post $post ): mixed {
		$source = $field['source'] ?? '';

		switch ( $source ) {
			case 'title':
				return get_the_title( $post );

			case 'excerpt':
				return get_the_excerpt( $post );

			case 'content':
				$content = get_the_content( null, false, $post );
				return wp_strip_all_tags( $content );

			case 'featured_image':
				return $this->get_image_object( $post );

			case 'author':
			case 'author_name':
				return $this->get_author_object( $post );

			case 'author_url':
				return get_author_posts_url( $post->post_author );

			case 'date_published':
				return get_the_date( 'c', $post );

			case 'date_modified':
				return get_the_modified_date( 'c', $post );

			case 'permalink':
			case 'url':
				return get_permalink( $post );

			case 'meta':
				$meta_key = $field['meta_key'] ?? '';
				if ( ! empty( $meta_key ) ) {
					$value = get_post_meta( $post->ID, $meta_key, true );
					return ! empty( $value ) ? $value : null;
				}
				return null;

			case 'site_name':
				return get_bloginfo( 'name' );

			case 'site_url':
				return home_url( '/' );

			case 'woocommerce':
				return $this->resolve_woocommerce_field( $field, $post );

			case 'static':
				return $field['static_value'] ?? null;

			case 'categories':
			case 'tags':
				$tax = ($source === 'categories') ? 'category' : 'post_tag';
				$terms = get_the_terms( $post, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
				return null;

			default:
				return null;
		}
	}

	/**
	 * Return a proper ImageObject or URL for the featured image.
	 */
	private function get_image_object( \WP_Post $post ): mixed {
		$image_id = get_post_thumbnail_id( $post );
		if ( ! $image_id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $image_id, 'full' );
		if ( ! $url ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $image_id );

		return [
			'@type'  => 'ImageObject',
			'url'    => $url,
			'width'  => $meta['width'] ?? null,
			'height' => $meta['height'] ?? null,
		];
	}

	/**
	 * Return an author as Person object (rich).
	 */
	private function get_author_object( \WP_Post $post ): array {
		$author_id = $post->post_author;
		return [
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $author_id ),
			'url'   => get_author_posts_url( $author_id ),
		];
	}

	/**
	 * Resolve a WooCommerce-specific field value.
	 *
	 * @param array $field The field configuration.
	 * @param \WP_Post $post The post object.
	 * @return mixed|null The resolved value or null.
	 */
	private function resolve_woocommerce_field( array $field, \WP_Post $post ): mixed {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return null;
		}

		$method = $field['meta_key'] ?? '';

		if ( empty( $method ) ) {
			return null;
		}

		// Support common WooCommerce product methods.
		$supported_methods = array(
			'get_price',
			'get_regular_price',
			'get_sale_price',
			'get_sku',
			'get_stock_status',
			'get_average_rating',
			'get_review_count',
			'get_total_sales',
			'get_weight',
			'get_length',
			'get_width',
			'get_height',
			'get_name',
			'get_description',
			'short_description',
		);

		if ( in_array( $method, $supported_methods, true ) && method_exists( $product, $method ) ) {
			$value = $product->{$method}();
			return ! empty( $value ) ? $value : null;
		}

		return null;
	}

	/**
	 * Get the human-readable label for a schema type slug.
	 *
	 * @param string $slug The schema type slug.
	 * @return string The type label.
	 */
	private function get_type_label( string $slug ): string {
		$type = $this->database->get_type( $slug );
		return $type ? $type['label'] : ucfirst( $slug );
	}

	/**
	 * Recursively remove null/empty values from the schema array.
	 *
	 * @param array $schema The schema array to clean.
	 * @return array Cleaned schema array.
	 */
	private function clean_schema( array $schema ): array {
		$cleaned = array();

		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$cleaned_value = $this->clean_schema( $value );
				if ( ! empty( $cleaned_value ) ) {
					$cleaned[ $key ] = $cleaned_value;
				}
			} elseif ( null !== $value && '' !== $value ) {
				$cleaned[ $key ] = $value;
			}
		}

		return $cleaned;
	}

	/**
	 * Output a basic Website/Organization schema for non-post pages.
	 */
	private function output_website_schema(): void {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'url'      => home_url( '/' ),
			'name'     => get_bloginfo( 'name' ),
		);

		if ( is_search() ) {
			$schema['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'        => 'EntryPoint',
					'urlTemplate'  => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		$schema = $this->clean_schema( $schema );

		echo sprintf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Clear cached schema output for a specific post.
	 *
	 * @param int $post_id The post ID to clear cache for.
	 */
	public function clear_cache( int $post_id ): void {
		// Must match the key written in output_json_ld().
		delete_transient( SG_OPTION_PREFIX . 'schema_output_' . $post_id );
	}
}
