<?php

use Better_Multisite_Cron\Process;
use PHPUnit\Framework\TestCase;

/**
 * Running a job: whatever it prints, the run gets its answer.
 */
final class ProcessTest extends TestCase {

	/**
	 * `timeout` turns a hang into a failure: with pipes read one after the other, the child blocks
	 * on a full stderr until it is killed (exit 124).
	 */
	public function test_a_job_flooding_stderr_does_not_hang_the_run(): void {
		$result = Process::run( 'timeout 10 ' . $this->php( 'echo "out"; fwrite( STDERR, str_repeat( "x", 300000 ) ); exit( 3 );' ) );

		$this->assertSame( 3, $result->return_code );
		$this->assertSame( 'out', $result->stdout );
		$this->assertStringContainsString( 'bytes cut', $result->stderr );
	}

	public function test_long_output_keeps_its_start_and_its_end(): void {
		$result = Process::run( $this->php( 'echo "first", str_repeat( ".", 5000 ), "last";' ), 0, 100 );

		$this->assertStringStartsWith( 'first', $result->stdout );
		$this->assertStringEndsWith( 'last', $result->stdout );
		$this->assertStringContainsString( '[4909 bytes cut]', $result->stdout );
	}

	public function test_a_cut_through_a_character_leaves_valid_utf8(): void {
		$result = Process::run( $this->php( 'echo str_repeat( "ä", 100 );' ), 0, 51 );

		$this->assertTrue( mb_check_encoding( $result->stdout, 'UTF-8' ) );
	}

	public function test_short_output_is_left_alone(): void {
		$result = Process::run( $this->php( 'echo "fine"; fwrite( STDERR, "a warning" );' ) );

		$this->assertSame( 0, $result->return_code );
		$this->assertSame( 'fine', $result->stdout );
		$this->assertSame( 'a warning', $result->stderr );
	}

	public function test_a_job_over_time_is_killed_and_says_so(): void {
		$marker = tempnam( sys_get_temp_dir(), 'bmsc' );
		unlink( $marker );
		$start  = microtime( true );
		$result = Process::run( $this->php( 'echo "started"; usleep( 500000 ); touch( \'' . $marker . '\' );' ), 0.1 );

		$this->assertLessThan( 0.4, microtime( true ) - $start );
		$this->assertTrue( $result->timed_out );
		$this->assertSame( 128 + 15, $result->return_code );
		$this->assertSame( 'started', $result->stdout );
		$this->assertStringEndsWith( 'Killed: still running after 0.1s (max_seconds).', $result->stderr );

		usleep( 600000 );
		$this->assertFileDoesNotExist( $marker, 'The job itself was killed, not only the shell around it.' );
	}

	public function test_a_job_ignoring_sigterm_is_killed_anyway(): void {
		if ( ! function_exists( 'pcntl_signal' ) ) {
			$this->markTestSkipped( 'Needs pcntl to ignore SIGTERM.' );
		}
		$result = Process::run( $this->php( 'pcntl_signal( SIGTERM, SIG_IGN ); sleep( 30 );' ), 0.1 );

		$this->assertTrue( $result->timed_out );
		$this->assertSame( 128 + 9, $result->return_code );
	}

	public function test_a_job_in_time_keeps_its_exit_code(): void {
		$result = Process::run( $this->php( 'exit( 3 );' ), 5 );

		$this->assertFalse( $result->timed_out );
		$this->assertSame( 3, $result->return_code );
	}

	/**
	 * @param string $code PHP code.
	 * @return string A command line running it.
	 */
	private function php( string $code ): string {
		return escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code );
	}
}
