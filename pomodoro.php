<?php
/**
 * Plugin Name: POMOdoro Translation Cache
 * Version: 1.0.0-alpha
 * Description: A cached translation override for WordPress.
 * Plugin URI: https://github.com/versusbassz/pomodoro/
 * License: GPLv3
 *
 * Bakes and stows away expensive translation lookups
 *  as PHP hashtables. Fast and beautiful.
 *
 * It's a fork of https://github.com/pressjitsu/pomodoro/
 * Pressjitsu, Inc.
 * https://pressjitsu.com
 */

namespace Versusbassz\Pomodoro;

use Throwable;
use MO;
use Translations;
use WP_CLI;

define( 'POMODORO_VERSION', '1.0.0-alpha' );

Pomodoro::init();

/**
 * The root class of the plugin.
 * Contains some utility logic and starts everything.
 */
class Pomodoro {
	/**
	 * Starts the plugin
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'override_load_textdomain', [ self::class, 'override_load_textdomain' ], 999, 3 );

		if ( defined( 'WP_CLI' ) ) {
			CLI::register_commands();
		}
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
		$temp_dir = Utils::get_temp_dir();

		$filename = md5( serialize( [ $home_url, $this->domain, $this->mofile ] ) );

		$cache_file = sprintf(
			'%s/%s--%s.mocache',
			untrailingslashit( $temp_dir ),
			Utils::sanitaze_textdomain_for_fs( $this->domain ),
			$filename
		);

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
			 * Don't update cached files during a request of "wp pomodoro prune" command
			 */
			if ( defined( 'POMODORO_DONT_UPDATE' ) && POMODORO_DONT_UPDATE ) {
				return;
			}

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
		 * Default MO upstream.
		 */
		if ( ! $this->upstream ) {
			$this->upstream = new MO();

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

class CLI {
	public static function register_commands() {
		WP_CLI::add_command( 'pomodoro stats', [ self::class, 'stats' ] );
		WP_CLI::add_command( 'pomodoro list', [ self::class, 'list' ] );
		WP_CLI::add_command( 'pomodoro lint', [ self::class, 'lint' ] );
		WP_CLI::add_command( 'pomodoro prune', [ self::class, 'prune' ] );
		WP_CLI::add_command( 'pomodoro version', [ self::class, 'version' ] );
	}

	public static function stats( $args, $args_assoc ) {
		$args = wp_parse_args( $args_assoc , [
			'format' => 'regular',
		] );

		self::switch_locale();

		$data = Utils::get_dir_stats();

		switch ( $args['format'] ) {
			case 'regular':
				$content = '';

				$content .= sprintf( "Version: %s\n", POMODORO_VERSION );
				$content .= sprintf( "Cache dir: %s\n", $data['dir_path'] );
				$content .= sprintf( "Cached files: %d\n", $data['files_total'] ) ;
				$content .= sprintf( "Disk space used: %s\n", $data['files_size'] ) ;

				WP_CLI::log( trim( $content ) );
				break;

			case 'var_dump':
				var_dump( $data );
				break;

			case 'print_r':
				print_r( $data );
				break;

			case 'json':
				$flags =  isset( $args_assoc['pretty'] ) ? JSON_PRETTY_PRINT : 0;
				echo json_encode( $data, $flags ) . PHP_EOL;
				break;
		}
	}

	public static function list() {
		self::switch_locale();

		$data = Utils::get_dir_stats();

		WP_CLI::log( sprintf( "Cache dir: %s", $data['dir_path'] ) );
		WP_CLI\Utils\format_items( 'table', $data['files'], [ 'filename', 'size', 'mtime' ] );
	}

	public static function lint() {
		self::switch_locale();

		if ( ! function_exists( 'exec' ) ) {
			WP_CLI::error( '"exec" function is not available, it\'s used for linting' );
		}

		$data = Utils::get_dir_stats();

		if ( ! count( $data['files'] ) ) {
			WP_CLI::log( sprintf( 'Files not found in %s', $data['dir_path'] ) );
			return;
		}

		$successful = 0;
		$failed = 0;

		foreach ( $data['files'] as $file ) {
			$code = file_get_contents( $file['path'] );

			$old = ini_set( 'display_errors', 0 );

			++$successful;

			// https://stackoverflow.com/a/51733942
			try {
				token_get_all( $code, TOKEN_PARSE );
			} catch ( Throwable $ex ) {
				++$failed;
				--$successful;
				$error = $ex->getMessage();
				$line = $ex->getLine();
				WP_CLI::log( sprintf( "Parse error:\n\tpath: %s:%s\n\toutput: %s", $file['path'], $line, $error ) );
			} finally {
				ini_set('display_errors', $old);
			}
		}

		WP_CLI::line('===================');
		WP_CLI::log( sprintf( 'Total files: %d', $data['files_total'] ) );

		$valid_files_msg = sprintf( 'Valid files: %d', $successful );
		$failed ? WP_CLI::log( $valid_files_msg ) : WP_CLI::success( $valid_files_msg );

		if ( $failed ) {
			WP_CLI::error( sprintf( 'Validation failed for: %d', $failed ) );
		}
	}

	public static function prune( $args ) {
		self::switch_locale();

		$data = Utils::get_dir_stats();

		if ( ! count( $data['files'] ) ) {
			WP_CLI::log( sprintf( 'Files not found in %s', $data['dir_path'] ) );
			return;
		}

		$files = $data['files'] ;

		$textdomain = isset( $args[0] ) && $args[0] ? $args[0] : '';

		if ( $textdomain ) {
			$files = array_filter( $files, function ( $file ) use ( $textdomain ) {
				$textdomain_for_fs = Utils::sanitaze_textdomain_for_fs( $textdomain );

				return strpos( $file['filename'], $textdomain_for_fs ) === 0;
			} );
		}

		$removed_counter = 0;
		$failed_counter = 0;

		foreach ( $files as $file ) {
			$removed = unlink( $file['path'] );

			if ( ! $removed ) {
				++$failed_counter;
				WP_CLI::log( sprintf( 'Removing failed for: %s', $file['path'] ) );
			}

			++$removed_counter;
		}

		WP_CLI::log( sprintf( 'Removed files: %d', $removed_counter ) );

		if ( $failed_counter ) {
			WP_CLI::error( sprintf( 'Total failed files: %d', $failed_counter ) );
		}

		/**
		 * Don't update cached files during a request of "wp pomodoro prune" command
		 */
		if ( ! defined( 'POMODORO_DONT_UPDATE' ) ) {
			define( 'POMODORO_DONT_UPDATE', true );
		}

		WP_CLI::success( 'Done' );
	}

	public static function version() {
		WP_CLI::log( POMODORO_VERSION );
	}

	/**
	 * Use default locale to not to apply translation to files sizes
	 *
	 * @return void
	 */
	protected static function switch_locale() {
		switch_to_locale( 'en_US' );
	}
}

class Utils {
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

	public static function get_dir_stats() {
		$cache_dir = Utils::get_temp_dir();
		$files = scandir( $cache_dir );

		$files_data = [];
		$files_total = 0;
		$files_total_size = 0;

		foreach ( $files as $file ) {
			$file_path = trailingslashit( $cache_dir ) . $file;

			if ( preg_match( '/\.mocache$/', $file_path ) && is_file( $file_path ) ) {
				$file_size = filesize( $file_path );

				++$files_total;
				$files_total_size += $file_size;

				$files_data[] = [
					'filename' => $file,
					'path' => $file_path,
					'size' => size_format( $file_size ),
					'size_raw' => $file_size,
					'mtime' => date( 'Y-m-d H:i:s', filemtime( $file_path ) ),
					'ctime' => date( 'Y-m-d H:i:s', filectime( $file_path ) ),
				];
			}
		}

		return [
			'dir_path' => $cache_dir,
			'files_total' => $files_total,
			'files_size_raw' => $files_total_size,
			'files_size' => size_format( $files_total_size ),
			'files' => $files_data,
		];
	}

	public static function sanitaze_textdomain_for_fs( $textdomain ) {
		return preg_replace( '/[^A-Za-z0-9\-_]/', '-' , $textdomain );
	}
}
