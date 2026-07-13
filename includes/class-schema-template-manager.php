<?php
/**
 * Schema Template Manager
 *
 * Manages reusable schema templates that can be assigned to post types.
 * Each template defines a schema type + property mappings.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaTemplateManager
 *
 * Handles CRUD for schema templates and rendering them as JSON-LD.
 */
class SchemaTemplateManager {

	/**
	 * Templates table name (without prefix).
	 */
	private const TABLE = 'sg_schema_templates';

	/**
	 * SchemaDatabase instance (lazy).
	 *
	 * @var SchemaDatabase|null
	 */
	private $database = null;

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @return string Full table name.
	 */
	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Get SchemaDatabase instance (lazy).
	 *
	 * @return SchemaDatabase
	 */
	private function db(): SchemaDatabase {
		if ( ! isset( $this->database ) ) {
			$this->database = new SchemaDatabase();
		}
		return $this->database;
	}

	/**
	 * Whether we already attempted to ensure the table exists in this request.
	 *
	 * @var bool
	 */
	private static bool $table_checked = false;

	/**
	 * Ensure the templates table exists (self-healing). Called before every DB operation.
	 */
	private function ensure_table_exists(): void {
		if ( self::$table_checked ) {
			return;
		}
		self::$table_checked = true;

		global $wpdb;
		$table = $this->table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->create_table();
		}
	}

	/**
	 * Create the templates table.
	 *
	 * @return bool True on success.
	 */
	public function create_table(): bool {
		global $wpdb;

		$table = $this->table_name();

		// Check if table already exists.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			return true;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			schema_type VARCHAR(100) NOT NULL,
			properties LONGTEXT,
			post_types TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY schema_type (schema_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Get templates.
	 *
	 * @param array $args Optional args: 'post_type' to filter, 'orderby', 'order', 'limit'.
	 * @return array Array of template objects.
	 */
	public function get_templates( array $args = [] ): array {
		$this->ensure_table_exists();
		global $wpdb;

		$table = $this->table_name();
		$where = '';
		$params = [];

		if ( ! empty( $args['post_type'] ) ) {
			$where = " WHERE post_types LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $args['post_type'] ) . '%';
		}

		$orderby = ( isset( $args['orderby'] ) && in_array( $args['orderby'], [ 'name', 'created_at', 'schema_type' ], true ) )
			? $args['orderby']
			: 'name';
		$order = ( isset( $args['order'] ) && 'desc' === strtolower( $args['order'] ) ) ? 'DESC' : 'ASC';

		$sql = "SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order}";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $results ) ) {
			return [];
		}

		return array_map( [ $this, 'unserialize_template' ], $results );
	}

	/**
	 * Get a single template by ID.
	 *
	 * @param int $id Template ID.
	 * @return array|null Template data or null.
	 */
	public function get_template( int $id ): ?array {
		$this->ensure_table_exists();
		global $wpdb;

		$table  = $this->table_name();
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		return $this->unserialize_template( $result );
	}

	/**
	 * Get templates assigned to a specific post type.
	 *
	 * @param string $post_type The post type slug.
	 * @return array Array of template data.
	 */
	public function get_templates_for_post_type( string $post_type ): array {
		return $this->get_templates( [ 'post_type' => $post_type ] );
	}

	/**
	 * Save a template (create or update).
	 *
	 * @param array $data Template data: name, schema_type, properties, post_types, optional id/slug.
	 * @return int|false Template ID on success, false on failure.
	 */
	public function save_template( array $data ) {
		$this->ensure_table_exists();
		global $wpdb;

		$table = $this->table_name();

		$name        = sanitize_text_field( $data['name'] ?? '' );
		$schema_type = sanitize_text_field( $data['schema_type'] ?? '' );
		$post_types  = isset( $data['post_types'] ) ? array_map( 'sanitize_key', (array) $data['post_types'] ) : [];
		$properties  = isset( $data['properties'] ) ? $data['properties'] : [];

		if ( empty( $name ) || empty( $schema_type ) ) {
			return false;
		}

		// Sanitize property mappings.
		$sanitized_props = [];
		foreach ( (array) $properties as $prop_name => $prop_config ) {
			$clean_name = sanitize_text_field( $prop_name );
			if ( empty( $clean_name ) ) {
				continue;
			}
			$sanitized_props[ $clean_name ] = [
				'source'       => sanitize_text_field( $prop_config['source'] ?? '' ),
				'meta_key'     => sanitize_text_field( $prop_config['meta_key'] ?? '' ),
				'static_value' => sanitize_text_field( $prop_config['static_value'] ?? '' ),
			];
		}

		$record = [
			'name'        => $name,
			'schema_type' => $schema_type,
			'properties'  => wp_json_encode( $sanitized_props ),
			'post_types'  => wp_json_encode( $post_types ),
		];

		if ( ! empty( $data['id'] ) ) {
			// Update existing.
			$id = (int) $data['id'];
			$wpdb->update( $table, $record, [ 'id' => $id ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );
			return $id;
		}

		// Create new — generate slug.
		$slug = sanitize_title( $name );
		$base_slug = $slug;
		$suffix = 1;

		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$slug = $base_slug . '-' . $suffix;
			$suffix++;
		}

		$record['slug'] = $slug;
		$wpdb->insert( $table, $record, [ '%s', '%s', '%s', '%s', '%s' ] );

		return $wpdb->insert_id ?: false;
	}

	/**
	 * Delete a template.
	 *
	 * @param int $id Template ID.
	 * @return bool True on success.
	 */
	public function delete_template( int $id ): bool {
		$this->ensure_table_exists();
		global $wpdb;

		$table = $this->table_name();
		return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Render a template into a schema array for a given post.
	 *
	 * @param array    $template Template data (with decoded properties/post_types).
	 * @param \WP_Post $post     The post object.
	 * @return array|null Schema array or null.
	 */
	public function render_template_schema( array $template, \WP_Post $post ): ?array {
		if ( empty( $template['schema_type'] ) ) {
			return null;
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => $template['schema_type'],
		];

		$properties = $template['properties'] ?? [];

		foreach ( $properties as $prop_name => $prop_config ) {
			if ( empty( $prop_config['source'] ) ) {
				continue;
			}

			$value = $this->resolve_field_value( $prop_config, $post );

			if ( null !== $value && '' !== $value ) {
				$schema[ $prop_name ] = $value;
			}
		}

		// Remove empty values.
		$schema = array_filter( $schema, fn( $v ) => null !== $v && '' !== $v && [] !== $v );

		if ( empty( $schema['@type'] ) ) {
			return null;
		}

		return $schema;
	}

	/**
	 * Resolve a field value from a property config (same sources as SchemaPublic).
	 *
	 * @param array    $field Property config with source, meta_key, static_value.
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

	/**
	 * Unserialize template data from DB row.
	 *
	 * @param array $row DB row.
	 * @return array Unserialized template data.
	 */
	private function unserialize_template( array $row ): array {
		$row['properties'] = json_decode( $row['properties'] ?? '[]', true );
		if ( ! is_array( $row['properties'] ) ) {
			$row['properties'] = [];
		}
		$row['post_types'] = json_decode( $row['post_types'] ?? '[]', true );
		if ( ! is_array( $row['post_types'] ) ) {
			$row['post_types'] = [];
		}
		return $row;
	}
}
