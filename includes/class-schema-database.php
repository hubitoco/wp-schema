<?php
/**
 * Schema.org Database Manager
 *
 * Manages custom database tables for storing Schema.org
 * types, properties, and inheritance relationships.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaDatabase
 *
 * Handles database operations for the Schema.org vocabulary.
 */
class SchemaDatabase {

	/**
	 * Table names.
	 */
	private const TYPES_TABLE      = 'sg_schema_types';
	private const PROPERTIES_TABLE = 'sg_schema_properties';
	private const INHERIT_TABLE    = 'sg_schema_inheritance';

	/**
	 * Whether we already attempted to ensure tables exist in this request.
	 */
	private static bool $tables_checked = false;

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @param string $table Short table name constant.
	 * @return string Full table name.
	 */
	private function table_name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Ensure the custom tables exist (self-healing). Called before heavy reads.
	 */
	private function ensure_tables_exist(): void {
		if ( self::$tables_checked ) {
			return;
		}
		self::$tables_checked = true;

		// Fast existence check on one table.
		global $wpdb;
		$table = $this->table_name( self::TYPES_TABLE );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			$this->create_table();
		}
	}

	/**
	 * Create all required database tables.
	 *
	 * @return bool True on success.
	 */
	public function create_table(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql_types = "CREATE TABLE {$this->table_name( self::TYPES_TABLE )} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			schema_id VARCHAR(255) NOT NULL,
			label VARCHAR(255) NOT NULL,
			description TEXT,
			parent VARCHAR(255) DEFAULT '',
			deprecated TINYINT(1) DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY schema_id (schema_id),
			KEY label (label),
			KEY parent (parent)
		) {$charset_collate};";

		$sql_properties = "CREATE TABLE {$this->table_name( self::PROPERTIES_TABLE )} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			schema_id VARCHAR(255) NOT NULL,
			label VARCHAR(255) NOT NULL,
			description TEXT,
			domains TEXT,
			ranges TEXT,
			PRIMARY KEY (id),
			UNIQUE KEY schema_id (schema_id),
			KEY label (label)
		) {$charset_collate};";

		$sql_inheritance = "CREATE TABLE {$this->table_name( self::INHERIT_TABLE )} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			child_type VARCHAR(255) NOT NULL,
			parent_type VARCHAR(255) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY child_parent (child_type, parent_type),
			KEY child_type (child_type),
			KEY parent_type (parent_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_types );
		dbDelta( $sql_properties );
		dbDelta( $sql_inheritance );

		return true;
	}

	/**
	 * Store schema types in the database using batch operations.
	 *
	 * @param array $types Associative array of type data.
	 * @return int Number of types inserted/updated.
	 */
	public function store_types( array $types ): int {
		global $wpdb;

		$table = $this->table_name( self::TYPES_TABLE );
		$count = 0;

		foreach ( $types as $type ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (schema_id, label, description, parent, deprecated) VALUES (%s, %s, %s, %s, %d)
				ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), parent=VALUES(parent), deprecated=VALUES(deprecated)",
				$type['id'],
				$type['label'],
				$type['description'],
				$type['parent'],
				$type['deprecated'] ? 1 : 0
			);
			$wpdb->query( $sql );
			++$count;
		}

		return $count;
	}

	/**
	 * Store schema properties in the database using batch operations.
	 *
	 * @param array $properties Associative array of property data.
	 * @return int Number of properties inserted/updated.
	 */
	public function store_properties( array $properties ): int {
		global $wpdb;

		$table = $this->table_name( self::PROPERTIES_TABLE );
		$count = 0;

		foreach ( $properties as $prop ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (schema_id, label, description, domains, ranges) VALUES (%s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), domains=VALUES(domains), ranges=VALUES(ranges)",
				$prop['id'],
				$prop['label'],
				$prop['description'],
				wp_json_encode( $prop['domains'] ),
				wp_json_encode( $prop['ranges'] )
			);
			$wpdb->query( $sql );
			++$count;
		}

		return $count;
	}

	/**
	 * Store inheritance relationships using batch operations.
	 *
	 * @param array $inheritance Associative array of child => parent.
	 * @return int Number of relationships inserted.
	 */
	public function store_inheritance( array $inheritance ): int {
		global $wpdb;

		$table = $this->table_name( self::INHERIT_TABLE );
		$count = 0;

		foreach ( $inheritance as $child => $parent ) {
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table} (child_type, parent_type) VALUES (%s, %s)",
				$child,
				$parent
			);
			$wpdb->query( $sql );
			++$count;
		}

		return $count;
	}

	/**
	 * Get all schema types (alias for get_all_types).
	 *
	 * @return array Associative array of type ID => type data.
	 */
	public function get_types(): array {
		return $this->get_all_types();
	}

	/**
	 * Get all schema types.
	 *
	 * @return array Associative array of type ID => type data.
	 */
	public function get_all_types(): array {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::TYPES_TABLE );
		$rows  = $wpdb->get_results(
			"SELECT schema_id, label, description, parent, deprecated FROM {$table} ORDER BY label ASC",
			ARRAY_A
		);

		$types = [];
		foreach ( $rows as $row ) {
			$types[ $row['schema_id'] ] = [
				'id'          => $row['schema_id'],
				'label'       => $row['label'],
				'description' => $row['description'],
				'parent'      => $row['parent'],
				'deprecated'  => (bool) $row['deprecated'],
			];
		}

		return $types;
	}

	/**
	 * Resolve a user-supplied type (bare label like "QAPage", or "schema:QAPage",
	 * or a full URL) to the canonical schema_id stored in the database.
	 *
	 * @param string $type The type label or id.
	 * @return string The canonical schema_id, or the cleaned input if not found.
	 */
	public function resolve_type_id( string $type ): string {
		$this->ensure_tables_exist();
		global $wpdb;

		$type = trim( $type );
		if ( '' === $type ) {
			return $type;
		}

		// If it already exists verbatim, use it as-is.
		$table = $this->table_name( self::TYPES_TABLE );
		$exact = $wpdb->get_var( $wpdb->prepare( "SELECT schema_id FROM {$table} WHERE schema_id = %s LIMIT 1", $type ) );
		if ( $exact ) {
			return $exact;
		}

		// Strip common prefixes to a bare label, then match by suffix or label.
		$bare = preg_replace( '/^https?:\/\/schema\.org\//i', '', $type );
		$bare = preg_replace( '/^schema:/i', '', $bare );
		$bare = trim( $bare );

		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT schema_id FROM {$table}
			 WHERE schema_id = %s OR label = %s OR schema_id LIKE %s OR schema_id LIKE %s
			 ORDER BY LENGTH(schema_id) ASC LIMIT 1",
			$bare,
			$bare,
			'%:' . $wpdb->esc_like( $bare ),
			'%/' . $wpdb->esc_like( $bare )
		) );

		return $found ? $found : $bare;
	}

	/**
	 * Get properties for a specific type, including inherited properties.
	 *
	 * @param string $type_id The schema type ID.
	 * @return array Array of property data.
	 */
	public function get_properties_for_type( string $type_id ): array {
		$this->ensure_tables_exist();
		static $cache = [];

		// Resolve a bare label (e.g. "QAPage") or prefixed id to the stored
		// canonical schema_id (e.g. "schema:QAPage") so domain/parent lookups match.
		$type_id = $this->resolve_type_id( $type_id );

		$cache_key = $type_id;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$properties = $this->get_direct_properties( $type_id );
		$parents    = $this->get_type_parents( $type_id );

		foreach ( $parents as $parent ) {
			$parent_props    = $this->get_direct_properties( $parent );
			$existing_ids    = array_column( $properties, 'id' );
			$filtered_parent = array_filter(
				$parent_props,
				fn( $p ) => ! in_array( $p['id'], $existing_ids, true )
			);
			$properties = array_merge( $properties, $filtered_parent );
		}

		$cache[ $cache_key ] = $properties;
		return $properties;
	}

	/**
	 * Get direct properties for a type (not inherited).
	 *
	 * Uses JSON_CONTAINS with fallback to PHP-side filter to be robust across MySQL versions.
	 *
	 * @param string $type_id The schema type ID.
	 * @return array Array of property data.
	 */
	private function get_direct_properties( string $type_id ): array {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::PROPERTIES_TABLE );

		// Try efficient JSON query first.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT schema_id, label, description, domains, ranges FROM {$table} WHERE JSON_CONTAINS(domains, %s)",
				wp_json_encode( $type_id )
			),
			ARRAY_A
		);

		// If no results or error, fallback to full scan + filter (tables stay small).
		if ( empty( $rows ) && empty( $wpdb->last_error ) ) {
			// Check if the JSON query simply returned nothing vs table problem.
			// Do a lightweight full load only when necessary.
			$all = $wpdb->get_results(
				"SELECT schema_id, label, description, domains, ranges FROM {$table} LIMIT 10000",
				ARRAY_A
			);
			$rows = [];
			$needle = wp_json_encode( $type_id );
			foreach ( $all as $row ) {
				if ( false !== strpos( $row['domains'] ?? '', $type_id ) ) {
					$rows[] = $row;
				}
			}
		}

		$properties = [];
		foreach ( $rows as $row ) {
			$properties[] = [
				'id'          => $row['schema_id'],
				'label'       => $row['label'],
				'description' => $row['description'],
				'domains'     => json_decode( $row['domains'], true ) ?? [],
				'ranges'      => json_decode( $row['ranges'], true ) ?? [],
			];
		}

		return $properties;
	}

	/**
	 * Get all parent types for a given type (traverses the hierarchy).
	 *
	 * @param string $type_id The schema type ID.
	 * @param array  $visited Prevents infinite loops. Pass by ref internally.
	 * @return array List of parent type IDs.
	 */
	public function get_type_parents( string $type_id, array &$visited = null ): array {
		$this->ensure_tables_exist();
		global $wpdb;

		if ( $visited === null ) {
			$visited = [];
		}

		if ( in_array( $type_id, $visited, true ) ) {
			return [];
		}

		$visited[] = $type_id;
		$parents   = [];

		$table  = $this->table_name( self::TYPES_TABLE );
		$parent = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT parent FROM {$table} WHERE schema_id = %s",
				$type_id
			)
		);

		if ( ! empty( $parent ) ) {
			$parents[]    = $parent;
			$grandparents = $this->get_type_parents( $parent, $visited );
			$parents      = array_merge( $parents, $grandparents );
		}

		return $parents;
	}

	/**
	 * Search types by label or ID.
	 *
	 * @param string $query Search term.
	 * @return array Matching types.
	 */
	public function search_types( string $query ): array {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::TYPES_TABLE );
		$like  = '%' . $wpdb->esc_like( $query ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT schema_id, label, description, parent, deprecated
				FROM {$table}
				WHERE label LIKE %s OR schema_id LIKE %s OR description LIKE %s
				ORDER BY label ASC
				LIMIT 50",
				$like,
				$like,
				$like
			),
			ARRAY_A
		);

		$types = [];
		foreach ( $rows as $row ) {
			$types[ $row['schema_id'] ] = [
				'id'          => $row['schema_id'],
				'label'       => $row['label'],
				'description' => $row['description'],
				'parent'      => $row['parent'],
				'deprecated'  => (bool) $row['deprecated'],
			];
		}

		return $types;
	}

	/**
	 * Search properties by label or ID.
	 *
	 * @param string $query Search term.
	 * @return array Matching properties.
	 */
	public function search_properties( string $query ): array {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::PROPERTIES_TABLE );
		$like  = '%' . $wpdb->esc_like( $query ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT schema_id, label, description, domains, ranges
				FROM {$table}
				WHERE label LIKE %s OR schema_id LIKE %s OR description LIKE %s
				ORDER BY label ASC
				LIMIT 50",
				$like,
				$like,
				$like
			),
			ARRAY_A
		);

		$properties = [];
		foreach ( $rows as $row ) {
			$properties[] = [
				'id'          => $row['schema_id'],
				'label'       => $row['label'],
				'description' => $row['description'],
				'domains'     => json_decode( $row['domains'], true ) ?? [],
				'ranges'      => json_decode( $row['ranges'], true ) ?? [],
			];
		}

		return $properties;
	}

	/**
	 * Get the total number of types in the database.
	 *
	 * @return int Total type count.
	 */
	public function get_type_count(): int {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::TYPES_TABLE );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return $count ? (int) $count : 0;
	}

	/**
	 * Get the total number of properties in the database.
	 *
	 * @return int Total property count.
	 */
	public function get_property_count(): int {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::PROPERTIES_TABLE );
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return $count ? (int) $count : 0;
	}

	/**
	 * Check if the schema vocabulary has been loaded.
	 *
	 * @return bool True if types exist in database.
	 */
	public function is_populated(): bool {
		return $this->get_type_count() > 0;
	}

	/**
	 * Get a single type by its schema ID.
	 *
	 * @param string $type_id The schema type ID.
	 * @return array|null Type data or null if not found.
	 */
	public function get_type( string $type_id ): ?array {
		$this->ensure_tables_exist();
		global $wpdb;

		$table = $this->table_name( self::TYPES_TABLE );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT schema_id, label, description, parent, deprecated FROM {$table} WHERE schema_id = %s",
				$type_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'id'          => $row['schema_id'],
			'label'       => $row['label'],
			'description' => $row['description'],
			'parent'      => $row['parent'],
			'deprecated'  => (bool) $row['deprecated'],
		];
	}

	/**
	 * Export all vocabulary data (for backup / migrate).
	 */
	public function export_vocabulary(): array {
		$this->ensure_tables_exist();
		global $wpdb;

		$types_table      = $this->table_name( self::TYPES_TABLE );
		$properties_table = $this->table_name( self::PROPERTIES_TABLE );
		$inherit_table    = $this->table_name( self::INHERIT_TABLE );

		return [
			'types'       => $wpdb->get_results( "SELECT * FROM {$types_table}", ARRAY_A ),
			'properties'  => $wpdb->get_results( "SELECT * FROM {$properties_table}", ARRAY_A ),
			'inheritance' => $wpdb->get_results( "SELECT * FROM {$inherit_table}", ARRAY_A ),
		];
	}

	/**
	 * Import vocabulary data (replaces existing vocabulary tables).
	 */
	public function import_vocabulary( array $data ): bool {
		$this->ensure_tables_exist();
		global $wpdb;

		$types_table      = $this->table_name( self::TYPES_TABLE );
		$properties_table = $this->table_name( self::PROPERTIES_TABLE );
		$inherit_table    = $this->table_name( self::INHERIT_TABLE );

		// Clear existing data
		$wpdb->query( "TRUNCATE TABLE {$types_table}" );
		$wpdb->query( "TRUNCATE TABLE {$properties_table}" );
		$wpdb->query( "TRUNCATE TABLE {$inherit_table}" );

		$inserted = 0;

		if ( ! empty( $data['types'] ) && is_array( $data['types'] ) ) {
			foreach ( $data['types'] as $row ) {
				$wpdb->insert( $types_table, $row );
				$inserted++;
			}
		}

		if ( ! empty( $data['properties'] ) && is_array( $data['properties'] ) ) {
			foreach ( $data['properties'] as $row ) {
				$wpdb->insert( $properties_table, $row );
			}
		}

		if ( ! empty( $data['inheritance'] ) && is_array( $data['inheritance'] ) ) {
			foreach ( $data['inheritance'] as $row ) {
				$wpdb->insert( $inherit_table, $row );
			}
		}

		return $inserted > 0;
	}

	/**
	 * Normalize a raw property key to the official Schema.org property name.
	 * Handles mangled keys like "schemaarticlebody", "schema:articleBody", "ARTICLEBODY", etc.
	 * Looks up in the vocabulary database for the canonical form.
	 */
	public function normalize_property_name( string $key ): string {
		$this->ensure_tables_exist();
		global $wpdb;

		$key = trim( $key );
		if ( empty( $key ) ) {
			return $key;
		}

		// Aggressive cleaning of prefixes
		$clean = preg_replace( '/^https?:\/\/schema\.org\//i', '', $key );
		$clean = preg_replace( '/^schema:/i', '', $clean );
		$clean = preg_replace( '/^schema/i', '', $clean );
		// Extra: if still starts with "schema" glued (e.g. schemaarticlebody)
		$clean = preg_replace( '/^schema/i', '', $clean );
		$clean = trim( $clean );

		if ( empty( $clean ) ) {
			return $key;
		}

		$lower_clean = strtolower( $clean );

		$properties_table = $this->table_name( self::PROPERTIES_TABLE );

		// Try to find a matching property in vocabulary (case-insensitive)
		// Match end of schema_id after : or / or direct
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT schema_id FROM {$properties_table} 
			 WHERE LOWER(schema_id) LIKE %s 
			    OR LOWER(schema_id) LIKE %s 
			    OR LOWER(schema_id) = %s 
			 LIMIT 1",
			'%/' . $lower_clean,
			'%:' . $lower_clean,
			$lower_clean
		), ARRAY_A );

		if ( $row && ! empty( $row['schema_id'] ) ) {
			$found = $row['schema_id'];
			// Always return cleaned version of the canonical id
			$found = preg_replace( '/^https?:\/\/schema\.org\//i', '', $found );
			$found = preg_replace( '/^schema:/i', '', $found );
			$found = preg_replace( '/^schema/i', '', $found );
			return trim( $found );
		}

		// Fallback: return the cleaned version
		// If all lower and looks like a property, use map for common ones
		if ( ctype_lower( str_replace( ['_', '-'], '', $clean ) ) ) {
			$map = [
				'articlebody' => 'articleBody',
				'alternativeheadline' => 'alternativeHeadline',
				'datecreated' => 'dateCreated',
				'datemodified' => 'dateModified',
				'datepublished' => 'datePublished',
				'discussionurl' => 'discussionUrl',
				'isbasedonurl' => 'isBasedOnUrl',
				'alternatename' => 'alternateName',
				'disambiguatingdescription' => 'disambiguatingDescription',
				'thumbnailurl' => 'thumbnailUrl',
				'headline' => 'headline',
				'description' => 'description',
				'name' => 'name',
				'url' => 'url',
				'author' => 'author',
				'creator' => 'creator',
				'image' => 'image',
				'thumbnail' => 'thumbnail',
				'publisher' => 'publisher',
				'mainentityofpage' => 'mainEntityOfPage',
				'ispartof' => 'isPartOf',
				'about' => 'about',
				'mentions' => 'mentions',
				'keywords' => 'keywords',
				'sddatepublished' => 'datePublished',
				// Extend this map as needed. For complete coverage, fetch the vocabulary.
			];
			if (isset($map[$clean])) {
				return $map[$clean];
			}
		}

		return $clean;
	}
}
