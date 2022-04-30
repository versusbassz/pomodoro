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

Pomodoro::init();

class Pomodoro {
	protected static $tmp_dir_path = '';

	/**
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

	public static function init() {
		add_filter( 'override_load_textdomain', [ self::class, 'override_load_textdomain' ], 999, 3 );
	}

	public static function override_load_textdomain( $plugin_override, $domain, $mofile ) {
		if ( ! is_readable( $mofile ) ) {
			return false;
		}

		global $l10n;

		$upstream = empty( $l10n[ $domain ] ) ? null : $l10n[ $domain ];

		$mo = new MoCache_Translation( $mofile, $domain, $upstream );
		$l10n[ $domain ] = $mo;

		return true;
	}
}

class MoCache_Translation {
	private $domain;

	private $cache = [];

	private $busted = false;

	private $override;

	private $upstream = null;

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
	 * @param $override
	 */
	public function __construct( $mofile, $domain, $override ) {
		$this->mofile = apply_filters( 'load_textdomain_mofile', $mofile, $domain );
		$this->domain = $domain;
		$this->override = $override;

		$temp_dir = Pomodoro::get_temp_dir();

		$filename = md5( serialize( [ get_home_url(), $this->domain, $this->mofile ] ) );

		$cache_file = sprintf( '%s/%s.mocache', untrailingslashit( $temp_dir ), $filename );

		$mtime = filemtime( $this->mofile );

		$file_exists = file_exists( $cache_file );

		if ( $file_exists ) {
			/**
			 * Load cache.
			 *
			 * OPcache will grab the values from memory.
			 */
			include $cache_file;
			$this->cache = &$_cache;

			/**
			 * Mofile has been modified, invalidate it all.
			 */
			if ( ! isset( $_mtime ) || $_mtime < $mtime ) {
				$this->cache = [];
			}
		}

		$_this = &$this;

		register_shutdown_function( function() use ( $cache_file, $_this, $mtime, $domain, $file_exists ) {
			/**
			 * New values have been found. Dump everything into a valid PHP script.
			 */
			if ( $_this->busted || ( empty( $_this->cache ) && ! $file_exists ) ) {
				$test_cache_file = "$cache_file.test";

				file_put_contents(
					$test_cache_file,
					sprintf(
						'<?php $_mtime = %d; $_domain = %s; $_cache = %s; // %s',
						$mtime,
						var_export( $domain, true ),
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
			}
		} );
	}

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
	 * The translate() function implementation that WordPress calls.
	 */
	public function translate( $text, $context = null ) {
		return $this->get_translation( $this->cache_key( func_get_args() ), $text, func_get_args() );
	}

	/**
	 * The translate_plural() function implementation that WordPress calls.
	 */
	public function translate_plural( $singular, $plural, $count, $context = null ) {
		$text = ( abs( $count ) == 1 ) ? $singular : $plural;

		return $this->get_translation( $this->cache_key( [ $text, $count, $context ] ), $text, func_get_args() );
	}

	/**
	 * Cache key calculator.
	 */
	private function cache_key( $args ) {
		return md5( serialize( [ $args, $this->domain ] ) );
	}
}
