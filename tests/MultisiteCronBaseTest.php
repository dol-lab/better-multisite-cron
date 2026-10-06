<?php

use Better_Multisite_Cron\Multisite_Cron_Base;
use Better_Multisite_Cron\Run_Log;
use PHPUnit\Framework\TestCase;

/**
 * The glue between a run and its record.
 */
final class MultisiteCronBaseTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bmsc_test_network_options'] = array();
		$GLOBALS['bmsc_test_filters']         = array();
		$GLOBALS['bmsc_test_actions']         = array();
	}

	public function test_records_start_and_completion(): void {
		$runner = $this->runner();

		$runner->start_for_test( 'Quick Job!' );
		$started = Run_Log::latest( 'quickjob' )[0];

		$this->assertNotEmpty( $started['started'] );
		$this->assertNull( $started['finished'] );
		$this->assertSame( getmypid(), $started['pid'] );

		$runner->finish_for_test(
			array(
				'duration_all_seconds' => 1.25,
				'error_count'          => 1,
				'blog_tasks'           => array(
					array( 'response' => 'done' ),
					array( 'response' => '' ),
				),
			),
			'Cron failed.'
		);

		$finished = Run_Log::latest( 'quickjob' )[0];
		$this->assertNotEmpty( $finished['finished'] );
		$this->assertSame( 1.25, $finished['duration_seconds'] );
		$this->assertSame( 2, $finished['blogs_found'] );
		$this->assertSame( 1, $finished['blogs_processed'] );
		$this->assertSame( 1, $finished['error_count'] );
		$this->assertSame( 'Cron failed.', $finished['error'] );
	}

	/**
	 * Readers (the status table, the admin screen) can treat every record alike, finished or not.
	 */
	public function test_a_record_carries_every_field_from_the_first_write(): void {
		$this->runner()->start_for_test( 'quick' );

		$run = Run_Log::by_name( 'quick' )['quick'][0];

		foreach ( array( 'duration_seconds', 'blogs_found', 'blogs_processed', 'error_count', 'error' ) as $field ) {
			$this->assertArrayHasKey( $field, $run );
		}
	}

	public function test_completing_a_run_updates_it_instead_of_adding_one(): void {
		$runner = $this->runner();
		$runner->start_for_test( 'quick' );
		$runner->finish_for_test( array( 'blog_tasks' => array() ), '' );

		$this->assertCount( 1, Run_Log::by_name( 'quick' )['quick'] );
	}

	public function test_a_run_that_died_keeps_its_unfinished_record(): void {
		$runner = $this->runner();
		$runner->start_for_test( 'quick' );

		$run = Run_Log::latest( 'quick' )[0];
		$this->assertNull( $run['finished'] );
		$this->assertSame( '', Run_Log::problem( $run ) ); // this process is still alive.
	}

	/**
	 * The shutdown handler is the only thing that can report a fatal or an out-of-memory kill,
	 * because neither ever reaches the catch-block in run().
	 */
	public function test_a_death_before_the_end_is_written_down(): void {
		$runner = $this->runner();
		$runner->start_for_test( 'quick' );

		$runner->die_for_test();

		$run = Run_Log::by_name( 'quick' )['quick'][0];
		$this->assertStringContainsString( 'died before finishing', $run['error'] );
		$this->assertStringContainsString( 'died before finishing', implode( "\n", $runner->logs ) );
	}

	public function test_a_finished_run_is_left_alone_on_shutdown(): void {
		$runner = $this->runner();
		$runner->start_for_test( 'quick' );
		$runner->finish_for_test( array( 'blog_tasks' => array() ), '' );

		$runner->die_for_test();

		$this->assertSame( '', Run_Log::by_name( 'quick' )['quick'][0]['error'] );
		$this->assertSame( array(), $runner->logs );
	}

	/**
	 * run() without a multisite throws, which is the cheapest way to walk its whole error path.
	 */
	public function test_a_failing_run_is_recorded_reported_and_signalled(): void {
		$runner = $this->runner();

		$runner->run( array(), array( 'name' => 'quick' ) );

		$run = Run_Log::by_name( 'quick' )['quick'][0];
		$this->assertStringContainsString( 'only works on multisite', $run['error'] );
		$this->assertNotNull( $run['finished'] ); // it failed, but it did finish.
		$this->assertTrue( $runner->after_run_args['has_errors'] );

		$fired = $GLOBALS['bmsc_test_actions']['better_multisite_cron_finished'][0] ?? null;
		$this->assertNotNull( $fired, 'Every run has to be reported through the action.' );
		$this->assertSame( $run['error'], $fired[0]['error'] );
		$this->assertSame( 'quick', $fired[1]['name'] ); // the config is passed along.
	}

	/**
	 * An error is cut to length when it is stored, and listeners are handed the record the runner
	 * holds — so that record has to be the stored one. A mail quoting the uncut version would show
	 * an error nobody can read back anywhere else.
	 */
	public function test_a_listener_is_handed_the_record_as_it_was_stored(): void {
		$runner = $this->runner();
		$runner->start_for_test( 'quick' );

		$runner->finish_for_test( array( 'blog_tasks' => array() ), str_repeat( 'x', 900 ) );

		$stored = Run_Log::by_name( 'quick' )['quick'][0];
		$this->assertSame( Run_Log::MAX_ERROR_CHARS, mb_strlen( $stored['error'] ) );
		$this->assertSame( $stored, $runner->record_for_test() );
	}

	public function test_an_unnamed_run_is_recorded_as_default(): void {
		$this->runner()->run( array(), array() );

		$this->assertSame( array( 'default' ), array_keys( Run_Log::by_name() ) );
	}

	/**
	 * --no-foo arrives as false, --foo=false as the string 'false'. Both are off.
	 */
	public function test_switches_are_real_booleans_whichever_way_they_arrive(): void {
		$runner = $this->runner();

		$runner->run(
			array(),
			array(
				'exit_on_error' => 'false',
				'log_verbose'   => false,
				'debug'         => 'true',
			)
		);

		$config = $runner->after_run_args['config'];
		$this->assertFalse( $config['exit_on_error'] );
		$this->assertFalse( $config['log_verbose'] );
		$this->assertTrue( $config['debug'] );
		$this->assertFalse( $config['overtime_is_error'] ); // untouched defaults stay bool.
	}

	/**
	 * A path is not a switch: these default to '' so "boolean default = switch" holds everywhere.
	 */
	public function test_a_log_path_survives_the_boolean_normalisation(): void {
		$runner = $this->runner();

		$runner->run( array(), array( 'log_errors_to_file' => '/tmp/bmsc-test.log' ) );

		$this->assertSame( '/tmp/bmsc-test.log', $runner->after_run_args['config']['log_errors_to_file'] );
	}

	public function test_an_invalid_argument_stops_the_run(): void {
		$runner = $this->runner();

		$runner->run( array(), array( 'no_such_option' => 1 ) );

		$this->assertStringContainsString(
			'Invalid arguments',
			Run_Log::by_name( 'default' )['default'][0]['error']
		);
	}

	/**
	 * The record keeps MAX_ERROR_CHARS: they have to name the job, the blog and the cause, not
	 * every due job, the command or the job's output (all of them stay in the log file).
	 */
	public function test_a_failure_says_which_job_failed_where_and_why(): void {
		$message = $this->runner()->describe_for_test(
			array(
				array(
					'blog_id'   => '1234',
					'over_time' => 0,
					'job_names' => array( 'wp_version_check', 'do_pings', 'wp_scheduled_delete', 'wp_update_themes' ),
					'site_url'  => 'https://example.org/creative-coding',
					'cmd'       => 'cron event run --url=https://example.org/creative-coding --due-now',
					'response'  => "Executed the cron event 'wp_version_check' in 0.017s.\n",
					'error'     => "PHP Warning: Undefined array key \"x\" in /srv/a.php on line 3\n"
						. "PHP Fatal error:  Uncaught TypeError: foo(): Argument #1 must be of type int in /srv/b.php:12\n"
						. "Stack trace:\n#0 /srv/c.php(4): foo()\n#1 {main}\n  thrown in /srv/b.php on line 12\n"
						. 'Fatal error: Uncaught TypeError: foo(): Argument #1 must be of type int in /srv/b.php:12',
				),
			)
		);

		$this->assertSame(
			"Jobs failed or were skipped in 1 blog:\n"
			. '- do_pings (+2 after it) in https://example.org/creative-coding (blog 1234): PHP Fatal error: Uncaught TypeError: foo(): Argument #1 must be of type int in /srv/b.php:12',
			$message
		);
	}

	/**
	 * One broken job on many blogs is one line, not one per blog.
	 */
	public function test_identical_failures_share_a_line(): void {
		$task     = fn( $id ) => array(
			'blog_id'   => $id,
			'job_names' => array( 'scoped_notify_process_queue' ),
			'site_url'  => "https://example.org/$id",
			'error'     => 'Error: Table missing.',
		);
		$overtime = fn( $id ) => array(
			'blog_id'   => $id,
			'over_time' => 1,
			'error'     => 'over_time',
		);

		$message = $this->runner()->describe_for_test(
			array( $task( '1' ), $task( '2' ), $overtime( '7' ), $overtime( '8' ), $overtime( '9' ), $overtime( '10' ) )
		);

		$this->assertSame(
			"Jobs failed or were skipped in 6 blogs:\n"
			. "- scoped_notify_process_queue in 2 blogs (1, 2): Error: Table missing.\n"
			. '- 4 blogs (7, 8, 9, … +1): skipped, max_seconds was reached',
			$message
		);
	}

	/**
	 * A reason is cut, so a second failure still fits into the record.
	 */
	public function test_a_long_reason_is_cut(): void {
		$message = $this->runner()->describe_for_test(
			array(
				array(
					'blog_id' => '1',
					'error'   => 'Error: ' . str_repeat( 'x', 500 ),
				),
				array(
					'blog_id' => '2',
					'error'   => '',
				),
			)
		);

		$this->assertLessThan( Run_Log::MAX_ERROR_CHARS, mb_strlen( $message ) );
		$this->assertStringContainsString( 'x…', $message );
		$this->assertStringContainsString( '- blog 2: failed without an error message', $message );
	}

	private function runner(): object {
		return new class() {
			use Multisite_Cron_Base;

			/** @var string[] Everything the runner logged. */
			public $logs = array();

			/** @var array What after_run() was called with. */
			public $after_run_args = array();

			public function start_for_test( string $name ): void {
				$this->start_run( $name );
			}

			public function finish_for_test( array $results, string $error ): void {
				$this->finish_run( $results, $error );
			}

			public function record_for_test(): array {
				return $this->run;
			}

			public function describe_for_test( array $tasks ): string {
				return $this->describe_failures( $tasks );
			}

			public function die_for_test(): void {
				$this->catch_fatal();
			}

			protected function log( string $type, string $string ) {
				$this->logs[] = "$type: $string";
			}

			protected function after_run( bool $has_errors, array $config ) {
				$this->after_run_args = compact( 'has_errors', 'config' );
			}
		};
	}
}
