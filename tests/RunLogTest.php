<?php

use Better_Multisite_Cron\Run_Log;
use PHPUnit\Framework\TestCase;

/**
 * The record itself: what is kept, what is dropped, what counts as a problem.
 */
final class RunLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bmsc_test_network_options'] = array();
		$GLOBALS['bmsc_test_filters']         = array();
	}

	public function test_runs_are_grouped_by_name_newest_first(): void {
		$this->store( 'quick', time() - 100 );
		$this->store( 'quick', time() );
		$this->store( 'daily', time() );

		$quick = Run_Log::by_name( 'quick' )['quick'];

		$this->assertCount( 2, $quick );
		$this->assertGreaterThan( $quick[1]['started'], $quick[0]['started'] );
		$this->assertCount( 2, Run_Log::by_name() ); // both names.
		$this->assertCount( 2, Run_Log::latest() ); // one row each.
	}

	public function test_a_name_is_a_key_not_a_label(): void {
		$this->assertSame( 'quickjob', Run_Log::sanitize_name( 'Quick Job!' ) );
		$this->assertSame( 'default', Run_Log::sanitize_name( '' ) );
		$this->assertSame( 'default', Run_Log::sanitize_name( '???' ) );
	}

	public function test_runs_older_than_the_retention_are_dropped(): void {
		$this->store( 'quick', time() - 8 * DAY_IN_SECONDS );
		$this->store( 'quick', time() - 6 * DAY_IN_SECONDS );
		$this->store( 'quick', time() );

		$kept = Run_Log::by_name( 'quick' )['quick'];

		$this->assertCount( 2, $kept );
		$this->assertLessThan( time() - 5 * DAY_IN_SECONDS, $kept[1]['started'] );
	}

	public function test_the_newest_run_survives_however_old_it_is(): void {
		$this->store( 'abandoned', time() - 90 * DAY_IN_SECONDS );

		$run = Run_Log::latest( 'abandoned' )[0];

		$this->assertNotEmpty( $run ); // "stopped a while ago" must not look like "never existed".
		$this->assertStringContainsString( 'more than max_age', Run_Log::problem( $run, 60 ) );
	}

	public function test_the_retention_is_filterable(): void {
		$GLOBALS['bmsc_test_filters']['better_multisite_cron_retention_days'] = fn() => 30;

		$this->store( 'quick', time() - 8 * DAY_IN_SECONDS );
		$this->store( 'quick', time() );

		$this->assertCount( 2, Run_Log::by_name( 'quick' )['quick'] );
	}

	public function test_an_error_is_a_problem(): void {
		$run = array(
			'finished' => time(),
			'error'    => 'A blog failed.',
		);

		$this->assertSame( 'A blog failed.', Run_Log::problem( $run ) );
	}

	public function test_a_finished_run_without_errors_is_fine(): void {
		$run = array(
			'started'  => time() - 10,
			'finished' => time(),
			'error'    => '',
		);

		$this->assertSame( '', Run_Log::problem( $run ) );
		$this->assertSame( '', Run_Log::problem( $run, 60 ) );
	}

	public function test_an_unfinished_run_from_another_host_is_a_problem(): void {
		$run = array(
			'started'  => time(),
			'finished' => null,
			'pid'      => getmypid(),
			'host'     => 'another-host',
		);

		$this->assertSame(
			'Started, but never finished (killed, timed out or out of memory).',
			Run_Log::problem( $run )
		);
	}

	/**
	 * Cron and the web server are usually different users. A running process we may not signal
	 * must not read as a dead one, or every long run gets reported as killed.
	 */
	public function test_a_process_of_another_user_still_counts_as_running(): void {
		$run = array(
			'started'  => time(),
			'finished' => null,
			'pid'      => 1, // init: always alive, never ours to signal.
			'host'     => gethostname(),
		);

		$this->assertTrue( Run_Log::is_still_running( $run ) );
		$this->assertSame( '', Run_Log::problem( $run ) );
	}

	public function test_an_unfinished_run_that_is_still_running_is_fine(): void {
		$run = array(
			'started'  => time(),
			'finished' => null,
			'pid'      => getmypid(),
			'host'     => gethostname(),
		);

		$this->assertSame( '', Run_Log::problem( $run ) );
	}

	public function test_only_so_many_runs_are_kept_per_name(): void {
		foreach ( range( 1, Run_Log::MAX_PER_NAME + 10 ) as $offset ) {
			$this->store( 'quick', time() - $offset );
		}

		$this->assertCount( Run_Log::MAX_PER_NAME, Run_Log::by_name( 'quick' )['quick'] );
	}

	public function test_names_do_not_prune_each_other(): void {
		$this->store( 'quick', time() );
		$this->store( 'daily', time() - 8 * DAY_IN_SECONDS );
		$this->store( 'quick', time() );

		$this->assertCount( 1, Run_Log::by_name( 'daily' )['daily'] );
	}

	public function test_latest_reports_a_run_that_is_overdue(): void {
		$this->store( 'quick', time() - 3600 );

		$this->assertSame( '', Run_Log::latest( 'quick' )[0]['problem'] );
		$this->assertStringContainsString( 'more than max_age', Run_Log::latest( 'quick', 60 )[0]['problem'] );
	}

	public function test_an_unknown_name_has_no_runs(): void {
		$this->store( 'quick', time() );

		$this->assertSame( array(), Run_Log::by_name( 'nightly' ) );
		$this->assertSame( array(), Run_Log::latest( 'nightly' ) );
	}

	public function test_failed_jobs_are_a_problem_even_without_a_message(): void {
		$run = array(
			'finished'    => time(),
			'error'       => '',
			'error_count' => 3,
		);

		$this->assertSame( '3 job(s) failed.', Run_Log::problem( $run ) );
	}

	/**
	 * The summary is the version that may travel, so it says what went wrong and nothing the run
	 * said — a message quotes urls, addresses, whatever ended up in an exception.
	 */
	public function test_the_summary_quotes_nothing(): void {
		$run = array(
			'finished' => time(),
			'error'    => 'Fatal: no user erika.musterfrau@example.org in /secret-space/',
		);

		$this->assertSame( 'The run stopped with an error.', Run_Log::summary( $run ) );
	}

	public function test_the_summary_counts_the_Spaces_that_failed(): void {
		$run = array(
			'finished'    => time(),
			'error'       => 'Something about 97 blogs, in detail.',
			'error_count' => 97,
			'blogs_found' => 200,
		);

		$this->assertSame( 'Jobs failed or were skipped in 97 of 200 Spaces.', Run_Log::summary( $run ) );
	}

	public function test_a_death_is_summarised_as_a_death(): void {
		$run = array(
			'started'  => time() - 100,
			'finished' => null,
			'error'    => 'Fatal: Allowed memory size exhausted in /srv/www/some/file.php:42',
			'pid'      => 999999999, // not this process, so it is not "still running".
			'host'     => gethostname(),
		);

		$this->assertSame( 'The run died before it finished (killed, timed out or out of memory).', Run_Log::summary( $run ) );
	}

	public function test_a_run_that_is_fine_has_no_summary(): void {
		$this->assertSame( '', Run_Log::summary( array( 'finished' => time() ) ) );
	}

	/**
	 * Being overdue is a problem with no message behind it: nothing to hold back, so it is passed
	 * on as it is.
	 */
	public function test_an_overdue_run_is_summarised_by_its_age(): void {
		$run = array( 'finished' => time() - 3600 );

		$this->assertStringContainsString( 'more than max_age', Run_Log::summary( $run, 60 ) );
	}

	public function test_error_messages_are_capped(): void {
		Run_Log::save(
			array(
				'id'      => 'x',
				'name'    => 'quick',
				'started' => time(),
				'error'   => str_repeat( 'e', 5000 ),
			)
		);

		$error = Run_Log::by_name( 'quick' )['quick'][0]['error'];
		$this->assertSame( Run_Log::MAX_ERROR_CHARS, mb_strlen( $error ) );
		$this->assertStringEndsWith( '…', $error ); // a cut message has to say that it was cut.
	}

	public function test_a_short_error_message_is_left_alone(): void {
		Run_Log::save(
			array(
				'id'      => 'x',
				'name'    => 'quick',
				'started' => time(),
				'error'   => 'over_time',
			)
		);

		$this->assertSame( 'over_time', Run_Log::by_name( 'quick' )['quick'][0]['error'] );
	}

	/**
	 * @param string $name    Run name.
	 * @param int    $started When it started.
	 */
	private function store( string $name, int $started ): void {
		Run_Log::save(
			array(
				'id'       => uniqid( '', true ),
				'name'     => $name,
				'started'  => $started,
				'finished' => $started + 10,
				'error'    => '',
			)
		);
	}
}
