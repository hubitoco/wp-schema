<?php
/**
 * Post Schema Manager
 *
 * Manages per-post multi-schema data. Each post can have multiple
 * schema instances, each with its own type and property mappings.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Class PostSchemaManager
 *
 * Handles per-post schema storage and rendering.
 */
class PostSchemaManager {

	/**
	 * Meta key for storing per-post schemas.
	 */
	private const META_KEY = 'sg_post_schemas';

	/**
	 * Get all schemas for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array Array of schema objects.
	 */
	public function get_post_schemas( int $post_id ): array {
		$schemas = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $schemas ) ) {
			return [];
		}

		// Filter out invalid entries.
		return array_values( array_filter( $schemas, function ( $schema ) {
			return is_array( $schema ) && ! empty( $schema['schema_type'] );
		} ) );
	}

	/**
	 * Save schemas for a post.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $schemas Array of schema objects.
	 * @return bool True on success.
	 */
	public function save_post_schemas( int $post_id, array $schemas ): bool {
		// Sanitize each schema.
		$sanitized = [];

		foreach ( $schemas as $schema ) {
			if ( empty( $schema['schema_type'] ) ) {
				continue;
			}

			$sanitized_schema = [
				'uuid'        => sanitize_text_field( $schema['uuid'] ?? wp_generate_uuid4() ),
				'enabled'     => ! empty( $schema['enabled'] ),
				'schema_type' => sanitize_text_field( $schema['schema_type'] ),
				'properties'  => [],
			];

			// Sanitize property mappings.
			if ( ! empty( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $prop_name => $prop_config ) {
					$clean_name = sanitize_text_field( $prop_name );
					if ( empty( $clean_name ) ) {
						continue;
					}
					$sanitized_schema['properties'][ $clean_name ] = [
						'source'       => sanitize_text_field( $prop_config['source'] ?? '' ),
						'meta_key'     => sanitize_text_field( $prop_config['meta_key'] ?? '' ),
						'static_value' => sanitize_text_field( $prop_config['static_value'] ?? '' ),
					];
				}
			}

			$sanitized[] = $sanitized_schema;
		}

		if ( empty( $sanitized ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return true;
		}

		return update_post_meta( $post_id, self::META_KEY, $sanitized );
	}

	/**
	 * Delete all schemas for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True on success.
	 */
	public function delete_post_schemas( int $post_id ): bool {
		return delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Render all enabled schemas for a post into schema arrays.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @return array Array of schema arrays.
	 */
	public function render_post_schemas( int $post_id, \WP_Post $post ): array {
		$schemas = $this->get_post_schemas( $post_id );
		$output  = [];

		foreach ( $schemas as $schema ) {
			if ( empty( $schema['enabled'] ) ) {
				continue;
			}

			$rendered = $this->render_single_schema( $schema, $post );
			if ( $rendered ) {
				$output[] = $rendered;
			}
		}

		return $output;
	}

	/**
	 * Render a single schema object into a schema array.
	 *
	 * @param array    $schema Schema data.
	 * @param \WP_Post $post   The post object.
	 * @return array|null Schema array or null.
	 */
	public function render_single_schema( array $schema, \WP_Post $post ): ?array {
		if ( empty( $schema['schema_type'] ) ) {
			return null;
		}

		$output = [
			'@context' => 'https://schema.org',
			'@type'    => $schema['schema_type'],
		];

		$properties = $schema['properties'] ?? [];

		foreach ( $properties as $prop_name => $prop_config ) {
			if ( empty( $prop_config['source'] ) ) {
				continue;
			}

			$value = $this->resolve_field_value( $prop_config, $post );

			if ( null !== $value && '' !== $value ) {
				$output[ $prop_name ] = $value;
			}
		}

		// Remove empty values.
		$output = array_filter( $output, fn( $v ) => null !== $v && '' !== $v && [] !== $v );

		if ( empty( $output['@type'] ) ) {
			return null;
		}

		return $output;
	}

	/**
	 * Resolve a field value from a property config.
	 * Same resolution logic as SchemaTemplateManager.
	 *
	 * @param array    $field Property config.
	 * @param \WP_Post $post  The post object.
	 * @return mixed The resolved value.
	 */
	private function resolve_field_value( array $field, \WP_Post $post ) {
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

			case 'author':
				return [
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $post->post_author ),
					'url'   => get_author_posts_url( $post->post_author ),
				];

			case 'author_name':
				return get_the_author_meta( 'display_name', $post->post_author );

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

			case 'static':
				return $field['static_value'] ?? null;

			case 'categories':
				$terms = get_the_terms( $post, 'category' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
				return null;

			case 'tags':
				$terms = get_the_terms( $post, 'post_tag' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
				return null;

			default:
				return null;
		}
	}
}
