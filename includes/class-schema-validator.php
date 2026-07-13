<?php
/**
 * Schema.org Output Validator
 *
 * Validates generated schema arrays by remapping known invalid
 * properties (Dublin Core, Open Graph, etc.) to their Schema.org
 * equivalents, and fixing value type issues.
 *
 * This class is intentionally simple — it only fixes known bad
 * properties. It does NOT do strict vocabulary-based stripping,
 * which was causing valid properties to be removed.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaValidator
 *
 * Cleans and validates schema arrays before they are output as JSON-LD.
 */
class SchemaValidator {

	/**
	 * Option key for storing the last validation issues.
	 */
	private const ISSUES_OPTION = 'sg_last_validation_issues';

	/**
	 * Known invalid → valid property remapping.
	 * These are common Dublin Core, Open Graph, and other
	 * non-Schema.org properties that may leak into output.
	 *
	 * @var array<string, string>
	 */
	private array $property_map = [
		// Dublin Core → Schema.org.
		'dctdescription' => 'description',
		'dctcreator'     => 'author',
		'dcttitle'       => 'name',
		'dctdate'        => 'datePublished',
		'dctformat'      => 'encodingFormat',
		'dctlanguage'    => 'inLanguage',
		'dctidentifier'  => 'identifier',
		'dctpublisher'   => 'publisher',
		'dctsubject'     => 'keywords',
		'dcttype'        => 'additionalType',

		// Open Graph → Schema.org.
		'ogurl'          => 'url',
		'ogimage'        => 'image',
		'ogtitle'        => 'name',
		'ogdescription'  => 'description',
		'ogtype'         => 'additionalType',
		'ogsite_name'    => 'publisher',

		// Other common non-Schema.org properties.
		'sddatepublished'           => 'datePublished',
		'thumbnailurl'              => 'thumbnail',
		'disambiguatingdescription' => 'description',
		'alternatename'             => 'alternateName',
		'mainentityofpage'          => 'mainEntityOfPage',
		'isbasedonurl'              => 'isBasedOn',
		'discussionurl'             => 'discussionUrl',
		'articlebody'               => 'articleBody',
		'alternativeheadline'       => 'alternativeHeadline',
	];

	/**
	 * Properties whose values should be URL strings, not objects.
	 * When these contain an ImageObject/array, extract the URL.
	 *
	 * @var array<string, true>
	 */
	private array $url_only_properties = [
		'thumbnail'    => true,
		'thumbnailUrl' => true,
		'url'          => true,
	];

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Clean and validate a full schema array.
	 *
	 * 1. Remaps known invalid properties (e.g., dctdescription → description).
	 * 2. Fixes value type issues (e.g., ImageObject → URL string for thumbnailUrl).
	 * 3. Passes through all other properties unchanged.
	 *
	 * @param array $schema The raw schema array.
	 * @return array The cleaned schema array.
	 */
	public function clean_schema( array $schema ): array {
		$issues = [];
		$type   = $schema['@type'] ?? '';

		if ( empty( $type ) ) {
			return $schema;
		}

		$cleaned = [
			'@context' => $schema['@context'] ?? 'https://schema.org',
			'@type'    => $type,
		];

		$numeric_keys = []; // child schemas pushed with []

		foreach ( $schema as $key => $value ) {
			// Skip @context and @type — handled above.
			if ( '@context' === $key || '@type' === $key ) {
				continue;
			}

			// Preserve numeric-keyed entries (child schemas) as-is.
			if ( is_int( $key ) ) {
				$numeric_keys[] = $value;
				continue;
			}

			$original_key = $key;

			// Step 1: Remap known invalid properties.
			$lower_key = strtolower( $key );
			if ( isset( $this->property_map[ $lower_key ] ) ) {
				$new_key  = $this->property_map[ $lower_key ];
				$issues[] = [
					'original' => $original_key,
					'remapped' => $new_key,
					'action'   => 'remapped',
					'reason'   => "Non-Schema.org property '{$original_key}' remapped to '{$new_key}'",
				];
				$key = $new_key;
			}

			// Step 2: Fix value type issues.
			$value = $this->fix_value_type( $key, $value, $issues );

			// Avoid overwriting if remapped key already set (keep first).
			if ( ! array_key_exists( $key, $cleaned ) ) {
				$cleaned[ $key ] = $value;
			}
		}

		// Append child schemas (numeric keys) at the end.
		foreach ( $numeric_keys as $child ) {
			$cleaned[] = $child;
		}

		// Store issues for admin display.
		if ( ! empty( $issues ) ) {
			$this->store_issues( $issues );
		}

		return $cleaned;
	}

	/**
	 * Fix value type issues for specific properties.
	 *
	 * For example, `thumbnailUrl` should be a URL string, not an ImageObject.
	 *
	 * @param string $key    The property key.
	 * @param mixed  $value  The current value.
	 * @param array  $issues Issues array (modified by reference).
	 * @return mixed The fixed value.
	 */
	private function fix_value_type( string $key, $value, array &$issues ) {
		// If this is a URL-only property and the value is an array/object with a 'url' key.
		if ( isset( $this->url_only_properties[ $key ] ) && is_array( $value ) ) {
			if ( isset( $value['@type'] ) && isset( $value['url'] ) ) {
				$issues[] = [
					'original' => $key,
					'remapped' => $key,
					'action'   => 'fixed_type',
					'reason'   => "Property '{$key}' had ImageObject value — extracted URL string",
				];
				return $value['url'];
			}
		}

		// Recursively clean nested schema objects.
		if ( is_array( $value ) && isset( $value['@type'] ) ) {
			return $this->clean_schema( $value );
		}

		// Clean arrays of values.
		if ( is_array( $value ) && ! isset( $value['@type'] ) ) {
			$fixed = [];
			foreach ( $value as $item ) {
				if ( is_array( $item ) && isset( $item['@type'] ) ) {
					$fixed[] = $this->clean_schema( $item );
				} else {
					$fixed[] = $item;
				}
			}
			return $fixed;
		}

		return $value;
	}

	/**
	 * Store validation issues for admin display.
	 *
	 * Keeps only the most recent batch of issues.
	 *
	 * @param array $issues The issues to store.
	 */
	private function store_issues( array $issues ): void {
		$existing = get_option( self::ISSUES_OPTION, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Merge with existing, keeping max 50 entries.
		$merged = array_merge( $issues, $existing );
		$merged = array_slice( $merged, 0, 50 );

		update_option( self::ISSUES_OPTION, $merged );
	}

	/**
	 * Get the stored validation issues.
	 *
	 * @return array The validation issues.
	 */
	public static function get_issues(): array {
		$issues = get_option( self::ISSUES_OPTION, [] );
		return is_array( $issues ) ? $issues : [];
	}

	/**
	 * Clear stored validation issues.
	 */
	public static function clear_issues(): void {
		delete_option( self::ISSUES_OPTION );
	}
}
