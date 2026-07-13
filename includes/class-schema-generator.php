<?php
/**
 * Main Schema Generator Orchestrator
 *
 * Coordinates all plugin components: admin, public output,
 * and REST API endpoints.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator;

use SchemaGenerator\Admin\AdminSettings;
use SchemaGenerator\Frontend\SchemaPublic;
use SchemaGenerator\Rest\ApiController;

defined( 'ABSPATH' ) || exit;

/**
 * Class SchemaGenerator
 *
 * Singleton orchestrator for the Schema Generator plugin.
 */
class SchemaGenerator {

	/**
	 * Singleton instance.
	 *
	 * @var \SchemaGenerator\SchemaGenerator|null
	 */
	private static ?\SchemaGenerator\SchemaGenerator $instance = null;

	/**
	 * SchemaParser instance (lazy).
	 *
	 * @var \SchemaGenerator\SchemaParser|null
	 */
	private $parser = null;

	/**
	 * SchemaDatabase instance (lazy).
	 *
	 * @var \SchemaGenerator\SchemaDatabase|null
	 */
	private $database = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return SchemaGenerator
	 */
	public static function instance(): \SchemaGenerator\SchemaGenerator {
		if ( null === self::$instance ) {
			self::$instance = new static();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton. Components are created lazily.
	 */
	private function __construct() {}

	/**
	 * Initialize the plugin.
	 *
	 * Registers hooks for admin and public functionality.
	 */
	public function init(): void {
		if ( is_admin() ) {
			$this->init_admin();
		}

		$this->init_public();
		$this->init_rest_api();
	}

	/**
	 * Initialize admin components.
	 */
	private function init_admin(): void {
		$admin = new AdminSettings( $this->get_database() );
		$admin->init();
	}

	/**
	 * Initialize public-facing components.
	 */
	private function init_public(): void {
		$public = new SchemaPublic( $this->get_database() );
		$public->init();
	}

	/**
	 * Initialize REST API endpoints.
	 */
	private function init_rest_api(): void {
		new ApiController( $this->get_parser(), $this->get_database() );
	}

	/**
	 * Get (lazy) the schema parser.
	 */
	public function get_parser(): \SchemaGenerator\SchemaParser {
		if ( ! isset( $this->parser ) ) {
			$this->parser = new \SchemaGenerator\SchemaParser();
		}
		return $this->parser;
	}

	/**
	 * Get (lazy) the schema database.
	 */
	public function get_database(): \SchemaGenerator\SchemaDatabase {
		if ( ! isset( $this->database ) ) {
			$this->database = new \SchemaGenerator\SchemaDatabase();
		}
		return $this->database;
	}
}
