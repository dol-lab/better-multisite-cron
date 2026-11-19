<?php
/**
 * A child process whose output cannot block it.
 *
 * @package better-multisite-cron
 */

namespace Better_Multisite_Cron;

/**
 * Runs a command line, stops it once it is over time, and hands back what it printed, at most
 * MAX_OUTPUT_BYTES per stream.
 *
 * WP_CLI::runcommand() reads the child's stdout to the end before it looks at stderr. A job that
 * writes more than a pipe buffer (64 KB) to stderr then waits for a reader that never comes, and
 * the run hangs for good (flock skips every later one). Here both pipes are read as data arrives.
 */
class Process {

	/** @var int Bytes kept of the stdout, and of the stderr. */
	const MAX_OUTPUT_BYTES = 64 * 1024;

	/** @var float Seconds a terminated process gets to clean up before it is killed (less if it had less to run). */
	const GRACE_SECONDS = 5;

	/** @var int Microseconds to wait for output before looking at the clock again. */
	const POLL_MICROSECONDS = 10000;

	/** @var int Chunks of 64 KB read from a pipe before the clock is looked at again. */
	const READ_CHUNKS = 16;

	/** @var string[] Without these, no job runs. */
	const REQUIRED = array( 'proc_open', 'proc_close', 'stream_select', 'mb_scrub' );

	/** @var string[] Without these, jobs run without a time limit. */
	const TO_STOP = array( 'proc_get_status', 'proc_terminate' );

	/**
	 * @param string $command_line Passed to the shell, which is replaced by it (`exec`), so a signal
	 *                             reaches the command and not just the shell.
	 * @param float  $max_seconds  Wall-clock limit, then SIGTERM and SIGKILL. 0 = none. Ignored
	 *                             where can_stop() is false.
	 * @param int    $max_bytes    Bytes kept per stream.
	 * @return object { return_code: int, stdout: string, stderr: string, timed_out: bool }
	 * @throws \Exception If a required function is missing or the process cannot be started.
	 */
	public static function run( string $command_line, float $max_seconds = 0, int $max_bytes = self::MAX_OUTPUT_BYTES ): object {
		$missing = static::missing( self::REQUIRED );
		if ( $missing ) {
			throw new \Exception( 'Cannot run jobs, missing or disabled: ' . implode( ', ', $missing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI, never HTML.
		}

		$pipes = array();
		$spec  = array(
			0 => STDIN,
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc  = proc_open( "exec $command_line", $spec, $pipes, getcwd() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- WP-CLI only, which needs it too.

		if ( ! is_resource( $proc ) ) {
			throw new \Exception( "Could not start: $command_line" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI, never HTML.
		}

		$result = self::wait( $proc, $pipes, static::can_stop() ? $max_seconds : 0, $max_bytes );
		if ( $result->timed_out ) {
			$result->stderr = trim( "$result->stderr\nKilled: still running after {$max_seconds}s (max_seconds)." );
		}
		return $result;
	}

	/**
	 * @return bool Whether run() can stop a job that is over time.
	 */
	public static function can_stop(): bool {
		return ! static::missing( self::TO_STOP );
	}

	/**
	 * What run() needs from this server, each tried for real: a disabled function or a signal that
	 * does not arrive shows up here, not in the first run.
	 *
	 * @param string $php The PHP binary jobs run with.
	 * @return array [ check => [ 'status' => ok|warning|error, 'detail' => string ] ]
	 */
	public static function check( string $php ): array {
		$missing = static::missing( self::REQUIRED );
		if ( $missing ) {
			return array( 'Run a job' => self::row( 'error', 'Missing or disabled: ' . implode( ', ', $missing ) ) );
		}

		$php = escapeshellarg( $php );
		try {
			$job = static::run( "$php -r " . escapeshellarg( 'echo "ok";' ) );
		} catch ( \Exception $e ) {
			return array( 'Run a job' => self::row( 'error', $e->getMessage() ) );
		}
		$checks = array( 'Run a job' => 'ok' === $job->stdout ? self::row() : self::row( 'error', "Expected \"ok\", got: $job->stdout$job->stderr" ) );

		if ( ! static::can_stop() ) {
			$checks['Stop a job over time'] = self::row( 'warning', 'Missing or disabled: ' . implode( ', ', static::missing( self::TO_STOP ) ) . '. Jobs run without max_seconds, a hanging one hangs the run.' );
			return $checks;
		}
		$hang = static::run( "$php -r " . escapeshellarg( 'sleep( 10 );' ), 0.2 );

		$checks['Stop a job over time'] = $hang->timed_out && $hang->return_code > 128 ? self::row() : self::row( 'error', 'It ran to its end: the signal did not reach it.' );
		return $checks;
	}

	/**
	 * Separate so a test can pretend a function is disabled.
	 *
	 * @param string[] $functions Function names.
	 * @return string[] Those that are missing or disabled.
	 */
	protected static function missing( array $functions ): array {
		return array_values( array_filter( $functions, fn( $name ) => ! function_exists( $name ) ) );
	}

	/**
	 * Read both pipes as data arrives, until the job ended. set_time_limit() would not stop it: it
	 * counts CPU time only (on Linux), and a job waiting for a server that never answers uses none.
	 *
	 * The end is the job's exit, not the end of its output: a process it left behind can hold the
	 * pipes open. Only where proc_get_status() is missing does the end of output have to do.
	 *
	 * @param resource   $proc        From proc_open().
	 * @param resource[] $pipes       [ 1 => stdout, 2 => stderr ].
	 * @param float      $max_seconds Wall-clock limit. 0 = none.
	 * @param int        $max_bytes   Bytes kept per stream.
	 * @return object { return_code: int, stdout: string, stderr: string, timed_out: bool }
	 */
	private static function wait( $proc, array $pipes, float $max_seconds, int $max_bytes ): object {
		$start     = microtime( true );
		$grace     = min( self::GRACE_SECONDS, $max_seconds );
		$watch     = static::can_stop();
		$timed_out = false;
		$exit      = null; // from proc_get_status(), which reports it once only (before PHP 8.3).
		$output    = array_fill_keys( array_keys( $pipes ), self::capped( $max_bytes ) );

		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}

		do {
			if ( $pipes ) {
				$ready = array_values( $pipes );
				$none  = null;
				$also  = null;
				@stream_select( $ready, $none, $also, 0, self::POLL_MICROSECONDS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a signal interrupts it, which is no error.
			} else {
				usleep( self::POLL_MICROSECONDS );
			}
			self::read( $pipes, $output );

			if ( ! $watch ) {
				continue;
			}
			$status = proc_get_status( $proc );
			if ( ! $status['running'] ) {
				$exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
				self::read( $pipes, $output ); // what it wrote just before it ended.
				break;
			}
			$elapsed = microtime( true ) - $start;
			if ( $max_seconds > 0 && $elapsed > $max_seconds ) {
				$timed_out = true;
				proc_terminate( $proc, $elapsed > $max_seconds + $grace ? 9 : 15 ); // SIGKILL : SIGTERM.
			}
		} while ( $watch || $pipes );

		foreach ( $pipes as $pipe ) {
			fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a pipe.
		}
		$closed = proc_close( $proc );

		return (object) array(
			'return_code' => $exit ?? $closed,
			'stdout'      => trim( self::joined( $output[1] ) ),
			'stderr'      => trim( self::joined( $output[2] ) ),
			'timed_out'   => $timed_out,
		);
	}

	/**
	 * Take what is there, without waiting for more, and at most READ_CHUNKS chunks per pipe: a job
	 * that never stops printing must not keep the clock from being looked at. A pipe at its end is
	 * closed and dropped.
	 *
	 * @param resource[] $pipes  Open pipes, by stream number.
	 * @param array[]    $output Buffers from capped(), by stream number.
	 * @return void
	 */
	private static function read( array &$pipes, array &$output ) {
		foreach ( $pipes as $i => $pipe ) {
			for ( $n = 0; $n < self::READ_CHUNKS; $n++ ) {
				$chunk = (string) fread( $pipe, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a pipe.
				if ( '' === $chunk ) {
					break;
				}
				$output[ $i ] = self::append( $output[ $i ], $chunk );
			}
			if ( feof( $pipe ) ) {
				fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a pipe.
				unset( $pipes[ $i ] );
			}
		}
	}

	/**
	 * The start and the end of a stream, where a fatal is: a job printing a warning per post would
	 * otherwise fill the memory of the run and the log file.
	 *
	 * @param int $max_bytes Bytes kept.
	 * @return array An empty buffer.
	 */
	private static function capped( int $max_bytes ): array {
		return array(
			'half' => intdiv( $max_bytes, 2 ),
			'head' => '',
			'tail' => '',
			'size' => 0,
		);
	}

	/**
	 * @param array  $buffer From capped().
	 * @param string $chunk  What the job printed next.
	 * @return array The buffer, with the chunk in its head until that is full, in its tail after.
	 */
	private static function append( array $buffer, string $chunk ): array {
		$buffer['size'] += strlen( $chunk );
		$room            = $buffer['half'] - strlen( $buffer['head'] );
		if ( $room > 0 ) {
			$buffer['head'] .= substr( $chunk, 0, $room );
			$chunk           = (string) substr( $chunk, $room );
		}
		if ( '' !== $chunk ) {
			$buffer['tail'] = substr( $buffer['tail'] . $chunk, -$buffer['half'] );
		}
		return $buffer;
	}

	/**
	 * @param array $buffer From capped().
	 * @return string Head and tail, with what was cut between them.
	 */
	private static function joined( array $buffer ): string {
		$cut = $buffer['size'] - strlen( $buffer['head'] ) - strlen( $buffer['tail'] );
		if ( 0 === $cut ) {
			return $buffer['head'] . $buffer['tail'];
		}
		return mb_scrub( "{$buffer['head']}\n… [$cut bytes cut] …\n{$buffer['tail']}", 'UTF-8' ); // the cut may split a character.
	}

	/**
	 * @param string $status ok, warning or error.
	 * @param string $detail What is wrong, and what that means.
	 * @return array One row of check().
	 */
	private static function row( string $status = 'ok', string $detail = '' ): array {
		return array(
			'status' => $status,
			'detail' => $detail,
		);
	}
}
