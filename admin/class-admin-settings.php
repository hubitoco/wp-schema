<?php
/**
 * Admin Settings Page
 *
 * Registers the WordPress admin menu and settings for the Schema Generator.
 *
 * @package SchemaGenerator
 */

namespace SchemaGenerator\Admin;

use SchemaGenerator\SchemaParser;
use SchemaGenerator\SchemaDatabase;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminSettings
 *
 * Handles admin menu registration, settings pages, and enqueue of assets.
 */
class AdminSettings {

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
	 * Initialize admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_metabox_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_sg_fetch_schema', array( $this, 'ajax_fetch_schema' ) );
		add_action( 'wp_ajax_sg_fetch_status', array( $this, 'ajax_fetch_status' ) );
		add_action( 'wp_ajax_sg_get_type_properties', array( $this, 'ajax_get_type_properties' ) );
		add_action( 'wp_ajax_sg_search_types', array( $this, 'ajax_search_types' ) );
		add_action( 'wp_ajax_sg_save_mappings', array( $this, 'ajax_save_mappings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		// Invalidate the cached frontend JSON-LD whenever a post is saved so
		// schema (or content the schema pulls from) changes show up immediately.
		add_action( 'save_post', array( $this, 'clear_post_cache' ) );
		add_action( 'sg_fetch_vocabulary_cron', array( $this, 'run_fetch_vocabulary' ) );

		// Import / Export
		add_action( 'admin_post_sg_export_data', array( $this, 'handle_export' ) );
		add_action( 'admin_post_sg_import_data', array( $this, 'handle_import' ) );

		// One-time normalization tool for existing mappings
		add_action( 'wp_ajax_sg_normalize_mappings', array( $this, 'ajax_normalize_mappings' ) );

		// Validation log
		add_action( 'wp_ajax_sg_clear_validation_log', array( $this, 'ajax_clear_validation_log' ) );

		// Schema templates CRUD.
		add_action( 'wp_ajax_sg_save_template', array( $this, 'ajax_save_template' ) );
		add_action( 'wp_ajax_sg_delete_template', array( $this, 'ajax_delete_template' ) );
		add_action( 'wp_ajax_sg_get_template', array( $this, 'ajax_get_template' ) );

		// Per-post schemas CRUD.
		add_action( 'wp_ajax_sg_save_post_schemas', array( $this, 'ajax_save_post_schemas' ) );
		add_action( 'wp_ajax_sg_get_post_schemas', array( $this, 'ajax_get_post_schemas' ) );

		// Register meta for block editor (Gutenberg) compatibility.
		add_action( 'init', array( $this, 'register_post_meta' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'Schema Generator', 'schema-generator' ),
			__( 'Schema Generator', 'schema-generator' ),
			'manage_options',
			'schema-generator',
			array( $this, 'render_main_page' ),
			'dashicons-networking',
			30
		);

		add_submenu_page(
			'schema-generator',
			__( 'Schema Dictionary', 'schema-generator' ),
			__( 'Schema Dictionary', 'schema-generator' ),
			'manage_options',
			'schema-generator',
			array( $this, 'render_main_page' )
		);

		add_submenu_page(
			'schema-generator',
			__( 'Post Type Mapping', 'schema-generator' ),
			__( 'Post Type Mapping', 'schema-generator' ),
			'manage_options',
			'sg-mapping',
			array( $this, 'render_mapping_page' )
		);

		add_submenu_page(
			'schema-generator',
			__( 'Settings', 'schema-generator' ),
			__( 'Settings', 'schema-generator' ),
			'manage_options',
			'sg-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'schema-generator',
			__( 'Schema Templates', 'schema-generator' ),
			__( 'Schema Templates', 'schema-generator' ),
			'manage_options',
			'sg-templates',
			array( $this, 'render_templates_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		$is_plugin_page = ( strpos( $hook, 'schema-generator' ) !== false );
		$is_post_edit   = in_array( $hook, [ 'post.php', 'post-new.php' ], true );

		// Detect block editor / classic editor via global $pagenow as fallback.
		if ( ! $is_plugin_page && ! $is_post_edit ) {
			global $pagenow;
			if ( in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
				$is_post_edit = true;
			}
		}

		wp_enqueue_style(
			'sg-admin',
			SG_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SG_VERSION
		);

		if ( $is_plugin_page ) {
			wp_enqueue_script(
				'sg-admin',
				SG_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				SG_VERSION,
				true
			);

			wp_localize_script(
				'sg-admin',
				'sgAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'sg_admin_nonce' ),
				)
			);
		}

		if ( $is_post_edit ) {
			wp_enqueue_script(
				'sg-metabox',
				SG_PLUGIN_URL . 'assets/js/metabox.js',
				array( 'jquery' ),
				SG_VERSION,
				true
			);

			wp_localize_script(
				'sg-metabox',
				'sgMetabox',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'sg_admin_nonce' ),
				)
			);
		}
	}

	/**
	 * Enqueue metabox assets for the block editor (Gutenberg).
	 */
	public function enqueue_metabox_assets(): void {
		// Always load in the block editor — the script will check if the metabox exists.
		wp_enqueue_style(
			'sg-admin',
			SG_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SG_VERSION
		);

		wp_enqueue_script(
			'sg-metabox',
			SG_PLUGIN_URL . 'assets/js/metabox.js',
			array( 'jquery' ),
			SG_VERSION,
			true
		);

		wp_localize_script(
			'sg-metabox',
			'sgMetabox',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sg_admin_nonce' ),
			)
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		register_setting( 'sg_settings_group', SG_OPTION_PREFIX . 'settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		register_setting( 'sg_settings_group', SG_OPTION_PREFIX . 'post_type_mappings', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_mappings' ),
		) );
	}

	/**
	 * Sanitize general settings.
	 *
	 * @param array $input Raw settings data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( ?array $input ): array {
		if ( empty( $input ) || ! is_array( $input ) ) {
			return array(
				'enabled_post_types' => array(),
				'schema_output'      => 'head',
				'cache_duration'     => 3600,
			);
		}

		return array(
			'enabled_post_types' => array_map( 'sanitize_text_field', $input['enabled_post_types'] ?? array() ),
			'schema_output'      => in_array( $input['schema_output'] ?? '', array( 'head', 'footer' ), true )
				? $input['schema_output']
				: 'head',
			'cache_duration'     => absint( $input['cache_duration'] ?? 3600 ),
		);
	}

	/**
	 * Sanitize post type mappings.
	 *
	 * @param array $input Raw mapping data.
	 * @return array Sanitized mappings.
	 */
	public function sanitize_mappings( ?array $input ): array {
		$sanitized = array();

		if ( empty( $input ) || ! is_array( $input ) ) {
			return $sanitized;
		}

		foreach ( $input as $post_type => $mapping ) {
			$sanitized[ sanitize_key( $post_type ) ] = array(
				'schema_type' => sanitize_text_field( $mapping['schema_type'] ?? '' ),
				'fields'      => array(),
			);

			if ( ! empty( $mapping['fields'] ) && is_array( $mapping['fields'] ) ) {
				foreach ( $mapping['fields'] as $field_slug => $field_config ) {
					$sanitized[ sanitize_key( $post_type ) ]['fields'][ sanitize_key( $field_slug ) ] = array(
						'source'       => sanitize_text_field( $field_config['source'] ?? '' ),
						'meta_key'     => sanitize_text_field( $field_config['meta_key'] ?? '' ),
						'static_value' => sanitize_text_field( $field_config['static_value'] ?? '' ),
					);
				}
			}
		}

		return $sanitized;
	}

	/**
	 * AJAX handler: Fetch and update the Schema.org dictionary.
	 */
	public function ajax_fetch_schema(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'schema-generator' ) ) );
		}

		// If WP-Cron is disabled, the scheduled event would never fire, so run
		// the fetch inline (it can take a while, so lift limits where allowed).
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}
			$this->run_fetch_vocabulary();
			$count = $this->database->get_type_count();
			wp_send_json_success( array(
				/* translators: %d: number of schema types loaded */
				'message' => sprintf( __( 'Vocabulary loaded. %d types in database.', 'schema-generator' ), $count ),
			) );
		}

		// Otherwise schedule the fetch as a WP-Cron event so it doesn't block the browser.
		if ( ! wp_next_scheduled( 'sg_fetch_vocabulary_cron' ) ) {
			wp_schedule_single_event( time(), 'sg_fetch_vocabulary_cron' );
		}

		wp_send_json_success( array(
			'message' => __( 'Vocabulary fetch started in the background. This may take a minute. Refresh this page to check progress.', 'schema-generator' ),
		) );
	}

	/**
	 * Cron callback: Fetch the Schema.org vocabulary.
	 */
	public function run_fetch_vocabulary(): void {
		$parser = new SchemaParser();
		$result = $parser->fetch_and_store();

		if ( $result ) {
			update_option( 'sg_fetch_status', 'success' );
		} else {
			update_option( 'sg_fetch_status', 'failed' );
		}
	}

	/**
	 * AJAX handler: Check fetch vocabulary status.
	 */
	public function ajax_fetch_status(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		$status   = get_option( 'sg_fetch_status', '' );
		$scheduled = wp_next_scheduled( 'sg_fetch_vocabulary_cron' );
		$count     = $this->database->get_type_count();

		if ( $scheduled ) {
			wp_send_json_success( array(
				'status'  => 'running',
				'message' => sprintf(
					/* translators: %d: Number of schema types */
					__( 'Fetch in progress... %d types in database.', 'schema-generator' ),
					$count
				),
				'count'   => $count,
			) );
		} elseif ( 'success' === $status ) {
			delete_option( 'sg_fetch_status' );
			wp_send_json_success( array(
				'status'  => 'success',
				'message' => sprintf(
					/* translators: %d: Number of schema types */
					__( 'Dictionary updated successfully. %d types loaded.', 'schema-generator' ),
					$count
				),
				'count'   => $count,
			) );
		} elseif ( 'failed' === $status ) {
			delete_option( 'sg_fetch_status' );
			wp_send_json_error( array(
				'message' => __( 'Failed to fetch vocabulary. Check error log.', 'schema-generator' ),
			) );
		} else {
			wp_send_json_success( array(
				'status'  => 'idle',
				'message' => sprintf(
					/* translators: %d: Number of schema types */
					__( '%d types in database. Click button to fetch latest.', 'schema-generator' ),
					$count
				),
				'count'   => $count,
			) );
		}
	}

	/**
	 * AJAX handler: Get properties for a specific schema type.
	 */
	public function ajax_get_type_properties(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		// Used by both the admin mapping pages and the per-post meta box, so
		// allow anyone who can edit content (not just full administrators).
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'schema-generator' ) ) );
		}

		$type_slug = sanitize_text_field( $_POST['type_slug'] ?? '' );

		if ( empty( $type_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No type slug provided.', 'schema-generator' ) ) );
		}

		$properties = $this->database->get_properties_for_type( $type_slug );

		if ( empty( $properties ) ) {
			// Fallback common properties for popular types
			$common_props = [
				['id' => 'headline', 'label' => 'headline', 'description' => 'Headline or title'],
				['id' => 'description', 'label' => 'description', 'description' => 'Description of the item'],
				['id' => 'image', 'label' => 'image', 'description' => 'Image'],
				['id' => 'author', 'label' => 'author', 'description' => 'Author or creator'],
				['id' => 'datePublished', 'label' => 'datePublished', 'description' => 'Date published'],
				['id' => 'dateModified', 'label' => 'dateModified', 'description' => 'Date modified'],
				['id' => 'url', 'label' => 'url', 'description' => 'URL'],
				['id' => 'name', 'label' => 'name', 'description' => 'Name'],
			];
			$properties = $common_props;
		}

		wp_send_json_success( array(
			'properties' => array_values( $properties ),
		) );
	}

	/**
	 * AJAX handler: Search schema types by keyword.
	 */
	public function ajax_search_types(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		// Used by both the admin mapping pages and the per-post meta box, so
		// allow anyone who can edit content (not just full administrators).
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'schema-generator' ) ) );
		}

		$query = sanitize_text_field( $_POST['query'] ?? '' );

		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( array( 'types' => array() ) );
		}

		$types = $this->database->search_types( $query );

		if ( empty( $types ) ) {
			// Fallback common schema types so UI works without fetch
			$common = [
				['id' => 'schema:Article', 'label' => 'Article', 'description' => 'An article or blog post'],
				['id' => 'schema:WebPage', 'label' => 'WebPage', 'description' => 'A web page'],
				['id' => 'schema:Product', 'label' => 'Product', 'description' => 'A product for sale'],
				['id' => 'schema:Recipe', 'label' => 'Recipe', 'description' => 'A recipe'],
				['id' => 'schema:FAQPage', 'label' => 'FAQPage', 'description' => 'A page with FAQs'],
				['id' => 'schema:LocalBusiness', 'label' => 'LocalBusiness', 'description' => 'A local business'],
			];
			$types = array_filter($common, function($t) use ($query) {
				return stripos($t['label'], $query) !== false || stripos($t['id'], $query) !== false;
			});
		}

		wp_send_json_success( array(
			'types' => array_values( $types ),
		) );
	}

	/**
	 * AJAX handler: Save post type mappings (and optionally enabled post types).
	 */
	public function ajax_save_mappings(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'schema-generator' ) ) );
		}

		$success = true;

		// Save mappings if provided (normalize keys on the fly for future-proofing)
		if ( isset( $_POST['mappings'] ) ) {
			$mappings = json_decode( wp_unslash( $_POST['mappings'] ), true );
			if ( is_array( $mappings ) ) {
				$normalized = [];
				foreach ( $mappings as $pt => $map ) {
					if ( ! empty( $map['fields'] ) && is_array( $map['fields'] ) ) {
						$new_fields = [];
						foreach ( $map['fields'] as $k => $v ) {
							$new_k = $this->database->normalize_property_name( $k );
							$new_fields[ $new_k ] = $v;
						}
						$map['fields'] = $new_fields;
					}
					$normalized[ sanitize_key( $pt ) ] = $map;
				}
				update_option( SG_OPTION_PREFIX . 'post_type_mappings', $normalized );
			} else {
				$success = false;
			}
		}

		// Save enabled post types if provided
		if ( isset( $_POST['enabled'] ) ) {
			$enabled = json_decode( wp_unslash( $_POST['enabled'] ), true );
			if ( is_array( $enabled ) ) {
				$settings = get_option( SG_OPTION_PREFIX . 'settings', array() );
				$settings['enabled_post_types'] = array_map( 'sanitize_text_field', $enabled );
				update_option( SG_OPTION_PREFIX . 'settings', $settings );
			}
		}

		if ( $success ) {
			wp_send_json_success( array(
				'message' => __( 'Mappings saved successfully.', 'schema-generator' ),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'schema-generator' ) ) );
		}
	}

	/**
	 * Register the per-post multi-schema meta box.
	 */
	public function register_meta_box(): void {
		$settings   = get_option( SG_OPTION_PREFIX . 'settings', array() );
		$enabled    = $settings['enabled_post_types'] ?? array();
		$post_types = ! empty( $enabled ) ? $enabled : get_post_types( [ 'public' => true ] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'sg_schemas',
				__( 'Schema Markup', 'schema-generator' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Register post meta for block editor (Gutenberg) compatibility.
	 */
	public function register_post_meta(): void {
		$post_types = get_post_types( [ 'public' => true ] );

		foreach ( $post_types as $post_type ) {
			register_post_meta( $post_type, 'sg_post_schemas', array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'object',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}

	/**
	 * Render the multi-schema meta box.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		// Bulletproof enqueue: ensure the metabox script/style are loaded
		// whenever the box itself renders, regardless of screen-hook detection.
		// Meta boxes render before wp_footer, so a footer-printed script still
		// outputs. Safe to call repeatedly — WP de-dupes by handle.
		wp_enqueue_style( 'sg-admin', SG_PLUGIN_URL . 'assets/css/admin.css', array(), SG_VERSION );
		wp_enqueue_script( 'sg-metabox', SG_PLUGIN_URL . 'assets/js/metabox.js', array( 'jquery' ), SG_VERSION, true );
		wp_localize_script( 'sg-metabox', 'sgMetabox', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sg_admin_nonce' ),
		) );

		wp_nonce_field( 'sg_schemas_nonce', 'sg_schemas_nonce_field' );

		$post_schemas = get_post_meta( $post->ID, 'sg_post_schemas', true );
		if ( ! is_array( $post_schemas ) ) {
			$post_schemas = [];
		}

		// Get templates for this post type.
		$tmpl_manager = new \SchemaGenerator\SchemaTemplateManager();
		$templates    = $tmpl_manager->get_templates_for_post_type( $post->post_type );
		?>
		<div class="sg-schemas-metabox" id="sg-schemas-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sg_admin_nonce' ) ); ?>" data-ajaxurl="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>">

			<p class="description">
				<?php esc_html_e( 'Add one or more Schema.org JSON-LD blocks for this post. Templates assigned to this post type are loaded automatically.', 'schema-generator' ); ?>
			</p>

			<!-- Schema list -->
			<div id="sg-schemas-list">
				<?php if ( empty( $post_schemas ) && empty( $templates ) ) : ?>
					<p class="description sg-no-schemas"><?php esc_html_e( 'No schemas configured yet. Add one below.', 'schema-generator' ); ?></p>
				<?php endif; ?>

				<?php
				// Show existing per-post schemas.
				foreach ( $post_schemas as $index => $schema ) :
					$uuid = $schema['uuid'] ?? $index;
					?>
					<div class="sg-schema-card <?php echo empty( $schema['enabled'] ) ? 'sg-schema-disabled' : ''; ?>" data-uuid="<?php echo esc_attr( $uuid ); ?>" data-index="<?php echo esc_attr( $index ); ?>">
						<div class="sg-schema-card-header">
							<label class="sg-schema-toggle">
								<input type="hidden" name="sg_schemas[<?php echo esc_attr( $index ); ?>][enabled]" value="0" />
								<input type="checkbox" class="sg-schema-enabled" name="sg_schemas[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $schema['enabled'] ) ); ?> />
								<span class="sg-toggle-slider"></span>
							</label>
							<strong class="sg-schema-type-name"><?php echo esc_html( $schema['schema_type'] ); ?></strong>
							<span class="sg-schema-card-actions">
								<button type="button" class="button-link sg-edit-schema"><?php esc_html_e( 'Edit', 'schema-generator' ); ?></button>
								<button type="button" class="button-link sg-remove-schema" style="color:#d63638;"><?php esc_html_e( 'Remove', 'schema-generator' ); ?></button>
							</span>
						</div>
						<div class="sg-schema-card-body" style="display:none;">
							<?php $this->render_schema_editor( $schema, $index ); ?>
						</div>
					</div>
				<?php endforeach; ?>

				<?php
				// Show templates as pre-loaded schemas (not yet saved to post).
				foreach ( $templates as $template ) :
					$already_added = false;
					foreach ( $post_schemas as $ps ) {
						if ( ( $ps['schema_type'] ?? '' ) === $template['schema_type'] ) {
							$already_added = true;
							break;
						}
					}
					if ( $already_added ) continue;
					?>
					<div class="sg-schema-card sg-schema-template" data-template-id="<?php echo esc_attr( $template['id'] ); ?>">
						<div class="sg-schema-card-header">
							<label class="sg-schema-toggle">
								<input type="hidden" name="sg_schemas[tpl_<?php echo esc_attr( $template['id'] ); ?>][enabled]" value="0" />
								<input type="checkbox" class="sg-schema-enabled" name="sg_schemas[tpl_<?php echo esc_attr( $template['id'] ); ?>][enabled]" value="1" checked />
								<span class="sg-toggle-slider"></span>
							</label>
							<strong class="sg-schema-type-name"><?php echo esc_html( $template['schema_type'] ); ?></strong>
							<span class="sg-template-badge"><?php esc_html_e( 'Template', 'schema-generator' ); ?></span>
							<span class="sg-schema-card-actions">
								<button type="button" class="button-link sg-edit-schema"><?php esc_html_e( 'Edit', 'schema-generator' ); ?></button>
							</span>
						</div>
						<div class="sg-schema-card-body">
							<?php
							$template_schema = [
								'uuid'        => 'tpl_' . $template['id'],
								'enabled'     => true,
								'schema_type' => $template['schema_type'],
								'properties'  => $template['properties'],
								'is_template' => true,
								'template_id' => $template['id'],
							];
							$this->render_schema_editor( $template_schema, 'tpl_' . $template['id'] );
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

		 <!-- Add schema buttons -->
			<div class="sg-add-schema-row" style="margin-top:15px;">
				<button type="button" class="button button-secondary" id="sg-add-schema">
					+ <?php esc_html_e( 'Add Custom Schema', 'schema-generator' ); ?>
				</button>
				<?php if ( ! empty( $templates ) ) : ?>
					<button type="button" class="button button-secondary" id="sg-add-from-template">
						+ <?php esc_html_e( 'Add from Template', 'schema-generator' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Hidden template for new schema cards -->
			<script type="text/template" id="sg-schema-card-template">
				<div class="sg-schema-card sg-schema-new" data-uuid="{{uuid}}" data-index="{{index}}">
					<div class="sg-schema-card-header">
						<label class="sg-schema-toggle">
							<input type="hidden" name="sg_schemas[{{uuid}}][enabled]" value="0" /><input type="checkbox" class="sg-schema-enabled" name="sg_schemas[{{uuid}}][enabled]" value="1" checked />
							<span class="sg-toggle-slider"></span>
						</label>
						<strong class="sg-schema-type-name">{{type_name}}</strong>
						<span class="sg-schema-card-actions">
							<button type="button" class="button-link sg-edit-schema"><?php esc_html_e( 'Edit', 'schema-generator' ); ?></button>
							<button type="button" class="button-link sg-remove-schema" style="color:#d63638;"><?php esc_html_e( 'Remove', 'schema-generator' ); ?></button>
						</span>
					</div>
					<div class="sg-schema-card-body">
						<div class="sg-schema-editor-wrap" data-uuid="{{uuid}}"></div>
					</div>
				</div>
			</script>

			<!-- Save button -->
			<div style="margin-top:15px;">
				<button type="button" class="button button-primary" id="sg-save-schemas">
					<?php esc_html_e( 'Save Schemas', 'schema-generator' ); ?>
				</button>
				<span id="sg-schemas-save-status" style="margin-left:10px;"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the property editor for a single schema.
	 *
	 * @param array $schema Schema data.
	 * @param mixed $index  Schema index or UUID.
	 */
	private function render_schema_editor( array $schema, $index ): void {
		$type   = $schema['schema_type'] ?? '';
		$props  = $schema['properties'] ?? [];
		$prefix = 'sg_schemas[' . $index . ']';
		$uuid   = $schema['uuid'] ?? $index;
		?>
		<div class="sg-schema-editor" data-uuid="<?php echo esc_attr( $uuid ); ?>">
			<input type="hidden" class="sg-schema-type-input" name="<?php echo esc_attr( $prefix ); ?>[schema_type]" value="<?php echo esc_attr( $type ); ?>" />
			<input type="hidden" class="sg-schema-uuid-input" name="<?php echo esc_attr( $prefix ); ?>[uuid]" value="<?php echo esc_attr( $uuid ); ?>" />

			<p>
				<label><strong><?php esc_html_e( 'Schema Type:', 'schema-generator' ); ?></strong></label>
				<input type="text" class="sg-schema-type-search widefat" value="<?php echo esc_attr( $type ); ?>" placeholder="<?php esc_attr_e( 'Search Schema.org types...', 'schema-generator' ); ?>" />
				<div class="sg-type-search-results" style="max-height:150px;overflow-y:auto;display:none;"></div>
			</p>

			<div class="sg-schema-properties" style="margin-top:15px;">
				<h4><?php esc_html_e( 'Property Mappings', 'schema-generator' ); ?></h4>
				<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Select a schema type above to load all its properties, then map each to a WordPress data source.', 'schema-generator' ); ?></p>

				<table class="widefat sg-prop-table">
					<thead>
						<tr>
							<th style="width:25%;"><?php esc_html_e( 'Property', 'schema-generator' ); ?></th>
							<th style="width:30%;"><?php esc_html_e( 'Map From', 'schema-generator' ); ?></th>
							<th style="width:35%;"><?php esc_html_e( 'Configuration', 'schema-generator' ); ?></th>
							<th style="width:10%;"></th>
						</tr>
					</thead>
					<tbody class="sg-property-rows">
						<?php
						if ( ! empty( $props ) ) {
							foreach ( $props as $prop_name => $prop_config ) {
								$this->render_property_row( $prefix, $prop_name, $prop_config );
							}
						} else {
							?>
							<tr class="sg-no-props"><td colspan="4"><em><?php esc_html_e( 'Select a schema type to load properties.', 'schema-generator' ); ?></em></td></tr>
							<?php
						}
						?>
					</tbody>
				</table>

				<button type="button" class="button button-small sg-add-property" style="margin-top:8px;">
					+ <?php esc_html_e( 'Add Property', 'schema-generator' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single property mapping row (table row).
	 *
	 * @param string $prefix     Field name prefix.
	 * @param string $prop_name  Property name.
	 * @param array  $prop_config Property config.
	 */
	private function render_property_row( string $prefix, string $prop_name, array $prop_config ): void {
		$sources = [
			''             => '— Not mapped —',
			'title'        => 'Post Title',
			'excerpt'      => 'Excerpt',
			'content'      => 'Full Content',
			'featured_image' => 'Featured Image (ImageObject)',
			'author'       => 'Author (Person object)',
			'author_name'  => 'Author Name',
			'author_url'   => 'Author URL',
			'date_published' => 'Date Published',
			'date_modified'  => 'Date Modified',
			'permalink'    => 'Permalink / URL',
			'meta'         => 'Custom Meta Field',
			'static'       => 'Static Value',
			'categories'   => 'Categories',
			'tags'         => 'Tags',
			'site_name'    => 'Site Name',
			'site_url'     => 'Site URL',
		];
		// $prefix already is "sg_schemas[<index>]"; just append the property path.
		$name = $prefix . '[properties][' . $prop_name . ']';
		?>
		<tr class="sg-property-row" data-prop="<?php echo esc_attr( $prop_name ); ?>">
			<td>
				<strong><?php echo esc_html( $prop_name ); ?></strong>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $prop_name ); ?>" />
			</td>
			<td>
				<select class="sg-prop-source" name="<?php echo esc_attr( $name ); ?>[source]">
					<?php foreach ( $sources as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( ( $prop_config['source'] ?? '' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" class="sg-prop-meta-key" name="<?php echo esc_attr( $name ); ?>[meta_key]" value="<?php echo esc_attr( $prop_config['meta_key'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'meta_key', 'schema-generator' ); ?>" style="width:100%;<?php echo ( ( $prop_config['source'] ?? '' ) !== 'meta' ) ? 'display:none;' : ''; ?>" />
				<input type="text" class="sg-prop-static" name="<?php echo esc_attr( $name ); ?>[static_value]" value="<?php echo esc_attr( $prop_config['static_value'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Static value', 'schema-generator' ); ?>" style="width:100%;<?php echo ( ( $prop_config['source'] ?? '' ) !== 'static' ) ? 'display:none;' : ''; ?>" />
			</td>
			<td>
				<button type="button" class="button-link sg-remove-property" style="color:#d63638;font-size:18px;">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the meta box data (multi-schema).
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['sg_schemas_nonce_field'] ) ||
			 ! wp_verify_nonce( $_POST['sg_schemas_nonce_field'], 'sg_schemas_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_schemas = $_POST['sg_schemas'] ?? [];
		if ( ! is_array( $raw_schemas ) ) {
			delete_post_meta( $post_id, 'sg_post_schemas' );
			return;
		}

		$manager = new \SchemaGenerator\PostSchemaManager();
		$schemas = [];

		foreach ( $raw_schemas as $raw ) {
			if ( empty( $raw['schema_type'] ) ) {
				continue;
			}

			$schema = [
				'uuid'        => sanitize_text_field( $raw['uuid'] ?? wp_generate_uuid4() ),
				'enabled'     => ! empty( $raw['enabled'] ),
				'schema_type' => sanitize_text_field( $raw['schema_type'] ),
				'properties'  => [],
			];

			if ( ! empty( $raw['properties'] ) && is_array( $raw['properties'] ) ) {
				foreach ( $raw['properties'] as $prop ) {
					$prop_name = sanitize_text_field( $prop['name'] ?? '' );
					if ( empty( $prop_name ) ) {
						continue;
					}
					$schema['properties'][ $prop_name ] = [
						'source'       => sanitize_text_field( $prop['source'] ?? '' ),
						'meta_key'     => sanitize_text_field( $prop['meta_key'] ?? '' ),
						'static_value' => sanitize_text_field( $prop['static_value'] ?? '' ),
					];
				}
			}

			$schemas[] = $schema;
		}

		$manager->save_post_schemas( $post_id, $schemas );
	}

	/**
	 * Render the main plugin page (Schema Dictionary overview).
	 */
	public function render_main_page(): void {
		$type_count = $this->database->get_type_count();
		$is_populated = $this->database->is_populated();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Schema Generator — Dictionary', 'schema-generator' ); ?></h1>

			<div class="sg-card">
				<h2><?php esc_html_e( 'Schema.org Vocabulary', 'schema-generator' ); ?></h2>

				<?php if ( $is_populated ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: Number of schema types */
							esc_html__( 'Database contains %d Schema.org types.', 'schema-generator' ),
							$type_count
						);
						?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'No schema types loaded yet. Click the button below to fetch the Schema.org vocabulary.', 'schema-generator' ); ?>
					</p>
				<?php endif; ?>

				<button type="button" class="button button-primary" id="sg-fetch-schema">
					<?php esc_html_e( 'Fetch / Update Schema Dictionary', 'schema-generator' ); ?>
				</button>
				<span id="sg-fetch-status"></span>
			</div>

			<?php if ( $is_populated ) : ?>
				<div class="sg-card">
					<h2><?php esc_html_e( 'Search Schema Types', 'schema-generator' ); ?></h2>
					<p>
						<input type="text"
							id="sg-type-search"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Search for a schema type...', 'schema-generator' ); ?>" />
					</p>
					<div id="sg-type-search-results"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the post type mapping page.
	 */
	public function render_mapping_page(): void {
		$settings = get_option( SG_OPTION_PREFIX . 'settings', array() );
		$enabled  = $settings['enabled_post_types'] ?? array();
		$mappings = get_option( SG_OPTION_PREFIX . 'post_type_mappings', array() );

		// Note: mapping rows are now shown for ALL public post types.
		// Setting a schema type below will enable output for that type (saved enabled list is for explicit control).

		// Check if dictionary is populated
		$database = new \SchemaGenerator\SchemaDatabase(); // safe, lightweight
		$is_populated = $database->is_populated();

		// Get all registered post types.
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post Type Mapping', 'schema-generator' ); ?></h1>

			<div class="sg-card">
				<h2><?php esc_html_e( 'Enable Schema Output', 'schema-generator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Check boxes to control enabled list (auto-saves). Rows shown for all. Picking a type below auto-enables output for it.', 'schema-generator' ); ?></p>

				<?php foreach ( $post_types as $pt ) : ?>
					<label style="display:block;margin:5px 0;">
						<input type="checkbox"
							class="sg-enabled-pt"
							name="<?php echo esc_attr( SG_OPTION_PREFIX . 'settings[enabled_post_types][]' ); ?>"
							value="<?php echo esc_attr( $pt->name ); ?>"
							<?php checked( in_array( $pt->name, $enabled, true ) ); ?>>
						<?php echo esc_html( $pt->labels->singular_name ); ?>
						<code>(<?php echo esc_html( $pt->name ); ?>)</code>
					</label>
				<?php endforeach; ?>
				<p>
					<button type="button" class="button" id="sg-enable-and-reload">
						<?php esc_html_e( 'Save Enabled Post Types', 'schema-generator' ); ?>
					</button>
					<span class="description" style="margin-left:10px;">(reload optional)</span>
				</p>
			</div>

			<div class="sg-card">
				<h2><?php esc_html_e( 'Map Post Types to Schema Types', 'schema-generator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'For every public post type, set a Schema.org type in the box. It will auto-save the type and load the mapping table (so frontend output works). Use the checkboxes above to control which ones are explicitly enabled. Click "Save All Mappings" for full save.', 'schema-generator' ); ?></p>

				<?php if ( ! $is_populated ) : ?>
					<div class="notice notice-warning inline">
						<p><strong><?php esc_html_e( 'Schema dictionary not loaded yet (recommended for full list).', 'schema-generator' ); ?></strong></p>
						<p><?php esc_html_e( 'Basic mapping will still work with common properties. Go to main page and Fetch for complete Schema.org types/properties if you want more options.', 'schema-generator' ); ?></p>
					</div>
				<?php endif; ?>

				<?php foreach ( $post_types as $pt ) :
					$pt_name = $pt->name;
					$pt_obj = $pt;
					$current = $mappings[ $pt_name ]['schema_type'] ?? '';
					?>
					<div class="sg-mapping-row" style="margin-bottom:20px;padding:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
						<h3 style="margin-top:0;">
							<?php echo esc_html( $pt_obj->labels->singular_name ); ?>
							<code>(<?php echo esc_html( $pt_name ); ?>)</code>
						</h3>

						<p>
							<label>
								<strong><?php esc_html_e( 'Schema Type:', 'schema-generator' ); ?></strong>
							</label>
							<input type="text"
								class="sg-type-search-input regular-text"
								data-post-type="<?php echo esc_attr( $pt_name ); ?>"
								value="<?php echo esc_attr( $current ); ?>"
								placeholder="<?php esc_attr_e( 'Type to search...', 'schema-generator' ); ?>"
								style="width:300px;" />
							<span class="sg-current-type" style="margin-left:10px;">
								<?php if ( ! empty( $current ) ) : ?>
									<code><?php echo esc_html( $current ); ?></code>
								<?php endif; ?>
							</span>
							<div class="sg-type-search-results" style="max-height:150px;overflow-y:auto;"></div>
						</p>

						<div class="sg-field-mappings" 
						     data-post-type="<?php echo esc_attr( $pt_name ); ?>"
						     data-existing-mappings='<?php echo esc_attr( wp_json_encode( $mappings[ $pt_name ]['fields'] ?? [] ) ); ?>'>
							<button type="button" class="button sg-load-properties" data-post-type="<?php echo esc_attr( $pt_name ); ?>" data-schema-type="<?php echo esc_attr( $current ); ?>">
								<?php esc_html_e( 'Load / Edit Property Mappings', 'schema-generator' ); ?>
							</button>
							<div class="sg-mapping-table-container" style="margin-top:15px;"></div>
							<?php if ( empty( $current ) ) : ?>
								<p class="description" style="margin-top:8px;">Type or search a Schema.org type above (e.g. Article, Product), then click the button or tab out of the field — the mapping table will appear with options.</p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="submit">
				<button type="button" class="button button-primary" id="sg-save-all-mappings">
					<?php esc_html_e( 'Save All Mappings', 'schema-generator' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$settings = get_option( SG_OPTION_PREFIX . 'settings', array() );

		// Show import success message
		if ( isset( $_GET['imported'] ) && $_GET['imported'] === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Data imported successfully.', 'schema-generator' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Schema Generator Settings', 'schema-generator' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'sg_settings_group' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sg-output-position"><?php esc_html_e( 'Output Position', 'schema-generator' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( SG_OPTION_PREFIX . 'settings[schema_output]' ); ?>" id="sg-output-position">
								<option value="head" <?php selected( $settings['schema_output'] ?? '', 'head' ); ?>><?php esc_html_e( 'wp_head', 'schema-generator' ); ?></option>
								<option value="footer" <?php selected( $settings['schema_output'] ?? '', 'footer' ); ?>><?php esc_html_e( 'wp_footer', 'schema-generator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Where to output the JSON-LD script tag.', 'schema-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sg-cache-duration"><?php esc_html_e( 'Cache Duration (seconds)', 'schema-generator' ); ?></label>
						</th>
						<td>
							<input type="number"
								name="<?php echo esc_attr( SG_OPTION_PREFIX . 'settings[cache_duration]' ); ?>"
								id="sg-cache-duration"
								value="<?php echo esc_attr( $settings['cache_duration'] ?? 3600 ); ?>"
								min="0" max="86400">
							<p class="description"><?php esc_html_e( 'How long to cache the generated JSON-LD output. Set to 0 to disable caching.', 'schema-generator' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'schema-generator' ) ); ?>
			</form>

			<!-- Import / Export -->
			<hr style="margin: 30px 0;">
			<h2><?php esc_html_e( 'Import / Export', 'schema-generator' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Backup or migrate your plugin settings, post type mappings, per-post overrides, and the full schema vocabulary database.', 'schema-generator' ); ?>
			</p>

			<div class="sg-card" style="max-width: 700px; margin-top: 15px;">
				<h3><?php esc_html_e( 'Export Data', 'schema-generator' ); ?></h3>
				<p><?php esc_html_e( 'Download a JSON backup of your current configuration and schema data.', 'schema-generator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sg_export_data">
					<?php wp_nonce_field( 'sg_export_data', 'sg_export_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Download Export File (.json)', 'schema-generator' ); ?>
					</button>
				</form>
			</div>

			<div class="sg-card" style="max-width: 700px; margin-top: 20px;">
				<h3><?php esc_html_e( 'Import Data', 'schema-generator' ); ?></h3>
				<p><?php esc_html_e( 'Restore from a previously exported JSON file. This will overwrite current settings and data.', 'schema-generator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="sg_import_data">
					<?php wp_nonce_field( 'sg_import_data', 'sg_import_nonce' ); ?>
					<input type="file" name="import_file" accept=".json" required>
					<br><br>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Import from File', 'schema-generator' ); ?>
					</button>
				</form>
				<p class="description" style="margin-top: 8px; color: #d63638;">
					<?php esc_html_e( 'Warning: Importing will replace your current settings, mappings, overrides, and vocabulary data.', 'schema-generator' ); ?>
				</p>
			</div>

			<div class="sg-card" style="max-width: 700px; margin-top: 20px; border-left: 4px solid #00a32a;">
				<h3><?php esc_html_e( 'Fix Property Names (One-time Cleanup)', 'schema-generator' ); ?></h3>
				<p>
					<?php esc_html_e( 'Click below to automatically normalize all stored property keys across mappings and per-post overrides using the full Schema.org vocabulary. This fixes issues for any schema type (past, present, and future).', 'schema-generator' ); ?>
				</p>
				<button type="button" id="sg-normalize-mappings" class="button button-secondary">
					<?php esc_html_e( 'Normalize All Existing Mappings Now', 'schema-generator' ); ?>
				</button>
				<span id="sg-normalize-status" style="margin-left: 10px;"></span>
				<p class="description" style="margin-top: 8px;">
					<?php esc_html_e( 'Safe to run anytime. It uses the vocabulary database to ensure every property uses the official camelCase name (e.g. articleBody, not schemaarticlebody).', 'schema-generator' ); ?>
				</p>
			</div>

			<p class="description" style="margin-top:15px;">
				<?php esc_html_e( 'Tip: Use this feature to backup your configuration before major changes or to migrate between sites.', 'schema-generator' ); ?>
			</p>

		</div>
		<?php
	}

	/**
	 * Handle export of all plugin data.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to export data.', 'schema-generator' ) );
		}

		check_admin_referer( 'sg_export_data', 'sg_export_nonce' );

		$export_data = [
			'plugin'   => 'schema-generator',
			'version'  => SG_VERSION,
			'exported' => current_time( 'mysql' ),
			'data'     => [
				'settings'            => get_option( SG_OPTION_PREFIX . 'settings', [] ),
				'post_type_mappings'  => get_option( SG_OPTION_PREFIX . 'post_type_mappings', [] ),
				'post_overrides'      => $this->get_all_post_overrides(),
				'vocabulary'          => $this->database->export_vocabulary(),
			],
		];

		$filename = 'schema-generator-export-' . date( 'Y-m-d-H-i' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: no-cache' );

		echo wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Handle import of plugin data from JSON.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to import data.', 'schema-generator' ) );
		}

		check_admin_referer( 'sg_import_data', 'sg_import_nonce' );

		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			wp_die( __( 'No file was uploaded.', 'schema-generator' ) );
		}

		$file = $_FILES['import_file'];

		// Basic validation
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_die( __( 'File upload error.', 'schema-generator' ) );
		}

		if ( $file['size'] > 10 * 1024 * 1024 ) { // 10MB limit
			wp_die( __( 'Import file is too large.', 'schema-generator' ) );
		}

		if ( ! in_array( $file['type'], [ 'application/json', 'text/plain' ], true ) &&
		     ! str_ends_with( strtolower( $file['name'] ), '.json' ) ) {
			wp_die( __( 'Please upload a valid .json file.', 'schema-generator' ) );
		}

		$content = file_get_contents( $file['tmp_name'] );
		if ( $content === false ) {
			wp_die( __( 'Could not read the uploaded file.', 'schema-generator' ) );
		}

		$imported = json_decode( $content, true );

		if ( ! is_array( $imported ) || empty( $imported['data'] ) ) {
			wp_die( __( 'Invalid export file format.', 'schema-generator' ) );
		}

		$data = $imported['data'];

		// Restore settings
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			update_option( SG_OPTION_PREFIX . 'settings', $data['settings'] );
		}

		// Restore mappings
		if ( isset( $data['post_type_mappings'] ) && is_array( $data['post_type_mappings'] ) ) {
			update_option( SG_OPTION_PREFIX . 'post_type_mappings', $data['post_type_mappings'] );
		}

		// Restore post overrides
		if ( ! empty( $data['post_overrides'] ) && is_array( $data['post_overrides'] ) ) {
			foreach ( $data['post_overrides'] as $post_id => $override ) {
				if ( is_numeric( $post_id ) ) {
					update_post_meta( (int) $post_id, SG_OPTION_PREFIX . 'schema_override', $override );
				}
			}
		}

		// Restore vocabulary
		if ( ! empty( $data['vocabulary'] ) && is_array( $data['vocabulary'] ) ) {
			$this->database->import_vocabulary( $data['vocabulary'] );
		}

		// Clean up caches
		delete_transient( SG_OPTION_PREFIX . 'schema_output_cache' );
		delete_option( 'sg_fetch_status' );

		wp_redirect( add_query_arg( [
			'page'    => 'sg-settings',
			'imported' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Collect all per-post schema overrides.
	 */
	private function get_all_post_overrides(): array {
		global $wpdb;
		$meta_key = SG_OPTION_PREFIX . 'schema_override';

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$meta_key
		), ARRAY_A );

		$overrides = [];
		foreach ( $results as $row ) {
			$overrides[ (int) $row['post_id'] ] = maybe_unserialize( $row['meta_value'] );
		}

		return $overrides;
	}

	/**
	 * AJAX handler: Normalize all existing mappings and overrides.
	 * Fixes property keys using the central vocabulary normalizer.
	 */
	public function ajax_normalize_mappings(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$normalized_count = 0;

		// 1. Normalize post type mappings
		$mappings = get_option( SG_OPTION_PREFIX . 'post_type_mappings', [] );
		$updated_mappings = [];
		foreach ( $mappings as $post_type => $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['fields'] ) ) {
				$updated_mappings[ $post_type ] = $mapping;
				continue;
			}

			$new_fields = [];
			foreach ( $mapping['fields'] as $old_key => $config ) {
				$new_key = $this->database->normalize_property_name( $old_key );
				if ( $new_key !== $old_key ) {
					$normalized_count++;
				}
				$new_fields[ $new_key ] = $config;
			}
			$updated_mappings[ $post_type ] = [
				'schema_type' => $mapping['schema_type'] ?? '',
				'fields'      => $new_fields,
			];
		}
		if ( $mappings !== $updated_mappings ) {
			update_option( SG_OPTION_PREFIX . 'post_type_mappings', $updated_mappings );
		}

		// 2. Normalize per-post overrides
		global $wpdb;
		$meta_key = SG_OPTION_PREFIX . 'schema_override';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$meta_key
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$override = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $override ) ) continue;

			$changed = false;

			// Normalize custom_properties if present (legacy)
			if ( ! empty( $override['custom_properties'] ) && is_array( $override['custom_properties'] ) ) {
				$new_custom = [];
				foreach ( $override['custom_properties'] as $old_key => $val ) {
					$new_key = $this->database->normalize_property_name( $old_key );
					if ( $new_key !== $old_key ) $changed = true;
					$new_custom[ $new_key ] = $val;
				}
				$override['custom_properties'] = $new_custom;
			}

			// Normalize new-style overrides
			if ( ! empty( $override['overrides'] ) && is_array( $override['overrides'] ) ) {
				$new_overrides = [];
				foreach ( $override['overrides'] as $old_key => $val ) {
					$new_key = $this->database->normalize_property_name( $old_key );
					if ( $new_key !== $old_key ) $changed = true;
					$new_overrides[ $new_key ] = $val;
				}
				$override['overrides'] = $new_overrides;
			}

			if ( $changed ) {
				update_post_meta( $post_id, $meta_key, $override );
				$normalized_count++;
			}
		}

		wp_send_json_success( [
			'message' => sprintf(
				__( 'Normalization complete. %d property keys were fixed across mappings and overrides.', 'schema-generator' ),
				$normalized_count
			),
			'fixed'   => $normalized_count,
		] );
	}

	/**
	 * AJAX handler: Clear the validation log.
	 */
	public function ajax_clear_validation_log(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		\SchemaGenerator\SchemaValidator::clear_issues();

		wp_send_json_success( [
			'message' => __( 'Validation log cleared.', 'schema-generator' ),
		] );
	}

	/**
	 * Render the schema templates management page.
	 */
	public function render_templates_page(): void {
		$manager   = new \SchemaGenerator\SchemaTemplateManager();
		$templates = $manager->get_templates();
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$is_populated = $this->database->is_populated();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Schema Templates', 'schema-generator' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create reusable schema templates that can be assigned to any post type. Each template defines a Schema.org type and its property mappings.', 'schema-generator' ); ?>
			</p>

			<?php if ( ! $is_populated ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Schema dictionary not loaded yet.', 'schema-generator' ); ?></strong></p>
					<p><?php esc_html_e( 'You can still create templates, but the type search will use a basic list. Go to the Dictionary page and fetch the full Schema.org vocabulary for the best experience.', 'schema-generator' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Template list -->
			<div class="sg-card" style="margin-top:20px;">
				<h2><?php esc_html_e( 'Existing Templates', 'schema-generator' ); ?></h2>
				<?php if ( empty( $templates ) ) : ?>
					<p class="description"><?php esc_html_e( 'No templates yet. Create one below.', 'schema-generator' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'schema-generator' ); ?></th>
								<th><?php esc_html_e( 'Schema Type', 'schema-generator' ); ?></th>
								<th><?php esc_html_e( 'Assigned Post Types', 'schema-generator' ); ?></th>
								<th><?php esc_html_e( 'Properties Mapped', 'schema-generator' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'schema-generator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $templates as $template ) : ?>
								<tr data-template-id="<?php echo esc_attr( $template['id'] ); ?>">
									<td><strong><?php echo esc_html( $template['name'] ); ?></strong></td>
									<td><code><?php echo esc_html( $template['schema_type'] ); ?></code></td>
									<td>
										<?php
										if ( empty( $template['post_types'] ) ) {
											esc_html_e( 'None', 'schema-generator' );
										} else {
											echo esc_html( implode( ', ', $template['post_types'] ) );
										}
										?>
									</td>
									<td><?php echo esc_html( count( $template['properties'] ?? [] ) ); ?></td>
									<td>
										<button type="button" class="button button-small sg-edit-template" data-id="<?php echo esc_attr( $template['id'] ); ?>">
											<?php esc_html_e( 'Edit', 'schema-generator' ); ?>
										</button>
										<button type="button" class="button button-small sg-delete-template" data-id="<?php echo esc_attr( $template['id'] ); ?>" style="color:#d63638;">
											<?php esc_html_e( 'Delete', 'schema-generator' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Add/Edit template form -->
			<div class="sg-card" style="margin-top:20px;">
				<h2 id="sg-template-form-title"><?php esc_html_e( 'Create New Template', 'schema-generator' ); ?></h2>

				<form id="sg-template-form" method="post">
					<input type="hidden" name="template_id" id="sg-template-id" value="" />

					<table class="form-table">
						<tr>
							<th scope="row"><label for="sg-template-name"><?php esc_html_e( 'Template Name', 'schema-generator' ); ?></label></th>
							<td>
								<input type="text" name="name" id="sg-template-name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g., Article Schema, Product Schema', 'schema-generator' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sg-template-type"><?php esc_html_e( 'Schema Type', 'schema-generator' ); ?></label></th>
							<td>
								<input type="text" name="schema_type" id="sg-template-type" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g., Article, Product, FAQPage', 'schema-generator' ); ?>" />
								<p class="description"><?php esc_html_e( 'Search and select a Schema.org type.', 'schema-generator' ); ?></p>
								<div id="sg-template-type-results" class="sg-type-search-results" style="max-height:150px;overflow-y:auto;"></div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Assign to Post Types', 'schema-generator' ); ?></th>
							<td>
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display:block;margin:3px 0;">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" />
										<?php echo esc_html( $pt->labels->singular_name ); ?>
										<code>(<?php echo esc_html( $pt->name ); ?>)</code>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Property Mappings', 'schema-generator' ); ?></th>
							<td>
								<p class="description" style="margin-bottom:10px;">
									<?php esc_html_e( 'Map Schema.org properties to WordPress data sources.', 'schema-generator' ); ?>
								</p>
								<div id="sg-template-properties-container">
									<p class="description"><?php esc_html_e( 'Select a schema type above to load available properties.', 'schema-generator' ); ?></p>
								</div>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" id="sg-save-template">
							<?php esc_html_e( 'Save Template', 'schema-generator' ); ?>
						</button>
						<button type="button" class="button" id="sg-cancel-template-edit" style="display:none;">
							<?php esc_html_e( 'Cancel', 'schema-generator' ); ?>
						</button>
						<span id="sg-template-save-status" style="margin-left:10px;"></span>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Save a template.
	 */
	public function ajax_save_template(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$data = [
			'id'          => absint( $_POST['id'] ?? 0 ),
			'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
			'schema_type' => sanitize_text_field( $_POST['schema_type'] ?? '' ),
			'post_types'  => array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [] ) ),
			'properties'  => [],
		];

		// Decode properties from JSON.
		if ( ! empty( $_POST['properties'] ) ) {
			$props = json_decode( wp_unslash( $_POST['properties'] ), true );
			if ( is_array( $props ) ) {
				$data['properties'] = $props;
			}
		}

		if ( empty( $data['name'] ) || empty( $data['schema_type'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Name and schema type are required.', 'schema-generator' ) ] );
		}

		$manager = new \SchemaGenerator\SchemaTemplateManager();
		$id      = $manager->save_template( $data );

		if ( $id ) {
			wp_send_json_success( [
				'message' => __( 'Template saved successfully.', 'schema-generator' ),
				'id'      => $id,
			] );
		}

		wp_send_json_error( [ 'message' => __( 'Failed to save template.', 'schema-generator' ) ] );
	}

	/**
	 * AJAX handler: Delete a template.
	 */
	public function ajax_delete_template(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$id = absint( $_POST['id'] ?? 0 );

		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid template ID.', 'schema-generator' ) ] );
		}

		$manager = new \SchemaGenerator\SchemaTemplateManager();

		if ( $manager->delete_template( $id ) ) {
			wp_send_json_success( [ 'message' => __( 'Template deleted.', 'schema-generator' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Failed to delete template.', 'schema-generator' ) ] );
	}

	/**
	 * AJAX handler: Get a single template (for editing).
	 */
	public function ajax_get_template(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$id      = absint( $_POST['id'] ?? 0 );
		$manager = new \SchemaGenerator\SchemaTemplateManager();
		$template = $manager->get_template( $id );

		if ( ! $template ) {
			wp_send_json_error( [ 'message' => __( 'Template not found.', 'schema-generator' ) ] );
		}

		wp_send_json_success( [ 'template' => $template ] );
	}

	/**
	 * AJAX handler: Save per-post schemas.
	 */
	public function ajax_save_post_schemas(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post.', 'schema-generator' ) ] );
		}

		$schemas = [];
		if ( ! empty( $_POST['schemas'] ) ) {
			$raw = json_decode( wp_unslash( $_POST['schemas'] ), true );
			if ( is_array( $raw ) ) {
				$schemas = $raw;
			}
		}

		$manager = new \SchemaGenerator\PostSchemaManager();
		$manager->save_post_schemas( $post_id, $schemas );

		$this->clear_post_cache( $post_id );

		wp_send_json_success( [ 'message' => __( 'Schemas saved.', 'schema-generator' ) ] );
	}

	/**
	 * Delete the cached frontend JSON-LD output for a single post.
	 *
	 * Matches the cache key written by SchemaPublic::output_json_ld().
	 *
	 * @param int $post_id The post ID.
	 */
	public function clear_post_cache( int $post_id ): void {
		delete_transient( SG_OPTION_PREFIX . 'schema_output_' . $post_id );
	}

	/**
	 * AJAX handler: Get per-post schemas.
	 */
	public function ajax_get_post_schemas(): void {
		check_ajax_referer( 'sg_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'schema-generator' ) ] );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post.', 'schema-generator' ) ] );
		}

		$manager  = new \SchemaGenerator\PostSchemaManager();
		$schemas  = $manager->get_post_schemas( $post_id );

		// Also get templates for this post type.
		$post_type    = get_post_type( $post_id );
		$tmpl_manager = new \SchemaGenerator\SchemaTemplateManager();
		$templates    = $tmpl_manager->get_templates_for_post_type( $post_type );

		wp_send_json_success( [
			'schemas'   => $schemas,
			'templates' => $templates,
		] );
	}
}
