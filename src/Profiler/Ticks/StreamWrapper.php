<?php

namespace Scanfully\Profiler\Ticks;

/**
 * StreamWrapper class.
 *
 * A stream wrapper to inject declare(ticks=1); into PHP files.
 */
class StreamWrapper {

	/**
	 * The protocol used by the stream wrapper
	 */
	const protocol = 'file';

	/**
	 * Length of declare(ticks=1); to inject
	 */
	private static int $tick_declare_length = 0;

	private static $io_stats = [
		'cast'       => 0,
		'chgrp'      => 0,
		'chmod'      => 0,
		'chown'      => 0,
		'close'      => 0,
		'closedir'   => 0,
		'eof'        => 0,
		'flush'      => 0,
		'lock'       => 0,
		'mkdir'      => 0,
		'open'       => 0,
		'opendir'    => 0,
		'readdir'    => 0,
		'rewinddir'  => 0,
		'read'       => 0,
		'rename'     => 0,
		'rmdir'      => 0,
		'seek'       => 0,
		'set_option' => 0,
		'stat'       => 0,
		'tell'       => 0,
		'truncate'   => 0,
		'write'      => 0,
		'touch'      => 0,
		'unlink'     => 0,
		'url_stat'   => 0
	];
	private static $io_read = 0;
	private static $io_write = 0;
	private static $io_list;
	private static $unclosed = [];

	public $context;
	public $resource;

	/**
	 * Set in stream_open, tells steam_read if it should inject declare(ticks=1);
	 *
	 * @var bool $should_inject
	 */
	private bool $should_inject = true;

	/**
	 * (Partial) path exclusions (case sensitive)
	 *
	 * @var array|string[] $exclusions
	 */
	private static array $exclusions = [ 'scanfully/src/Profiler' ];

	/**
	 * Start the stream wrapper
	 *
	 * @return void
	 */
	public static function start(): void {

		// needed for the declare(ticks=1); injection
		self::$tick_declare_length = 17 + strlen( (string) TickProfiler::ticks );

		// Register the stream wrapper
		stream_wrapper_unregister( self::protocol );
		stream_wrapper_register( self::protocol, StreamWrapper::class );
	}

	/**
	 * Stop the stream wrapper
	 *
	 * @return void
	 */
	public static function stop(): void {
		// Unregister the stream wrapper
		stream_wrapper_restore( self::protocol );
	}

	/**
	 * Check if the path is excluded
	 *
	 * @param  string $path
	 *
	 * @return bool
	 */
	private static function check_exclusions( string $path ): bool {

		foreach ( StreamWrapper::$exclusions as $item ) {
			if ( $item && strpos( $path, $item ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Opens a stream
	 *
	 * @param  string $path
	 * @param  string $mode
	 * @param  int $options
	 * @param  string|null $opened_path
	 *
	 * @return bool
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		//self::$io_stats['open']++;

		self::stop();
		if ( isset( $this->context ) ) {
			$this->resource = fopen( $path, $mode, $options, $this->context );
		} else {
			$this->resource = fopen( $path, $mode, $options );
		}

		//self::$io_list                           .= "[$path][$mode][" . (int) $this->resource . "]\n";
		//self::$unclosed[ (int) $this->resource ] = $path;

		self::start();

		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			if ( in_array( $mode, [ 'rb', 'rt', 'r' ] ) &&
			     "{$path[-4]}{$path[-3]}{$path[-2]}{$path[-1]}" == '.php' ) {

				$this->should_inject = self::check_exclusions( $path );
			}
		} else {
			if ( str_ends_with( $path, '.php' ) && in_array( $mode, [ 'rb', 'rt', 'r' ] ) ) {
				$this->should_inject = self::check_exclusions( $path );
			}
		}

		return $this->resource !== false;
	}

	/**
	 * Closes a stream
	 *
	 * @return void
	 */
	public function stream_close(): void {
		//self::$io_stats['close']++;


		//unset( self::$unclosed[ (int) $this->resource ] );

		fclose( $this->resource );
	}

	/**
	 * Reads from a stream
	 *
	 * @param  int $count
	 *
	 * @return string
	 */
	public function stream_read( int $count ): string {
		//self::$io_stats['read']++;

		if ( $this->should_inject ) {
			self::stop();
			self::start();
			if ( ftell( $this->resource ) == 0 ) {
				$read = fread( $this->resource, $count - self::$tick_declare_length );

				//self::$io_read += strlen( $read );

				$pos = stripos( $read, '<?php' );
				if ( $pos !== false ) {
					return substr_replace(
						$read,
						'<?php declare(ticks=' . TickProfiler::ticks . ');',
						$pos,
						5
					);
				}

				return $read;
			}
		}
		$read = fread( $this->resource, $count );

		//self::$io_read += strlen( $read );

		return $read;
	}

	/**
	 * Writes to a stream
	 *
	 * @param  string $data
	 *
	 * @return int
	 */
	public function stream_write( string $data ): int {
		//self::$io_stats['write']++;

		$write = fwrite( $this->resource, $data );

		//self::$io_write += $write;

		return $write;
	}

	/**
	 * Checks for end-of-file
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		//self::$io_stats['eof'] ++;

		return feof( $this->resource );
	}

	/**
	 * Retrieves information about a file
	 *
	 * @return array
	 */
	public function stream_stat(): array {
		//self::$io_stats['stat']++;

		$res = fstat( $this->resource );
		if ( $this->should_inject ) {
			$res['size'] += self::$tick_declare_length;
			$res[7]      += self::$tick_declare_length;
		}

		return $res;
	}

	/**
	 * Seeks to a specific point in a stream
	 *
	 * @param  int $offset
	 * @param  int $whence
	 *
	 * @return bool
	 */
	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		//self::$io_stats['seek']++;

		return fseek( $this->resource, $offset, $whence ) === 0;
	}

	/**
	 * Returns the current position in a stream
	 *
	 * @return int
	 */
	function stream_tell(): int {
		//self::$io_stats['tell']++;

		$res = ftell( $this->resource );
		if ( $this->should_inject ) {
			$res += self::$tick_declare_length;
		}

		return $res;
	}

	/**
	 * Flushes output
	 *
	 * @return bool
	 */
	public function stream_flush(): bool {
		//self::$io_stats['flush']++;

		return fflush( $this->resource );
	}

	/**
	 * Deletes a file
	 *
	 * @param  string $path
	 *
	 * @return bool
	 */
	public function unlink( string $path ): bool {
		//self::$io_list .= "[$path][unlink]\n";
		//self::$io_stats['unlink'] ++;
		self::stop();
		if ( isset( $this->context ) ) {
			$res = unlink( $path, $this->context );
		} else {
			$res = unlink( $path );
		}
		self::start();

		return $res;
	}

	/**
	 * Renames a file
	 *
	 * @param  string $path_from
	 * @param  string $path_to
	 *
	 * @return bool
	 */
	public function rename( string $path_from, string $path_to ): bool {
		//self::$io_list .= "[$path_from => $path_to][rename]\n";
		//self::$io_stats['rename'] ++;

		self::stop();
		if ( isset( $this->context ) ) {
			$res = rename( $path_from, $path_to, $this->context );
		} else {
			$res = rename( $path_from, $path_to );
		}
		self::start();

		return $res;
	}

	/**
	 * Retrieves information about a URL (e.g., file size, creation time)
	 *
	 * @param  string $path
	 * @param  int $flags
	 *
	 * @return array|bool
	 */
	public function url_stat( string $path, int $flags ) {
		//self::$io_stats['url_stat']++;

		self::stop();
		// Catch error and exception
		set_error_handler( function () {
		} );
		try {
			$res = stat( $path );
		} catch ( \Exception $e ) {
			$res = false;
		}
		restore_error_handler();
		self::start();

		return $res;
	}

	/**
	 * Creates a directory
	 *
	 * @param  string $path
	 * @param  int $mode
	 * @param  int $options
	 *
	 * @return bool
	 */
	public function mkdir( string $path, int $mode, int $options ): bool {
		//self::$io_list .= "[$path][mkdir:$mode]\n";
		//self::$io_stats['mkdir'] ++;

		self::stop();
		if ( isset( $this->context ) ) {
			$res = mkdir( $path, $mode, $options, $this->context );
		} else {
			$res = mkdir( $path, $mode, $options );
		}
		self::start();

		return $res;
	}

	/**
	 * Removes a directory
	 *
	 * @param  string $path
	 * @param  int $options
	 *
	 * @return bool
	 */
	public function rmdir( string $path, int $options ): bool {
		//self::$io_list .= "[$path][rmdir]\n";
		//self::$io_stats['rmdir']++;

		self::stop();
		if ( isset( $this->context ) ) {
			$res = rmdir( $path, $this->context );
		} else {
			$res = rmdir( $path );
		}
		self::start();

		return $res;
	}

	/**
	 * Retrieve the underlaying resource
	 *
	 * @param  int $cast_as
	 *
	 * @return mixed
	 */
	public function stream_cast( int $cast_as ) {
		//self::$io_stats['cast']++;

		return $this->resource;
	}

	/**
	 * Advisory file locking
	 *
	 * @param  int $operation
	 *
	 * @return bool
	 */
	public function stream_lock( int $operation ): bool {
		//self::$io_stats['lock']++;

		if ( ! $operation ) {
			$operation = LOCK_EX;
		}

		return flock( $this->resource, $operation );
	}

	/**
	 * Truncate stream
	 *
	 * @param  int $new_size
	 *
	 * @return bool
	 */
	public function stream_truncate( int $new_size ): bool {
		//self::$io_stats['truncate']++;

		return ftruncate( $this->resource, $new_size );
	}

	/**
	 * Change stream options
	 *
	 * @param  int $option
	 * @param  int $arg1
	 * @param  int $arg2
	 *
	 * @return bool
	 */
	public function stream_set_option( int $option, int $arg1, int $arg2 ): bool {
		//self::$io_stats['set_option']++;

		switch ( $option ) {
			case STREAM_OPTION_BLOCKING:
				return stream_set_blocking( $this->resource, $arg1 );
			case STREAM_OPTION_READ_TIMEOUT:
				return stream_set_timeout( $this->resource, $arg1, $arg2 );
			case STREAM_OPTION_WRITE_BUFFER:
				return stream_set_write_buffer( $this->resource, $arg1 );
			case STREAM_OPTION_READ_BUFFER:
				return stream_set_read_buffer( $this->resource, $arg1 );
			default:
				return false;
		}
	}

	/**
	 * Change stream metadata
	 *
	 * @param  int $path
	 * @param  int $option
	 * @param  mixed $value
	 *
	 * @return bool
	 */
	public function stream_metadata( int $path, int $option, $value ): bool {
		self::stop();
		$res = false;
		switch ( $option ) {
			case STREAM_META_ACCESS:
				$res = chmod( $path, $value );
				//self::$io_list .= "[$path][chmod:$value]\n";
				//self::$io_stats['chmod']++;
				break;
			case STREAM_META_GROUP:
			case STREAM_META_GROUP_NAME:
				$res = chgrp( $path, $value );
				//self::$io_list .= "[$path][chgrp:$value]\n";
				//self::$io_stats['chgrp']++;
				break;
			case STREAM_META_OWNER:
			case STREAM_META_OWNER_NAME:
				$res = chown( $path, $value );
				//self::$io_list .= "[$path][chown:$value]\n";
				//self::$io_stats['chown']++;
				break;
			case STREAM_META_TOUCH:
				if ( ! empty( $value ) ) {
					$res = touch( $path, $value[0], $value[1] );
					//self::$io_list .= "[$path][touch:{$value[0]}:{$value[1]}]\n";
				} else {
					$res = touch( $path );
					//self::$io_list .= "[$path][touch]\n";
				}
				//self::$io_stats['touch']++;
				break;
		}
		self::start();

		return $res;
	}

	/**
	 * Open directory handle
	 *
	 * @param  string $path
	 * @param  int $options
	 *
	 * @return mixed
	 */
	public function dir_opendir( string $path, int $options ) {

		//self::$io_list .= "[$path][opendir]\n";
		//self::$io_stats['opendir']++;

		self::stop();

		if ( isset( $this->context ) ) {
			$this->resource = opendir( $path, $this->context );
		} else {
			$this->resource = opendir( $path );
		}
		self::start();

		return $this->resource;
	}

	/**
	 * Close directory handle
	 *
	 * @return void
	 */
	public function dir_closedir():void {
		//self::$io_stats['closedir']++;

		// closedir returns no value
		closedir( $this->resource );
	}

	/**
	 * Read entry from directory handle
	 *
	 * @return string|bool
	 */
	public function dir_readdir() {
		//self::$io_stats['readdir']++;

		return readdir( $this->resource );
	}

	/**
	 * Rewind directory handle
	 */
	public function dir_rewinddir() {
		//self::$io_stats['rewinddir']++;

		return rewinddir( $this->resource );
	}
}