<?php
/**
 * Template registry for Nesta Dashboard.
 *
 * Discovers bundled template packs and exposes helpers
 * for fetching manifests and hydrated page content.
 *
 * @package NestaDashboard
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Nesta_Template_Registry' ) ) {
	return;
}

class Nesta_Template_Registry {
	/**
	 * Template source directories and base URLs.
	 *
	 * @var array
	 */
	private $sources = array();

	/**
	 * Cached template manifests.
	 *
	 * @var array
	 */
	private $templates = null;

	/**
	 * Constructor.
	 *
	 * @param string $bundled_dir Absolute path to bundled templates.
	 * @param string $bundled_url Public URL base to bundled templates.
	 */
	public function __construct( $bundled_dir, $bundled_url ) {
		$this->sources[] = array(
			'dir' => trailingslashit( $bundled_dir ),
			'url' => trailingslashit( $bundled_url ),
		);
	}

	/**
	 * Register an additional template source directory.
	 *
	 * @param string $dir Absolute path to templates.
	 * @param string $url Base URL to templates.
	 * @return void
	 */
	public function add_source( $dir, $url ) {
		if ( ! $dir || ! $url ) {
			return;
		}

		$this->sources[] = array(
			'dir' => trailingslashit( $dir ),
			'url' => trailingslashit( $url ),
		);
		$this->templates = null;
	}

	/**
	 * Clear cached templates so sources are re-scanned.
	 *
	 * @return void
	 */
	public function refresh() {
		$this->templates = null;
	}

	/**
	 * Return all available templates keyed by template ID.
	 *
	 * @return array
	 */
	public function get_templates() {
		if ( is_array( $this->templates ) ) {
			return $this->templates;
		}

		$this->templates = array();

		if ( empty( $this->sources ) ) {
			return $this->templates;
		}

		foreach ( $this->sources as $source ) {
			if ( empty( $source['dir'] ) || empty( $source['url'] ) ) {
				continue;
			}

			if ( ! is_dir( $source['dir'] ) ) {
				continue;
			}

			$manifests = glob( $source['dir'] . '*/manifest.json' );

			if ( empty( $manifests ) ) {
				continue;
			}

			foreach ( $manifests as $manifest_path ) {
				$template = $this->parse_manifest( $manifest_path, $source['dir'], $source['url'] );

				if ( empty( $template['id'] ) ) {
					continue;
				}

				$this->templates[ $template['id'] ] = $template;
			}
		}

		return $this->templates;
	}

	/**
	 * Retrieve a template manifest by ID.
	 *
	 * @param string $template_id Template identifier.
	 * @return array|null
	 */
	public function get_template( $template_id ) {
		$templates = $this->get_templates();

		return isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;
	}

	/**
	 * Get hydrated page markup for a template.
	 *
	 * @param string $template_id Template identifier.
	 * @param string $page_key    Page key from manifest.
	 * @param array  $tokens      Replacement tokens.
	 * @return string
	 */
	public function get_page_markup( $template_id, $page_key, $tokens = array() ) {
		$template = $this->get_template( $template_id );

		if ( empty( $template ) || empty( $template['pages'][ $page_key ] ) ) {
			return '';
		}

		$page = $template['pages'][ $page_key ];

		if ( empty( $page['absolute_file'] ) || ! file_exists( $page['absolute_file'] ) ) {
			return '';
		}

		$markup = file_get_contents( $page['absolute_file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $markup ) {
			return '';
		}

		return $this->replace_tokens( $markup, $tokens );
	}

	/**
	 * Replace {{token}} placeholders in a string.
	 *
	 * @param string $content Content string.
	 * @param array  $tokens  Key/value replacements.
	 * @return string
	 */
	public function replace_tokens( $content, $tokens ) {
		if ( empty( $tokens ) || '' === $content ) {
			return $content;
		}

		$patterns = array();
		$replace  = array();

		foreach ( $tokens as $key => $value ) {
			$normalized = $this->normalize_token_placeholder( $key );

			if ( '' === $normalized ) {
				continue;
			}

			$patterns[] = '/\{\{\s*' . preg_quote( $normalized, '/' ) . '\s*\}\}/i';
			$replace[]  = $value;
		}

		if ( empty( $patterns ) ) {
			return $content;
		}

		return preg_replace( $patterns, $replace, $content );
	}

	/**
	 * Parse the template manifest file into an array.
	 *
	 * @param string $manifest_path Absolute manifest path.
	 * @return array
	 */
	private function parse_manifest( $manifest_path, $source_dir, $source_url ) {
		$dir = trailingslashit( dirname( $manifest_path ) );

		$data = $this->decode_json_file( $manifest_path );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return array();
		}

		$relative_dir = ltrim( str_replace( $source_dir, '', $dir ), '/' );
		$data['dir']  = $dir;
		$data['url']  = $source_url . trailingslashit( $relative_dir );

		if ( ! empty( $data['screenshot'] ) ) {
			$data['screenshot_url'] = esc_url_raw( $data['url'] . ltrim( $data['screenshot'], '/' ) );
		}

		if ( ! empty( $data['pages'] ) && is_array( $data['pages'] ) ) {
			foreach ( $data['pages'] as $key => $page ) {
				$file = isset( $page['file'] ) ? $page['file'] : '';
				$data['pages'][ $key ]['absolute_file'] = $file ? $dir . ltrim( $file, '/' ) : '';
			}
		} else {
			$data['pages'] = array();
		}

		return $data;
	}

	/**
	 * Decode JSON file contents to array.
	 *
	 * @param string $file Absolute path.
	 * @return array|null
	 */
	private function decode_json_file( $file ) {
		if ( function_exists( 'wp_json_file_decode' ) ) {
			$decoded = wp_json_file_decode( $file, array( 'associative' => true ) );
			return $decoded;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return null;
		}

		return json_decode( $contents, true );
	}

	/**
	 * Normalize token placeholders by stripping braces and sanitizing.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function normalize_token_placeholder( $key ) {
		$key = trim( (string) $key );
		$key = preg_replace( '/^\{\{|\}\}$/', '', $key );

		return sanitize_key( $key );
	}
}
