<?php
/**
 * Plugin Name: POMOdoro Translation Cache
 * Description: A cached translation override for WordPress.
 * Plugin URI: https://github.com/pressjitsu/pomodoro/
 *
 * Bakes and stows away expensive translation lookups
 *  as PHP hashtables. Fast and beautiful.
 *
 * GPL3
 * Pressjitsu, Inc.
 * https://pressjitsu.com
 */

namespace Pressjitsu\Pomodoro;

use Mo;
use Translations;

Pomodoro::init();

/**
 * The root class of the plugin.
 * Contains some utility logic and starts everything.
 */
class Pomodoro {
	/**
	 * @var string The path in filesystem to a directory where cached files are stored
	 */
	protected static $tmp_dir_path = '';

	/**
	 * Returns the path in filesystem to a directory where cached files are stored.
	 * Tries to create the directory if it doesn't exist and POMODORO_CACHE_DIR constant is used.
	 *
	 * @return string
	 */
	public static function get_temp_dir() {
		if ( self::$tmp_dir_path ) {
			return self::$tmp_dir_path;
		}

		if ( defined( 'POMODORO_CACHE_DIR' ) && POMODORO_CACHE_DIR ) {
			$path_exists = wp_mkdir_p( POMODORO_CACHE_DIR );

			// TODO trigger error here if $path_exists === false

			if ( $path_exists ) {
				self::$tmp_dir_path = POMODORO_CACHE_DIR;
				return self::$tmp_dir_path;
			}
		}

		self::$tmp_dir_path = get_temp_dir();

		return self::$tmp_dir_path;
	}

	/**
	 * Starts the plugin
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'override_load_textdomain', [ self::class, 'override_load_textdomain' ], 999, 3 );
	}

	/**
	 * The handler for 'override_load_textdomain' filter in load_textdomain() function
	 *
	 * @see load_textdomain()
	 *
	 * @param bool $plugin_override The flag for load_textdomain() to stop loading a textdomain
	 *                              if the value is true.
	 * @param string $domain Text domain. Unique identifier for retrieving translated strings.
	 * @param string $mofile Path to the .mo file.
	 *
	 * @return bool
	 */
	public static function override_load_textdomain( $plugin_override, $domain, $mofile ) {
		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		$mofile = apply_filters( 'load_textdomain_mofile', $mofile, $domain );

		global $l10n;

		$upstream = empty( $l10n[ $domain ] ) ? null : $l10n[ $domain ];

		$mo = new MoCache_Translation( $mofile, $domain, $upstream );
		$l10n[ $domain ] = $mo;

		return true;
	}
}

class MoCache_Translation {
	/**
	 * @var string The textdomain of a current .mo file
	 */
	private $domain;

	/**
	 * @var string[] The set of cached strings for translation
	 */
	private $cache = [];

	/**
	 * @var bool Did new strings have been requested ("new" means "are not in cache").
	 *           "True" value triggers rebuilding of a cached file
	 */
	private $busted = false;

	/**
	 * @var Translations The object for a current textdomain that was in $l10n global variable
	 *                   before we try to place our own cache object there.
	 *
	 *                   Notes: \MO class is descendant of \Translations
	 */
	private $override;

	/**
	 * @var MO|null The origin \MO object (the representation of .mo file)
	 */
	private $upstream = null;

	/**
	 * @var string The path in filesystem to a target .mo file
	 */
	private $mofile;

	/**
	 * Cache file end marker.
	 */
	private const END = 'POMODORO_END_e867edfb-4a36-4643-8ad4-b95507068e44';

	/**
	 * Construct the main translation cache instance for a domain.
	 *
	 * @param string $mofile The path to the mo file.
	 * @param string $domain The textdomain.
	 * @param Translations $override The class in the same domain, we have overridden it
	 */
	public function __construct( $mofile, $domain, $override ) {
		$this->mofile = $mofile;
		$this->domain = $domain;
		$this->override = $override;

		$home_url = get_home_url();
		$temp_dir = Pomodoro::get_temp_dir();

		$filename = md5( serialize( [ $home_url, $this->domain, $this->mofile ] ) );

		$cache_file = sprintf( '%s/%s.mocache', untrailingslashit( $temp_dir ), $filename );

		$current_mtime = filemtime( $this->mofile );

		$file_exists = file_exists( $cache_file );

		if ( $file_exists ) {
			/**
			 * Load cache.
			 *
			 * OPcache will grab the values from memory.
			 */
			include $cache_file;

			/** @var int $_mtime Timestamp. Modification time of source .mo file that were cached on the moment of caching. */
			/** @var string $_domain The textdomain of a source .mo file. */
			/** @var array $_cache The cached data (strings for translation). */

			$cached_mtime = $_mtime ?? 0;

			if ( isset( $_mtime ) && isset( $_cache ) && $cached_mtime === $current_mtime ) {
				$this->cache = &$_cache;
			} else {
				// Mofile has been modified, or it's invalid (doesn't contain necessary data).
				// Invalidate the cache (immediately) and rebuild the cached file (on shutdown) as a consequence
				$this->cache = [];
			}
		}

		$_this = $this;

		register_shutdown_function( function() use ( $cache_file, $_this, $current_mtime, $file_exists ) {
			/**
			 * About this check:
			 * empty( $_this->cache ) && ! $file_exists
			 *
			 * The idea is that for consistency the plugin dumps even empty cache (i.e. $this->cache === [])
			 */
			if ( ! $_this->busted && ! ( empty( $_this->cache ) && ! $file_exists ) ) {
				return;
			}

			/**
			 * New values have been found. Dump everything into a valid PHP script.
			 */
			$test_cache_file = "$cache_file.test";

			file_put_contents(
				$test_cache_file,
				sprintf(
					'<?php $_mtime = %d; $_domain = %s; $_cache = %s; // %s',
					$current_mtime,
					var_export( $_this->domain, true ),
					var_export( $_this->cache, true ),
					self::END
				),
				LOCK_EX
			);

			// Test the file before committing.
			$fp = fopen( $test_cache_file, 'rb' );

			fseek( $fp, -strlen( self::END ), SEEK_END );

			if ( fgets( $fp ) === self::END ) {
				rename( $test_cache_file, $cache_file );
			} else {
				trigger_error( "pomodoro {$test_cache_file} cache file missing end marker." );
				unlink( $test_cache_file );
			}

			fclose( $fp );
		} );
	}

	/**
	 * Fetches a translated string from the cache of other sources
	 *
	 * @param string $cache_key The hash of arguments of the higher functions (see $args parameter)
	 * @param string $text The string to translate
	 * @param array $args The arguments of the higher functions themselves
	 *
	 * @return mixed
	 */
	private function get_translation( $cache_key, $text, $args ) {
		/**
		 * Check cache first.
		 */
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		/**
		 * Bust it.
		 */
		$this->busted = true;

		$translate_function = count( $args ) > 2 ? 'translate_plural' : 'translate';

		/**
		 * Merge overrides.
		 */
		if ( $this->override ) {
			return $this->cache[ $cache_key ] = call_user_func_array( [ $this->override, $translate_function ], $args );
		}

		/**
		 * Default Mo upstream.
		 */
		if ( ! $this->upstream ) {
			$this->upstream = new Mo();

			do_action( 'load_textdomain', $this->domain, $this->mofile );

			$this->upstream->import_from_file( $this->mofile );
		}

		return $this->cache[ $cache_key ] = call_user_func_array( [ $this->upstream, $translate_function ], $args );
	}

	/**
	 * The \Translations->translate() method implementation that WordPress calls.
	 *
	 * @param string $text The string for translation.
	 * @param string $context The description for a usage case (in fact, it's gettext context string).
	 *
	 * @return string
	 */
	public function translate( $text, $context = null ) {
		return $this->get_translation( $this->cache_key( func_get_args() ), $text, func_get_args() );
	}

	/**
	 * The \Translations->translate_plural() method implementation that WordPress calls.
	 *
	 * @param string $singular The singular form of the string for translation.
	 * @param string $plural The plural form of the string for translation.
	 * @param int    $count The quantity of items for a current call to detect singular/plural form.
	 * @param string $context The description for a usage case (in fact, it's gettext context string).
	 *
	 * @return string
	 */
	public function translate_plural( $singular, $plural, $count, $context = null ) {
		$text = ( abs( $count ) == 1 ) ? $singular : $plural;

		return $this->get_translation( $this->cache_key( [ $text, $count, $context ] ), $text, func_get_args() );
	}

	/**
	 * Cache key calculator.
	 *
	 * @param array $args The parameters of translate* functions.
	 *
	 * @return string The key (hash) for the provided $args.
	 */
	private function cache_key( $args ) {
		return md5( serialize( [ $args, $this->domain ] ) );
	}
}
