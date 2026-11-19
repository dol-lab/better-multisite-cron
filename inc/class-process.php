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
 * the run hangs for good (flock skips every later one). A file never fills up, so the child
 * writes there.
 */
class Process {

	/** @var int Bytes kept of the stdout, and of the stderr. */
	const MAX_OUTPUT_BYTES = 64 * 1024;

	/** @var float Seconds a terminated process gets to clean up before it is killed (less if it had less to run). */
	const GRACE_SECONDS = 5;

	/** @var int Microseconds between two looks at the process. */
	const POLL_MICROSECONDS = 10000;

	/**
	 * @param string $command_line Passed to the shell, which is replaced by it (`exec`), so a signal
	 *                             reaches the command and not just the shell.
	 * @param float  $max_seconds  Wall-clock limit, then SIGTERM and SIGKILL. 0 = none.
	 * @param int    $max_bytes    Bytes kept per stream.
	 * @return object { return_code: int, stdout: string, stderr: string, timed_out: bool }
	 * @throws \Exception If the process cannot be started.
	 */
	public static function run( string $command_line, float $max_seconds = 0, int $max_bytes = self::MAX_OUTPUT_BYTES ): object {
		$out = tmpfile();
		$err = tmpfile();
		if ( ! $out || ! $err ) {
			throw new \Exception( 'Could not create a temp file in ' . sys_get_temp_dir() . ' (set TMPDIR, or check open_basedir).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI, never HTML.
		}
		$pipes = array();
		$files = array(
			0 => STDIN,
			1 => $out,
			2 => $err,
		);
		$proc  = proc_open( "exec $command_line", $files, $pipes, getcwd() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- WP-CLI only, which needs it too.

		if ( ! is_resource( $proc ) ) {
			throw new \Exception( "Could not start: $command_line" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI, never HTML.
		}

		list( $return_code, $timed_out ) = self::wait( $proc, $max_seconds );

		$result = (object) array(
			'return_code' => $return_code,
			'stdout'      => trim( self::read_capped( $out, $max_bytes ) ),
			'stderr'      => trim( self::read_capped( $err, $max_bytes ) ),
			'timed_out'   => $timed_out,
		);
		if ( $timed_out ) {
			$result->stderr = trim( "$result->stderr\nKilled: still running after {$max_seconds}s (max_seconds)." );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a temp file, not the site's file system.
		fclose( $out ); // tmpfile() deletes itself on close.
		fclose( $err );
		// phpcs:enable
		return $result;
	}

	/**
	 * set_time_limit() counts CPU time only (on Linux), and a job waiting for a server that never
	 * answers uses none: only a clock outside the job can stop it.
	 *
	 * @param resource $proc        From proc_open().
	 * @param float    $max_seconds Wall-clock limit. 0 = none.
	 * @return array [ int exit code (128 + signal if killed), bool whether it was over time ]
	 */
	private static function wait( $proc, float $max_seconds ): array {
		$start     = microtime( true );
		$timed_out = false;
		$grace     = min( self::GRACE_SECONDS, $max_seconds );
		$status    = proc_get_status( $proc );

		while ( $status['running'] ) {
			$elapsed = microtime( true ) - $start;
			if ( $max_seconds > 0 && $elapsed > $max_seconds ) {
				$timed_out = true;
				proc_terminate( $proc, $elapsed > $max_seconds + $grace ? 9 : 15 ); // SIGKILL : SIGTERM.
			}
			usleep( self::POLL_MICROSECONDS );
			$status = proc_get_status( $proc );
		}
		proc_close( $proc ); // its exit code was used up by proc_get_status() before PHP 8.3.

		return array( $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'], $timed_out );
	}

	/**
	 * The start and the end, where a fatal is. A job printing a warning per post would otherwise
	 * fill the memory of the run and the log file.
	 *
	 * @param resource $file      Written by the child.
	 * @param int      $max_bytes Bytes kept.
	 * @return string
	 */
	private static function read_capped( $file, int $max_bytes ): string {
		$size = fstat( $file )['size'];
		rewind( $file );
		if ( $size <= $max_bytes ) {
			return (string) stream_get_contents( $file );
		}
		$half = intdiv( $max_bytes, 2 );
		$head = stream_get_contents( $file, $half );
		$tail = stream_get_contents( $file, $half, $size - $half );
		$cut  = $size - 2 * $half;
		return mb_scrub( "$head\n… [$cut bytes cut] …\n$tail", 'UTF-8' ); // the cut may split a character.
	}
}
