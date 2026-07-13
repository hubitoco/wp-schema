<?php
/**
 * Schema.org Vocabulary Parser
 *
 * Fetches and parses the official Schema.org vocabulary,
 * extracting Types and their Properties for local storage.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaParser
 *
 * Handles fetching the Schema.org JSON-LD vocabulary,
 * parsing the graph, and storing types/properties.
 */
class SchemaParser {

	/**
	 * Schema.org vocabulary URL.
	 */
	private const VOCABULARY_URL = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';

	/**
	 * WP Options key for the parsed schema data.
	 */
	private const OPTION_KEY = 'sg_schema_vocabulary';

	/**
	 * Maximum age in seconds before re-fetching vocabulary (30 days).
	 */
	private const CACHE_TTL = 2592000;

	/**
	 * Batch size for DB inserts.
	 */
	private const BATCH_SIZE = 80;

	/**
	 * SchemaDatabase instance (lazy).
	 *
	 * @var \SchemaGenerator\SchemaDatabase|null
	 */
	private $database = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// database created lazily via get_database()
	}

	private function get_database(): \SchemaGenerator\SchemaDatabase {
		if ( ! isset( $this->database ) ) {
			$this->database = new \SchemaGenerator\SchemaDatabase();
		}
		return $this->database;
	}

	/**
	 * Fetch the Schema.org vocabulary and store it locally.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function fetch_and_store(): bool {
		// The transient is set with a TTL. If it still exists, we are within the cache window.
		if ( false !== get_transient( self::OPTION_KEY . '_fetch_time' ) ) {
			return true;
		}

		$response = wp_remote_get(
			self::VOCABULARY_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Accept' => 'application/ld+json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log(
				sprintf(
					'[Schema Generator] Failed to fetch vocabulary: %s',
					$response->get_error_message()
				)
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log(
				sprintf(
					'[Schema Generator] Vocabulary fetch returned HTTP %d',
					$code
				)
			);
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		unset( $body );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log(
				sprintf(
					'[Schema Generator] JSON parse error: %s',
					json_last_error_msg()
				)
			);
			return false;
		}

		$graph = $data['@graph'] ?? [];
		unset( $data );

		if ( empty( $graph ) ) {
			error_log( '[Schema Generator] No @graph found in vocabulary.' );
			return false;
		}

		return $this->process_graph( $graph );
	}

	/**
	 * Process the Schema.org graph in a single pass with immediate DB storage.
	 *
	 * Never holds more than one batch of types/properties in memory at a time.
	 *
	 * @param array $graph The @graph array from Schema.org vocabulary.
	 * @return bool True on success.
	 */
	private function process_graph( array $graph ): bool {
		$type_batch      = [];
		$property_batch  = [];
		$inheritance     = [];
		$total_types     = 0;
		$total_props     = 0;

		$db = $this->get_database();

		// Iterate by key so we can release memory as we go.
		foreach ( array_keys( $graph ) as $key ) {
			$item = $graph[ $key ];
			unset( $graph[ $key ] ); // release reference early

			$id   = $item['@id'] ?? '';
			$type = $item['@type'] ?? '';

			if ( 'rdfs:Class' === $type || 'Class' === $type ) {
				$type_batch[ $id ] = [
					'id'          => $id,
					'label'       => $item['rdfs:label'] ?? $id,
					'description' => $item['rdfs:comment'] ?? '',
					'parent'      => '',
					'deprecated'  => ! empty( $item['meta'] ) && str_contains(
						(string) $item['meta'],
						'deprecated'
					),
				];

				if ( ! empty( $item['rdfs:subClassOf'] ) ) {
					$parent = is_array( $item['rdfs:subClassOf'] )
						? ( $item['rdfs:subClassOf']['@id'] ?? '' )
						: $item['rdfs:subClassOf'];
					$type_batch[ $id ]['parent'] = $parent;
					$inheritance[ $id ]           = $parent;
				}

				// Flush type batch when it reaches the limit.
				if ( count( $type_batch ) >= self::BATCH_SIZE ) {
					$db->store_types( $type_batch );
					$total_types += count( $type_batch );
					$type_batch = [];
				}
			} elseif ( 'rdf:Property' === $type || 'Property' === $type ) {
				$domain = $item['schema:domainIncludes'] ?? [];
				if ( ! is_array( $domain ) ) {
					$domain = [ $domain ];
				}

				$domain_ids = [];
				foreach ( $domain as $d ) {
					$domain_ids[] = is_array( $d ) ? ( $d['@id'] ?? '' ) : $d;
				}

				$ranges = $item['schema:rangeIncludes'] ?? [];
				if ( ! is_array( $ranges ) ) {
					$ranges = [ $ranges ];
				}

				$range_ids = [];
				foreach ( $ranges as $r ) {
					$range_ids[] = is_array( $r ) ? ( $r['@id'] ?? '' ) : $r;
				}

				$property_batch[ $id ] = [
					'id'          => $id,
					'label'       => $item['rdfs:label'] ?? $id,
					'description' => $item['rdfs:comment'] ?? '',
					'domains'     => $domain_ids,
					'ranges'      => $range_ids,
				];

				// Flush property batch when it reaches the limit.
				if ( count( $property_batch ) >= self::BATCH_SIZE ) {
					$db->store_properties( $property_batch );
					$total_props += count( $property_batch );
					$property_batch = [];
				}
			}

			unset( $item );
		}

		// Flush remaining batches.
		if ( ! empty( $type_batch ) ) {
			$db->store_types( $type_batch );
			$total_types += count( $type_batch );
			$type_batch = [];
		}

		if ( ! empty( $property_batch ) ) {
			$db->store_properties( $property_batch );
			$total_props += count( $property_batch );
			$property_batch = [];
		}

		// Store inheritance in batches too.
		$inherit_batch = [];
		foreach ( $inheritance as $child => $parent ) {
			$inherit_batch[ $child ] = $parent;

			if ( count( $inherit_batch ) >= self::BATCH_SIZE ) {
				$db->store_inheritance( $inherit_batch );
				$inherit_batch = [];
			}
		}
		if ( ! empty( $inherit_batch ) ) {
			$db->store_inheritance( $inherit_batch );
		}
		unset( $inheritance, $inherit_batch, $graph );

		set_transient( self::OPTION_KEY . '_fetch_time', time(), self::CACHE_TTL );

		error_log(
			sprintf(
				'[Schema Generator] Vocabulary stored: %d types, %d properties.',
				$total_types,
				$total_props
			)
		);

		return true;
	}

	/**
	 * Get all available schema types from the database.
	 *
	 * @param bool $include_deprecated Whether to include deprecated types.
	 * @return array Associative array of type ID => type data.
	 */
	public function get_types( bool $include_deprecated = false ): array {
		$types = $this->get_database()->get_all_types();

		if ( ! $include_deprecated ) {
			$types = array_filter(
				$types,
				fn( $type ) => empty( $type['deprecated'] )
			);
		}

		return $types;
	}

	/**
	 * Get properties for a specific schema type (including inherited).
	 *
	 * @param string $type_id The schema type ID.
	 * @return array Array of property data.
	 */
	public function get_properties_for_type( string $type_id ): array {
		return $this->get_database()->get_properties_for_type( $type_id );
	}

	/**
	 * Search types or properties by keyword.
	 *
	 * @param string $query The search term.
	 * @param string $kind  'types' or 'properties'.
	 * @return array Matching items.
	 */
	public function search( string $query, string $kind = 'types' ): array {
		$db = $this->get_database();
		if ( 'types' === $kind ) {
			return $db->search_types( $query );
		}

		return $db->search_properties( $query );
	}
}
