<?php

declare(strict_types=1);

/**
 * Twig Template Engine for PolyTrans
 *
 * Provides modern templating with Twig while maintaining backward compatibility
 * with legacy {variable} syntax.
 *
 * Features:
 * - Twig 3.x template rendering with caching
 * - Legacy syntax conversion ({variable} → {{ variable }})
 * - Deprecated variable mapping (post_title → title)
 * - WordPress filters (wp_excerpt, wp_date, wp_kses)
 * - Graceful fallback to regex interpolation on errors
 *
 * @package PolyTrans
 * @subpackage Templating
 * @since 1.4.0
 */

namespace PolyTrans\Templating;

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\Error\Error as TwigError;
use PolyTrans\Core\Diagnostics;

/**
 * Twig Template Engine
 *
 * Handles template rendering using Twig with WordPress integration.
 */
final class TwigEngine {
	/**
	 * Twig environment instance
	 *
	 * @var Environment|null
	 */
	private static ?Environment $twig = null;

	/**
	 * Cache directory path
	 *
	 * @var string
	 */
	private static string $cache_dir = '';

	/**
	 * Whether to enable debug mode
	 *
	 * @var bool
	 */
	private static bool $debug = false;

	/**
	 * Deprecated variable mappings (old => new)
	 *
	 * Maps legacy variable names to new standardized names.
	 *
	 * @var array<string, string>
	 */
	private const DEPRECATED_MAPPINGS = array(
		'post_title'          => 'title',
		'post_content'        => 'content',
		'post_excerpt'        => 'excerpt',
		'post_date'           => 'date',
		'post_author'         => 'author',
		'translated_title'    => 'translated.title',
		'translated_content'  => 'translated.content',
		'translated_excerpt'  => 'translated.excerpt',
		'original_title'      => 'original.title',
		'original_content'    => 'original.content',
		'original_excerpt'    => 'original.excerpt',
	);

	/**
	 * Initialize Twig environment
	 *
	 * Sets up Twig with caching, WordPress filters, and custom functions.
	 *
	 * @param array<string, mixed> $options Configuration options.
	 * @return void
	 */
	public static function init( array $options = array() ): void {
		if ( null !== self::$twig ) {
			return; // Already initialized.
		}

		// Configure cache directory.
		self::$cache_dir = $options['cache_dir'] ?? dirname( __DIR__, 2 ) . '/cache/twig';
		self::$debug     = $options['debug'] ?? ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		// Enable caching in production (WP_DEBUG = false)
		$use_cache = ! self::$debug;

		// Ensure cache directory exists (only if caching is enabled).
		if ( $use_cache && ! file_exists( self::$cache_dir ) ) {
			// Try to create cache directory with proper permissions
			if ( wp_mkdir_p( self::$cache_dir ) ) {
				// Set permissions to 777 so Docker container (www-data) can write
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Cache directory management
				@chmod( self::$cache_dir, 0777 );
			} else {
				// If creation fails, disable caching
				$use_cache = false;
			}
		}

		// Initialize Twig with ArrayLoader (templates provided as strings).
		$loader      = new ArrayLoader();
		self::$twig = new Environment(
			$loader,
			array(
				'cache'            => $use_cache ? self::$cache_dir : false,
				'debug'            => self::$debug,
				'auto_reload'      => self::$debug,
				'strict_variables' => false, // Don't error on missing variables.
				'autoescape'       => false, // WordPress handles escaping.
			)
		);

		// Add WordPress filters.
		self::add_wordpress_filters();

		// Add custom functions.
		self::add_custom_functions();
	}

	/**
	 * Render template with context
	 *
	 * Main entry point for template rendering. Handles legacy syntax conversion,
	 * deprecated variable mapping, and graceful fallback.
	 *
	 * @param string               $template Template string to render.
	 * @param array<string, mixed> $context  Variables available in template.
	 * @return string Rendered template.
	 */
	public static function render( string $template, array $context = array() ): string {
		// Initialize if not already done.
		if ( null === self::$twig ) {
			self::init();
		}

		try {
			// Convert legacy syntax to Twig.
			$twig_template = self::convert_legacy_syntax( $template );

			// Add deprecated variable mappings to context.
			$context = self::add_deprecated_mappings( $context );

			// Create unique template name.
			$template_name = 'template_' . md5( $twig_template );

			// Load template into Twig.
			$loader = self::$twig->getLoader();
			if ( $loader instanceof ArrayLoader ) {
				$loader->setTemplate( $template_name, $twig_template );
			}

			// Render template.
			return self::$twig->render( $template_name, $context );

		} catch ( TwigError $e ) {
			// Twig failed, log error and fall back to legacy regex.
			if ( self::$debug ) {
				Diagnostics::log(
					sprintf(
						'[TreeTank] Twig rendering failed: %s. Falling back to regex. Template: %s',
						$e->getMessage(),
						$template
					)
				);
			}

			return self::fallback_regex_interpolation( $template, $context );
		}
	}

	/**
	 * Convert legacy {variable} syntax to Twig {{ variable }}
	 *
	 * Handles simple variable interpolation. Advanced Twig features
	 * (if/for/filters) should use Twig syntax directly.
	 *
	 * @param string $template Template string with legacy syntax.
	 * @return string Template with Twig syntax.
	 */
	private static function convert_legacy_syntax( string $template ): string {
		// If template already uses Twig syntax ({{ or {%), don't convert
		if ( strpos( $template, '{{' ) !== false || strpos( $template, '{%' ) !== false ) {
			return $template;
		}

		// Convert {variable} to {{ variable }}.
		// Matches: {word} but not {% or {{ (already Twig).
		$pattern = '/\{(?![\{%])\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}/';
		$result  = preg_replace( $pattern, '{{ $1 }}', $template );

		return $result ?? $template;
	}

	/**
	 * Add deprecated variable mappings to context
	 *
	 * Creates aliases for deprecated variable names, allowing old templates
	 * to continue working without modification.
	 *
	 * @param array<string, mixed> $context Original context.
	 * @return array<string, mixed> Context with deprecated aliases added.
	 */
	private static function add_deprecated_mappings( array $context ): array {
		foreach ( self::DEPRECATED_MAPPINGS as $old => $new ) {
			// If old variable already exists, skip (user-provided takes precedence).
			if ( isset( $context[ $old ] ) ) {
				continue;
			}

			// Map old to new (handle dot notation for nested access).
			if ( strpos( $new, '.' ) !== false ) {
				$parts = explode( '.', $new );
				$value = $context;
				foreach ( $parts as $part ) {
					if ( ! isset( $value[ $part ] ) ) {
						$value = null;
						break;
					}
					$value = $value[ $part ];
				}
				if ( null !== $value ) {
					$context[ $old ] = $value;
				}
			} else {
				// Simple mapping.
				if ( isset( $context[ $new ] ) ) {
					$context[ $old ] = $context[ $new ];
				}
			}
		}

		return $context;
	}

	/**
	 * Add WordPress-specific filters to Twig
	 *
	 * Provides filters for common WordPress operations.
	 *
	 * @return void
	 */
	private static function add_wordpress_filters(): void {
		if ( null === self::$twig ) {
			return;
		}

		// Add wp_excerpt filter: Truncate text like wp_trim_excerpt().
		self::$twig->addFilter(
			new TwigFilter(
				'wp_excerpt',
				function ( ?string $text, int $length = 55 ): string {
					if ( empty( $text ) ) {
						return '';
					}
					return wp_trim_words( $text, $length, '...' );
				}
			)
		);

		// Add wp_date filter: Format date like WordPress.
		self::$twig->addFilter(
			new TwigFilter(
				'wp_date',
				function ( ?string $date, string $format = '' ): string {
					if ( empty( $date ) ) {
						return '';
					}
					if ( empty( $format ) ) {
						$format = get_option( 'date_format' );
					}
					return date_i18n( $format, strtotime( $date ) );
				}
			)
		);

		// Add wp_kses filter: Sanitize HTML like wp_kses_post().
		self::$twig->addFilter(
			new TwigFilter(
				'wp_kses',
				function ( ?string $html ): string {
					if ( empty( $html ) ) {
						return '';
					}
					return wp_kses_post( $html );
				}
			)
		);

		// Add esc_html filter.
		self::$twig->addFilter(
			new TwigFilter(
				'esc_html',
				function ( ?string $text ): string {
					if ( empty( $text ) ) {
						return '';
					}
					return esc_html( $text );
				}
			)
		);

		// Add esc_url filter.
		self::$twig->addFilter(
			new TwigFilter(
				'esc_url',
				function ( ?string $url ): string {
					if ( empty( $url ) ) {
						return '';
					}
					return esc_url( $url );
				}
			)
		);
	}

	/**
	 * Add custom functions to Twig
	 *
	 * Provides helper functions for templates.
	 *
	 * @return void
	 */
	private static function add_custom_functions(): void {
		if ( null === self::$twig ) {
			return;
		}

		// Add get_permalink function.
		self::$twig->addFunction(
			new TwigFunction(
				'get_permalink',
				function ( int $post_id = 0 ): string {
					return get_permalink( $post_id );
				}
			)
		);

		// Add get_post_meta function.
		self::$twig->addFunction(
			new TwigFunction(
				'get_post_meta',
				function ( int $post_id, string $key, bool $single = false ) {
					return get_post_meta( $post_id, $key, $single );
				}
			)
		);
	}

	/**
	 * Fallback regex interpolation
	 *
	 * Used when Twig rendering fails. Simple {variable} replacement.
	 *
	 * @param string               $template Template string.
	 * @param array<string, mixed> $context  Variables to interpolate.
	 * @return string Interpolated template.
	 */
	private static function fallback_regex_interpolation( string $template, array $context ): string {
		// Add deprecated mappings.
		$context = self::add_deprecated_mappings( $context );

		// Simple regex replacement: {variable} → value.
		$result = preg_replace_callback(
			'/\{([a-zA-Z_][a-zA-Z0-9_\.]*)\}/',
			function ( array $matches ) use ( $context ) {
				$var_name = $matches[1];

				// Handle dot notation (e.g., original.title).
				if ( strpos( $var_name, '.' ) !== false ) {
					$parts = explode( '.', $var_name );
					$value = $context;
					foreach ( $parts as $part ) {
						if ( ! isset( $value[ $part ] ) ) {
							return $matches[0]; // Not found, keep original.
						}
						$value = $value[ $part ];
					}
					return (string) $value;
				}

				// Simple variable.
				return isset( $context[ $var_name ] ) ? (string) $context[ $var_name ] : $matches[0];
			},
			$template
		);

		return $result ?? $template;
	}

	/**
	 * Clear Twig cache
	 *
	 * Useful for development/debugging.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function clear_cache(): bool {
		if ( ! file_exists( self::$cache_dir ) ) {
			return true; // Nothing to clear.
		}

		// Use WordPress filesystem to recursively delete.
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( $wp_filesystem ) {
			return $wp_filesystem->rmdir( self::$cache_dir, true );
		}

		// Fallback to PHP rmdir (less safe).
		return self::recursive_rmdir( self::$cache_dir );
	}

	/**
	 * Recursive directory removal (fallback)
	 *
	 * @param string $dir Directory to remove.
	 * @return bool True on success.
	 */
	private static function recursive_rmdir( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				self::recursive_rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cache file cleanup
				unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cache directory cleanup
		return rmdir( $dir );
	}

	/**
	 * Get Twig environment (for advanced usage)
	 *
	 * @return Environment|null
	 */
	public static function get_twig(): ?Environment {
		return self::$twig;
	}

	/**
	 * Reset Twig instance (for testing)
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$twig = null;
	}
}
