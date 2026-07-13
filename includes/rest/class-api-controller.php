<?php
/**
 * REST API Controller
 *
 * Provides REST endpoints for querying schema types
 * and properties from the admin interface.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator\Rest;

use SchemaGenerator\SchemaParser;
use SchemaGenerator\SchemaDatabase;

defined( 'ABSPATH' ) || exit;

/**
 * Class ApiController
 *
 * Registers REST API routes for schema queries.
 */
class ApiController {

	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'schema-generator/v1';

	/**
	 * SchemaParser instance.
	 *
	 * @var SchemaParser
	 */
	private SchemaParser $parser;

	/**
	 * SchemaDatabase instance.
	 *
	 * @var SchemaDatabase
	 */
	private SchemaDatabase $database;

	/**
	 * Constructor.
	 *
	 * @param SchemaParser   $parser   The schema parser.
	 * @param SchemaDatabase $database The schema database.
	 */
	public function __construct( SchemaParser $parser, SchemaDatabase $database ) {
		$this->parser   = $parser;
		$this->database = $database;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/types',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_types' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args' => [
					'search' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
					'per_page' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 100,
						'minimum'  => 1,
						'maximum'  => 500,
					],
					'page' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
						'minimum'  => 1,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/types/(?P<type_id>[a-zA-Z:]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_type' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/types/(?P<type_id>[a-zA-Z:]+)/properties',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_type_properties' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/properties',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_properties' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args' => [
					'search' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args' => [
					'query' => [
						'required' => true,
						'type'     => 'string',
					],
					'kind' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'types',
						'enum'     => [ 'types', 'properties' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Get all schema types.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_types( \WP_REST_Request $request ): \WP_REST_Response {
		$search  = $request->get_param( 'search' );
		$per_page = $request->get_param( 'per_page' );
		$page    = $request->get_param( 'page' );

		if ( ! empty( $search ) ) {
			$types = $this->parser->search( $search, 'types' );
		} else {
			$types = $this->parser->get_types();
		}

		$types  = array_values( $types );
		$total  = count( $types );
		$offset = ( $page - 1 ) * $per_page;
		$types  = array_slice( $types, $offset, $per_page );

		$response = new \WP_REST_Response( $types );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Get a single schema type.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_type( \WP_REST_Request $request ): \WP_REST_Response {
		$type_id = $request->get_param( 'type_id' );
		$type    = $this->database->get_type( $type_id );

		if ( ! $type ) {
			return new \WP_REST_Response(
				[ 'message' => 'Type not found' ],
				404
			);
		}

		return new \WP_REST_Response( $type );
	}

	/**
	 * Get properties for a specific schema type.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_type_properties( \WP_REST_Request $request ): \WP_REST_Response {
		$type_id = $request->get_param( 'type_id' );
		$type    = $this->database->get_type( $type_id );

		if ( ! $type ) {
			return new \WP_REST_Response(
				[ 'message' => 'Type not found' ],
				404
			);
		}

		$properties = $this->parser->get_properties_for_type( $type_id );

		return new \WP_REST_Response( [
			'type'       => $type,
			'properties' => $properties,
		] );
	}

	/**
	 * Get all properties with optional search.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_properties( \WP_REST_Request $request ): \WP_REST_Response {
		$search = $request->get_param( 'search' );

		if ( ! empty( $search ) ) {
			$properties = $this->parser->search( $search, 'properties' );
		} else {
			// Avoid returning thousands of properties by default.
			$properties = [];
		}

		return new \WP_REST_Response( $properties );
	}

	/**
	 * Search types or properties.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $request->get_param( 'query' );
		$kind  = $request->get_param( 'kind' );

		$results = $this->parser->search( $query, $kind );

		return new \WP_REST_Response( [
			'results' => $results,
			'count'   => count( $results ),
		] );
	}

	/**
	 * Get dictionary statistics.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'types_count'    => $this->database->get_type_count(),
			'properties_count' => $this->database->get_property_count(),
			'is_populated'   => $this->database->is_populated(),
		] );
	}
}
