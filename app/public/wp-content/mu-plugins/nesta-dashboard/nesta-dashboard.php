<?php
/**
 * Plugin Name: Nesta Dashboard
 * Description: Custom white-label admin experience for Nesta Sites clients.
 * Author: Nesta Sites
 * Version: 1.2.6
 *
 * @package NestaDashboard
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-nesta-template-registry.php';

final class Nesta_Dashboard {
	const SLUG               = 'nesta-dashboard';
	const QUICK_START_SLUG   = 'nesta-quick-start';
	const MENU_TITLE         = 'Nesta Hub';
	const PAGE_BUILDER_SLUG  = 'nesta-create-page';
	const SETTINGS_SLUG      = 'nesta-settings';
	const REQUEST_CHANGE_URL = 'https://nestasites.com/support';
	const SUPPORT_URL        = 'https://nestasites.com/support';
	const QUICK_GUIDES_URL   = 'https://nestasites.com/support';
	const LOGIN_LOGO_URL     = ''; // Optional: set to a custom logo URL for the login screen.
	const PLUGIN_VERSION         = '1.2.6';
	const TEMPLATE_CATALOG_URL   = 'https://getnesta.com/nesta-templates/templates.json';
	const TEMPLATE_CACHE_DIR     = 'nesta-templates';
	const MU_UPDATE_MANIFEST_URL = 'https://getnesta.com/nesta-updates/mu-plugin/manifest.json';
	const SHARED_UPLOADS_URL     = 'https://getnesta.com/nesta-templates/shared/uploads.zip';
	const MU_UPDATE_OPTION       = 'nesta_mu_plugin_update_state';

	/**
	 * Stores the hook suffix returned by add_menu_page.
	 *
	 * @var string
	 */
	private $menu_hook = '';

	/**
	 * Template registry instance.
	 *
	 * @var Nesta_Template_Registry
	 */
	private $template_registry;

	/**
	 * Page blueprints for single-page builder.
	 *
	 * @var array
	 */
	private $page_blueprints = array();
	/**
	 * Website launch checklist items.
	 *
	 * @var array
	 */
	private $launch_checklist = array();

	public function __construct() {
		$this->template_registry = new Nesta_Template_Registry(
			plugin_dir_path( __FILE__ ) . 'templates',
			plugins_url( 'templates', __FILE__ )
		);
		$this->register_template_sources();
		$this->page_blueprints   = $this->get_default_page_blueprints();

		$this->launch_checklist = array(
			array(
				'slug'  => 'content-reviewed',
				'label' => __( 'Review all page content for accuracy and grammar', 'nesta-dashboard' ),
			),
			array(
				'slug'  => 'forms-tested',
				'label' => __( 'Test all forms and confirmation emails', 'nesta-dashboard' ),
			),
			array(
				'slug'  => 'analytics-installed',
				'label' => __( 'Confirm analytics, tracking, and pixels are installed', 'nesta-dashboard' ),
			),
			array(
				'slug'  => 'responsiveness',
				'label' => __( 'Check responsiveness on desktop, tablet, and mobile', 'nesta-dashboard' ),
			),
			array(
				'slug'  => 'performance',
				'label' => __( 'Run performance and accessibility scans', 'nesta-dashboard' ),
			),
			array(
				'slug'  => 'backups',
				'label' => __( 'Verify backups and security settings', 'nesta-dashboard' ),
			),
		);

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'load-index.php', array( $this, 'redirect_dashboard' ) );
		add_action( 'admin_bar_menu', array( $this, 'customize_admin_bar' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'suppress_plugin_marketing_notices' ), 1 );
		add_action( 'admin_bar_menu', array( $this, 'remove_plugin_toolbar_shortcuts' ), 999 );
		add_filter( 'admin_body_class', array( $this, 'add_admin_body_class' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_dashboard_widgets' ), 100 );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_left' ) );
		add_filter( 'update_footer', array( $this, 'admin_footer_right' ), 11 );
		add_action( 'login_enqueue_scripts', array( $this, 'login_branding' ) );
		add_filter( 'login_headerurl', array( $this, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_logo_title' ) );
		add_action( 'admin_post_nesta_dashboard_checklist', array( $this, 'handle_checklist_action' ) );
		add_action( 'admin_post_nesta_quick_start', array( $this, 'handle_quick_start_request' ) );
		add_action( 'admin_post_nesta_quick_start_install', array( $this, 'handle_template_install' ) );
		add_action( 'admin_post_nesta_sync_templates', array( $this, 'handle_template_sync' ) );
		add_action( 'admin_post_nesta_toggle_quick_start', array( $this, 'handle_quick_start_toggle' ) );
		add_action( 'admin_post_nesta_mu_plugin_check', array( $this, 'handle_mu_plugin_update_check' ) );
		add_action( 'admin_post_nesta_mu_plugin_update', array( $this, 'handle_mu_plugin_update' ) );
		add_action( 'admin_post_nesta_quick_start_undo', array( $this, 'handle_quick_start_undo' ) );
		add_action( 'admin_post_nesta_quick_start_reset', array( $this, 'handle_quick_start_reset' ) );
		add_action( 'admin_post_nesta_create_page', array( $this, 'handle_page_builder_request' ) );
		add_action( 'wp_head', array( $this, 'print_global_palette_css' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_spectra_fallback_assets' ), 25 );
	}

	/**
	 * Register additional template sources such as remote caches.
	 *
	 * @return void
	 */
	private function register_template_sources() {
		$source = $this->get_cached_template_source();
		if ( empty( $source['dir'] ) || empty( $source['url'] ) ) {
			return;
		}

		$this->template_registry->add_source( $source['dir'], $source['url'] );
	}

	/**
	 * Check whether Quick Start is enabled in the menu.
	 *
	 * @return bool
	 */
	private function is_quick_start_enabled() {
		$enabled = get_option( 'nesta_quick_start_enabled', '1' );
		return '0' !== (string) $enabled;
	}

	/**
	 * Return the template catalog URL (filterable).
	 *
	 * @return string
	 */
	private function get_template_catalog_url() {
		$url = self::TEMPLATE_CATALOG_URL;
		return apply_filters( 'nesta_template_catalog_url', $url );
	}

	/**
	 * Return the MU plugin update manifest URL (filterable).
	 *
	 * @return string
	 */
	private function get_mu_plugin_manifest_url() {
		$url = self::MU_UPDATE_MANIFEST_URL;
		return apply_filters( 'nesta_mu_plugin_manifest_url', $url );
	}

	/**
	 * Read the cached MU plugin update state.
	 *
	 * @return array
	 */
	private function get_mu_plugin_update_state() {
		$state = get_option( self::MU_UPDATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist MU plugin update state.
	 *
	 * @param array $state Update data.
	 * @return void
	 */
	private function set_mu_plugin_update_state( $state ) {
		if ( ! is_array( $state ) ) {
			return;
		}

		update_option( self::MU_UPDATE_OPTION, $state, false );
	}

	/**
	 * Get the local cache directory + URL for remote templates.
	 *
	 * @return array
	 */
	private function get_cached_template_source() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array();
		}

		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . self::TEMPLATE_CACHE_DIR,
			'url' => trailingslashit( $uploads['baseurl'] ) . self::TEMPLATE_CACHE_DIR,
		);
	}

	/**
	 * Ensure the cache directory exists for remote templates.
	 *
	 * @return array|WP_Error
	 */
	private function ensure_cached_template_dir() {
		$source = $this->get_cached_template_source();
		if ( empty( $source['dir'] ) || empty( $source['url'] ) ) {
			return new WP_Error( 'nesta_template_cache_missing', __( 'Template cache location is unavailable.', 'nesta-dashboard' ) );
		}

		if ( ! is_dir( $source['dir'] ) && ! wp_mkdir_p( $source['dir'] ) ) {
			return new WP_Error( 'nesta_template_cache_create_failed', __( 'Template cache directory could not be created.', 'nesta-dashboard' ) );
		}

		return $source;
	}

	/**
	 * Delete a template cache directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function delete_template_dir( $dir ) {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$filesystem_ready = WP_Filesystem();
		if ( ! $filesystem_ready ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->delete( $dir, true, 'd' );
	}

	/**
	 * Read a JSON file from disk.
	 *
	 * @param string $path Absolute path.
	 * @return array|null
	 */
	private function read_json_file( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}

		if ( function_exists( 'wp_json_file_decode' ) ) {
			return wp_json_file_decode( $path, array( 'associative' => true ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		return json_decode( $contents, true );
	}

	/**
	 * Sync remote templates into the local cache.
	 *
	 * @return array|WP_Error
	 */
	private function sync_remote_templates() {
		$catalog_url = $this->get_template_catalog_url();
		if ( ! $catalog_url ) {
			return new WP_Error( 'nesta_template_catalog_missing', __( 'Template catalog URL is not configured.', 'nesta-dashboard' ) );
		}

		$response = wp_remote_get(
			$catalog_url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'nesta_template_catalog_http', __( 'Template catalog could not be fetched.', 'nesta-dashboard' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'nesta_template_catalog_invalid', __( 'Template catalog response was invalid.', 'nesta-dashboard' ) );
		}

		$templates = isset( $data['templates'] ) ? $data['templates'] : $data;
		if ( empty( $templates ) || ! is_array( $templates ) ) {
			return new WP_Error( 'nesta_template_catalog_empty', __( 'No templates were found in the catalog.', 'nesta-dashboard' ) );
		}

		$source = $this->ensure_cached_template_dir();
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$filesystem_ready = WP_Filesystem();
		if ( ! $filesystem_ready ) {
			return new WP_Error( 'nesta_template_filesystem', __( 'Unable to initialize filesystem for template sync.', 'nesta-dashboard' ) );
		}

		$checksums = get_option( 'nesta_template_remote_checksums', array() );
		$results   = array(
			'added'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				$results['failed']++;
				continue;
			}

			$template_id  = isset( $template['id'] ) ? sanitize_key( $template['id'] ) : '';
			$download_url = isset( $template['download_url'] ) ? esc_url_raw( $template['download_url'] ) : '';
			$version      = isset( $template['version'] ) ? (string) $template['version'] : '';
			$checksum     = isset( $template['checksum'] ) ? strtolower( trim( (string) $template['checksum'] ) ) : '';

			if ( ! $template_id || ! $download_url ) {
				$results['failed']++;
				continue;
			}

			$local_dir      = trailingslashit( $source['dir'] ) . $template_id;
			$manifest_path  = trailingslashit( $local_dir ) . 'manifest.json';
			$had_local_copy = is_dir( $local_dir );

			$local_version = '';
			if ( file_exists( $manifest_path ) ) {
				$manifest_data = $this->read_json_file( $manifest_path );
				if ( is_array( $manifest_data ) && ! empty( $manifest_data['version'] ) ) {
					$local_version = (string) $manifest_data['version'];
				}
			}

			$needs_update = ! $had_local_copy;

			if ( $version && $local_version && $version !== $local_version ) {
				$needs_update = true;
			}

			$previous_checksum = isset( $checksums[ $template_id ] ) ? $checksums[ $template_id ] : '';
			if ( $checksum && $checksum !== $previous_checksum ) {
				$needs_update = true;
			}

			if ( ! $needs_update ) {
				$results['skipped']++;
				continue;
			}

			$temp_file = download_url( $download_url );
			if ( is_wp_error( $temp_file ) ) {
				$results['failed']++;
				continue;
			}

			if ( $checksum ) {
				$file_hash = hash_file( 'sha256', $temp_file );
				if ( $checksum !== strtolower( $file_hash ) ) {
					@unlink( $temp_file );
					$results['failed']++;
					continue;
				}
			}

			if ( $had_local_copy && ! $this->delete_template_dir( $local_dir ) ) {
				@unlink( $temp_file );
				$results['failed']++;
				continue;
			}

			if ( ! wp_mkdir_p( $local_dir ) ) {
				@unlink( $temp_file );
				$results['failed']++;
				continue;
			}

			$unzipped = unzip_file( $temp_file, $local_dir );
			@unlink( $temp_file );

			if ( is_wp_error( $unzipped ) ) {
				$results['failed']++;
				continue;
			}

			if ( ! file_exists( $manifest_path ) ) {
				$results['failed']++;
				continue;
			}

			if ( $checksum ) {
				$checksums[ $template_id ] = $checksum;
			}

			if ( $had_local_copy ) {
				$results['updated']++;
			} else {
				$results['added']++;
			}
		}

		update_option( 'nesta_template_remote_checksums', $checksums );

		return $results;
	}

	/**
	 * Resolve the uploads zip path for a template, supporting shared bundles.
	 *
	 * @param array  $template    Template data.
	 * @param string $uploads_rel Relative uploads path from manifest.
	 * @return string
	 */
	private function resolve_template_uploads_path( $template, $uploads_rel ) {
		if ( ! $uploads_rel ) {
			return '';
		}

		$base_dir    = isset( $template['dir'] ) ? trailingslashit( $template['dir'] ) : '';
		$relative    = ltrim( $uploads_rel, '/' );
		$candidate   = $base_dir ? $base_dir . $relative : '';
		$normalized  = str_replace( '\\', '/', $relative );

		if ( $candidate && file_exists( $candidate ) ) {
			return $candidate;
		}

		if ( false !== strpos( $normalized, 'shared/uploads.zip' ) ) {
			$shared_path = $this->ensure_shared_uploads_asset();
			if ( $shared_path ) {
				return $shared_path;
			}
		}

		return '';
	}

	/**
	 * Return the shared uploads directory and file path.
	 *
	 * @return array{dir:string,path:string}
	 */
	private function get_shared_uploads_info() {
		$uploads  = wp_upload_dir();
		$base_dir = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : trailingslashit( WP_CONTENT_DIR ) . 'uploads';
		$dir      = trailingslashit( $base_dir ) . 'nesta-shared';

		return array(
			'dir'  => $dir,
			'path' => trailingslashit( $dir ) . 'uploads.zip',
		);
	}

	/**
	 * Ensure the shared uploads zip is available in a persistent location.
	 *
	 * @return string Shared uploads path if found or created, empty string otherwise.
	 */
	private function ensure_shared_uploads_asset() {
		$shared = $this->get_shared_uploads_info();
		if ( file_exists( $shared['path'] ) ) {
			return $shared['path'];
		}

		$plugin_shared = plugin_dir_path( __FILE__ ) . 'templates/shared/uploads.zip';
		if ( ! file_exists( $plugin_shared ) ) {
			$downloaded = $this->download_shared_uploads( $shared['path'] );
			return $downloaded ? $downloaded : '';
		}

		if ( ! wp_mkdir_p( $shared['dir'] ) ) {
			return $plugin_shared;
		}

		@copy( $plugin_shared, $shared['path'] );

		return file_exists( $shared['path'] ) ? $shared['path'] : $plugin_shared;
	}

	/**
	 * Download the shared uploads bundle to the uploads directory.
	 *
	 * @param string $target_path Target path for the shared bundle.
	 * @return string Shared uploads path if downloaded, empty string otherwise.
	 */
	private function download_shared_uploads( $target_path ) {
		if ( empty( self::SHARED_UPLOADS_URL ) ) {
			return '';
		}

		$target_dir = dirname( $target_path );
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return '';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_file = download_url( self::SHARED_UPLOADS_URL, 60 );
		if ( is_wp_error( $temp_file ) ) {
			return '';
		}

		$copied = @copy( $temp_file, $target_path );
		@unlink( $temp_file );

		return $copied && file_exists( $target_path ) ? $target_path : '';
	}

	/**
	 * Toggle Quick Start visibility in the admin menu.
	 */
	public function handle_quick_start_toggle() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Quick Start visibility.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_toggle_quick_start' ) ) {
			wp_die( esc_html__( 'Quick Start toggle nonce check failed.', 'nesta-dashboard' ) );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$enabled = ( 'enable' === $mode ) ? '1' : '0';
		update_option( 'nesta_quick_start_enabled', $enabled );

		$query_args = array(
			'page' => self::SETTINGS_SLUG,
		);

		$query_args['nesta_quick_start_toggle'] = ( '1' === $enabled ) ? 'enabled' : 'disabled';

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Register the top-level Nesta menu page.
	 */
	public function register_menu() {
		$this->menu_hook = add_menu_page(
			__( 'Nesta Dashboard', 'nesta-dashboard' ),
			self::MENU_TITLE,
			'read',
			self::SLUG,
			array( $this, 'render_portal' ),
			'dashicons-layout',
			1
		);

		add_menu_page(
			__( 'Nesta Quick Start', 'nesta-dashboard' ),
			__( 'Quick Start', 'nesta-dashboard' ),
			'read',
			self::QUICK_START_SLUG,
			array( $this, 'render_quick_start_page' ),
			'dashicons-hammer',
			1.5
		);

		remove_menu_page( 'index.php' );

		add_submenu_page(
			self::SLUG,
			__( 'Nesta Dashboard', 'nesta-dashboard' ),
			__( 'Overview', 'nesta-dashboard' ),
			'read',
			self::SLUG,
			array( $this, 'render_portal' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Create a Page', 'nesta-dashboard' ),
			__( 'Create a Page', 'nesta-dashboard' ),
			'edit_pages',
			self::PAGE_BUILDER_SLUG,
			array( $this, 'render_page_builder_page' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'nesta-dashboard' ),
			__( 'Settings', 'nesta-dashboard' ),
			'edit_pages',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( ! $this->is_quick_start_enabled() ) {
			remove_menu_page( self::QUICK_START_SLUG );
		}
	}

	/**
	 * Redirect the default Dashboard screen to the Nesta portal.
	 */
	public function redirect_dashboard() {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		$target = admin_url( 'admin.php?page=' . self::SLUG );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Remove the WordPress logo and add a Nesta link to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public function customize_admin_bar( $wp_admin_bar ) {
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		if ( ! is_admin() ) {
			return;
		}

		$wp_admin_bar->remove_node( 'wp-logo' );

		$logo_path = plugin_dir_path( __FILE__ ) . 'assets/nesta-logo.png';
		$logo_url  = plugins_url( 'assets/nesta-logo.png', __FILE__ );
		$logo_markup = file_exists( $logo_path )
			? sprintf(
				'<span class="nesta-admin-logo"><img src="%s" alt="%s" decoding="async" loading="lazy" /></span>',
				esc_url( $logo_url ),
				esc_attr__( 'Nesta Portal', 'nesta-dashboard' )
			)
			: sprintf(
				'<span class="nesta-admin-logo-text">%s</span>',
				esc_html__( 'Nesta', 'nesta-dashboard' )
			);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'nesta-dashboard',
				'title' => $logo_markup,
				'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
				'meta'  => array(
					'class' => 'nesta-dashboard-admin-bar-link',
					'title' => __( 'Back to Nesta dashboard', 'nesta-dashboard' ),
				),
			)
		);

		$visit_icon = '<span class="nesta-visit-site-icon" aria-hidden="true">↗</span>';

		$wp_admin_bar->add_node(
			array(
				'parent' => false,
				'id'     => 'nesta-visit-site',
				'title'  => $visit_icon . '<span class="nesta-visit-site-text">' . esc_html__( 'Visit Site', 'nesta-dashboard' ) . '</span>',
				'href'   => home_url( '/' ),
				'meta'   => array(
					'class'    => 'nesta-visit-site-link',
					'target'   => '_blank',
					'title'    => __( 'Visit the live site', 'nesta-dashboard' ),
					'position' => 12,
				),
			)
		);

	}

	/**
	 * Enqueue the portal styles only on the Nesta dashboard screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		wp_enqueue_style(
			'nesta-dashboard-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			'1.0.0'
		);

	}

	/**
	 * Append a custom body class so global admin theming can be targeted safely.
	 *
	 * @param string $classes Existing body classes.
	 * @return string
	 */
	public function add_admin_body_class( $classes ) {
		if ( strpos( $classes, 'nesta-admin-theme' ) === false ) {
			$classes .= ' nesta-admin-theme';
		}

		return $classes;
	}

	/**
	 * Opt-out of telemetry/marketing notices from third-party plugins.
	 */
	public function suppress_plugin_marketing_notices() {
		$spectra_option = get_site_option( 'spectra_analytics_optin', null );

		if ( null === $spectra_option ) {
			update_site_option( 'spectra_analytics_optin', 'no' );
		}
	}

	/**
	 * Remove plugin toolbar shortcuts that are not needed (e.g., Spectra AI).
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public function remove_plugin_toolbar_shortcuts( $wp_admin_bar ) {
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$wp_admin_bar->remove_node( 'spectra-ai-shortcut' );
		$wp_admin_bar->remove_node( 'spectra-ai' );
	}

	/**
	 * Remove core Dashboard widgets for a cleaner experience.
	 */
	public function remove_dashboard_widgets() {
		$widgets = array(
			'dashboard_activity',
			'dashboard_quick_press',
			'dashboard_primary',
			'dashboard_site_health',
			'dashboard_right_now',
			'dashboard_recent_drafts',
			'dashboard_recent_comments',
			'dashboard_incoming_links',
			'dashboard_plugins',
		);

		foreach ( $widgets as $widget ) {
			remove_meta_box( $widget, 'dashboard', 'normal' );
			remove_meta_box( $widget, 'dashboard', 'side' );
		}
	}

	/**
	 * Replace the left footer text.
	 *
	 * @return string
	 */
	public function admin_footer_left() {
		return esc_html__( 'Managed by Nesta Sites', 'nesta-dashboard' );
	}

	/**
	 * Replace the right footer text with a support link.
	 *
	 * @return string
	 */
	public function admin_footer_right() {
		$link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::SUPPORT_URL ),
			esc_html__( 'Support', 'nesta-dashboard' )
		);

		return $link;
	}

	/**
	 * Output a minimal login skin for Nesta branding.
	 */
	public function login_branding() {
		$logo_declaration = self::LOGIN_LOGO_URL ? sprintf( 'background-image: url(%s);', esc_url( self::LOGIN_LOGO_URL ) ) : '';
		?>
		<style>
			body.login {
				background: #0f172a;
				color: #f8fafc;
			}

			body.login #loginform {
				border-radius: 12px;
				box-shadow: 0 25px 60px rgba(15, 23, 42, 0.35);
				border: none;
			}

			body.login .button-primary {
				background: #2563eb;
				border-color: #2563eb;
				box-shadow: none;
			}

			body.login .button-primary:hover,
			body.login .button-primary:focus {
				background: #1d4ed8;
				border-color: #1d4ed8;
			}

			body.login h1 a {
				<?php echo esc_html( $logo_declaration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				width: 220px;
				background-size: contain;
				background-position: center;
			}
		</style>
		<?php
	}

	/**
	 * Make the login logo link back to the site home.
	 *
	 * @return string
	 */
	public function login_logo_url() {
		return home_url( '/' );
	}

	/**
	 * Customize the login logo title attribute.
	 *
	 * @return string
	 */
	public function login_logo_title() {
		return __( 'Nesta Sites Client Portal', 'nesta-dashboard' );
	}

	/**
	 * Render the Nesta portal UI.
	 */
	public function render_portal() {
		$current_user = wp_get_current_user();
		$display_name = $current_user instanceof WP_User && $current_user->display_name ? $current_user->display_name : __( 'Partner', 'nesta-dashboard' );
		$site_name    = get_bloginfo( 'name', 'display' );
		$user_id      = get_current_user_id();
		$completed    = $this->get_checklist_completed( $user_id );
		$dismissed    = (bool) get_user_meta( $user_id, 'nesta_dashboard_checklist_dismissed', true );

		$quick_actions = array(
			array(
				'title'       => __( 'Create a Page', 'nesta-dashboard' ),
				'description' => __( 'Spin up an SEO-optimized service or location page.', 'nesta-dashboard' ),
				'url'         => admin_url( 'admin.php?page=' . self::PAGE_BUILDER_SLUG ),
				'external'    => false,
				'icon'        => 'dashicons-media-document',
			),
			array(
				'title'       => __( 'Edit Pages', 'nesta-dashboard' ),
				'description' => __( 'Update copy and layout across key pages.', 'nesta-dashboard' ),
				'url'         => admin_url( 'edit.php?post_type=page' ),
				'external'    => false,
				'icon'        => 'dashicons-edit-page',
			),
			array(
				'title'       => __( 'Manage Posts', 'nesta-dashboard' ),
				'description' => __( 'Publish blogs or announcements.', 'nesta-dashboard' ),
				'url'         => admin_url( 'edit.php' ),
				'external'    => false,
				'icon'        => 'dashicons-admin-post',
			),
			array(
				'title'       => __( 'Media Library', 'nesta-dashboard' ),
				'description' => __( 'Upload photos, logos, and files.', 'nesta-dashboard' ),
				'url'         => admin_url( 'upload.php' ),
				'external'    => false,
				'icon'        => 'dashicons-format-image',
			),
			array(
				'title'       => __( 'Menus & Navigation', 'nesta-dashboard' ),
				'description' => __( 'Control site navigation structure.', 'nesta-dashboard' ),
				'url'         => admin_url( 'nav-menus.php' ),
				'external'    => false,
				'icon'        => 'dashicons-menu',
			),
			array(
				'title'       => __( 'Site Customizer', 'nesta-dashboard' ),
				'description' => __( 'Adjust branding, headers, and footers.', 'nesta-dashboard' ),
				'url'         => admin_url( 'customize.php' ),
				'external'    => false,
				'icon'        => 'dashicons-admin-appearance',
			),
			array(
				'title'       => __( 'View Website', 'nesta-dashboard' ),
				'description' => __( 'Open the live site in a new tab.', 'nesta-dashboard' ),
				'url'         => home_url( '/' ),
				'external'    => true,
				'icon'        => 'dashicons-external',
			),
		);

		$support_cards = array(
			array(
				'title'       => __( 'Request a Change', 'nesta-dashboard' ),
				'description' => __( 'Send detailed instructions to the Nesta production team.', 'nesta-dashboard' ),
				'url'         => self::REQUEST_CHANGE_URL,
				'label'       => __( 'Start request', 'nesta-dashboard' ),
				'external'    => true,
			),
			array(
				'title'       => __( 'Quick Guides', 'nesta-dashboard' ),
				'description' => __( 'Walk through common edits, best practices, and Nesta processes.', 'nesta-dashboard' ),
				'url'         => self::QUICK_GUIDES_URL,
				'label'       => __( 'Open guides', 'nesta-dashboard' ),
				'external'    => true,
			),
			array(
				'title'       => __( 'Support Desk', 'nesta-dashboard' ),
				'description' => __( 'Need a hand? Reach the Nesta success team anytime.', 'nesta-dashboard' ),
				'url'         => self::SUPPORT_URL,
				'label'       => __( 'Contact support', 'nesta-dashboard' ),
				'external'    => true,
			),
		);

		?>
		<div class="wrap nesta-dashboard">
			<section class="nesta-hero">
				<div class="nesta-hero__content">
					<p class="nesta-eyebrow"><?php esc_html_e( 'Nesta Website Hub', 'nesta-dashboard' ); ?></p>
					<h1>
						<?php esc_html_e( 'Welcome back', 'nesta-dashboard' ); ?>
					</h1>
					<p class="nesta-description">
						<?php
						printf( esc_html__( 'Hi %1$s, you are managing %2$s through Nesta.', 'nesta-dashboard' ), esc_html( $display_name ), esc_html( $site_name ) );
						?>
					</p>
					<p class="nesta-description">
						<?php esc_html_e( 'What would you like to work on today?', 'nesta-dashboard' ); ?>
					</p>
				</div>
				<div class="nesta-hero__actions">
					<a class="nesta-button" href="<?php echo esc_url( self::REQUEST_CHANGE_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Submit a change request', 'nesta-dashboard' ); ?>
					</a>
					<p>
						<?php
						printf(
							/* translators: %s support link. */
							esc_html__( 'Need help? %s', 'nesta-dashboard' ),
							sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								esc_url( self::SUPPORT_URL ),
								esc_html__( 'Chat with Nesta support', 'nesta-dashboard' )
							)
						);
						?>
					</p>
				</div>
			</section>

			<section class="nesta-quick-grid">
				<?php foreach ( $quick_actions as $action ) : ?>
					<a
						class="nesta-quick-card"
						href="<?php echo esc_url( $action['url'] ); ?>"
						<?php echo $action['external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
					>
						<span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>"></span>
						<div>
							<strong><?php echo esc_html( $action['title'] ); ?></strong>
							<p><?php echo esc_html( $action['description'] ); ?></p>
						</div>
						<span class="nesta-quick-card__arrow" aria-hidden="true"></span>
					</a>
				<?php endforeach; ?>
			</section>

			<section class="nesta-panel nesta-panel--checklist" id="nesta-checklist">
				<header class="nesta-panel__header">
					<div>
						<p class="nesta-eyebrow"><?php esc_html_e( 'Website Launch Checklist', 'nesta-dashboard' ); ?></p>
						<h2><?php esc_html_e( 'Complete these tasks before going live.', 'nesta-dashboard' ); ?></h2>
					</div>
				</header>
				<?php if ( $dismissed ) : ?>
					<p><?php esc_html_e( 'You dismissed the checklist. Restore it if you need to review launch tasks again.', 'nesta-dashboard' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nesta_dashboard_checklist" />
						<input type="hidden" name="mode" value="restore" />
						<?php wp_nonce_field( 'nesta_dashboard_checklist' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Restore checklist', 'nesta-dashboard' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nesta_dashboard_checklist" />
						<input type="hidden" name="mode" value="save" />
						<?php wp_nonce_field( 'nesta_dashboard_checklist' ); ?>
						<ul class="nesta-checklist">
							<?php foreach ( $this->launch_checklist as $item ) : ?>
								<li>
									<label>
										<input type="checkbox" name="checklist_items[]" value="<?php echo esc_attr( $item['slug'] ); ?>" <?php checked( in_array( $item['slug'], $completed, true ) ); ?> />
										<span><?php echo esc_html( $item['label'] ); ?></span>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="nesta-checklist__actions">
							<button type="submit" name="mode" value="save" class="button button-primary"><?php esc_html_e( 'Save progress', 'nesta-dashboard' ); ?></button>
							<button type="submit" name="mode" value="complete" class="button"><?php esc_html_e( 'Mark all complete', 'nesta-dashboard' ); ?></button>
							<button type="submit" name="mode" value="dismiss" class="button button-link-delete"><?php esc_html_e( 'Dismiss checklist', 'nesta-dashboard' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</section>

			<section class="nesta-panel nesta-panel--support">
				<header class="nesta-panel__header">
					<div>
						<p class="nesta-eyebrow"><?php esc_html_e( 'Support & brand consistency', 'nesta-dashboard' ); ?></p>
						<h2><?php esc_html_e( 'Stay aligned with the Nesta experience.', 'nesta-dashboard' ); ?></h2>
					</div>
				</header>
				<div class="nesta-support-grid">
					<?php foreach ( $support_cards as $card ) : ?>
						<div class="nesta-info-card">
							<h3><?php echo esc_html( $card['title'] ); ?></h3>
							<p><?php echo esc_html( $card['description'] ); ?></p>
							<a
								class="nesta-link"
								href="<?php echo esc_url( $card['url'] ); ?>"
								<?php echo $card['external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
							>
								<?php echo esc_html( $card['label'] ); ?>
								<span class="dashicons dashicons-arrow-right-alt"></span>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

		</div>
		<?php
	}

	/**
	 * Render the Settings screen.
	 */
	public function render_settings_page() {
		$quick_start_enabled = $this->is_quick_start_enabled();
		$quick_start_toggle_notice = isset( $_GET['nesta_quick_start_toggle'] ) ? sanitize_text_field( wp_unslash( $_GET['nesta_quick_start_toggle'] ) ) : '';
		$mu_plugin_notice = isset( $_GET['nesta_mu_plugin_update'] ) ? sanitize_text_field( wp_unslash( $_GET['nesta_mu_plugin_update'] ) ) : '';
		$mu_plugin_message = isset( $_GET['nesta_mu_plugin_update_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['nesta_mu_plugin_update_message'] ) ) ) : '';
		$mu_plugin_state = $this->get_mu_plugin_update_state();
		$mu_plugin_current = self::PLUGIN_VERSION;
		$mu_plugin_remote = ! empty( $mu_plugin_state['remote_version'] ) ? (string) $mu_plugin_state['remote_version'] : '';
		$mu_plugin_checked_raw = ! empty( $mu_plugin_state['checked_at'] ) ? (string) $mu_plugin_state['checked_at'] : '';
		$mu_plugin_checked = $mu_plugin_checked_raw ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mu_plugin_checked_raw, true ) : '';
		$mu_plugin_update_available = ! empty( $mu_plugin_state['update_available'] );
		$can_update_plugin = current_user_can( 'update_plugins' );
		$mu_plugin_status = '';

		if ( $mu_plugin_remote ) {
			if ( $mu_plugin_update_available ) {
				$mu_plugin_status = sprintf(
					/* translators: %s: version number. */
					__( 'Update available: v%s.', 'nesta-dashboard' ),
					$mu_plugin_remote
				);
			} else {
				$mu_plugin_status = __( 'You are running the latest available build.', 'nesta-dashboard' );
			}
		} else {
			$mu_plugin_status = __( 'No update check has been run yet.', 'nesta-dashboard' );
		}
		?>
		<div class="wrap nesta-dashboard nesta-settings-page">
			<?php if ( 'enabled' === $quick_start_toggle_notice || 'disabled' === $quick_start_toggle_notice ) : ?>
				<div class="nesta-alert nesta-alert--success">
					<strong><?php esc_html_e( 'Quick Start visibility updated.', 'nesta-dashboard' ); ?></strong>
					<p>
						<?php echo esc_html( $quick_start_enabled ? __( 'Quick Start is visible in the menu.', 'nesta-dashboard' ) : __( 'Quick Start is hidden from the menu.', 'nesta-dashboard' ) ); ?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( 'updated' === $mu_plugin_notice ) : ?>
				<div class="nesta-alert nesta-alert--success">
					<strong><?php esc_html_e( 'MU plugin updated.', 'nesta-dashboard' ); ?></strong>
					<p><?php esc_html_e( 'The latest dashboard files are now installed.', 'nesta-dashboard' ); ?></p>
				</div>
			<?php elseif ( 'available' === $mu_plugin_notice ) : ?>
				<div class="nesta-alert nesta-alert--success">
					<strong><?php esc_html_e( 'Update available.', 'nesta-dashboard' ); ?></strong>
					<p><?php esc_html_e( 'A newer MU plugin build is ready to install.', 'nesta-dashboard' ); ?></p>
				</div>
			<?php elseif ( 'current' === $mu_plugin_notice ) : ?>
				<div class="nesta-alert nesta-alert--success">
					<strong><?php esc_html_e( 'Plugin is up to date.', 'nesta-dashboard' ); ?></strong>
					<p><?php esc_html_e( 'You already have the latest MU plugin build.', 'nesta-dashboard' ); ?></p>
				</div>
			<?php elseif ( 'error' === $mu_plugin_notice ) : ?>
				<div class="nesta-alert nesta-alert--error">
					<strong><?php esc_html_e( 'MU plugin update failed.', 'nesta-dashboard' ); ?></strong>
					<p><?php echo esc_html( $mu_plugin_message ? $mu_plugin_message : __( 'Please try again or contact Nesta support.', 'nesta-dashboard' ) ); ?></p>
				</div>
			<?php endif; ?>

			<section class="nesta-panel nesta-panel--settings">
				<header class="nesta-panel__header">
					<div>
						<p class="nesta-eyebrow"><?php esc_html_e( 'Settings & updates', 'nesta-dashboard' ); ?></p>
						<h2><?php esc_html_e( 'Manage Quick Start access and keep the dashboard current.', 'nesta-dashboard' ); ?></h2>
					</div>
				</header>
				<div class="nesta-settings-grid">
					<div class="nesta-settings-card">
						<h3><?php esc_html_e( 'Quick Start visibility', 'nesta-dashboard' ); ?></h3>
						<p>
							<?php
							echo esc_html(
								$quick_start_enabled
									? __( 'Quick Start is currently visible in the admin menu. Disable it once the site is approved to reduce accidental resets.', 'nesta-dashboard' )
									: __( 'Quick Start is hidden from the admin menu. You can re-enable it here if you need to run the workflow again.', 'nesta-dashboard' )
							);
							?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="nesta_toggle_quick_start" />
							<input type="hidden" name="mode" value="<?php echo esc_attr( $quick_start_enabled ? 'disable' : 'enable' ); ?>" />
							<?php wp_nonce_field( 'nesta_toggle_quick_start' ); ?>
							<?php if ( $quick_start_enabled ) : ?>
								<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Hide Quick Start from the menu? You can re-enable it from this dashboard.', 'nesta-dashboard' ) ); ?>');">
									<?php esc_html_e( 'Disable Quick Start', 'nesta-dashboard' ); ?>
								</button>
							<?php else : ?>
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Enable Quick Start', 'nesta-dashboard' ); ?>
								</button>
							<?php endif; ?>
						</form>
					</div>
					<div class="nesta-settings-card">
						<h3><?php esc_html_e( 'MU plugin updates', 'nesta-dashboard' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: %s: version number */
								esc_html__( 'Current version: %s', 'nesta-dashboard' ),
								esc_html( $mu_plugin_current )
							);
							?>
						</p>
						<?php if ( $mu_plugin_remote ) : ?>
							<p>
								<?php
								printf(
									/* translators: %s: version number */
									esc_html__( 'Latest available: %s', 'nesta-dashboard' ),
									esc_html( $mu_plugin_remote )
								);
								?>
							</p>
						<?php endif; ?>
						<p>
							<?php
							printf(
								/* translators: %s: date/time string */
								esc_html__( 'Last checked: %s', 'nesta-dashboard' ),
								esc_html( $mu_plugin_checked ? $mu_plugin_checked : __( 'Not checked yet', 'nesta-dashboard' ) )
							);
							?>
						</p>
						<p><?php echo esc_html( $mu_plugin_status ); ?></p>
						<?php if ( $can_update_plugin ) : ?>
							<div class="nesta-settings-actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-progress-form">
									<input type="hidden" name="action" value="nesta_mu_plugin_check" />
									<?php wp_nonce_field( 'nesta_mu_plugin_check' ); ?>
									<button type="submit" class="button" data-progress="true" data-loading-text="<?php esc_attr_e( 'Checking…', 'nesta-dashboard' ); ?>">
										<?php esc_html_e( 'Check for updates', 'nesta-dashboard' ); ?>
									</button>
								</form>
								<?php if ( $mu_plugin_update_available ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-progress-form">
										<input type="hidden" name="action" value="nesta_mu_plugin_update" />
										<?php wp_nonce_field( 'nesta_mu_plugin_update' ); ?>
										<button type="submit" class="button button-primary" data-progress="true" data-loading-text="<?php esc_attr_e( 'Updating…', 'nesta-dashboard' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Install the latest MU plugin update now?', 'nesta-dashboard' ) ); ?>');">
											<?php esc_html_e( 'Update MU plugin', 'nesta-dashboard' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<p><?php esc_html_e( 'Only administrators can check for and install MU plugin updates.', 'nesta-dashboard' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render the dedicated quick start setup page.
	 */
	public function render_quick_start_page() {
	$can_manage_quick_start = current_user_can( 'edit_pages' );
	$user_id                = get_current_user_id();
	$templates              = $this->template_registry->get_templates();
	$state                  = $this->get_quick_start_defaults( $user_id, $templates );
	$selected_template      = $state['template_id'];
	$brand_defaults         = $state['brand'];
	$token_defaults         = $state['tokens'];
	$quick_start_notice     = isset( $_GET['nesta_quick_start'] ) ? sanitize_text_field( wp_unslash( $_GET['nesta_quick_start'] ) ) : '';
	$quick_start_created    = isset( $_GET['nesta_quick_start_created'] ) ? absint( $_GET['nesta_quick_start_created'] ) : 0;
	$quick_start_updated    = isset( $_GET['nesta_quick_start_updated'] ) ? absint( $_GET['nesta_quick_start_updated'] ) : 0;
	$quick_start_removed    = isset( $_GET['nesta_quick_start_removed'] ) ? absint( $_GET['nesta_quick_start_removed'] ) : 0;
	$quick_start_error      = '';
	if ( isset( $_GET['nesta_quick_start_message'] ) ) {
		$quick_start_error = sanitize_text_field( rawurldecode( wp_unslash( $_GET['nesta_quick_start_message'] ) ) );
	}

	$install_notice   = isset( $_GET['nesta_install'] ) ? sanitize_text_field( wp_unslash( $_GET['nesta_install'] ) ) : '';
	$install_message  = isset( $_GET['nesta_install_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['nesta_install_message'] ) ) ) : '';
	$install_created  = isset( $_GET['nesta_install_created'] ) ? absint( $_GET['nesta_install_created'] ) : 0;
	$install_updated  = isset( $_GET['nesta_install_updated'] ) ? absint( $_GET['nesta_install_updated'] ) : 0;
	$sync_notice      = isset( $_GET['nesta_template_sync'] ) ? sanitize_text_field( wp_unslash( $_GET['nesta_template_sync'] ) ) : '';
	$sync_message     = isset( $_GET['nesta_template_sync_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['nesta_template_sync_message'] ) ) ) : '';
	$sync_added       = isset( $_GET['nesta_template_sync_added'] ) ? absint( $_GET['nesta_template_sync_added'] ) : 0;
	$sync_updated     = isset( $_GET['nesta_template_sync_updated'] ) ? absint( $_GET['nesta_template_sync_updated'] ) : 0;
	$sync_skipped     = isset( $_GET['nesta_template_sync_skipped'] ) ? absint( $_GET['nesta_template_sync_skipped'] ) : 0;
	$sync_failed      = isset( $_GET['nesta_template_sync_failed'] ) ? absint( $_GET['nesta_template_sync_failed'] ) : 0;

	$last_generation       = get_option( 'nesta_quick_start_last_generation', array() );
	$last_generation_pages = ( is_array( $last_generation ) && ! empty( $last_generation['page_ids'] ) ) ? (array) $last_generation['page_ids'] : array();
	$last_generation_time  = ! empty( $last_generation['generated_at'] ) ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_generation['generated_at'], true ) : '';
	$has_template_install  = ! empty( $last_generation_pages );
	$has_generation        = ! empty( $last_generation['content_applied'] );
	$front_page_id         = (int) get_option( 'page_on_front' );
	$view_site_url         = $front_page_id ? get_permalink( $front_page_id ) : home_url( '/' );

	$token_schema        = $this->get_token_schema();
	$token_json_prefill  = isset( $state['token_json'] ) ? $state['token_json'] : '';
	$token_placeholder   = '{"{{hero__h1}}": "Your hero headline", "{{faq__item_1_q}}": "Question?", "{{faq__item_1_a}}": "Answer"}';
	$base_url            = admin_url( 'admin.php?page=' . self::QUICK_START_SLUG );
	$current_step        = isset( $_GET['qs_step'] ) ? sanitize_key( wp_unslash( $_GET['qs_step'] ) ) : 'template';
	$valid_steps         = array( 'template', 'customize', 'launch' );
	if ( ! in_array( $current_step, $valid_steps, true ) ) {
		$current_step = 'template';
	}
	if ( 'launch' === $current_step && ! $has_generation ) {
		$current_step = 'customize';
	}

	$steps = array(
		'template'  => __( 'Choose template', 'nesta-dashboard' ),
		'customize' => __( 'Customize & content', 'nesta-dashboard' ),
		'launch'    => __( 'Review & launch', 'nesta-dashboard' ),
	);
	?>
	<div class="wrap nesta-dashboard nesta-quick-start-page">
		<nav class="nesta-stepper nesta-stepper--compact">
			<?php
			$index = 1;
			foreach ( $steps as $step_key => $label ) :
				$is_customize_disabled = ( 'customize' === $step_key && ! $has_template_install );
				$is_launch_disabled    = ( 'launch' === $step_key && ! $has_generation );
				$is_disabled           = $is_customize_disabled || $is_launch_disabled;
				$link                  = $is_disabled ? '#' : add_query_arg( 'qs_step', $step_key, $base_url );
				$classes            = array();
				if ( $current_step === $step_key ) {
					$classes[] = 'is-active';
				}
				if ( $is_disabled ) {
					$classes[] = 'is-disabled';
				}
				?>
					<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $link ); ?>" data-step="<?php echo esc_attr( $step_key ); ?>" <?php echo $is_disabled ? 'aria-disabled="true"' : ''; ?>>
					<span class="nesta-stepper__number"><?php echo esc_html( $index ); ?></span>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
				<?php
				$index++;
			endforeach;
			?>
			<?php if ( $can_manage_quick_start ) : ?>
				<form class="nesta-reset-form nesta-progress-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="nesta_quick_start_reset" />
					<?php wp_nonce_field( 'nesta_quick_start_reset' ); ?>
					<button type="submit" class="button nesta-button--danger nesta-button--with-icon" data-progress="true" data-loading-text="<?php esc_attr_e( 'Clearing…', 'nesta-dashboard' ); ?>">
						<span class="dashicons dashicons-update"></span>
						<span><?php esc_html_e( 'Start over', 'nesta-dashboard' ); ?></span>
					</button>
				</form>
			<?php endif; ?>
		</nav>

		<?php if ( 'saved' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Brand selections saved.', 'nesta-dashboard' ); ?></strong>
				<p><?php esc_html_e( 'We stored your template choice and onboarding details. Generate the site when you are ready.', 'nesta-dashboard' ); ?></p>
			</div>
		<?php elseif ( 'prefilled' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Token JSON applied.', 'nesta-dashboard' ); ?></strong>
				<p><?php esc_html_e( 'Review the fields below, make any edits, and then generate the site when everything looks right.', 'nesta-dashboard' ); ?></p>
			</div>
		<?php elseif ( 'generated' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Template applied.', 'nesta-dashboard' ); ?></strong>
				<p>
					<?php
					printf(
						esc_html__( '%1$d pages created and %2$d pages refreshed. Review and publish any edits before launch.', 'nesta-dashboard' ),
						esc_html( $quick_start_created ),
						esc_html( $quick_start_updated )
					);
					?>
				</p>
			</div>
		<?php elseif ( 'error' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--error">
				<strong><?php esc_html_e( 'Unable to generate template.', 'nesta-dashboard' ); ?></strong>
				<p><?php echo esc_html( $quick_start_error ? $quick_start_error : __( 'Please try again or choose a different template.', 'nesta-dashboard' ) ); ?></p>
			</div>
		<?php elseif ( 'json_error' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--error">
				<strong><?php esc_html_e( 'Token JSON could not be imported.', 'nesta-dashboard' ); ?></strong>
				<p><?php echo esc_html( $quick_start_error ? $quick_start_error : __( 'Please verify the JSON structure and try again.', 'nesta-dashboard' ) ); ?></p>
			</div>
		<?php elseif ( 'undo' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Last generation removed.', 'nesta-dashboard' ); ?></strong>
				<p>
					<?php
					printf(
						esc_html__( '%d generated pages were deleted so you can run a clean test again.', 'nesta-dashboard' ),
						esc_html( $quick_start_removed )
					);
					?>
				</p>
			</div>
		<?php elseif ( 'undo_empty' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--error">
				<strong><?php esc_html_e( 'Nothing to undo.', 'nesta-dashboard' ); ?></strong>
				<p><?php esc_html_e( 'Generate a template before using the undo control.', 'nesta-dashboard' ); ?></p>
			</div>
		<?php elseif ( 'reset' === $quick_start_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Quick Start reset.', 'nesta-dashboard' ); ?></strong>
				<p><?php esc_html_e( 'We cleared the installed template, saved colors, and token data so you can start fresh.', 'nesta-dashboard' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'success' === $install_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Template installed.', 'nesta-dashboard' ); ?></strong>
				<p>
					<?php
					printf(
						esc_html__( '%1$d pages created and %2$d pages refreshed from the bundle.', 'nesta-dashboard' ),
						esc_html( $install_created ),
						esc_html( $install_updated )
					);
					?>
				</p>
			</div>
		<?php elseif ( 'error' === $install_notice ) : ?>
			<div class="nesta-alert nesta-alert--error">
				<strong><?php esc_html_e( 'Template install failed.', 'nesta-dashboard' ); ?></strong>
				<p><?php echo esc_html( $install_message ? $install_message : __( 'Please try again or verify the bundle files.', 'nesta-dashboard' ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'success' === $sync_notice ) : ?>
			<div class="nesta-alert nesta-alert--success">
				<strong><?php esc_html_e( 'Templates synced.', 'nesta-dashboard' ); ?></strong>
				<p>
					<?php
					printf(
						esc_html__( '%1$d added, %2$d updated, %3$d skipped, %4$d failed.', 'nesta-dashboard' ),
						esc_html( $sync_added ),
						esc_html( $sync_updated ),
						esc_html( $sync_skipped ),
						esc_html( $sync_failed )
					);
					?>
				</p>
			</div>
		<?php elseif ( 'error' === $sync_notice ) : ?>
			<div class="nesta-alert nesta-alert--error">
				<strong><?php esc_html_e( 'Template sync failed.', 'nesta-dashboard' ); ?></strong>
				<p><?php echo esc_html( $sync_message ? $sync_message : __( 'Please try again in a moment.', 'nesta-dashboard' ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'template' === $current_step ) : ?>
			<section class="nesta-panel nesta-panel--quickstart">
				<header class="nesta-panel__header">
					<div>
							<p class="nesta-eyebrow"><?php esc_html_e( 'Step 1', 'nesta-dashboard' ); ?></p>
							<h2><?php esc_html_e( 'Pick your starting point.', 'nesta-dashboard' ); ?></h2>
							<p><?php esc_html_e( 'Each template is a full mini-site. Install one to drop in the starter pages, sections, and imagery so you can move straight to styling.', 'nesta-dashboard' ); ?></p>
					</div>
					<?php if ( $can_manage_quick_start ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-progress-form">
							<input type="hidden" name="action" value="nesta_sync_templates" />
							<?php wp_nonce_field( 'nesta_sync_templates' ); ?>
							<button type="submit" class="button" data-progress="true" data-loading-text="<?php esc_attr_e( 'Syncing…', 'nesta-dashboard' ); ?>">
								<?php esc_html_e( 'Sync templates', 'nesta-dashboard' ); ?>
							</button>
						</form>
					<?php endif; ?>
				</header>
				<?php if ( empty( $templates ) ) : ?>
					<p><?php esc_html_e( 'Template packs are not available yet. Add one to the templates directory to begin.', 'nesta-dashboard' ); ?></p>
				<?php else : ?>
					<div class="nesta-template-grid">
						<?php foreach ( $templates as $template ) : ?>
							<div class="nesta-template-card nesta-template-card--standalone">
								<span class="nesta-template-card__body">
									<?php if ( ! empty( $template['screenshot_url'] ) ) : ?>
										<div class="nesta-template-card__preview">
											<img src="<?php echo esc_url( $template['screenshot_url'] ); ?>" alt="" loading="lazy" decoding="async" />
											<button type="button" class="nesta-template-preview-trigger" data-template-id="<?php echo esc_attr( $template['id'] ); ?>" data-template-name="<?php echo esc_attr( $template['name'] ); ?>" data-template-image="<?php echo esc_url( $template['screenshot_url'] ); ?>">
												<span class="dashicons dashicons-visibility"></span>
												<?php esc_html_e( 'Preview', 'nesta-dashboard' ); ?>
											</button>
										</div>
									<?php endif; ?>
									<strong><?php echo esc_html( $template['name'] ); ?></strong>
									<p><?php echo esc_html( $template['description'] ); ?></p>
								</span>
								<?php
								$bundle_ready = ! empty( $template['bundle']['export'] ) && ! empty( $template['bundle']['uploads'] );
								$bundle_files_ready = false;

								if ( $bundle_ready && ! empty( $template['dir'] ) ) {
									$export_path  = trailingslashit( $template['dir'] ) . ltrim( $template['bundle']['export'], '/' );
									$uploads_path = $this->resolve_template_uploads_path( $template, $template['bundle']['uploads'] );
									$bundle_files_ready = file_exists( $export_path ) && ! empty( $uploads_path );
								}

								$bundle_ready = $bundle_ready && $bundle_files_ready;
								?>
								<?php if ( $bundle_ready && $can_manage_quick_start ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-progress-form">
										<input type="hidden" name="action" value="nesta_quick_start_install" />
										<input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>" />
										<?php wp_nonce_field( 'nesta_quick_start_install' ); ?>
										<button type="submit" class="nesta-install-button" data-progress="true" data-loading-text="<?php esc_attr_e( 'Installing…', 'nesta-dashboard' ); ?>"><?php esc_html_e( 'Install template', 'nesta-dashboard' ); ?></button>
									</form>
								<?php elseif ( ! $bundle_ready ) : ?>
									<button type="button" class="nesta-install-button is-disabled" disabled><?php esc_html_e( 'Coming soon', 'nesta-dashboard' ); ?></button>
								<?php else : ?>
									<p><?php esc_html_e( 'Only editors can install templates.', 'nesta-dashboard' ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		<?php elseif ( 'customize' === $current_step ) : ?>
			<section class="nesta-panel nesta-panel--quickstart" id="nesta-quick-start">
				<header class="nesta-panel__header">
					<div>
							<p class="nesta-eyebrow"><?php esc_html_e( 'Step 2', 'nesta-dashboard' ); ?></p>
							<h2><?php esc_html_e( 'Make the site feel like your business.', 'nesta-dashboard' ); ?></h2>
							<p><?php esc_html_e( 'Pick your colors, drop in your logo, and paste the content JSON if you have it. Review everything below, then save or generate the site when it feels ready.', 'nesta-dashboard' ); ?></p>
					</div>
				</header>
				<?php if ( ! $can_manage_quick_start ) : ?>
					<p><?php esc_html_e( 'You need editing permissions to run the Quick Start workflow. Please contact your Nesta administrator.', 'nesta-dashboard' ); ?></p>
				<?php elseif ( empty( $templates ) ) : ?>
					<p><?php esc_html_e( 'Template packs are not available yet. Add one to the templates directory to begin.', 'nesta-dashboard' ); ?></p>
				<?php elseif ( empty( $selected_template ) || ! isset( $templates[ $selected_template ] ) ) : ?>
					<p><?php esc_html_e( 'Pick a template on Step 1 before customizing the colors and content.', 'nesta-dashboard' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'qs_step', 'template', $base_url ) ); ?>"><?php esc_html_e( 'Go to Step 1', 'nesta-dashboard' ); ?></a>
				<?php else : ?>
					<?php $active_template = $templates[ $selected_template ]; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-quick-start__form nesta-progress-form" enctype="multipart/form-data">
						<input type="hidden" name="action" value="nesta_quick_start" />
						<input type="hidden" name="template_id" value="<?php echo esc_attr( $selected_template ); ?>" />
						<?php wp_nonce_field( 'nesta_quick_start' ); ?>
						<div class="nesta-customize-layout">
							<div class="nesta-customize-layout__left">
								<?php $has_logo = ! empty( $brand_defaults['logo_id'] ); ?>
								<div class="nesta-field nesta-field--logo">
									<label for="nesta-brand-logo"><?php esc_html_e( 'Site logo', 'nesta-dashboard' ); ?></label>
									<input type="hidden" name="brand[logo_id]" value="<?php echo esc_attr( isset( $brand_defaults['logo_id'] ) ? (int) $brand_defaults['logo_id'] : 0 ); ?>" />
									<div class="nesta-logo-upload">
										<label for="nesta-brand-logo" class="nesta-logo-dropzone <?php echo $has_logo ? 'has-image' : ''; ?>">
											<?php if ( $has_logo ) : ?>
												<div class="nesta-logo-preview">
													<?php echo wp_get_attachment_image( (int) $brand_defaults['logo_id'], 'medium' ); ?>
												</div>
											<?php else : ?>
												<div class="nesta-logo-empty">
													<span class="dashicons dashicons-format-image"></span>
													<p><?php esc_html_e( 'Upload your logo', 'nesta-dashboard' ); ?></p>
												</div>
											<?php endif; ?>
										</label>
										<input type="file" id="nesta-brand-logo" name="brand_logo" accept="image/*" class="nesta-logo-upload__input" />
										<?php if ( $has_logo ) : ?>
											<label class="nesta-logo-remove">
												<input type="checkbox" name="brand[logo_remove]" value="1" />
												<?php esc_html_e( 'Remove current logo', 'nesta-dashboard' ); ?>
											</label>
										<?php endif; ?>
										<p class="description">
											<?php esc_html_e( 'Transparent PNG or SVG works best. Max 2 MB.', 'nesta-dashboard' ); ?>
										</p>
									</div>
								</div>
								<div class="nesta-form-grid">
									<div class="nesta-field nesta-field--color">
										<label for="nesta-primary-color"><?php esc_html_e( 'Primary color', 'nesta-dashboard' ); ?></label>
										<?php $primary_value = ! empty( $brand_defaults['primary_color'] ) ? $brand_defaults['primary_color'] : '#d65a16'; ?>
										<input type="color" id="nesta-primary-color" name="brand[primary_color]" value="<?php echo esc_attr( $primary_value ); ?>" class="nesta-color-picker__input" />
									</div>
									<div class="nesta-field nesta-field--color">
										<label for="nesta-secondary-color"><?php esc_html_e( 'Secondary color', 'nesta-dashboard' ); ?></label>
										<?php $secondary_value = ! empty( $brand_defaults['secondary_color'] ) ? $brand_defaults['secondary_color'] : '#1d4ed8'; ?>
										<input type="color" id="nesta-secondary-color" name="brand[secondary_color]" value="<?php echo esc_attr( $secondary_value ); ?>" class="nesta-color-picker__input" />
									</div>
									<div class="nesta-field nesta-field--color">
										<label for="nesta-accent-color"><?php esc_html_e( 'Accent color', 'nesta-dashboard' ); ?></label>
										<?php $accent_value = ! empty( $brand_defaults['accent_color'] ) ? $brand_defaults['accent_color'] : '#f97316'; ?>
										<input type="color" id="nesta-accent-color" name="brand[accent_color]" value="<?php echo esc_attr( $accent_value ); ?>" class="nesta-color-picker__input" />
									</div>
									<div class="nesta-field nesta-field--color">
										<label for="nesta-text-color"><?php esc_html_e( 'Text color', 'nesta-dashboard' ); ?></label>
										<?php $text_value = ! empty( $brand_defaults['text_color'] ) ? $brand_defaults['text_color'] : '#0f172a'; ?>
										<input type="color" id="nesta-text-color" name="brand[text_color]" value="<?php echo esc_attr( $text_value ); ?>" class="nesta-color-picker__input" />
									</div>
								</div>
							</div>
							<div class="nesta-customize-layout__right">
								<div class="nesta-template-card nesta-template-card--standalone nesta-template-card--active">
									<span class="nesta-template-card__body">
										<?php if ( ! empty( $active_template['screenshot_url'] ) ) : ?>
											<div class="nesta-template-card__preview">
												<img src="<?php echo esc_url( $active_template['screenshot_url'] ); ?>" alt="" loading="lazy" decoding="async" />
												<button type="button" class="nesta-template-preview-trigger" data-template-id="<?php echo esc_attr( $active_template['id'] ); ?>" data-template-name="<?php echo esc_attr( $active_template['name'] ); ?>" data-template-image="<?php echo esc_url( $active_template['screenshot_url'] ); ?>">
													<span class="dashicons dashicons-visibility"></span>
													<?php esc_html_e( 'Preview', 'nesta-dashboard' ); ?>
												</button>
											</div>
										<?php endif; ?>
										<strong><?php echo esc_html( $active_template['name'] ); ?></strong>
										<p><?php echo esc_html( $active_template['description'] ); ?></p>
									</span>
								</div>
							</div>
						</div>

						<div class="nesta-token-json">
							<label for="nesta-token-json"><?php esc_html_e( 'Paste website content in JSON format (optional)', 'nesta-dashboard' ); ?></label>
							<textarea id="nesta-token-json" name="token_json" rows="8" placeholder="<?php echo esc_attr( $token_placeholder ); ?>"><?php echo esc_textarea( $token_json_prefill ); ?></textarea>
							<p class="description"><?php esc_html_e( 'When provided, the JSON map will overwrite the individual fields below on save or generate.', 'nesta-dashboard' ); ?></p>
							<div class="nesta-token-json__actions">
								<button type="submit" name="quick_start_action" value="apply_json" class="button nesta-button--outline" data-progress="true" data-loading-text="<?php esc_attr_e( 'Applying…', 'nesta-dashboard' ); ?>">
									<?php esc_html_e( 'Apply page content', 'nesta-dashboard' ); ?>
								</button>
							</div>
						</div>
						<?php
						$primary_section_key = 'business';
						$primary_section     = isset( $token_schema[ $primary_section_key ] ) ? $token_schema[ $primary_section_key ] : null;
						$additional_sections = $token_schema;
						if ( $primary_section ) {
							unset( $additional_sections[ $primary_section_key ] );
						}
						?>

						<?php if ( $primary_section ) : ?>
							<div class="nesta-token-section nesta-token-section--primary">
								<h3><?php echo esc_html( $primary_section['title'] ); ?></h3>
								<div class="nesta-form-grid nesta-form-grid--tokens">
									<?php foreach ( $primary_section['tokens'] as $token_key => $label ) : ?>
										<div class="nesta-field">
											<label for="nesta-token-<?php echo esc_attr( $token_key ); ?>"><?php echo esc_html( $label ); ?></label>
											<input
												type="text"
												id="nesta-token-<?php echo esc_attr( $token_key ); ?>"
												name="tokens[<?php echo esc_attr( $token_key ); ?>]"
												value="<?php echo esc_attr( isset( $token_defaults[ $token_key ] ) ? $token_defaults[ $token_key ] : '' ); ?>"
											/>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<div class="nesta-token-toggle">
							<button type="button" class="button nesta-button--outline nesta-button--with-icon" data-token-toggle aria-expanded="false" data-label-collapsed="<?php esc_attr_e( 'Show more content fields', 'nesta-dashboard' ); ?>" data-label-expanded="<?php esc_attr_e( 'Hide extra content fields', 'nesta-dashboard' ); ?>">
								<span class="nesta-token-toggle__icon" aria-hidden="true">▾</span>
								<span class="nesta-token-toggle__label"><?php esc_html_e( 'Show more content fields', 'nesta-dashboard' ); ?></span>
							</button>
						</div>
						<div class="nesta-token-sections is-collapsed" data-token-sections>
							<?php foreach ( $additional_sections as $section ) : ?>
								<div class="nesta-token-section">
									<h3><?php echo esc_html( $section['title'] ); ?></h3>
									<div class="nesta-form-grid nesta-form-grid--tokens">
										<?php foreach ( $section['tokens'] as $token_key => $label ) : ?>
											<div class="nesta-field">
												<label for="nesta-token-<?php echo esc_attr( $token_key ); ?>"><?php echo esc_html( $label ); ?></label>
												<input
													type="text"
													id="nesta-token-<?php echo esc_attr( $token_key ); ?>"
													name="tokens[<?php echo esc_attr( $token_key ); ?>]"
													value="<?php echo esc_attr( isset( $token_defaults[ $token_key ] ) ? $token_defaults[ $token_key ] : '' ); ?>"
												/>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="nesta-quick-start__actions">
							<button type="submit" name="quick_start_action" value="save" class="button button-secondary nesta-button--save" data-progress="true" data-loading-text="<?php esc_attr_e( 'Saving…', 'nesta-dashboard' ); ?>">
								<span class="dashicons dashicons-download"></span>
								<span><?php esc_html_e( 'Save', 'nesta-dashboard' ); ?></span>
							</button>
							<span class="nesta-actions-separator"><?php esc_html_e( 'or', 'nesta-dashboard' ); ?></span>
							<button type="submit" name="quick_start_action" value="generate" class="button button-primary nesta-button nesta-button--launch" data-progress="true" data-loading-text="<?php esc_attr_e( 'Generating…', 'nesta-dashboard' ); ?>">
								<span class="nesta-button__sparkle" aria-hidden="true">✦</span>
								<span class="nesta-button__label"><?php esc_html_e( 'Generate Website', 'nesta-dashboard' ); ?></span>
							</button>
							<p><?php esc_html_e( 'Saving keeps your progress, generating will create/update the template pages.', 'nesta-dashboard' ); ?></p>
						</div>
					</form>
				<?php endif; ?>
			</section>
		<?php else : ?>
			<section class="nesta-panel nesta-panel--quickstart">
				<header class="nesta-panel__header">
					<div>
						<p class="nesta-eyebrow"><?php esc_html_e( 'Step 3', 'nesta-dashboard' ); ?></p>
						<h2><?php esc_html_e( 'Preview & launch.', 'nesta-dashboard' ); ?></h2>
						<p><?php esc_html_e( 'Take a victory lap and jump into the site. You can always rerun the wizard if you want to test another template.', 'nesta-dashboard' ); ?></p>
					</div>
					<?php if ( ! empty( $last_generation_pages ) ) : ?>
						<div class="nesta-panel__actions">
							<a class="button nesta-stepper-button nesta-button--with-icon" href="<?php echo esc_url( $view_site_url ); ?>" target="_blank" rel="noopener noreferrer">
								<span><?php esc_html_e( 'View site', 'nesta-dashboard' ); ?></span>
								<span class="dashicons dashicons-external"></span>
							</a>
						</div>
					<?php endif; ?>
				</header>
				<?php if ( empty( $last_generation_pages ) ) : ?>
					<p><?php esc_html_e( 'Generate the site on Step 2 to unlock the final review.', 'nesta-dashboard' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'qs_step', 'customize', $base_url ) ); ?>"><?php esc_html_e( 'Return to Step 2', 'nesta-dashboard' ); ?></a>
				<?php else : ?>
					<div class="nesta-launch-summary">
						<p>
							<?php
							printf(
								esc_html__( 'Latest run created or updated %1$d pages on %2$s.', 'nesta-dashboard' ),
								esc_html( count( $last_generation_pages ) ),
								$last_generation_time ? esc_html( $last_generation_time ) : esc_html__( 'the last run', 'nesta-dashboard' )
							);
							?>
						</p>
						<ul class="nesta-launch-list">
							<?php foreach ( $last_generation_pages as $page_id ) : ?>
								<?php
								$page = get_post( $page_id );
								if ( ! $page ) :
									continue;
								endif;
								?>
								<li>
									<strong><?php echo esc_html( $page->post_title ); ?></strong>
									<span>
										<a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'nesta-dashboard' ); ?></a>
										&middot;
										<a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php esc_html_e( 'Edit', 'nesta-dashboard' ); ?></a>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="nesta-launch-actions">
							<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Manage pages', 'nesta-dashboard' ); ?></a>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>"><?php esc_html_e( 'Open Customizer', 'nesta-dashboard' ); ?></a>
						</div>
						<p><?php esc_html_e( 'Want to try a different template? Use the reset button above to return to Step 1.', 'nesta-dashboard' ); ?></p>
					</div>
				<?php endif; ?>
			</section>
		<?php endif; ?>

	</div>
	<div class="nesta-reset-modal" aria-hidden="true">
		<div class="nesta-reset-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nesta-reset-title">
			<div class="nesta-reset-modal__icon">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<h3 id="nesta-reset-title"><?php esc_html_e( 'Reset Quick Start?', 'nesta-dashboard' ); ?></h3>
			<p><?php esc_html_e( 'This will erase your template install, color selections, token fields, and any generated pages so you can begin again.', 'nesta-dashboard' ); ?></p>
			<div class="nesta-reset-modal__actions">
				<button type="button" class="button button-secondary" data-reset-action="cancel"><?php esc_html_e( 'No, keep progress', 'nesta-dashboard' ); ?></button>
				<button type="button" class="button nesta-button--danger nesta-button--with-icon" data-reset-action="confirm">
					<span class="dashicons dashicons-update"></span>
					<span><?php esc_html_e( 'Yes, start over', 'nesta-dashboard' ); ?></span>
				</button>
			</div>
		</div>
	</div>
	<div class="nesta-template-modal" aria-hidden="true">
		<div class="nesta-template-modal__dialog" role="dialog" aria-modal="true">
			<header class="nesta-template-modal__header">
				<h3 id="nesta-template-modal-title"><?php esc_html_e( 'Template preview', 'nesta-dashboard' ); ?></h3>
				<button type="button" class="nesta-template-modal__close" aria-label="<?php esc_attr_e( 'Close preview', 'nesta-dashboard' ); ?>">&times;</button>
			</header>
			<div class="nesta-template-modal__body">
				<img src="" alt="" id="nesta-template-modal-image" />
			</div>
		</div>
	</div>
	<div class="nesta-loading-overlay" aria-hidden="true">
		<div class="nesta-loading-overlay__dialog" role="alert" aria-live="assertive">
			<div class="nesta-loading-overlay__spinner"></div>
			<p><?php esc_html_e( 'Working… please don’t refresh.', 'nesta-dashboard' ); ?></p>
		</div>
	</div>
	<script>
	(function() {
		const triggers = document.querySelectorAll('.nesta-template-preview-trigger');
		const resetModal = document.querySelector('.nesta-reset-modal');
		const resetForms = document.querySelectorAll('.nesta-reset-form');
		let pendingResetForm = null;

		if (resetModal && resetForms.length) {
			const cancelReset = resetModal.querySelector('[data-reset-action="cancel"]');
			const confirmReset = resetModal.querySelector('[data-reset-action="confirm"]');

			const openResetModal = () => {
				resetModal.classList.add('is-visible');
				resetModal.setAttribute('aria-hidden', 'false');
			};

			const closeResetModal = () => {
				resetModal.classList.remove('is-visible');
				resetModal.setAttribute('aria-hidden', 'true');
				pendingResetForm = null;
			};

			resetForms.forEach((form) => {
				form.addEventListener('submit', (event) => {
					if (form.dataset.confirmed === 'true') {
						form.dataset.confirmed = '';
						return;
					}

					event.preventDefault();
					pendingResetForm = form;
					openResetModal();
				});
			});

			if (cancelReset) {
				cancelReset.addEventListener('click', closeResetModal);
			}

			if (confirmReset) {
				confirmReset.addEventListener('click', () => {
					const targetForm = pendingResetForm;
					closeResetModal();

					if (targetForm) {
						targetForm.dataset.confirmed = 'true';
						targetForm.submit();
					}
				});
			}

			resetModal.addEventListener('click', (event) => {
				if (event.target === resetModal) {
					closeResetModal();
				}
			});

			document.addEventListener('keydown', (event) => {
				if (event.key === 'Escape' && resetModal.classList.contains('is-visible')) {
					closeResetModal();
				}
			});
		}

		const modal = document.querySelector('.nesta-template-modal');
		const modalImage = document.getElementById('nesta-template-modal-image');
		const modalTitle = document.getElementById('nesta-template-modal-title');
		const closeBtn = document.querySelector('.nesta-template-modal__close');

		if (!modal) {
			return;
		}

		const openModal = (name, image) => {
			modalImage.src = image;
			modalImage.alt = name;
			modalTitle.textContent = name;
			modal.classList.add('is-visible');
			modal.setAttribute('aria-hidden', 'false');
			closeBtn.focus();
		};

		const closeModal = () => {
			modal.classList.remove('is-visible');
			modal.setAttribute('aria-hidden', 'true');
			modalImage.src = '';
		};

		triggers.forEach((trigger) => {
			trigger.addEventListener('click', () => {
				const name = trigger.getAttribute('data-template-name') || '';
				const image = trigger.getAttribute('data-template-image') || '';
				openModal(name, image);
			});
		});

		closeBtn.addEventListener('click', closeModal);

		modal.addEventListener('click', (event) => {
			if (event.target === modal) {
				closeModal();
			}
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && modal.classList.contains('is-visible')) {
				closeModal();
			}
		});

		const backForm = document.getElementById('nesta-back-form');
		if (backForm) {
			const templateLink = document.querySelector('.nesta-stepper a[data-step="template"]');
			if (templateLink) {
				templateLink.addEventListener('click', (event) => {
					event.preventDefault();
					backForm.submit();
				});
			}
		}

		const colorInputs = document.querySelectorAll('.nesta-color-picker__input');
		colorInputs.forEach((input) => {
			input.addEventListener('input', () => {
				if (!input.value || !input.value.startsWith('#')) {
					return;
				}
				input.value = input.value.toLowerCase();
			});
		});

		const tokenToggleBtn = document.querySelector('[data-token-toggle]');
		const tokenSections = document.querySelector('[data-token-sections]');

		if (tokenToggleBtn && tokenSections) {
			const collapsedLabel = tokenToggleBtn.dataset.labelCollapsed || tokenToggleBtn.textContent;
			const expandedLabel = tokenToggleBtn.dataset.labelExpanded || collapsedLabel;

			const setExpanded = (isExpanded) => {
				tokenToggleBtn.setAttribute('aria-expanded', String(isExpanded));
				tokenSections.classList.toggle('is-collapsed', !isExpanded);
				const labelEl = tokenToggleBtn.querySelector('.nesta-token-toggle__label');
				const iconEl = tokenToggleBtn.querySelector('.nesta-token-toggle__icon');
				if (labelEl) {
					labelEl.textContent = isExpanded ? expandedLabel : collapsedLabel;
				}
				if (iconEl) {
					iconEl.textContent = isExpanded ? '▴' : '▾';
				}
			};

			tokenToggleBtn.addEventListener('click', () => {
				const current = tokenToggleBtn.getAttribute('aria-expanded') === 'true';
				setExpanded(!current);
			});
		}

		const overlay = document.querySelector('.nesta-loading-overlay');
		const progressForms = document.querySelectorAll('.nesta-progress-form');

		if (overlay && progressForms.length) {
			progressForms.forEach((form) => {
				form.addEventListener('submit', (event) => {
					if (event.defaultPrevented) {
						return;
					}

					const trigger = event.submitter;

					if (!trigger || !trigger.dataset.progress) {
						return;
					}

					overlay.classList.add('is-visible');

					const text = trigger.getAttribute('data-loading-text');
					if (text) {
						trigger.dataset.originalText = trigger.textContent;
						trigger.textContent = text;
					}
					trigger.classList.add('is-busy');
				});
			});
		}

		const dismissLabel = '<?php echo esc_js( __( 'Dismiss notification', 'nesta-dashboard' ) ); ?>';
		const alerts = document.querySelectorAll('.nesta-alert');

		if (alerts.length) {
			alerts.forEach((alert) => {
				if (alert.querySelector('.nesta-alert__close')) {
					return;
				}

				const closeBtn = document.createElement('button');
				closeBtn.type = 'button';
				closeBtn.className = 'nesta-alert__close';
				closeBtn.setAttribute('aria-label', dismissLabel);
				closeBtn.innerHTML = '&times;';

				closeBtn.addEventListener('click', () => {
					alert.classList.add('is-hidden');
					setTimeout(() => alert.remove(), 200);
				});

				alert.appendChild(closeBtn);
			});
		}
	})();
	</script>
	<?php
	}

	/**
	 * Render the single page builder wizard.
	 */
	public function render_page_builder_page() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to create pages.', 'nesta-dashboard' ) );
		}

		$blueprints = $this->page_blueprints;
		if ( empty( $blueprints ) ) {
			echo '<div class="wrap nesta-dashboard"><p>' . esc_html__( 'No page blueprints are available yet. Please add one to get started.', 'nesta-dashboard' ) . '</p></div>';
			return;
		}

		$user_id     = get_current_user_id();
		$state       = $this->get_page_builder_state( $user_id );
		$current     = isset( $state['blueprint'], $blueprints[ $state['blueprint'] ] ) ? $state['blueprint'] : key( $blueprints );
		$blueprint   = $blueprints[ $current ];
		$notice_key  = isset( $_GET['page_builder'] ) ? sanitize_key( wp_unslash( $_GET['page_builder'] ) ) : '';
		$notice_text = isset( $_GET['page_builder_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['page_builder_message'] ) ) ) : '';
		$created_id  = isset( $_GET['created_page'] ) ? absint( $_GET['created_page'] ) : 0;

		$token_values = array();
		foreach ( $blueprint['tokens'] as $token_key => $label ) {
			$token_values[ $token_key ] = isset( $state['tokens'][ $token_key ] ) ? $state['tokens'][ $token_key ] : '';
		}

		?>
		<div class="wrap nesta-dashboard nesta-page-builder">
			<?php if ( 'prefilled' === $notice_key ) : ?>
				<div class="nesta-alert nesta-alert--success">
					<strong><?php esc_html_e( 'Website content applied.', 'nesta-dashboard' ); ?></strong>
					<p><?php esc_html_e( 'Review the fields below and make any adjustments before generating the page.', 'nesta-dashboard' ); ?></p>
				</div>
			<?php elseif ( 'created' === $notice_key && $created_id ) : ?>
				<?php $created_page = get_post( $created_id ); ?>
				<?php if ( $created_page ) : ?>
					<div class="nesta-alert nesta-alert--success nesta-alert--with-actions">
						<div class="nesta-alert__content">
							<strong><?php esc_html_e( 'Page created successfully.', 'nesta-dashboard' ); ?></strong>
							<p><?php esc_html_e( 'Dial in the copy from the editor or preview the live layout to double-check styling.', 'nesta-dashboard' ); ?></p>
						</div>
						<div class="nesta-alert__actions">
							<a class="button nesta-stepper-button nesta-button--with-icon" href="<?php echo esc_url( get_edit_post_link( $created_page ) ); ?>">
								<span><?php esc_html_e( 'Edit page', 'nesta-dashboard' ); ?></span>
								<span class="dashicons dashicons-edit-page" aria-hidden="true"></span>
							</a>
							<a class="button nesta-stepper-button nesta-button--with-icon" href="<?php echo esc_url( get_permalink( $created_page ) ); ?>" target="_blank" rel="noopener noreferrer">
								<span><?php esc_html_e( 'View page', 'nesta-dashboard' ); ?></span>
								<span class="dashicons dashicons-external" aria-hidden="true"></span>
							</a>
						</div>
					</div>
				<?php endif; ?>
			<?php elseif ( 'error' === $notice_key && $notice_text ) : ?>
				<div class="nesta-alert nesta-alert--error">
					<strong><?php esc_html_e( 'Unable to continue.', 'nesta-dashboard' ); ?></strong>
					<p><?php echo esc_html( $notice_text ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nesta-page-builder__form nesta-progress-form">
				<input type="hidden" name="action" value="nesta_create_page" />
				<?php wp_nonce_field( 'nesta_create_page' ); ?>

				<section class="nesta-panel">
					<header class="nesta-panel__header">
						<div>
							<p class="nesta-eyebrow"><?php esc_html_e( 'Step 1', 'nesta-dashboard' ); ?></p>
							<h2><?php esc_html_e( 'Choose a blueprint', 'nesta-dashboard' ); ?></h2>
							<p><?php esc_html_e( 'Select the type of page you want to launch. Each blueprint uses Nesta styling and token placeholders.', 'nesta-dashboard' ); ?></p>
						</div>
					</header>
					<div class="nesta-template-grid">
						<?php foreach ( $blueprints as $blueprint_id => $candidate ) : ?>
							<label class="nesta-template-card">
								<input type="radio" name="page_blueprint" value="<?php echo esc_attr( $blueprint_id ); ?>" <?php checked( $current, $blueprint_id ); ?> />
								<span class="nesta-template-card__body">
									<strong><?php echo esc_html( $candidate['label'] ); ?></strong>
									<p><?php echo esc_html( $candidate['description'] ); ?></p>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="nesta-panel">
					<header class="nesta-panel__header">
						<div>
							<p class="nesta-eyebrow"><?php esc_html_e( 'Step 2', 'nesta-dashboard' ); ?></p>
							<h2><?php esc_html_e( 'Page details & content', 'nesta-dashboard' ); ?></h2>
							<p><?php esc_html_e( 'Name the page, decide the URL, and fill in the blueprint tokens. Paste JSON to speed things up.', 'nesta-dashboard' ); ?></p>
						</div>
					</header>
					<div class="nesta-form-grid">
						<div class="nesta-field">
							<label for="nesta-page-title"><?php esc_html_e( 'Page title', 'nesta-dashboard' ); ?></label>
							<input type="text" id="nesta-page-title" name="page_title" value="<?php echo esc_attr( $state['page_title'] ); ?>" placeholder="<?php esc_attr_e( 'e.g., EV Charger Installation', 'nesta-dashboard' ); ?>" />
						</div>
						<div class="nesta-field">
							<label for="nesta-page-slug"><?php esc_html_e( 'Page slug (optional)', 'nesta-dashboard' ); ?></label>
							<input type="text" id="nesta-page-slug" name="page_slug" value="<?php echo esc_attr( $state['page_slug'] ); ?>" placeholder="<?php esc_attr_e( 'ev-charger-installation', 'nesta-dashboard' ); ?>" />
						</div>
						<div class="nesta-field">
							<label for="nesta-page-status"><?php esc_html_e( 'Publish status', 'nesta-dashboard' ); ?></label>
							<select id="nesta-page-status" name="page_status">
								<option value="draft" <?php selected( 'draft', $state['page_status'] ); ?>><?php esc_html_e( 'Draft', 'nesta-dashboard' ); ?></option>
								<option value="publish" <?php selected( 'publish', $state['page_status'] ); ?>><?php esc_html_e( 'Publish immediately', 'nesta-dashboard' ); ?></option>
							</select>
						</div>
					</div>

					<div class="nesta-token-json">
						<label for="nesta-page-token-json"><?php esc_html_e( 'Paste website content in JSON format (optional)', 'nesta-dashboard' ); ?></label>
						<textarea id="nesta-page-token-json" name="token_json" rows="6" placeholder="<?php esc_attr_e( '{"service__name": "Panel upgrades", "service__hero_desc": "Short pitch"}', 'nesta-dashboard' ); ?>"><?php echo esc_textarea( $state['token_json'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Tokens that match this blueprint will populate automatically.', 'nesta-dashboard' ); ?></p>
						<div class="nesta-token-json__actions">
							<button type="submit" name="page_builder_action" value="apply_json" class="button nesta-button--outline" data-progress="true" data-loading-text="<?php esc_attr_e( 'Applying…', 'nesta-dashboard' ); ?>">
								<?php esc_html_e( 'Apply page content', 'nesta-dashboard' ); ?>
							</button>
						</div>
					</div>

					<div class="nesta-token-section nesta-token-section--primary">
						<h3><?php esc_html_e( 'Content fields', 'nesta-dashboard' ); ?></h3>
						<div class="nesta-form-grid nesta-form-grid--tokens">
							<?php foreach ( $blueprint['tokens'] as $token_key => $token_label ) : ?>
								<div class="nesta-field">
									<label for="builder-token-<?php echo esc_attr( $token_key ); ?>"><?php echo esc_html( $token_label ); ?></label>
									<textarea id="builder-token-<?php echo esc_attr( $token_key ); ?>" name="tokens[<?php echo esc_attr( $token_key ); ?>]" rows="2"><?php echo esc_textarea( $token_values[ $token_key ] ); ?></textarea>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>

				<div class="nesta-builder-actions">
					<button type="submit" name="page_builder_action" value="create" class="button button-primary nesta-button nesta-button--launch" data-progress="true" data-loading-text="<?php esc_attr_e( 'Creating…', 'nesta-dashboard' ); ?>">
						<span class="nesta-button__sparkle" aria-hidden="true">✦</span>
						<span class="nesta-button__label"><?php esc_html_e( 'Create page', 'nesta-dashboard' ); ?></span>
					</button>
				</div>
			</form>
			<div class="nesta-loading-overlay" aria-hidden="true">
				<div class="nesta-loading-overlay__dialog" role="alert" aria-live="assertive">
					<div class="nesta-loading-overlay__spinner"></div>
					<p><?php esc_html_e( 'Working… please don’t refresh.', 'nesta-dashboard' ); ?></p>
				</div>
			</div>
		</div>
		<script>
		(function() {
			const overlay = document.querySelector('.nesta-loading-overlay');
			const forms = document.querySelectorAll('.nesta-progress-form');

			if (!overlay || !forms.length) {
				return;
			}

			forms.forEach((form) => {
				form.addEventListener('submit', (event) => {
					if (event.defaultPrevented) {
						return;
					}

					const trigger = event.submitter;

					if (!trigger || !trigger.dataset.progress) {
						return;
					}

					overlay.classList.add('is-visible');

					const loadingText = trigger.getAttribute('data-loading-text');
					const labelSpan = trigger.querySelector('.nesta-button__label');

					if (loadingText && labelSpan) {
						labelSpan.dataset.originalText = labelSpan.textContent;
						labelSpan.textContent = loadingText;
					} else if (loadingText) {
						trigger.dataset.originalText = trigger.textContent;
						trigger.textContent = loadingText;
					}

					trigger.classList.add('is-busy');
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle checklist form submissions.
	 */
	public function handle_checklist_action() {
		if ( ! current_user_can( 'read' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_dashboard_checklist' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$mode    = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'save';
		$user_id = get_current_user_id();

		switch ( $mode ) {
			case 'complete':
				update_user_meta( $user_id, 'nesta_dashboard_checklist_completed', wp_list_pluck( $this->launch_checklist, 'slug' ) );
				break;
			case 'dismiss':
				update_user_meta( $user_id, 'nesta_dashboard_checklist_dismissed', 1 );
				break;
			case 'restore':
				delete_user_meta( $user_id, 'nesta_dashboard_checklist_dismissed' );
				break;
			case 'save':
			default:
				$items = isset( $_POST['checklist_items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['checklist_items'] ) ) : array();
				update_user_meta( $user_id, 'nesta_dashboard_checklist_completed', $items );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '#nesta-checklist' ) );
		exit;
	}

	/**
	 * Get completed checklist items.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_checklist_completed( $user_id ) {
		$completed = get_user_meta( $user_id, 'nesta_dashboard_checklist_completed', true );
		if ( ! is_array( $completed ) ) {
			return array();
		}
		return array_values( array_unique( $completed ) );
	}

	/**
	 * Handle submissions from the quick start wizard.
	 */
	public function handle_quick_start_request() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the quick start.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_quick_start' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nesta-dashboard' ) );
		}

		$action_mode = isset( $_POST['quick_start_action'] ) ? sanitize_key( wp_unslash( $_POST['quick_start_action'] ) ) : 'save';
		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		$templates   = $this->template_registry->get_templates();

		if ( $template_id && ! isset( $templates[ $template_id ] ) ) {
			$template_id = '';
		}

		$brand_input = isset( $_POST['brand'] ) ? (array) wp_unslash( $_POST['brand'] ) : array();

		$logo_error  = '';
		$logo_id     = isset( $brand_input['logo_id'] ) ? absint( $brand_input['logo_id'] ) : 0;
		$logo_remove = ! empty( $brand_input['logo_remove'] );

		if ( ! empty( $_FILES['brand_logo']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$uploaded_logo = media_handle_upload( 'brand_logo', 0 );

			if ( is_wp_error( $uploaded_logo ) ) {
				$logo_error = sprintf(
					/* translators: %s: upload error message */
					__( 'Logo upload failed: %s', 'nesta-dashboard' ),
					$uploaded_logo->get_error_message()
				);
			} else {
				$logo_id = (int) $uploaded_logo;
			}
		} elseif ( $logo_remove ) {
			$logo_id = 0;
		} elseif ( 0 === $logo_id ) {
			$saved = get_user_meta( get_current_user_id(), 'nesta_quick_start_last_submission', true );
			if ( is_array( $saved ) && ! empty( $saved['brand']['logo_id'] ) ) {
				$logo_id = (int) $saved['brand']['logo_id'];
			}
		}

		$brand = array(
			'primary_color'   => $this->sanitize_hex_value( isset( $brand_input['primary_color'] ) ? $brand_input['primary_color'] : '', '#d65a16' ),
			'secondary_color' => $this->sanitize_hex_value( isset( $brand_input['secondary_color'] ) ? $brand_input['secondary_color'] : '', '#1d4ed8' ),
			'accent_color'    => $this->sanitize_hex_value( isset( $brand_input['accent_color'] ) ? $brand_input['accent_color'] : '', '#0f172a' ),
			'text_color'      => $this->sanitize_hex_value( isset( $brand_input['text_color'] ) ? $brand_input['text_color'] : '', '#0f172a' ),
			'heading_font'    => sanitize_text_field( isset( $brand_input['heading_font'] ) ? $brand_input['heading_font'] : '' ),
			'body_font'       => sanitize_text_field( isset( $brand_input['body_font'] ) ? $brand_input['body_font'] : '' ),
			'logo_id'         => $logo_id,
		);

		$token_input     = isset( $_POST['tokens'] ) ? (array) wp_unslash( $_POST['tokens'] ) : array();
		$token_keys      = $this->get_token_keys();
		$token_json_raw  = isset( $_POST['token_json'] ) ? wp_unslash( $_POST['token_json'] ) : '';
		$json_error      = '';
		$tokens          = array();

		foreach ( $token_keys as $key ) {
			$value          = isset( $token_input[ $key ] ) ? $token_input[ $key ] : '';
			$tokens[ $key ] = $this->sanitize_token_value( $key, $value );
		}

		if ( $token_json_raw ) {
			$decoded = json_decode( $token_json_raw, true );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $json_key => $json_value ) {
					$normalized = $this->normalize_token_key( $json_key );

					if ( in_array( $normalized, $token_keys, true ) ) {
						$tokens[ $normalized ] = $this->sanitize_token_value( $normalized, $json_value );
					}
				}
			} else {
				$json_error = __( 'Unable to parse the token JSON. Please check the formatting and try again.', 'nesta-dashboard' );
			}
		}

		if ( empty( $tokens['business_name'] ) ) {
			$tokens['business_name'] = get_bloginfo( 'name', 'display' );
		}

		if ( empty( $tokens['contact_email'] ) ) {
			$tokens['contact_email'] = get_option( 'admin_email' );
		}

		$payload = array(
			'template_id' => $template_id,
			'brand'       => $brand,
			'tokens'      => $tokens,
			'token_json'  => $token_json_raw,
		);

		update_user_meta( get_current_user_id(), 'nesta_quick_start_last_submission', $payload );
		update_option(
			'nesta_quick_start_last_branding',
			array_merge(
				$payload,
				array(
					'updated_at' => current_time( 'mysql' ),
				)
			)
		);

		$query_args = array();
		$next_step  = 'customize';

		switch ( $action_mode ) {
			case 'apply_json':
				$query_args['nesta_quick_start'] = 'prefilled';
				break;
			case 'save':
			default:
				$query_args['nesta_quick_start'] = 'saved';
				break;
		}

		if ( $logo_error ) {
			$query_args = array(
				'nesta_quick_start'         => 'error',
				'nesta_quick_start_message' => rawurlencode( $logo_error ),
			);
		} elseif ( $json_error ) {
			$query_args = array(
				'nesta_quick_start'         => 'json_error',
				'nesta_quick_start_message' => rawurlencode( $json_error ),
			);
		} elseif ( 'generate' === $action_mode ) {
			$result = $this->generate_site_from_template( $template_id, $tokens );

			if ( is_wp_error( $result ) ) {
				$query_args = array(
					'nesta_quick_start'          => 'error',
					'nesta_quick_start_message'  => rawurlencode( $result->get_error_message() ),
				);
			} else {
				$query_args = array(
					'nesta_quick_start'          => 'generated',
					'nesta_quick_start_created'  => isset( $result['created'] ) ? (int) $result['created'] : 0,
					'nesta_quick_start_updated'  => isset( $result['updated'] ) ? (int) $result['updated'] : 0,
				);
				$this->apply_site_identity( $tokens, $brand );
				$this->apply_brand_palette( $brand );
				$next_step = 'launch';
			}
		}

		$query_args['qs_step'] = $next_step;

		$redirect = add_query_arg(
			$query_args,
			admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle manual template sync.
	 */
	public function handle_template_sync() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to sync templates.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_sync_templates' ) ) {
			wp_die( esc_html__( 'Template sync nonce check failed.', 'nesta-dashboard' ) );
		}

		$result = $this->sync_remote_templates();

		$query_args = array(
			'page'    => self::QUICK_START_SLUG,
			'qs_step' => 'template',
		);

		if ( is_wp_error( $result ) ) {
			$query_args['nesta_template_sync'] = 'error';
			$query_args['nesta_template_sync_message'] = rawurlencode( $result->get_error_message() );
		} else {
			$query_args['nesta_template_sync'] = 'success';
			$query_args['nesta_template_sync_added'] = isset( $result['added'] ) ? (int) $result['added'] : 0;
			$query_args['nesta_template_sync_updated'] = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
			$query_args['nesta_template_sync_skipped'] = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
			$query_args['nesta_template_sync_failed'] = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
		}

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle MU plugin update checks.
	 */
	public function handle_mu_plugin_update_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for updates.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_mu_plugin_check' ) ) {
			wp_die( esc_html__( 'MU plugin update nonce check failed.', 'nesta-dashboard' ) );
		}

		$manifest = $this->fetch_mu_plugin_manifest();
		$query_args = array(
			'page' => self::SETTINGS_SLUG,
		);

		if ( is_wp_error( $manifest ) ) {
			$query_args['nesta_mu_plugin_update'] = 'error';
			$query_args['nesta_mu_plugin_update_message'] = rawurlencode( $manifest->get_error_message() );
			wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$current_version = self::PLUGIN_VERSION;
		$update_available = version_compare( $manifest['version'], $current_version, '>' );

		$this->set_mu_plugin_update_state(
			array(
				'checked_at'      => current_time( 'mysql' ),
				'current_version' => $current_version,
				'remote_version'  => $manifest['version'],
				'zip_url'         => $manifest['zip_url'],
				'sha256'          => $manifest['sha256'],
				'size'            => $manifest['size'],
				'update_available'=> $update_available,
			)
		);

		$query_args['nesta_mu_plugin_update'] = $update_available ? 'available' : 'current';
		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle MU plugin updates.
	 */
	public function handle_mu_plugin_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update the plugin.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_mu_plugin_update' ) ) {
			wp_die( esc_html__( 'MU plugin update nonce check failed.', 'nesta-dashboard' ) );
		}

		$manifest = $this->fetch_mu_plugin_manifest();
		$query_args = array(
			'page' => self::SETTINGS_SLUG,
		);

		if ( is_wp_error( $manifest ) ) {
			$query_args['nesta_mu_plugin_update'] = 'error';
			$query_args['nesta_mu_plugin_update_message'] = rawurlencode( $manifest->get_error_message() );
			wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( version_compare( $manifest['version'], self::PLUGIN_VERSION, '<=' ) ) {
			$query_args['nesta_mu_plugin_update'] = 'current';
			wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$result = $this->apply_mu_plugin_update( $manifest );

		if ( is_wp_error( $result ) ) {
			$query_args['nesta_mu_plugin_update'] = 'error';
			$query_args['nesta_mu_plugin_update_message'] = rawurlencode( $result->get_error_message() );
			wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$this->set_mu_plugin_update_state(
			array(
				'checked_at'      => current_time( 'mysql' ),
				'current_version' => $manifest['version'],
				'remote_version'  => $manifest['version'],
				'zip_url'         => $manifest['zip_url'],
				'sha256'          => $manifest['sha256'],
				'size'            => $manifest['size'],
				'update_available'=> false,
				'last_update'     => current_time( 'mysql' ),
			)
		);

		$query_args['nesta_mu_plugin_update'] = 'updated';
		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle template bundle installation.
	 */
	public function handle_template_install() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to install templates.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_quick_start_install' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nesta-dashboard' ) );
		}

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		$result      = $this->install_template_bundle( $template_id );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'qs_step'               => 'template',
					'nesta_install'         => 'error',
					'nesta_install_message' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$snapshot = get_user_meta( get_current_user_id(), 'nesta_quick_start_last_submission', true );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		$snapshot['template_id'] = $template_id;
		$template_data = $this->template_registry->get_template( $template_id );
		if ( $template_data ) {
			$snapshot['brand'] = $this->apply_template_brand_defaults( $this->get_default_brand_palette(), $template_data );
		}
		update_user_meta( get_current_user_id(), 'nesta_quick_start_last_submission', $snapshot );

		$redirect = add_query_arg(
			array(
				'qs_step'              => 'customize',
				'nesta_install'        => 'success',
				'nesta_install_created'=> isset( $result['created'] ) ? (int) $result['created'] : 0,
				'nesta_install_updated'=> isset( $result['updated'] ) ? (int) $result['updated'] : 0,
			),
			admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Fetch the MU plugin update manifest.
	 *
	 * @return array|WP_Error
	 */
	private function fetch_mu_plugin_manifest() {
		$manifest_url = $this->get_mu_plugin_manifest_url();
		if ( ! $manifest_url ) {
			return new WP_Error( 'nesta_mu_manifest_missing', __( 'MU plugin update manifest is not configured.', 'nesta-dashboard' ) );
		}

		$response = wp_remote_get(
			$manifest_url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'nesta_mu_manifest_fetch_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'nesta_mu_manifest_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Manifest request failed with status %d.', 'nesta-dashboard' ),
					(int) $code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'nesta_mu_manifest_invalid', __( 'Manifest response was not valid JSON.', 'nesta-dashboard' ) );
		}

		$version = isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '';
		$zip     = isset( $data['zip'] ) ? sanitize_text_field( $data['zip'] ) : '';
		$sha256  = isset( $data['sha256'] ) ? sanitize_text_field( $data['sha256'] ) : '';
		$size    = isset( $data['size'] ) ? (int) $data['size'] : 0;

		if ( ! $version || ! $zip ) {
			return new WP_Error( 'nesta_mu_manifest_missing_fields', __( 'Manifest is missing required fields.', 'nesta-dashboard' ) );
		}

		$zip_url = $this->resolve_mu_plugin_zip_url( $zip, $manifest_url );
		if ( ! $zip_url ) {
			return new WP_Error( 'nesta_mu_manifest_zip_missing', __( 'Manifest zip path could not be resolved.', 'nesta-dashboard' ) );
		}

		return array(
			'version' => $version,
			'zip_url' => $zip_url,
			'sha256'  => $sha256,
			'size'    => $size,
		);
	}

	/**
	 * Resolve a zip path to an absolute URL.
	 *
	 * @param string $zip          Zip path from manifest.
	 * @param string $manifest_url Manifest URL.
	 * @return string
	 */
	private function resolve_mu_plugin_zip_url( $zip, $manifest_url ) {
		if ( ! $zip ) {
			return '';
		}

		if ( filter_var( $zip, FILTER_VALIDATE_URL ) ) {
			return $zip;
		}

		if ( ! $manifest_url ) {
			return '';
		}

		return trailingslashit( dirname( $manifest_url ) ) . ltrim( $zip, '/' );
	}

	/**
	 * Apply an MU plugin update from a manifest payload.
	 *
	 * @param array $manifest Manifest payload.
	 * @return array|WP_Error
	 */
	private function apply_mu_plugin_update( $manifest ) {
		if ( empty( $manifest['zip_url'] ) ) {
			return new WP_Error( 'nesta_mu_update_missing', __( 'Update package URL is missing.', 'nesta-dashboard' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_file = download_url( $manifest['zip_url'], 60 );
		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error( 'nesta_mu_download_failed', $temp_file->get_error_message() );
		}

		if ( ! empty( $manifest['sha256'] ) ) {
			$hash = hash_file( 'sha256', $temp_file );
			if ( ! $hash || strtolower( $hash ) !== strtolower( $manifest['sha256'] ) ) {
				@unlink( $temp_file );
				return new WP_Error( 'nesta_mu_checksum_failed', __( 'Update package checksum did not match.', 'nesta-dashboard' ) );
			}
		}

		$temp_dir = trailingslashit( get_temp_dir() ) . 'nesta-mu-update-' . wp_generate_uuid4();
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'nesta_mu_temp_failed', __( 'Unable to create a temporary directory for the update.', 'nesta-dashboard' ) );
		}

		$unzipped = unzip_file( $temp_file, $temp_dir );
		@unlink( $temp_file );

		if ( is_wp_error( $unzipped ) ) {
			$this->delete_template_dir( $temp_dir );
			return new WP_Error( 'nesta_mu_unzip_failed', $unzipped->get_error_message() );
		}

		$source_dir    = trailingslashit( $temp_dir ) . 'nesta-dashboard';
		$source_loader = trailingslashit( $temp_dir ) . 'nesta-dashboard.php';

		if ( ! is_dir( $source_dir ) ) {
			$this->delete_template_dir( $temp_dir );
			return new WP_Error( 'nesta_mu_payload_invalid', __( 'Update package is missing the plugin folder.', 'nesta-dashboard' ) );
		}

		$filesystem_ready = WP_Filesystem();
		if ( ! $filesystem_ready ) {
			$this->delete_template_dir( $temp_dir );
			return new WP_Error( 'nesta_mu_filesystem_failed', __( 'Unable to initialize filesystem for the update.', 'nesta-dashboard' ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			$this->delete_template_dir( $temp_dir );
			return new WP_Error( 'nesta_mu_filesystem_missing', __( 'Filesystem handler is unavailable.', 'nesta-dashboard' ) );
		}

		// Preserve shared uploads before swapping the plugin folder.
		$this->ensure_shared_uploads_asset();

		$mu_dir     = trailingslashit( WPMU_PLUGIN_DIR );
		$target_dir = $mu_dir . 'nesta-dashboard';
		$backup_dir = $mu_dir . 'nesta-dashboard.backup-' . gmdate( 'Ymd-His' );

		if ( $wp_filesystem->is_dir( $target_dir ) ) {
			if ( ! $wp_filesystem->move( $target_dir, $backup_dir, true ) ) {
				$this->delete_template_dir( $temp_dir );
				return new WP_Error( 'nesta_mu_backup_failed', __( 'Unable to backup the existing plugin.', 'nesta-dashboard' ) );
			}
		}

		if ( ! $wp_filesystem->move( $source_dir, $target_dir, true ) ) {
			$copied = copy_dir( $source_dir, $target_dir );
			if ( is_wp_error( $copied ) ) {
				if ( $wp_filesystem->is_dir( $backup_dir ) ) {
					$wp_filesystem->move( $backup_dir, $target_dir, true );
				}
				$this->delete_template_dir( $temp_dir );
				return new WP_Error( 'nesta_mu_replace_failed', $copied->get_error_message() );
			}
		}

		if ( ! $wp_filesystem->is_dir( $target_dir ) ) {
			if ( $wp_filesystem->is_dir( $backup_dir ) ) {
				$wp_filesystem->move( $backup_dir, $target_dir, true );
			}
			$this->delete_template_dir( $temp_dir );
			return new WP_Error( 'nesta_mu_replace_failed', __( 'Unable to replace the MU plugin folder.', 'nesta-dashboard' ) );
		}

		if ( $wp_filesystem->exists( $source_loader ) ) {
			if ( ! $wp_filesystem->move( $source_loader, $mu_dir . 'nesta-dashboard.php', true ) ) {
				$wp_filesystem->copy( $source_loader, $mu_dir . 'nesta-dashboard.php', true );
			}
		}

		$this->delete_template_dir( $temp_dir );

		return array(
			'backup_dir' => $backup_dir,
		);
	}

	/**
	 * Undo the last template generation by deleting created pages.
	 */
	public function handle_quick_start_undo() {
		if ( ! current_user_can( 'delete_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to undo generations.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_quick_start_undo' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nesta-dashboard' ) );
		}

		$redirect_step = isset( $_POST['redirect_step'] ) ? sanitize_key( wp_unslash( $_POST['redirect_step'] ) ) : '';
		$valid_steps   = array( 'template', 'customize' );
		if ( ! in_array( $redirect_step, $valid_steps, true ) ) {
			$redirect_step = '';
		}

		$last_run = get_option( 'nesta_quick_start_last_generation', array() );
		$page_ids = ( isset( $last_run['page_ids'] ) && is_array( $last_run['page_ids'] ) ) ? array_map( 'intval', $last_run['page_ids'] ) : array();

		if ( empty( $page_ids ) ) {
			$query_args = array(
				'nesta_quick_start' => 'undo_empty',
			);
			if ( $redirect_step ) {
				$query_args['qs_step'] = $redirect_step;
			}

			$redirect = add_query_arg(
				$query_args,
				admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$removed = $this->purge_quick_start_pages( $page_ids );

		update_option( 'nesta_quick_start_last_generation', array() );

		$query_args = array(
			'nesta_quick_start'         => 'undo',
			'nesta_quick_start_removed' => $removed,
		);

		if ( $redirect_step ) {
			$query_args['qs_step'] = $redirect_step;
		}

		$redirect = add_query_arg(
			$query_args,
			admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build default quick start state for the form.
	 *
	 * @param int   $user_id   User identifier.
	 * @param array $templates Available templates.
	 * @return array
	 */
	private function get_quick_start_defaults( $user_id, $templates ) {
		$token_keys = $this->get_token_keys();
		$token_defaults = array();
		foreach ( $token_keys as $token_key ) {
			$token_defaults[ $token_key ] = '';
		}

		$brand_seed = $this->get_default_brand_palette();

		$defaults = array(
			'template_id' => '',
			'brand'       => $brand_seed,
			'tokens'      => $token_defaults,
			'token_json'  => '',
		);

		if ( ! empty( $templates ) ) {
			$templates_copy = $templates;
			$first_template = reset( $templates_copy );

			if ( $first_template ) {
				$defaults['template_id'] = isset( $first_template['id'] ) ? $first_template['id'] : '';
				$defaults['brand'] = $this->apply_template_brand_defaults( $brand_seed, $first_template );
			}
		}

		$saved = get_user_meta( $user_id, 'nesta_quick_start_last_submission', true );
		if ( is_array( $saved ) ) {
			if ( ! empty( $saved['template_id'] ) ) {
				$defaults['template_id'] = $saved['template_id'];
			}

			if ( $defaults['template_id'] && isset( $templates[ $defaults['template_id'] ] ) ) {
				$template_ref     = $templates[ $defaults['template_id'] ];
				$template_default = $this->apply_template_brand_defaults( $brand_seed, $template_ref );
				$defaults['brand'] = $template_default;
			}

			if ( ! empty( $saved['brand'] ) && is_array( $saved['brand'] ) ) {
				$defaults['brand'] = wp_parse_args( $saved['brand'], $defaults['brand'] );
			}

			if ( ! empty( $saved['tokens'] ) && is_array( $saved['tokens'] ) ) {
				$defaults['tokens'] = wp_parse_args( $saved['tokens'], $defaults['tokens'] );
			}

			if ( isset( $saved['token_json'] ) ) {
				$defaults['token_json'] = (string) $saved['token_json'];
			}
		}

		return $defaults;
	}

	/**
	 * Retrieve the persisted page builder state for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_page_builder_state( $user_id ) {
		$blueprints        = $this->page_blueprints;
		$default_blueprint = $blueprints ? key( $blueprints ) : '';
		$state             = get_user_meta( $user_id, 'nesta_page_builder_state', true );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state = wp_parse_args(
			$state,
			array(
				'blueprint'   => $default_blueprint,
				'page_title'  => '',
				'page_slug'   => '',
				'page_status' => 'draft',
				'token_json'  => '',
				'tokens'      => array(),
			)
		);

		if ( empty( $state['blueprint'] ) || ! isset( $blueprints[ $state['blueprint'] ] ) ) {
			$state['blueprint'] = $default_blueprint;
		}

		if ( ! in_array( $state['page_status'], array( 'draft', 'publish' ), true ) ) {
			$state['page_status'] = 'draft';
		}

		if ( ! is_array( $state['tokens'] ) ) {
			$state['tokens'] = array();
		}

		return $state;
	}

	/**
	 * Persist page builder state in user meta.
	 *
	 * @param int   $user_id User ID.
	 * @param array $state   Builder state.
	 */
	private function set_page_builder_state( $user_id, $state ) {
		update_user_meta( $user_id, 'nesta_page_builder_state', $state );
	}

	/**
	 * Sanitize a hex color value and provide fallback.
	 *
	 * @param string $value    Raw value.
	 * @param string $fallback Fallback color.
	 * @return string
	 */
	private function sanitize_hex_value( $value, $fallback = '' ) {
		$sanitized = sanitize_hex_color( $value );

		if ( $sanitized ) {
			return $sanitized;
		}

		$no_hash = sanitize_hex_color_no_hash( $value );

		if ( $no_hash ) {
			return '#' . $no_hash;
		}

		return $fallback;
	}

	/**
	 * Extract the global palette structure from customizer data.
	 *
	 * @param array $section Customizer section payload.
	 * @return array|null
	 */
	private function extract_global_palette( $section ) {
		if ( isset( $section['global-color-palette'] ) && is_array( $section['global-color-palette'] ) ) {
			return $this->normalize_global_palette_structure( $section['global-color-palette'] );
		}

		if ( isset( $section['astra-color-palettes'] ) && is_array( $section['astra-color-palettes'] ) ) {
			return $this->convert_astra_palette_to_global( $section['astra-color-palettes'] );
		}

		return null;
	}

	/**
	 * Ensure a palette array only contains valid color values.
	 *
	 * @param array $palette Palette data.
	 * @return array|null
	 */
	private function normalize_global_palette_structure( $palette ) {
		if ( empty( $palette['palette'] ) || ! is_array( $palette['palette'] ) ) {
			return null;
		}

		$palette['palette'] = $this->sanitize_palette_colors( $palette['palette'] );

		return empty( $palette['palette'] ) ? null : $palette;
	}

	/**
	 * Convert Astra's legacy color palette option into the new global palette format.
	 *
	 * @param array $astra_palette Astra color palette data.
	 * @return array|null
	 */
	private function convert_astra_palette_to_global( $astra_palette ) {
		if ( empty( $astra_palette['palettes'] ) || ! is_array( $astra_palette['palettes'] ) ) {
			return null;
		}

		$current_key = isset( $astra_palette['currentPalette'] ) ? (string) $astra_palette['currentPalette'] : '';
		if ( $current_key && isset( $astra_palette['palettes'][ $current_key ] ) ) {
			$colors = $astra_palette['palettes'][ $current_key ];
		} else {
			$first_palette = reset( $astra_palette['palettes'] );
			$colors        = $first_palette;
		}

		if ( empty( $colors ) || ! is_array( $colors ) ) {
			return null;
		}

		$colors = $this->sanitize_palette_colors( $colors );

		return empty( $colors ) ? null : array( 'palette' => $colors );
	}

	/**
	 * Sanitize an array of colors.
	 *
	 * @param array $colors Raw colors.
	 * @return array
	 */
	private function sanitize_palette_colors( $colors ) {
		$cleaned = array();

		foreach ( (array) $colors as $color ) {
			$hex = $this->sanitize_hex_value( $color, '' );
			if ( $hex ) {
				$cleaned[] = $hex;
			}
		}

		return $cleaned;
	}

	/**
	 * Ensure Astra always receives a valid global palette, even on legacy installs.
	 *
	 * @param array $palette Current palette passed through the filter.
	 * @return array
	 */
	public function ensure_global_palette_fallback( $palette ) {
		if ( ! empty( $palette['palette'] ) && is_array( $palette['palette'] ) ) {
			return $palette;
		}

		$astra_palette = get_option( 'astra-color-palettes', array() );
		$converted     = $this->convert_astra_palette_to_global( $astra_palette );

		if ( $converted ) {
			if ( empty( get_option( 'global-color-palette' ) ) ) {
				update_option( 'global-color-palette', $converted );
			}
			return $converted;
		}

		return $palette;
	}

	/**
	 * Output CSS variables for the active global palette if Astra does not.
	 *
	 * @return void
	 */
	public function print_global_palette_css() {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$palette = $this->normalize_global_palette_structure( get_option( 'global-color-palette', array() ) );
		$converted = $this->convert_astra_palette_to_global( get_option( 'astra-color-palettes', array() ) );

		if ( $converted && ! empty( $converted['palette'] ) ) {
			$current_palette = ! empty( $palette['palette'] ) ? $this->sanitize_palette_colors( $palette['palette'] ) : array();
			$next_palette    = $this->sanitize_palette_colors( $converted['palette'] );

			if ( empty( $current_palette ) || $current_palette !== $next_palette ) {
				$palette = $converted;
				update_option( 'global-color-palette', $converted );
			}
		}

		if ( ! $palette ) {
			$palette   = $converted ? $converted : null;
		}

		if ( empty( $palette['palette'] ) || ! is_array( $palette['palette'] ) ) {
			return;
		}

		$variables = '';
		foreach ( $palette['palette'] as $index => $color ) {
			$hex = $this->sanitize_hex_value( $color, '' );
			if ( $hex ) {
				$variables .= sprintf( '--ast-global-color-%1$d:%2$s;', (int) $index, $hex );
			}
		}

		if ( '' === $variables ) {
			return;
		}

		printf(
			'<style id="nesta-global-palette">:root{%s}</style>',
			$variables // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		$printed = true;
	}

	/**
	 * Ensure Spectra CSS/JS exists on singular pages even when files aren't enqueued.
	 *
	 * @return void
	 */
	public function enqueue_spectra_fallback_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$assets    = get_post_meta( $post_id, '_uag_page_assets', true );
		$css_blob  = '';
		$js_blob   = '';

		if ( ! empty( $assets ) && is_array( $assets ) ) {
			if ( ! empty( $assets['css'] ) && is_string( $assets['css'] ) ) {
				$css_blob = trim( $assets['css'] );
			}

			if ( ! empty( $assets['js'] ) && is_string( $assets['js'] ) ) {
				$js_blob = trim( $assets['js'] );
			}
		}

		if ( '' === $css_blob ) {
			$css_blob = $this->read_spectra_asset_file( $post_id, 'css' );
		}

		if ( '' === $js_blob ) {
			$js_blob = $this->read_spectra_asset_file( $post_id, 'js' );
		}

		if ( '' === $css_blob && '' === $js_blob ) {
			return;
		}

		if ( '' !== $css_blob ) {
			$primary_handle = 'uag-style-' . $post_id;
			if ( ! wp_style_is( $primary_handle, 'enqueued' ) ) {
				$fallback_handle = 'nesta-uag-inline-' . $post_id;
				wp_register_style( $fallback_handle, false, array(), null );
				wp_enqueue_style( $fallback_handle );
				wp_add_inline_style( $fallback_handle, $css_blob );
			}
		}

		if ( '' !== $js_blob ) {
			$primary_script = 'uag-script-' . $post_id;
			if ( ! wp_script_is( $primary_script, 'enqueued' ) ) {
				$fallback_script = 'nesta-uag-inline-' . $post_id;
				wp_register_script( $fallback_script, '', array(), null, true );
				wp_enqueue_script( $fallback_script );
				wp_add_inline_script( $fallback_script, $js_blob );
			}
		}
	}

	/**
	 * Attempt to read Spectra asset files directly when meta is empty.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $type    Asset type (css|js).
	 * @return string
	 */
	private function read_spectra_asset_file( $post_id, $type = 'css' ) {
		$post_id = (int) $post_id;
		$type    = 'js' === $type ? 'js' : 'css';

		if ( $post_id <= 0 ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$assets_root = trailingslashit( $upload_dir['basedir'] ) . 'uag-plugin/assets/';
		if ( ! is_dir( $assets_root ) ) {
			return '';
		}

		$filename    = sprintf( 'uag-%1$s-%2$d.%1$s', $type, $post_id );
		$directories = glob( $assets_root . '*', GLOB_ONLYDIR );
		if ( false === $directories ) {
			$directories = array();
		}

		array_unshift( $directories, rtrim( $assets_root, '/\\' ) );

		foreach ( $directories as $directory ) {
			$path = trailingslashit( $directory ) . $filename;
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$contents = file_get_contents( $path );
				if ( false !== $contents ) {
					return trim( $contents );
				}
			}
		}

		return '';
	}

	/**
	 * Default brand palette fallbacks.
	 *
	 * @return array
	 */
	private function get_default_brand_palette() {
		return array(
			'primary_color'   => '#d65a16',
			'secondary_color' => '#1d4ed8',
			'accent_color'    => '#f97316',
			'text_color'      => '#0f172a',
			'heading_font'    => 'Inter',
			'body_font'       => 'Inter',
			'logo_id'         => 0,
		);
	}

	/**
	 * Default blueprints for single-page builder.
	 *
	 * @return array
	 */
	private function get_default_page_blueprints() {
		$base_path = plugin_dir_path( __FILE__ ) . 'templates/page-blueprints/';

		return array(
			'service'  => array(
				'label'       => __( 'Service page', 'nesta-dashboard' ),
				'description' => __( 'Highlights a single offering with hero, benefits, and contact prompts.', 'nesta-dashboard' ),
				'template'    => $base_path . 'service/page.html',
				'tokens'      => array(
					'service__name'            => __( 'Service name', 'nesta-dashboard' ),
					'service__hero_desc'       => __( 'Hero description', 'nesta-dashboard' ),
					'service__info_title'      => __( 'Overview title', 'nesta-dashboard' ),
					'service__info_body_1'     => __( 'Overview body 1', 'nesta-dashboard' ),
					'service__info_body_2'     => __( 'Overview body 2', 'nesta-dashboard' ),
					'service__problems_title'  => __( 'Problems section title', 'nesta-dashboard' ),
					'service__problems_item_1' => __( 'Problem 1', 'nesta-dashboard' ),
					'service__problems_item_2' => __( 'Problem 2', 'nesta-dashboard' ),
					'service__problems_item_3' => __( 'Problem 3', 'nesta-dashboard' ),
					'service__problems_item_4' => __( 'Problem 4', 'nesta-dashboard' ),
					'service__problems_item_5' => __( 'Problem 5', 'nesta-dashboard' ),
					'service__problems_item_6' => __( 'Problem 6', 'nesta-dashboard' ),
					'service__problems_item_7' => __( 'Problem 7', 'nesta-dashboard' ),
					'service__problems_item_8' => __( 'Problem 8', 'nesta-dashboard' ),
					'service__faq_1_q'         => __( 'FAQ #1 question', 'nesta-dashboard' ),
					'service__faq_1_a'         => __( 'FAQ #1 answer', 'nesta-dashboard' ),
					'service__faq_2_q'         => __( 'FAQ #2 question', 'nesta-dashboard' ),
					'service__faq_2_a'         => __( 'FAQ #2 answer', 'nesta-dashboard' ),
					'service__faq_3_q'         => __( 'FAQ #3 question', 'nesta-dashboard' ),
					'service__faq_3_a'         => __( 'FAQ #3 answer', 'nesta-dashboard' ),
					'service__faq_4_q'         => __( 'FAQ #4 question', 'nesta-dashboard' ),
					'service__faq_4_a'         => __( 'FAQ #4 answer', 'nesta-dashboard' ),
					'service__faq_5_q'         => __( 'FAQ #5 question', 'nesta-dashboard' ),
					'service__faq_5_a'         => __( 'FAQ #5 answer', 'nesta-dashboard' ),
					'service__faq_6_q'         => __( 'FAQ #6 question', 'nesta-dashboard' ),
					'service__faq_6_a'         => __( 'FAQ #6 answer', 'nesta-dashboard' ),
					'service__faq_7_q'         => __( 'FAQ #7 question', 'nesta-dashboard' ),
					'service__faq_7_a'         => __( 'FAQ #7 answer', 'nesta-dashboard' ),
					'service__faq_8_q'         => __( 'FAQ #8 question', 'nesta-dashboard' ),
					'service__faq_8_a'         => __( 'FAQ #8 answer', 'nesta-dashboard' ),
				),
			),
			'location' => array(
				'label'       => __( 'Location page', 'nesta-dashboard' ),
				'description' => __( 'Showcase a regional hub with service areas, hours, and directions.', 'nesta-dashboard' ),
				'source_slug' => 'location-template',
				'template'    => $base_path . 'location/page.html',
				'tokens'      => array(
					'location__hero_headline' => __( 'Hero headline', 'nesta-dashboard' ),
					'location__hero_summary'  => __( 'Hero summary', 'nesta-dashboard' ),
					'location__cta_label'     => __( 'Primary CTA label', 'nesta-dashboard' ),
					'location__cta_url'       => __( 'Primary CTA link', 'nesta-dashboard' ),
					'location__neighborhoods' => __( 'Service areas / neighborhoods', 'nesta-dashboard' ),
					'location__hours'         => __( 'Hours of operation', 'nesta-dashboard' ),
					'location__address'       => __( 'Address details', 'nesta-dashboard' ),
					'location__team_intro'    => __( 'Team introduction', 'nesta-dashboard' ),
					'location__contact_phone' => __( 'Phone number', 'nesta-dashboard' ),
					'location__contact_email' => __( 'Contact email', 'nesta-dashboard' ),
				),
			),
		);
	}

	/**
	 * Create a page using the provided blueprint and tokens.
	 *
	 * @param string $blueprint_id Blueprint identifier.
	 * @param array  $page_data    Page parameters.
	 * @param array  $token_values Token replacements.
	 * @return int|WP_Error Page ID on success.
	 */
	private function create_page_from_blueprint( $blueprint_id, $page_data, $token_values ) {
		if ( empty( $this->page_blueprints[ $blueprint_id ] ) ) {
		 return new WP_Error( 'nesta_blueprint_missing', __( 'Selected blueprint could not be found.', 'nesta-dashboard' ) );
		}

		$blueprint = $this->page_blueprints[ $blueprint_id ];

		if ( 'service' === $blueprint_id ) {
			return $this->create_service_page_from_pattern( $page_data, $token_values );
		}

		$template_content = '';

		$source_page_id = 0;

		if ( ! empty( $blueprint['source_slug'] ) ) {
			$source_page = $this->get_blueprint_source_page( $blueprint['source_slug'] );
			if ( $source_page ) {
				$template_content = $source_page->post_content;
				$source_page_id   = (int) $source_page->ID;
			}
		}

		if ( empty( $template_content ) ) {
			if ( empty( $blueprint['template'] ) || ! file_exists( $blueprint['template'] ) ) {
				return new WP_Error( 'nesta_blueprint_template', __( 'Blueprint template file is missing.', 'nesta-dashboard' ) );
			}

			$template_content = file_get_contents( $blueprint['template'] );

			if ( false === $template_content ) {
				return new WP_Error( 'nesta_blueprint_load', __( 'Unable to load blueprint template.', 'nesta-dashboard' ) );
			}
		}

		$replacements = array();
		foreach ( $blueprint['tokens'] as $token_key => $label ) {
			$placeholder              = '{{' . $token_key . '}}';
			$replacements[ $placeholder ] = isset( $token_values[ $token_key ] ) ? wp_kses_post( $token_values[ $token_key ] ) : '';
		}

		$content = strtr( $template_content, $replacements );
		$content = $this->regenerate_spectra_block_ids( $content );

		$postarr = array(
			'post_title'   => $page_data['title'],
			'post_status'  => $page_data['status'],
			'post_type'    => 'page',
			'post_content' => $content,
		);

		if ( ! empty( $page_data['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $page_data['slug'] );
		}

		$page_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		if ( 'service' === $blueprint_id ) {
			update_post_meta( $page_id, 'site-post-title', 'disabled' );
			$this->regenerate_spectra_assets( $page_id );
		} elseif ( $source_page_id ) {
			$this->copy_page_meta( $source_page_id, $page_id );
		} else {
			update_post_meta( $page_id, 'site-post-title', 'disabled' );
		}

		return (int) $page_id;
	}

	/**
	 * Create a service page by inserting and detaching the synced pattern.
	 *
	 * @param array $page_data    Page args (title, slug, status).
	 * @param array $token_values Token replacements.
	 * @return int|WP_Error
	 */
	private function create_service_page_from_pattern( $page_data, $token_values ) {
		$sync_block_id = $this->get_reusable_block_id_by_title( 'Service Page Synced' );

		if ( ! $sync_block_id ) {
			return new WP_Error(
				'nesta_service_pattern_missing',
				__( 'The synced Service Page pattern is missing. Re-import it before creating a new service page.', 'nesta-dashboard' )
			);
		}

		$title = isset( $page_data['title'] ) ? wp_strip_all_tags( $page_data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error(
				'nesta_service_missing_title',
				__( 'Add a page title before creating a service page.', 'nesta-dashboard' )
			);
		}

		$status = isset( $page_data['status'] ) && in_array( $page_data['status'], array( 'draft', 'publish' ), true ) ? $page_data['status'] : 'draft';
		$slug   = ! empty( $page_data['slug'] ) ? sanitize_title( $page_data['slug'] ) : '';

		$postarr = array(
			'post_title'   => $title,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_content' => sprintf( '<!-- wp:block {"ref":%d} /-->', (int) $sync_block_id ),
		);

		if ( '' !== $slug ) {
			$postarr['post_name'] = $slug;
		}

		$page_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		update_post_meta( $page_id, 'site-post-title', 'disabled' );

		$this->regenerate_spectra_assets( $page_id );

		if ( ! $this->detach_synced_reusable_block( $page_id, $sync_block_id ) ) {
			return new WP_Error(
				'nesta_service_detach_failed',
				__( 'The synced Service Page pattern could not be detached automatically. Open the page in the editor and use the Detach action, then try again.', 'nesta-dashboard' ),
				array(
					'page_id' => $page_id,
				)
			);
		}

		$this->regenerate_spectra_assets( $page_id );

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $page_id );
		}

		$page = get_post( $page_id );

		if ( ! $page instanceof WP_Post ) {
			return new WP_Error(
				'nesta_service_page_missing',
				__( 'The service page was created but the content could not be loaded.', 'nesta-dashboard' ),
				array(
					'page_id' => $page_id,
				)
			);
		}

		$hydrated_content = $this->hydrate_blueprint_tokens( $page->post_content, 'service', $token_values );

		if ( ! $this->update_page_via_rest( $page_id, $hydrated_content, $status ) ) {
			$update = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $hydrated_content,
					'post_status'  => $status,
				),
				true
			);

			if ( is_wp_error( $update ) ) {
				return new WP_Error(
					'nesta_service_update_failed',
					__( 'The service page content could not be finalized. Please edit the page manually.', 'nesta-dashboard' ),
					array(
						'page_id' => $page_id,
					)
				);
			}
		}

		$this->regenerate_spectra_assets( $page_id );

		return (int) $page_id;
	}

	/**
	 * Replace blueprint token placeholders with sanitized values.
	 *
	 * @param string $content       Block markup.
	 * @param string $blueprint_id  Blueprint identifier.
	 * @param array  $token_values  Token replacements.
	 * @return string
	 */
	private function hydrate_blueprint_tokens( $content, $blueprint_id, $token_values ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return $content;
		}

		if ( empty( $this->page_blueprints[ $blueprint_id ]['tokens'] ) ) {
			return $content;
		}

		$replacements = array();

		foreach ( $this->page_blueprints[ $blueprint_id ]['tokens'] as $token_key => $label ) {
			$placeholder                 = '{{' . $token_key . '}}';
			$replacements[ $placeholder ] = isset( $token_values[ $token_key ] ) ? wp_kses_post( $token_values[ $token_key ] ) : '';
		}

		return strtr( $content, $replacements );
	}

	/**
	 * Retrieve block pattern markup by user-facing title.
	 *
	 * @param string $pattern_title Pattern title.
	 * @return string
	 */
	private function get_block_pattern_content( $pattern_title ) {
		$pattern_title = trim( (string) $pattern_title );

		if ( '' === $pattern_title ) {
			return '';
		}

		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			$registry = WP_Block_Patterns_Registry::get_instance();

			if ( $registry && method_exists( $registry, 'get_all_registered' ) ) {
				$registered = $registry->get_all_registered();

				foreach ( $registered as $pattern ) {
					if ( isset( $pattern['title'], $pattern['content'] ) && 0 === strcasecmp( $pattern['title'], $pattern_title ) ) {
						return (string) $pattern['content'];
					}
				}
			}
		}

		$reusable = get_page_by_title( $pattern_title, OBJECT, 'wp_block' );

		if ( $reusable instanceof WP_Post && ! empty( $reusable->post_content ) ) {
			return (string) $reusable->post_content;
		}

		return '';
	}

	/**
	 * Fetch reusable block ID by title.
	 *
	 * @param string $title Block title.
	 * @return int
	 */
	private function get_reusable_block_id_by_title( $title ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return 0;
		}

		$block = get_page_by_title( $title, OBJECT, 'wp_block' );

		if ( $block instanceof WP_Post ) {
			return (int) $block->ID;
		}

		return 0;
	}

	/**
	 * Rename the legacy Service Page pattern to the synced title expected by the builder.
	 */
	private function ensure_service_page_pattern_alias() {
		$desired_title = 'Service Page Synced';
		$existing_id   = $this->get_reusable_block_id_by_title( $desired_title );

		if ( $existing_id ) {
			return;
		}

		$legacy_id = $this->get_reusable_block_id_by_title( 'Service Page' );

		if ( ! $legacy_id ) {
			return;
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'         => $legacy_id,
					'post_title' => $desired_title,
					'post_name'  => sanitize_title( $desired_title ),
					'post_status' => 'publish',
				)
			)
		);
	}

	/**
	 * Get reusable block content by ID.
	 *
	 * @param int $block_id Block post ID.
	 * @return string
	 */
	private function get_reusable_block_content( $block_id ) {
		$block_id = (int) $block_id;

		if ( $block_id <= 0 ) {
			return '';
		}

		$block_post = get_post( $block_id );

		if ( ! $block_post || 'wp_block' !== $block_post->post_type ) {
			return '';
		}

		return (string) $block_post->post_content;
	}

	/**
	 * Replace a synced reusable block reference with standalone blocks.
	 *
	 * @param int $page_id       Page to update.
	 * @param int $reusable_id   Reusable block ID.
	 * @return bool
	 */
	private function detach_synced_reusable_block( $page_id, $reusable_id ) {
		$page_id     = (int) $page_id;
		$reusable_id = (int) $reusable_id;

		if ( $page_id <= 0 || $reusable_id <= 0 ) {
			return false;
		}

		$page = get_post( $page_id );

		if ( ! $page instanceof WP_Post ) {
			return false;
		}

		$current_content = (string) $page->post_content;

		if ( false === strpos( $current_content, '"ref":' . $reusable_id ) ) {
			return false;
		}

		$reusable_content = $this->get_reusable_block_content( $reusable_id );

		if ( '' === $reusable_content ) {
			return false;
		}

		$reusable_content = $this->regenerate_spectra_block_ids( $reusable_content );

		$page_blocks     = parse_blocks( $current_content );
		$reusable_blocks = parse_blocks( $reusable_content );
		$replacement     = $this->replace_reusable_placeholder( $page_blocks, $reusable_id, $reusable_blocks );

		if ( null === $replacement ) {
			return false;
		}

		$serialized_content = serialize_blocks( $replacement );

		if ( ! $this->update_page_via_rest( $page_id, $serialized_content, get_post_status( $page_id ) ) ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $serialized_content,
				)
			);
		}

		return true;
	}

	/**
	 * Replace a reusable block reference with standalone block content.
	 *
	 * @param array $blocks          Parsed page blocks.
	 * @param int   $reusable_id     Reusable block ID to replace.
	 * @param array $replacement     Parsed replacement blocks.
	 * @return array|null
	 */
	private function replace_reusable_placeholder( $blocks, $reusable_id, $replacement ) {
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return null;
		}

		$updated = array();
		$replaced = false;

		foreach ( $blocks as $block ) {
			if ( ! $replaced && 'core/block' === $block['blockName'] && isset( $block['attrs']['ref'] ) && (int) $block['attrs']['ref'] === (int) $reusable_id ) {
				$updated = array_merge( $updated, $replacement );
				$replaced = true;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner_replacement = $this->replace_reusable_placeholder( $block['innerBlocks'], $reusable_id, $replacement );
				if ( null !== $inner_replacement ) {
					$block['innerBlocks'] = $inner_replacement;
					$replaced             = true;
				}
			}

			$updated[] = $block;
		}

		return $replaced ? $updated : null;
	}

	/**
	 * Update a page via the REST API pipeline so editor hooks fire.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $content Serialized block markup.
	 * @return bool
	 */
	private function update_page_via_rest( $page_id, $content, $status = 'publish' ) {
		if ( ! class_exists( 'WP_REST_Request' ) ) {
			return false;
		}

		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return false;
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
		$request->set_body_params(
			array(
				'content' => $content,
				'id'      => $page_id,
				'status'  => $status,
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return false;
		}

		return true;
	}

	/**
	 * Re-generate Spectra block IDs so cloned patterns behave like manual inserts.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	private function regenerate_spectra_block_ids( $content ) {
		$content = (string) $content;

		if ( '' === $content || ! function_exists( 'has_blocks' ) || ! has_blocks( $content ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		$used   = array();
		$map    = array();
		$blocks = $this->map_spectra_block_ids( $blocks, $used, $map );

		$new_content = serialize_blocks( $blocks );

		if ( ! empty( $map ) ) {
			foreach ( $map as $original => $replacement ) {
				$new_content = str_replace( 'uagb-block-' . $original, 'uagb-block-' . $replacement, $new_content );
			}
		}

		return $new_content;
	}

	/**
	 * Recursively update block IDs for Spectra blocks.
	 *
	 * @param array $blocks Parsed block array.
	 * @param array $used   Already-assigned IDs.
	 * @return array
	 */
	private function map_spectra_block_ids( $blocks, &$used, &$map ) {
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return $blocks;
		}

		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( 0 === strpos( $block_name, 'uagb/' ) && isset( $block['attrs'], $block['attrs']['block_id'] ) ) {
				$original_id                = (string) $block['attrs']['block_id'];
				$new_id                     = $this->generate_unique_spectra_block_id( $used );
				$block['attrs']['block_id'] = $new_id;
				if ( '' !== $original_id ) {
					$map[ $original_id ] = $new_id;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->map_spectra_block_ids( $block['innerBlocks'], $used, $map );
			}
		}

		return $blocks;
	}

	/**
	 * Create a unique Spectra block ID.
	 *
	 * @param array $used Already-used IDs.
	 * @return string
	 */
	private function generate_unique_spectra_block_id( &$used ) {
		$candidate = '';
		$attempts  = 0;

		do {
			$attempts++;
			try {
				$bytes     = random_bytes( 4 );
				$candidate = bin2hex( $bytes );
			} catch ( Exception $e ) {
				$candidate = strtolower( substr( wp_generate_password( 8, false, false ), 0, 8 ) );
			}
		} while ( in_array( $candidate, $used, true ) && $attempts < 5 );

		if ( in_array( $candidate, $used, true ) ) {
			$candidate = strtolower( substr( wp_generate_password( 8, false, false ), 0, 8 ) );
		}

		$used[] = $candidate;

		return $candidate;
	}

	/**
	 * Retrieve a blueprint source page regardless of publish status.
	 *
	 * @param string $slug Page slug.
	 * @return WP_Post|null
	 */
	private function get_blueprint_source_page( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$post_statuses = array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );

		$pages = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'page',
				'post_status'    => $post_statuses,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $pages ) ) {
			return $pages[0];
		}

		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
			$sql          = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($placeholders) AND post_name LIKE %s ORDER BY post_date DESC LIMIT 1",
				array_merge( array( 'page' ), $post_statuses, array( $wpdb->esc_like( $slug ) . '%' ) )
			);
			$page_id     = $wpdb->get_var( $sql );

			if ( $page_id ) {
				$page = get_post( (int) $page_id );

				if ( $page && in_array( $page->post_status, $post_statuses, true ) ) {
					return $page;
				}
			}
		}

		$last_run = get_option( 'nesta_quick_start_last_generation', array() );
		if ( ! empty( $last_run['page_ids'][ $slug ] ) ) {
			$page = get_post( (int) $last_run['page_ids'][ $slug ] );

			if ( $page && in_array( $page->post_status, $post_statuses, true ) ) {
				return $page;
			}
		}

		return null;
	}


	/**
	 * Copy over layout/meta settings from the blueprint source page.
	 *
	 * @param int $source_page_id Source page ID.
	 * @param int $target_page_id Target page ID.
	 * @return void
	 */
	private function copy_page_meta( $source_page_id, $target_page_id ) {
		$source_page_id = (int) $source_page_id;
		$target_page_id = (int) $target_page_id;

		if ( ! $source_page_id || ! $target_page_id ) {
			return;
		}

		$meta      = get_post_meta( $source_page_id );
		$blacklist = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_uag_css_file_name', '_uag_js_file_name' );

		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $blacklist, true ) ) {
				continue;
			}

			delete_post_meta( $target_page_id, $key );

			foreach ( (array) $values as $value ) {
				add_post_meta( $target_page_id, $key, maybe_unserialize( $value ) );
			}
		}

		delete_post_meta( $target_page_id, '_uag_css_file_name' );
		delete_post_meta( $target_page_id, '_uag_js_file_name' );

		$this->regenerate_spectra_assets( $target_page_id );
	}

	/**
	 * Regenerate Spectra/UAG assets for a cloned page.
	 *
	 * @param int $page_id Target page ID.
	 * @return void
	 */
	private function regenerate_spectra_assets( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return;
		}

		$page = get_post( $page_id );

		if ( ! $page ) {
			return;
		}

		$this->maybe_load_spectra_asset_classes();

		delete_post_meta( $page_id, '_uag_css_file_name' );
		delete_post_meta( $page_id, '_uag_js_file_name' );

		if ( class_exists( 'UAGB_Post_Assets' ) ) {
			$post_assets = new UAGB_Post_Assets( $page_id );

			if ( method_exists( $post_assets, 'prepare_assets' ) ) {
				$post_assets->prepare_assets( $page );
			}

			if ( method_exists( $post_assets, 'generate_assets' ) ) {
				$post_assets->generate_assets();
			}
		}

		// Fire a minimal update to trigger Spectra's normal hooks.
		wp_update_post(
			array(
				'ID' => $page_id,
			)
		);
	}

	/**
	 * Load Spectra asset classes if the plugin is available.
	 *
	 * @return void
	 */
	private function maybe_load_spectra_asset_classes() {
		$spectra_base = $this->locate_spectra_library();

		if ( ! $spectra_base ) {
			return;
		}

		$helper_path = trailingslashit( $spectra_base ) . 'classes/utils.php';
		if ( ! function_exists( 'uagb_get_post_assets' ) && file_exists( $helper_path ) ) {
			require_once $helper_path;
		}

		$class_path = trailingslashit( $spectra_base ) . 'classes/class-uagb-post-assets.php';
		if ( ! class_exists( 'UAGB_Post_Assets' ) && file_exists( $class_path ) ) {
			require_once $class_path;
		}
	}

	/**
	 * Ensure Spectra rebuild runs even if direct regeneration is not available.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private function schedule_spectra_regeneration( $page_id ) {
		if ( has_action( 'spectra_regenerate_post_assets' ) ) {
			do_action( 'spectra_regenerate_post_assets', $page_id );
		}

		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			if ( ! wp_next_scheduled( 'spectra_regenerate_post_assets', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + 10, 'spectra_regenerate_post_assets', array( $page_id ) );
			}
		}
	}

	/**
	 * Mimic the block editor save flow so Spectra hooks fire.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private function simulate_editor_save_cycle( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return;
		}

		// Trigger save_post and Spectra hooks.
		wp_update_post(
			array(
				'ID' => $page_id,
			)
		);

		if ( has_action( 'spectra_regenerate_post_assets' ) ) {
			do_action( 'spectra_regenerate_post_assets', $page_id );
		}
	}

	/**
	 * Queue a page for Spectra regeneration.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private function queue_spectra_regeneration( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return;
		}

		$queue = get_option( 'nesta_pending_spectra_regen', array() );
		if ( ! in_array( $page_id, $queue, true ) ) {
			$queue[] = $page_id;
			update_option( 'nesta_pending_spectra_regen', $queue, false );
		}
	}

	/**
	 * Remove a page from the Spectra regeneration queue.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private function dequeue_spectra_regeneration( $page_id ) {
		$queue = get_option( 'nesta_pending_spectra_regen', array() );
		if ( empty( $queue ) ) {
			return;
		}

		$queue = array_values(
			array_filter(
				$queue,
				function( $queued_id ) use ( $page_id ) {
					return (int) $queued_id !== (int) $page_id;
				}
			)
		);

		update_option( 'nesta_pending_spectra_regen', $queue, false );
	}

	/**
	 * Attempt to regenerate Spectra assets for a page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private function process_single_spectra_regeneration( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return false;
		}

		$this->simulate_editor_save_cycle( $page_id );

		$spectra_base = $this->locate_spectra_library();
		if ( $spectra_base ) {
			$helper_path = trailingslashit( $spectra_base ) . 'classes/utils.php';
			if ( ! function_exists( 'uagb_get_post_assets' ) && file_exists( $helper_path ) ) {
				require_once $helper_path;
			}

			$class_path = trailingslashit( $spectra_base ) . 'classes/class-uagb-post-assets.php';
			if ( ! class_exists( 'UAGB_Post_Assets' ) && file_exists( $class_path ) ) {
				require_once $class_path;
			}
		}

		$page = get_post( $page_id );

		if ( ! $page ) {
			return false;
		}

		if ( class_exists( 'UAGB_Post_Assets' ) ) {
			$post_assets = new UAGB_Post_Assets( $page_id );
		} elseif ( function_exists( 'uagb_get_post_assets' ) ) {
			$post_assets = uagb_get_post_assets( $page_id );
		} else {
			$post_assets = null;
		}

		if ( ! $post_assets ) {
			return false;
		}

		delete_post_meta( $page_id, '_uag_page_assets' );
		delete_post_meta( $page_id, '_uag_css_file_name' );
		delete_post_meta( $page_id, '_uag_js_file_name' );

		if ( method_exists( $post_assets, 'prepare_assets' ) ) {
			$post_assets->prepare_assets( $page );
		}

		if ( method_exists( $post_assets, 'generate_assets' ) ) {
			$post_assets->generate_assets();
			$this->dequeue_spectra_regeneration( $page_id );
			return true;
		}

		return false;
	}

	/**
	 * Determine Spectra/UAG plugin path for class loading.
	 *
	 * @return string Absolute path if found, empty string otherwise.
	 */
	private function locate_spectra_library() {
		$candidates = array(
			WP_PLUGIN_DIR . '/spectra',
			WP_PLUGIN_DIR . '/ultimate-addons-for-gutenberg',
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( trailingslashit( $candidate ) . 'classes/class-uagb-post-assets.php' ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Process queued Spectra regenerations once Spectra is loaded.
	 *
	 * @return void
	 */
	public function process_spectra_regeneration_queue() {
		$queue = get_option( 'nesta_pending_spectra_regen', array() );

		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		foreach ( $queue as $page_id ) {
			if ( $this->process_single_spectra_regeneration( (int) $page_id ) ) {
				continue;
			}
		}
	}

	/**
	 * Fire a front-end request so Spectra's normal pipeline regenerates assets.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private function ping_page_for_spectra( $page_id ) {
		$page_id = (int) $page_id;

		if ( $page_id <= 0 ) {
			return;
		}

		$permalink = get_permalink( $page_id );

		if ( ! $permalink ) {
			return;
		}

		$permalink = add_query_arg(
			array(
				'nesta_spectra_regen' => time(),
			),
			$permalink
		);

		wp_remote_get(
			$permalink,
			array(
				'timeout'   => 5,
				'sslverify' => false,
				'blocking'  => false,
			)
		);
	}


	/**
	 * Merge template-defined colors and fonts into a brand array.
	 *
	 * @param array $brand    Existing brand values.
	 * @param array $template Template configuration.
	 * @return array
	 */
	private function apply_template_brand_defaults( $brand, $template ) {
		if ( empty( $template ) ) {
			return $brand;
		}

		if ( ! empty( $template['settings']['colors'] ) && is_array( $template['settings']['colors'] ) ) {
			$colors = $template['settings']['colors'];

			if ( ! empty( $colors['primary'] ) ) {
				$brand['primary_color'] = $this->sanitize_hex_value( $colors['primary'], $brand['primary_color'] );
			}

			if ( ! empty( $colors['secondary'] ) ) {
				$brand['secondary_color'] = $this->sanitize_hex_value( $colors['secondary'], $brand['secondary_color'] );
			}

			if ( ! empty( $colors['accent'] ) ) {
				$brand['accent_color'] = $this->sanitize_hex_value( $colors['accent'], $brand['accent_color'] );
			}

			if ( ! empty( $colors['text'] ) ) {
				$brand['text_color'] = $this->sanitize_hex_value( $colors['text'], $brand['text_color'] );
			}
		}

		if ( ! empty( $template['settings']['typography'] ) && is_array( $template['settings']['typography'] ) ) {
			$typography = $template['settings']['typography'];

			if ( ! empty( $typography['heading'] ) ) {
				$brand['heading_font'] = sanitize_text_field( $typography['heading'] );
			}

			if ( ! empty( $typography['body'] ) ) {
				$brand['body_font'] = sanitize_text_field( $typography['body'] );
			}
		}

		$customizer_payload = $this->load_template_customizer_payload( $template );
		if ( ! is_wp_error( $customizer_payload ) && ! empty( $customizer_payload ) ) {
			$section          = $this->normalize_customizer_section( $customizer_payload );
			$astra_settings   = isset( $section['astra-settings'] ) && is_array( $section['astra-settings'] ) ? $section['astra-settings'] : array();
			$palettes_section = isset( $section['astra-color-palettes'] ) && is_array( $section['astra-color-palettes'] ) ? $section['astra-color-palettes'] : array();

			if ( ! empty( $palettes_section['palettes'] ) && is_array( $palettes_section['palettes'] ) ) {
				$palette_key = isset( $palettes_section['currentPalette'] ) ? $palettes_section['currentPalette'] : '';

				if ( $palette_key && isset( $palettes_section['palettes'][ $palette_key ] ) ) {
					$palette = $palettes_section['palettes'][ $palette_key ];

					if ( is_array( $palette ) ) {
						$palette_values = array_values( $palette );

						if ( isset( $palette_values[0] ) ) {
							$brand['primary_color'] = $this->sanitize_hex_value( $palette_values[0], $brand['primary_color'] );
						}

						if ( isset( $palette_values[1] ) ) {
							$brand['secondary_color'] = $this->sanitize_hex_value( $palette_values[1], $brand['secondary_color'] );
						}

						if ( isset( $palette_values[2] ) ) {
							$brand['accent_color'] = $this->sanitize_hex_value( $palette_values[2], $brand['accent_color'] );
						}

						if ( isset( $palette_values[3] ) ) {
							$brand['text_color'] = $this->sanitize_hex_value( $palette_values[3], $brand['text_color'] );
						}
					}
				}
			}

			if ( ! empty( $astra_settings['body-color'] ) ) {
				$text_color = $this->sanitize_hex_value( $astra_settings['body-color'], '' );
				if ( $text_color ) {
					$brand['text_color'] = $text_color;
				}
			}

			if ( ! empty( $astra_settings['heading-color'] ) ) {
				$heading_color = $this->sanitize_hex_value( $astra_settings['heading-color'], '' );
				if ( $heading_color ) {
					$brand['accent_color'] = $heading_color;
				}
			}

			$body_font = '';
			if ( ! empty( $astra_settings['body-typography']['font-family'] ) ) {
				$body_font = $astra_settings['body-typography']['font-family'];
			} elseif ( ! empty( $astra_settings['body-font-family'] ) ) {
				$body_font = $astra_settings['body-font-family'];
			}

			if ( $body_font ) {
				$brand['body_font'] = sanitize_text_field( $body_font );
			}

			$heading_font = '';
			if ( ! empty( $astra_settings['heading-typography']['font-family'] ) ) {
				$heading_font = $astra_settings['heading-typography']['font-family'];
			} elseif ( ! empty( $astra_settings['headings-font-family'] ) ) {
				$heading_font = $astra_settings['headings-font-family'];
			}

			if ( $heading_font ) {
				$brand['heading_font'] = sanitize_text_field( $heading_font );
			}
		}

		return $brand;
	}

	/**
	 * Install a template bundle (uploads + export).
	 *
	 * @param string $template_id Template identifier.
	 * @return array|WP_Error
	 */
	private function install_template_bundle( $template_id ) {
		$template = $this->template_registry->get_template( $template_id );

		if ( empty( $template ) ) {
			return new WP_Error( 'nesta_template_missing', __( 'Selected template could not be found.', 'nesta-dashboard' ) );
		}

		if ( empty( $template['bundle'] ) ) {
			return new WP_Error( 'nesta_bundle_missing', __( 'This template does not define bundle files yet.', 'nesta-dashboard' ) );
		}

		$base_dir    = isset( $template['dir'] ) ? trailingslashit( $template['dir'] ) : '';
		$export_rel  = isset( $template['bundle']['export'] ) ? ltrim( $template['bundle']['export'], '/' ) : '';
		$uploads_rel = isset( $template['bundle']['uploads'] ) ? ltrim( $template['bundle']['uploads'], '/' ) : '';
		$export_path = $export_rel ? $base_dir . $export_rel : '';
		$uploads_path = $this->resolve_template_uploads_path( $template, $uploads_rel );

		if ( ! $export_path || ! file_exists( $export_path ) ) {
			return new WP_Error( 'nesta_export_missing', __( 'Template export file is missing.', 'nesta-dashboard' ) );
		}

		if ( $uploads_path ) {
			$uploads_result = $this->extract_template_uploads( $uploads_path );
			if ( is_wp_error( $uploads_result ) ) {
				return $uploads_result;
			}
		}

		$import_result = $this->import_template_content( $export_path );
		if ( is_wp_error( $import_result ) ) {
			return $import_result;
		}

		$this->ensure_service_page_pattern_alias();

		$this->ensure_primary_navigation( $template, isset( $import_result['pages'] ) ? $import_result['pages'] : array() );

		$customizer_result = $this->apply_template_customizer_settings( $template );
		if ( is_wp_error( $customizer_result ) ) {
			return $customizer_result;
		}

		update_option(
			'nesta_quick_start_last_generation',
			array(
				'template_id' => $template_id,
				'page_ids'    => isset( $import_result['pages'] ) ? $import_result['pages'] : array(),
				'created'     => isset( $import_result['created'] ) ? (int) $import_result['created'] : 0,
				'updated'     => isset( $import_result['updated'] ) ? (int) $import_result['updated'] : 0,
				'generated_at' => '',
				'content_applied' => false,
			)
		);

		return $import_result;
	}

	/**
	 * Extract uploaded media bundle into the current uploads directory.
	 *
	 * @param string $zip_path Zip file absolute path.
	 * @return true|WP_Error
	 */
	private function extract_template_uploads( $zip_path ) {
		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'nesta_uploads_missing', __( 'Uploads archive could not be found.', 'nesta-dashboard' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$result = WP_Filesystem();
		if ( ! $result ) {
			return new WP_Error( 'nesta_filesystem_error', __( 'Unable to initialize filesystem for extraction.', 'nesta-dashboard' ) );
		}

		$upload_dir = wp_upload_dir();
		$target     = trailingslashit( $upload_dir['basedir'] );
		$unzipped   = unzip_file( $zip_path, $target );

		if ( is_wp_error( $unzipped ) ) {
			return new WP_Error( 'nesta_unzip_failed', sprintf( __( 'Unable to unzip uploads archive: %s', 'nesta-dashboard' ), $unzipped->get_error_message() ) );
		}

		return true;
	}

	/**
	 * Import page content from a WXR export.
	 *
	 * @param string $export_path Absolute path to export.xml.
	 * @return array|WP_Error
	 */
	private function import_template_content( $export_path, $tokens = array() ) {
		if ( ! file_exists( $export_path ) ) {
			return new WP_Error( 'nesta_export_missing', __( 'Export file missing.', 'nesta-dashboard' ) );
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_file( $export_path, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( false === $xml ) {
			return new WP_Error( 'nesta_wxr_parse_error', __( 'Unable to parse the export file.', 'nesta-dashboard' ) );
		}

		$namespaces = $xml->getNamespaces( true );
		if ( empty( $namespaces['wp'] ) || empty( $namespaces['content'] ) ) {
			return new WP_Error( 'nesta_wxr_invalid', __( 'The export file is missing required data.', 'nesta-dashboard' ) );
		}

		$channel           = $xml->channel;
		$wp_ns             = $namespaces['wp'];
		$content_ns        = $namespaces['content'];
		$base_site_url     = isset( $channel->children( $wp_ns )->base_site_url ) ? (string) $channel->children( $wp_ns )->base_site_url : '';
		$base_blog_url     = isset( $channel->children( $wp_ns )->base_blog_url ) ? (string) $channel->children( $wp_ns )->base_blog_url : '';
		$home_url          = home_url( '/' );
		$created           = 0;
		$updated           = 0;
		$page_ids          = array();
		$id_map            = array();
		$imported_post_ids = array();
		$uploads_info      = wp_upload_dir();
		$uploads_basedir   = trailingslashit( $uploads_info['basedir'] );
		$uploads_baseurl   = trailingslashit( $uploads_info['baseurl'] );
		$allowed_post_types = array( 'page', 'wp_block', 'sureforms_form', 'wp_navigation', 'attachment' );

		$items = array();
		foreach ( $channel->item as $item ) {
			$items[] = $item;
		}

		// First pass: import attachments so we have an ID map for content replacements.
		foreach ( $items as $item ) {
			$wp_node    = $item->children( $wp_ns );
			$post_type  = (string) $wp_node->post_type;

			if ( ! in_array( $post_type, $allowed_post_types, true ) ) {
				continue;
			}

			$status     = (string) $wp_node->status;
			if ( 'trash' === $status ) {
				continue;
			}

			$slug       = (string) $wp_node->post_name;
			if ( 'attachment' !== $post_type ) {
				continue;
			}

			$attachment_id = $this->import_template_attachment( $item, $wp_node, $uploads_basedir, $uploads_baseurl );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			if ( $attachment_id ) {
				$imported_post_ids[] = $attachment_id;
				$original_id          = isset( $wp_node->post_id ) ? (int) $wp_node->post_id : 0;

				if ( $original_id ) {
					$id_map[ $original_id ] = $attachment_id;
				}
			}
		}

		// Second pass: import pages, blocks, forms, navigation, etc.
		foreach ( $items as $item ) {
			$wp_node    = $item->children( $wp_ns );
			$post_type  = (string) $wp_node->post_type;

			if ( ! in_array( $post_type, $allowed_post_types, true ) ) {
				continue;
			}

			$status     = (string) $wp_node->status;
			if ( 'trash' === $status ) {
				continue;
			}

			$slug       = (string) $wp_node->post_name;

			if ( 'attachment' === $post_type ) {
				$attachment_id = $this->import_template_attachment( $item, $wp_node, $uploads_basedir, $uploads_baseurl );

				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}

				if ( $attachment_id ) {
					$imported_post_ids[] = $attachment_id;
					$original_id          = isset( $wp_node->post_id ) ? (int) $wp_node->post_id : 0;

					if ( $original_id ) {
						$id_map[ $original_id ] = $attachment_id;
					}
				}

				continue;
			}

			$title      = (string) $item->title;
			$content    = (string) $item->children( $content_ns )->encoded;
			if ( ! empty( $tokens ) ) {
				$content = $this->template_registry->replace_tokens( $content, $tokens );
			}
			$content    = $this->replace_template_urls( $content, $base_site_url, $home_url );
			$content    = $this->replace_template_urls( $content, $base_blog_url, $home_url );
			if ( ! empty( $id_map ) ) {
				$content = $this->replace_template_attachment_ids( $content, $id_map );
			}
			$post_date  = (string) $wp_node->post_date;
			$menu_order = (int) $wp_node->menu_order;

				$postarr = array(
					'post_type'    => $post_type,
					'post_status'  => $status ? $status : 'publish',
					'post_title'   => $title,
					'post_name'    => $slug ? $slug : sanitize_title( $title ),
					'post_content' => $content,
					'post_date'    => $post_date,
					'menu_order'   => $menu_order,
				);

				$existing = $slug ? get_page_by_path( $slug, OBJECT, $post_type ) : null;
				if ( $existing ) {
					$postarr['ID'] = $existing->ID;
				}

			$post_id = wp_insert_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

				if ( $existing ) {
					$updated++;
				} else {
					$created++;
				}

				$imported_post_ids[] = $post_id;

				$original_id = isset( $wp_node->post_id ) ? (int) $wp_node->post_id : 0;
				if ( $original_id ) {
					$id_map[ $original_id ] = $post_id;
				}

			if ( isset( $wp_node->postmeta ) ) {
				foreach ( $wp_node->postmeta as $meta_entry ) {
					$meta_key = isset( $meta_entry->meta_key ) ? (string) $meta_entry->meta_key : '';

					if ( '' === $meta_key ) {
						continue;
					}

					$raw_value = isset( $meta_entry->meta_value ) ? (string) $meta_entry->meta_value : '';
					$meta_value = maybe_unserialize( $raw_value );

					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

				$key = $slug ? $slug : $post_type . '_' . $post_id;
				$page_ids[ $key ] = $post_id;
			}

			if ( ! empty( $imported_post_ids ) && ! empty( $id_map ) ) {
				$this->update_block_reference_ids( $imported_post_ids, $id_map );
			}

		libxml_clear_errors();

		return array(
			'created' => $created,
			'updated' => $updated,
			'pages'   => $page_ids,
		);
	}

	/**
	 * Import an attachment entry from the template export.
	 *
	 * @param SimpleXMLElement $item            Raw item node.
	 * @param SimpleXMLElement $wp_node         WP namespace node.
	 * @param string           $uploads_basedir Uploads base directory.
	 * @param string           $uploads_baseurl Uploads base URL.
	 * @return int|false|WP_Error Attachment ID on success.
	 */
	private function import_template_attachment( $item, $wp_node, $uploads_basedir, $uploads_baseurl ) {
		$title          = (string) $item->title;
		$status         = (string) $wp_node->status;
		$mime_type      = isset( $wp_node->post_mime_type ) ? (string) $wp_node->post_mime_type : '';
		$attachment_url = isset( $wp_node->attachment_url ) ? (string) $wp_node->attachment_url : '';
		$slug           = (string) $wp_node->post_name;

		list( $local_file, $local_url ) = $this->resolve_local_attachment_paths( $attachment_url, $uploads_basedir, $uploads_baseurl );

		$attachment_data = array(
			'post_title'     => $title,
			'post_status'    => $status ? $status : 'inherit',
			'post_type'      => 'attachment',
			'post_mime_type' => $mime_type,
			'guid'           => $local_url ? $local_url : $attachment_url,
		);

		if ( $slug ) {
			$attachment_data['post_name'] = $slug;
		}

		$existing = $slug ? get_page_by_path( $slug, OBJECT, 'attachment' ) : null;
		if ( $existing ) {
			$attachment_data['ID'] = $existing->ID;
		}

		$attachment_id = wp_insert_attachment(
			wp_slash( $attachment_data ),
			( $local_file && file_exists( $local_file ) ) ? $local_file : '',
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( isset( $wp_node->postmeta ) ) {
			foreach ( $wp_node->postmeta as $meta_entry ) {
				$meta_key   = isset( $meta_entry->meta_key ) ? (string) $meta_entry->meta_key : '';
				$raw_value  = isset( $meta_entry->meta_value ) ? (string) $meta_entry->meta_value : '';
				$meta_value = maybe_unserialize( $raw_value );

				if ( '' === $meta_key ) {
					continue;
				}

				update_post_meta( $attachment_id, $meta_key, $meta_value );
			}
		}

		if ( $local_file && file_exists( $local_file ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$metadata = wp_generate_attachment_metadata( $attachment_id, $local_file );
			if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		return $attachment_id;
	}

	/**
	 * Map an attachment URL from the export file to the local uploads path.
	 *
	 * @param string $attachment_url  Export attachment URL.
	 * @param string $uploads_basedir Uploads base directory.
	 * @param string $uploads_baseurl Uploads base URL.
	 * @return array Array with file path and URL.
	 */
	private function resolve_local_attachment_paths( $attachment_url, $uploads_basedir, $uploads_baseurl ) {
		if ( '' === $attachment_url ) {
			return array( '', '' );
		}

		$path = wp_parse_url( $attachment_url, PHP_URL_PATH );

		if ( empty( $path ) ) {
			return array( '', '' );
		}

		$uploads_relative = trim( str_replace( ABSPATH, '', $uploads_basedir ), '/' );
		$needle           = '/' . trim( $uploads_relative, '/' ) . '/';

		if ( false !== strpos( $path, $needle ) ) {
			$relative_path = substr( $path, strpos( $path, $needle ) + strlen( $needle ) );
		} else {
			$relative_path = preg_replace( '#^/?wp-content/uploads/#', '', ltrim( $path, '/' ) );
		}

		$relative_path = ltrim( $relative_path, '/' );

		if ( '' === $relative_path ) {
			return array( '', '' );
		}

		return array(
			$uploads_basedir . $relative_path,
			$uploads_baseurl . $relative_path,
		);
	}

	/**
	 * Replace upstream URLs with the local site URL inside content.
	 *
	 * @param string $content Content string.
	 * @param string $search  Search URL.
	 * @param string $replace Replacement URL.
	 * @return string
	 */
	private function replace_template_urls( $content, $search, $replace ) {
		if ( ! $search ) {
			return $content;
		}

		return str_replace( trailingslashit( $search ), trailingslashit( $replace ), $content );
	}

	/**
	 * Swap attachment ID references inside serialized block content.
	 *
	 * @param string $content Content string.
	 * @param array  $id_map  Map of original IDs to new IDs.
	 * @return string
	 */
	private function replace_template_attachment_ids( $content, $id_map ) {
		if ( empty( $id_map ) || ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$keys = array(
			'"id":',
			'"imageId":',
			'"mediaId":',
			'"mediaID":',
			'"attachmentId":',
			'"attachmentID":',
			'"featuredImageId":',
			'data-id="',
			'data-image-id="',
			'data-media-id="',
		);

		foreach ( $id_map as $original_id => $new_id ) {
			if ( ! $original_id || ! $new_id ) {
				continue;
			}

			$search = array();
			$replace = array();

			foreach ( $keys as $prefix ) {
				if ( strpos( $prefix, '=' ) !== false ) {
					$search[]  = $prefix . $original_id . '"';
					$replace[] = $prefix . $new_id . '"';
				} else {
					$search[]  = $prefix . $original_id;
					$replace[] = $prefix . $new_id;
				}
			}

			$content = str_replace( $search, $replace, $content );
		}

		return $content;
	}

	/**
	 * Update reusable block reference IDs inside imported content.
	 *
	 * @param array $post_ids List of imported post IDs.
	 * @param array $id_map   Map of original IDs => new IDs.
	 * @return void
	 */
	private function update_block_reference_ids( $post_ids, $id_map ) {
		if ( empty( $post_ids ) || empty( $id_map ) ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || empty( $post->post_content ) ) {
				continue;
			}

			$blocks = parse_blocks( $post->post_content );
			if ( empty( $blocks ) ) {
				continue;
			}

			$updated = $this->walk_block_reference_updates( $blocks, $id_map );

			if ( $updated ) {
				$serialized = serialize_blocks( $blocks );

				if ( $serialized && $serialized !== $post->post_content ) {
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => wp_slash( $serialized ),
						)
					);
				}
			}
		}
	}

	/**
	 * Recursively update reusable block refs within parsed block array.
	 *
	 * @param array $blocks Parsed blocks array (passed by reference).
	 * @param array $id_map Original => new ID map.
	 * @return bool Whether any block was updated.
	 */
	private function walk_block_reference_updates( array &$blocks, $id_map ) {
		$changed = false;

		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName'] ) {
				$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;

				if ( $ref && isset( $id_map[ $ref ] ) ) {
					$block['attrs']['ref'] = (int) $id_map[ $ref ];
					$changed               = true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( $this->walk_block_reference_updates( $block['innerBlocks'], $id_map ) ) {
					$changed = true;
				}
			}
		}

		return $changed;
	}

	/**
	 * Replace tokens stored inside post meta values.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tokens  Token replacements.
	 * @return bool Whether any meta value changed.
	 */
	private function replace_tokens_in_meta( $post_id, $tokens ) {
		if ( empty( $tokens ) ) {
			return false;
		}

		$meta    = get_post_meta( $post_id );
		$changed = false;

		foreach ( $meta as $meta_key => $values ) {
			foreach ( $values as $entry ) {
				$decoded  = maybe_unserialize( $entry );
				$replaced = $this->replace_tokens_in_value( $decoded, $tokens );

				if ( $replaced !== $decoded ) {
					update_post_meta( $post_id, $meta_key, $replaced, $decoded );
					$changed = true;
				}
			}
		}

		return $changed;
	}

	/**
	 * Recursively replace tokens within mixed values.
	 *
	 * @param mixed $value  Original value.
	 * @param array $tokens Token replacements.
	 * @return mixed Updated value.
	 */
	private function replace_tokens_in_value( $value, $tokens ) {
		if ( is_string( $value ) ) {
			return $this->template_registry->replace_tokens( $value, $tokens );
		}

		if ( is_array( $value ) ) {
			$updated = false;
			$new     = $value;

			foreach ( $value as $key => $child ) {
				$child_new = $this->replace_tokens_in_value( $child, $tokens );

				if ( $child_new !== $child ) {
					$new[ $key ] = $child_new;
					$updated     = true;
				}
			}

			return $updated ? $new : $value;
		}

		return $value;
	}

	/**
	 * Apply site identity values (title/logo) based on tokens and branding.
	 *
	 * @param array $tokens Token map.
	 * @param array $brand  Brand selections.
	 * @return void
	 */
	private function apply_site_identity( $tokens, $brand ) {
		if ( ! empty( $tokens['business_name'] ) ) {
			update_option( 'blogname', wp_strip_all_tags( $tokens['business_name'] ) );
		}

		$logo_id = isset( $brand['logo_id'] ) ? (int) $brand['logo_id'] : 0;

		if ( $logo_id > 0 ) {
			set_theme_mod( 'custom_logo', $logo_id );
			update_option( 'site_icon', $logo_id );
			$this->sync_astra_logo_settings( $logo_id );
		} elseif ( 0 === $logo_id ) {
			remove_theme_mod( 'custom_logo' );
			delete_option( 'site_icon' );
			$this->sync_astra_logo_settings( 0 );
		}
	}

	/**
	 * Align Astra logo settings with the quick start logo.
	 *
	 * @param int $logo_id Attachment ID.
	 * @return void
	 */
	private function sync_astra_logo_settings( $logo_id ) {
		$astra_settings = get_option( 'astra-settings', array() );
		if ( ! is_array( $astra_settings ) ) {
			return;
		}

		$logo_url = '';
		if ( $logo_id > 0 ) {
			$logo_url = wp_get_attachment_url( $logo_id );
			if ( ! $logo_url ) {
				$logo_url = '';
			}
		}

		$astra_settings['ast-header-retina-logo'] = $logo_url;
		$astra_settings['transparent-header-logo'] = $logo_url;
		$astra_settings['transparent-header-retina-logo'] = $logo_url;
		$astra_settings['different-retina-logo'] = false;
		$astra_settings['different-transparent-logo'] = false;

		update_option( 'astra-settings', $astra_settings );
	}

	/**
	 * Apply quick start color selections to the active Astra palette.
	 *
	 * @param array $brand Brand selections.
	 * @return void
	 */
	private function apply_brand_palette( $brand ) {
		if ( empty( $brand ) || ! is_array( $brand ) ) {
			return;
		}

		$defaults = $this->get_default_brand_palette();
		$primary  = $this->sanitize_hex_value( isset( $brand['primary_color'] ) ? $brand['primary_color'] : '', $defaults['primary_color'] );
		$secondary = $this->sanitize_hex_value( isset( $brand['secondary_color'] ) ? $brand['secondary_color'] : '', $defaults['secondary_color'] );
		$accent   = $this->sanitize_hex_value( isset( $brand['accent_color'] ) ? $brand['accent_color'] : '', $defaults['accent_color'] );
		$text     = $this->sanitize_hex_value( isset( $brand['text_color'] ) ? $brand['text_color'] : '', $defaults['text_color'] );

		$palette_option = get_option( 'astra-color-palettes', array() );
		if ( ! is_array( $palette_option ) ) {
			$palette_option = array();
		}

		$palettes = isset( $palette_option['palettes'] ) && is_array( $palette_option['palettes'] ) ? $palette_option['palettes'] : array();
		$current  = isset( $palette_option['currentPalette'] ) ? (string) $palette_option['currentPalette'] : '';

		if ( empty( $palettes ) ) {
			$palettes = array( 'palette_1' => array() );
		}

		if ( ! $current || ! isset( $palettes[ $current ] ) ) {
			$current = array_key_first( $palettes );
		}

		$palette = isset( $palettes[ $current ] ) && is_array( $palettes[ $current ] ) ? array_values( $palettes[ $current ] ) : array();

		$palette[0] = $primary;
		$palette[1] = $secondary;
		$palette[2] = $accent;
		$palette[3] = $text;

		$palettes[ $current ] = $palette;
		$palette_option['palettes'] = $palettes;
		$palette_option['currentPalette'] = $current;

		update_option( 'astra-color-palettes', $palette_option );

		$global_palette = array(
			'palette' => $this->sanitize_palette_colors( $palette ),
		);
		if ( ! empty( $global_palette['palette'] ) ) {
			update_option( 'global-color-palette', $global_palette );
		}

		$this->refresh_theme_asset_cache();
	}

	/**
	 * Clear theme caches so palette changes apply for visitors.
	 *
	 * @return void
	 */
	private function refresh_theme_asset_cache() {
		if ( function_exists( 'astra_clear_all_assets_cache' ) ) {
			astra_clear_all_assets_cache();
			return;
		}

		if ( function_exists( 'astra_clear_theme_addon_asset_cache' ) ) {
			astra_clear_theme_addon_asset_cache();
			return;
		}

		if ( class_exists( 'Astra_Cache_Base' ) ) {
			$astra_cache = new Astra_Cache_Base( 'astra' );
			$astra_cache->refresh_assets( 'astra' );

			$addon_cache = new Astra_Cache_Base( 'astra-addon' );
			$addon_cache->refresh_assets( 'astra-addon' );
			return;
		}

		do_action( 'astra_theme_update_after' );
	}

	/**
	 * Ensure the primary navigation menu exists and reflects template pages.
	 *
	 * @param array $template Template configuration.
	 * @param array $page_ids Map of identifiers => page IDs.
	 * @return void
	 */
	private function ensure_primary_navigation( $template, $page_ids ) {
		if ( empty( $template ) || empty( $page_ids ) || ! is_array( $page_ids ) ) {
			return;
		}

		$page_ids = array_change_key_case( $page_ids, CASE_LOWER );

		$menu_name = __( 'Primary Menu', 'nesta-dashboard' );
		$menu_slug = 'primary-menu';

		if ( isset( $template['navigation']['primary']['name'] ) ) {
			$menu_name = sanitize_text_field( $template['navigation']['primary']['name'] );
		}

		if ( isset( $template['navigation']['primary']['slug'] ) ) {
			$menu_slug = sanitize_title( $template['navigation']['primary']['slug'] );
		}

		$menu = wp_get_nav_menu_object( $menu_name );

		if ( ! $menu && $menu_slug ) {
			$menu = wp_get_nav_menu_object( $menu_slug );
		}

		if ( $menu ) {
			$menu_id = $menu->term_id;
		} else {
			$menu_id = wp_create_nav_menu( $menu_name );
		}

		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			return;
		}

		// Remove existing items so we can rebuild cleanly.
		$existing_items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		if ( ! empty( $existing_items ) ) {
			foreach ( $existing_items as $item ) {
				wp_delete_post( $item->ID, true );
			}
		}

		$ordered_page_ids = array();

		if ( ! empty( $template['pages'] ) && is_array( $template['pages'] ) ) {
			foreach ( $template['pages'] as $page ) {
				if ( empty( $page['slug'] ) ) {
					continue;
				}

				$slug = strtolower( $page['slug'] );

				if ( isset( $page_ids[ $slug ] ) ) {
					$ordered_page_ids[] = (int) $page_ids[ $slug ];
				}
			}
		}

		if ( empty( $ordered_page_ids ) ) {
			return;
		}

		foreach ( $ordered_page_ids as $order => $page_id ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-object-id' => $page_id,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $order + 1,
				)
			);
		}

		// Assign to the primary location.
		$locations            = get_theme_mod( 'nav_menu_locations', array() );
		$locations['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Load the decoded customizer payload for a template.
	 *
	 * @param array $template Template configuration.
	 * @param bool  $strict   Whether to return WP_Error on failures.
	 * @return array|WP_Error|null
	 */
	private function load_template_customizer_payload( $template, $strict = false ) {
		if ( empty( $template ) ) {
			return null;
		}

		$customizer_rel = '';

		if ( isset( $template['settings']['customizer'] ) ) {
			$customizer_rel = $template['settings']['customizer'];
		} elseif ( isset( $template['customizer'] ) ) {
			$customizer_rel = $template['customizer'];
		}

		if ( ! $customizer_rel ) {
			return null;
		}

		$base_dir = isset( $template['dir'] ) ? trailingslashit( $template['dir'] ) : '';

		if ( ! $base_dir ) {
			return $strict ? new WP_Error( 'nesta_customizer_dir_missing', __( 'Template directory missing for customizer settings.', 'nesta-dashboard' ) ) : null;
		}

		$customizer_path = $base_dir . ltrim( $customizer_rel, '/' );

		if ( ! file_exists( $customizer_path ) ) {
			return $strict ? new WP_Error( 'nesta_customizer_missing', __( 'Customizer settings file could not be found.', 'nesta-dashboard' ) ) : null;
		}

		if ( function_exists( 'wp_json_file_decode' ) ) {
			$payload = wp_json_file_decode( $customizer_path, array( 'associative' => true ) );
		} else {
			$raw     = file_get_contents( $customizer_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$payload = $raw ? json_decode( $raw, true ) : null;
		}

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return $strict ? new WP_Error( 'nesta_customizer_invalid', __( 'Customizer settings file is not valid JSON.', 'nesta-dashboard' ) ) : null;
		}

		return $payload;
	}

	/**
	 * Normalize the customizer payload structure.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	private function normalize_customizer_section( $payload ) {
		if ( isset( $payload['customizer-settings'] ) && is_array( $payload['customizer-settings'] ) ) {
			return $payload['customizer-settings'];
		}

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Replace tokens within existing WordPress pages.
	 *
	 * @param array $page_ids Map of identifiers => page IDs.
	 * @param array $tokens   Replacement tokens.
	 * @return int Number of pages updated.
	 */
	private function replace_tokens_on_pages( $page_ids, $tokens ) {
		if ( empty( $page_ids ) || empty( $tokens ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $page_ids as $page_id ) {
			$page_id = (int) $page_id;

			if ( $page_id <= 0 ) {
				continue;
			}

			$post = get_post( $page_id );

			if ( ! $post ) {
				continue;
			}

			$tokenizable_types = array( 'page', 'wp_block', 'sureforms_form' );
			if ( ! in_array( $post->post_type, $tokenizable_types, true ) ) {
				continue;
			}

			$original     = $post->post_content;
			$replaced     = $this->template_registry->replace_tokens( $original, $tokens );
			$page_changed = false;

			if ( $replaced !== $original ) {
				$result = wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => wp_slash( $replaced ),
					),
					true
				);

				if ( ! is_wp_error( $result ) ) {
					$page_changed = true;
				}
			}

			if ( $this->replace_tokens_in_meta( $page_id, $tokens ) ) {
				$page_changed = true;
			}

			if ( $page_changed ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Apply Astra Customizer settings bundled with a template.
	 *
	 * @param array $template Template configuration.
	 * @return true|WP_Error
	 */
	private function apply_template_customizer_settings( $template ) {
		if ( empty( $template ) ) {
			return true;
		}

		$payload = $this->load_template_customizer_payload( $template, true );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( empty( $payload ) ) {
			return true;
		}

		$section = $this->normalize_customizer_section( $payload );

		if ( isset( $section['astra-settings'] ) && is_array( $section['astra-settings'] ) ) {
			update_option( 'astra-settings', $section['astra-settings'] );
		}

		if ( isset( $section['astra-color-palettes'] ) && is_array( $section['astra-color-palettes'] ) ) {
			update_option( 'astra-color-palettes', $section['astra-color-palettes'] );
		}

		$global_palette = $this->extract_global_palette( $section );
		if ( $global_palette ) {
			update_option( 'global-color-palette', $global_palette );
		}

		if ( isset( $section['astra-typography-presets'] ) && is_array( $section['astra-typography-presets'] ) ) {
			update_option( 'astra-typography-presets', $section['astra-typography-presets'] );
		}

		if ( isset( $section['theme_mods'] ) && is_array( $section['theme_mods'] ) ) {
			foreach ( $section['theme_mods'] as $mod_key => $mod_value ) {
				set_theme_mod( $mod_key, $mod_value );
			}
		}

		if ( isset( $template['settings']['additional_css'] ) ) {
			$this->apply_template_additional_css( $template );
		}

		if ( isset( $section['custom_css'] ) && is_string( $section['custom_css'] ) ) {
			$this->apply_additional_css( $section['custom_css'] );
		}

		return true;
	}

	/**
	 * Apply Additional CSS bundled with a template file.
	 *
	 * @param array $template Template configuration.
	 * @return void
	 */
	private function apply_template_additional_css( $template ) {
		if ( empty( $template['settings']['additional_css'] ) ) {
			return;
		}

		$base_dir = isset( $template['dir'] ) ? trailingslashit( $template['dir'] ) : '';
		if ( ! $base_dir ) {
			return;
		}

		$css_path = $base_dir . ltrim( $template['settings']['additional_css'], '/' );
		if ( ! file_exists( $css_path ) ) {
			return;
		}

		$css = file_get_contents( $css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->apply_additional_css( $css );
	}

	/**
	 * Merge Additional CSS into the current theme's customizer CSS.
	 *
	 * @param string $css CSS to append.
	 * @return void
	 */
	private function apply_additional_css( $css ) {
		if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
			return;
		}

		$css = trim( (string) $css );
		if ( '' === $css ) {
			return;
		}

		$stylesheet = get_stylesheet();
		$current    = wp_get_custom_css( $stylesheet );

		if ( false !== strpos( $current, $css ) ) {
			return;
		}

		$combined = trim( $current . "\n\n" . $css );

		wp_update_custom_css_post(
			$combined,
			array(
				'stylesheet' => $stylesheet,
			)
		);
	}

	/**
	 * Token schema grouped by section.
	 *
	 * @return array
	 */
	private function get_token_schema() {
		return array(
			'business' => array(
				'title'  => __( 'Business profile', 'nesta-dashboard' ),
				'tokens' => array(
					'business_name'   => __( 'Business name', 'nesta-dashboard' ),
					'city'            => __( 'Primary city', 'nesta-dashboard' ),
					'phone_display'   => __( 'Phone number (display)', 'nesta-dashboard' ),
					'email'           => __( 'Support email', 'nesta-dashboard' ),
					'address'         => __( 'Address', 'nesta-dashboard' ),
					'service_area_1'  => __( 'Service area 1', 'nesta-dashboard' ),
					'service_area_2'  => __( 'Service area 2', 'nesta-dashboard' ),
					'service_area_3'  => __( 'Service area 3', 'nesta-dashboard' ),
					'service_area_4'  => __( 'Service area 4', 'nesta-dashboard' ),
					'service_area_5'  => __( 'Service area 5', 'nesta-dashboard' ),
					'service_area_6'  => __( 'Service area 6', 'nesta-dashboard' ),
				),
			),
			'hero'     => array(
				'title'  => __( 'Hero section', 'nesta-dashboard' ),
				'tokens' => array(
					'hero__h1'   => __( 'Hero headline', 'nesta-dashboard' ),
					'hero__desc' => __( 'Hero description', 'nesta-dashboard' ),
				),
			),
			'services' => array(
				'title'  => __( 'Services overview', 'nesta-dashboard' ),
				'tokens' => array(
					'services__item_1_title' => __( 'Service 1 title', 'nesta-dashboard' ),
					'services__item_1_desc'  => __( 'Service 1 description', 'nesta-dashboard' ),
					'services__item_2_title' => __( 'Service 2 title', 'nesta-dashboard' ),
					'services__item_2_desc'  => __( 'Service 2 description', 'nesta-dashboard' ),
					'services__item_3_title' => __( 'Service 3 title', 'nesta-dashboard' ),
					'services__item_3_desc'  => __( 'Service 3 description', 'nesta-dashboard' ),
					'services__item_4_title' => __( 'Service 4 title', 'nesta-dashboard' ),
					'services__item_4_desc'  => __( 'Service 4 description', 'nesta-dashboard' ),
					'services__item_5_title' => __( 'Service 5 title', 'nesta-dashboard' ),
					'services__item_5_desc'  => __( 'Service 5 description', 'nesta-dashboard' ),
					'services__item_6_title' => __( 'Service 6 title', 'nesta-dashboard' ),
					'services__item_6_desc'  => __( 'Service 6 description', 'nesta-dashboard' ),
					'services__item_7_title' => __( 'Service 7 title', 'nesta-dashboard' ),
					'services__item_7_desc'  => __( 'Service 7 description', 'nesta-dashboard' ),
					'services__all_title'    => __( 'All services title', 'nesta-dashboard' ),
					'services__all_desc'     => __( 'All services description', 'nesta-dashboard' ),
				),
			),
			'who'      => array(
				'title'  => __( 'Who we are', 'nesta-dashboard' ),
				'tokens' => array(
					'who__title'       => __( 'Section title', 'nesta-dashboard' ),
					'who__body'        => __( 'Body copy', 'nesta-dashboard' ),
					'who__years_badge' => __( 'Years badge', 'nesta-dashboard' ),
				),
			),
			'why'      => array(
				'title'  => __( 'Why choose us', 'nesta-dashboard' ),
				'tokens' => array(
					'why__title'        => __( 'Section title', 'nesta-dashboard' ),
					'why__intro'        => __( 'Intro paragraph', 'nesta-dashboard' ),
					'why__item_1_title' => __( 'Pillar 1 title', 'nesta-dashboard' ),
					'why__item_1_desc'  => __( 'Pillar 1 description', 'nesta-dashboard' ),
					'why__item_2_title' => __( 'Pillar 2 title', 'nesta-dashboard' ),
					'why__item_2_desc'  => __( 'Pillar 2 description', 'nesta-dashboard' ),
					'why__item_3_title' => __( 'Pillar 3 title', 'nesta-dashboard' ),
					'why__item_3_desc'  => __( 'Pillar 3 description', 'nesta-dashboard' ),
					'why__item_4_title' => __( 'Pillar 4 title', 'nesta-dashboard' ),
					'why__item_4_desc'  => __( 'Pillar 4 description', 'nesta-dashboard' ),
				),
			),
			'faq'      => array(
				'title'  => __( 'FAQ', 'nesta-dashboard' ),
				'tokens' => array(
					'faq__item_1_q' => __( 'Question 1', 'nesta-dashboard' ),
					'faq__item_1_a' => __( 'Answer 1', 'nesta-dashboard' ),
					'faq__item_2_q' => __( 'Question 2', 'nesta-dashboard' ),
					'faq__item_2_a' => __( 'Answer 2', 'nesta-dashboard' ),
					'faq__item_3_q' => __( 'Question 3', 'nesta-dashboard' ),
					'faq__item_3_a' => __( 'Answer 3', 'nesta-dashboard' ),
					'faq__item_4_q' => __( 'Question 4', 'nesta-dashboard' ),
					'faq__item_4_a' => __( 'Answer 4', 'nesta-dashboard' ),
					'faq__item_5_q' => __( 'Question 5', 'nesta-dashboard' ),
					'faq__item_5_a' => __( 'Answer 5', 'nesta-dashboard' ),
					'faq__item_6_q' => __( 'Question 6', 'nesta-dashboard' ),
					'faq__item_6_a' => __( 'Answer 6', 'nesta-dashboard' ),
				),
			),
		);
	}

	/**
	 * Flattened list of token keys.
	 *
	 * @return array
	 */
	private function get_token_keys() {
		$schema = $this->get_token_schema();
		$keys   = array();

		foreach ( $schema as $section ) {
			foreach ( $section['tokens'] as $token_key => $label ) {
				$keys[] = $token_key;
			}
		}

		return $keys;
	}

	/**
	 * Normalize a token key by stripping braces and sanitizing.
	 *
	 * @param string $key Raw token key.
	 * @return string Normalized key.
	 */
	private function normalize_token_key( $key ) {
		$key = trim( (string) $key );
		$key = preg_replace( '/^\{\{|\}\}$/', '', $key );

		return sanitize_key( $key );
	}

	/**
	 * Sanitize token values with awareness of key type.
	 *
	 * @param string $key   Token key.
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_token_value( $key, $value ) {
		if ( 'email' === $key || ( strlen( $key ) > 6 && '_email' === substr( $key, -6 ) ) ) {
			return sanitize_email( $value );
		}

		if ( strlen( $key ) > 4 && '_url' === substr( $key, -4 ) ) {
			return esc_url_raw( $value );
		}

		return sanitize_textarea_field( $value );
	}

	/**
	 * Generate or update pages based on the selected template.
	 *
	 * @param string $template_id Template identifier.
	 * @param array  $tokens      Token replacements.
	 * @return array|WP_Error
	 */
	private function generate_site_from_template( $template_id, $tokens ) {
		if ( empty( $template_id ) ) {
			return new WP_Error( 'nesta_missing_template', __( 'Select a template before generating the site.', 'nesta-dashboard' ) );
		}

		$last_run = get_option( 'nesta_quick_start_last_generation', array() );
		$page_ids = isset( $last_run['page_ids'] ) ? (array) $last_run['page_ids'] : array();

		if ( empty( $page_ids ) ) {
			return new WP_Error( 'nesta_template_empty', __( 'Install the template bundle before generating content.', 'nesta-dashboard' ) );
		}

		$template = $this->template_registry->get_template( $template_id );

		if ( empty( $template ) ) {
			return new WP_Error( 'nesta_template_not_found', __( 'Selected template could not be found.', 'nesta-dashboard' ) );
		}

		$updated = $this->replace_tokens_on_pages( $page_ids, $tokens );

		if ( isset( $page_ids['home'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_ids['home'] );
		}

		update_option(
			'nesta_quick_start_last_generation',
			array(
				'template_id' => $template_id,
				'page_ids'    => $page_ids,
				'created'     => isset( $last_run['created'] ) ? (int) $last_run['created'] : 0,
				'updated'     => $updated,
				'generated_at' => current_time( 'mysql' ),
				'content_applied' => true,
			)
		);

		return array(
			'created' => 0,
			'updated' => $updated,
			'pages'   => $page_ids,
		);
	}

	/**
	 * Handle a full quick start reset.
	 */
	public function handle_quick_start_reset() {
		if ( ! current_user_can( 'delete_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset the quick start.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_quick_start_reset' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nesta-dashboard' ) );
		}

		$last_run = get_option( 'nesta_quick_start_last_generation', array() );
		$page_ids = ( isset( $last_run['page_ids'] ) && is_array( $last_run['page_ids'] ) ) ? array_map( 'intval', $last_run['page_ids'] ) : array();

		if ( ! empty( $page_ids ) ) {
			$this->purge_quick_start_pages( $page_ids );
		}

		update_option( 'nesta_quick_start_last_generation', array() );
		delete_option( 'nesta_quick_start_last_branding' );
		delete_user_meta( get_current_user_id(), 'nesta_quick_start_last_submission' );

		$redirect = add_query_arg(
			array(
				'nesta_quick_start' => 'reset',
				'qs_step'           => 'template',
			),
			admin_url( 'admin.php?page=' . self::QUICK_START_SLUG )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle submissions from the page builder.
	 */
	public function handle_page_builder_request() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to create pages.', 'nesta-dashboard' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nesta_create_page' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nesta-dashboard' ) );
		}

		$user_id    = get_current_user_id();
		$action     = isset( $_POST['page_builder_action'] ) ? sanitize_key( wp_unslash( $_POST['page_builder_action'] ) ) : 'create';
		$blueprint  = isset( $_POST['page_blueprint'] ) ? sanitize_key( wp_unslash( $_POST['page_blueprint'] ) ) : '';
		$page_title = isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '';
		$page_slug  = isset( $_POST['page_slug'] ) ? sanitize_title( wp_unslash( $_POST['page_slug'] ) ) : '';
		$page_status = isset( $_POST['page_status'] ) && in_array( $_POST['page_status'], array( 'draft', 'publish' ), true ) ? sanitize_key( wp_unslash( $_POST['page_status'] ) ) : 'draft';
		$token_json = isset( $_POST['token_json'] ) ? wp_unslash( $_POST['token_json'] ) : '';
		$token_input = array();

		if ( isset( $_POST['tokens'] ) && is_array( $_POST['tokens'] ) ) {
			foreach ( $_POST['tokens'] as $token_key => $token_value ) {
				$normalized                 = sanitize_key( $token_key );
				$token_input[ $normalized ] = $this->sanitize_token_value( $normalized, wp_unslash( $token_value ) );
			}
		}

		$state = array(
			'blueprint'  => $blueprint,
			'page_title' => $page_title,
			'page_slug'  => $page_slug,
			'page_status'=> $page_status,
			'token_json' => $token_json,
			'tokens'     => $token_input,
		);
		$this->set_page_builder_state( $user_id, $state );

		$redirect = admin_url( 'admin.php?page=' . self::PAGE_BUILDER_SLUG );

		if ( 'apply_json' === $action ) {
			if ( empty( $token_json ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page_builder'         => 'error',
							'page_builder_message' => rawurlencode( __( 'Paste JSON before applying it.', 'nesta-dashboard' ) ),
						),
						$redirect
					)
				);
				exit;
			}

			$decoded = json_decode( $token_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page_builder'         => 'error',
							'page_builder_message' => rawurlencode( __( 'Token JSON could not be parsed. Please verify the structure.', 'nesta-dashboard' ) ),
						),
						$redirect
					)
				);
				exit;
			}

			if ( isset( $decoded['token_map'] ) && is_array( $decoded['token_map'] ) ) {
				$decoded = $decoded['token_map'];
			}

			if ( isset( $this->page_blueprints[ $blueprint ] ) ) {
				foreach ( $decoded as $json_key => $json_value ) {
					$normalized = $this->normalize_token_key( $json_key );
					if ( isset( $this->page_blueprints[ $blueprint ]['tokens'][ $normalized ] ) ) {
						$state['tokens'][ $normalized ] = $this->sanitize_token_value( $normalized, $json_value );
					}
				}
			}

			$this->set_page_builder_state( $user_id, $state );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page_builder' => 'prefilled',
					),
					$redirect
				)
			);
			exit;
		}

		if ( empty( $blueprint ) || ! isset( $this->page_blueprints[ $blueprint ] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page_builder'         => 'error',
						'page_builder_message' => rawurlencode( __( 'Select a blueprint before creating the page.', 'nesta-dashboard' ) ),
					),
					$redirect
				)
			);
			exit;
		}

		if ( empty( $page_title ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page_builder'         => 'error',
						'page_builder_message' => rawurlencode( __( 'Add a page title before continuing.', 'nesta-dashboard' ) ),
					),
					$redirect
				)
			);
			exit;
		}

		$page_data = array(
			'title'  => $page_title,
			'slug'   => $page_slug ? sanitize_title( $page_slug ) : sanitize_title( $page_title ),
			'status' => $page_status,
		);

		$token_values = array();
		foreach ( $this->page_blueprints[ $blueprint ]['tokens'] as $token_key => $label ) {
			$token_values[ $token_key ] = isset( $state['tokens'][ $token_key ] ) ? $state['tokens'][ $token_key ] : '';
		}

		$result = $this->create_page_from_blueprint( $blueprint, $page_data, $token_values );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page_builder'         => 'error',
						'page_builder_message' => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		$this->set_page_builder_state(
			$user_id,
			array(
				'blueprint'   => $blueprint,
				'page_title'  => '',
				'page_slug'   => '',
				'page_status' => 'draft',
				'token_json'  => '',
				'tokens'      => array(),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page_builder' => 'created',
					'created_page' => (int) $result,
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * Delete generated page IDs and reset front page if needed.
	 *
	 * @param array $page_ids List of page IDs.
	 * @return int Removed count.
	 */
	private function purge_quick_start_pages( $page_ids ) {
		if ( empty( $page_ids ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( $page_ids as $page_id ) {
			$page_id = (int) $page_id;

			if ( $page_id <= 0 ) {
				continue;
			}

			$post = get_post( $page_id );

			if ( $post ) {
				wp_delete_post( $page_id, true );
				$removed++;
			}
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id && in_array( $front_page_id, $page_ids, true ) ) {
			delete_option( 'page_on_front' );
			update_option( 'show_on_front', 'posts' );
		}

		return $removed;
	}

	/**
	 * Accessor for the template registry.
	 *
	 * @return Nesta_Template_Registry
	 */
	public function get_template_registry() {
		return $this->template_registry;
	}
}

new Nesta_Dashboard();
