<?php

use Better_Multisite_Cron\Network_Settings;
use Better_Multisite_Cron\Run_Log;
use PHPUnit\Framework\TestCase;

/**
 * The report at the bottom of Network Admin → Settings: one row per cron.
 */
final class NetworkSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bmsc_test_network_options'] = array();
		$GLOBALS['bmsc_test_filters']         = array();
	}

	public function test_it_says_so_when_nothing_ever_ran(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'No run recorded yet', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_every_cron_gets_exactly_one_row(): void {
		$this->store( 'quick', array() );
		$this->store( 'quick', array() );
		$this->store( 'daily', array() );

		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<tr><td><strong>' ) );
		$this->assertStringContainsString( '<strong>quick</strong>', $html );
		$this->assertStringContainsString( '<strong>daily</strong>', $html );
	}

	public function test_a_successful_last_run_says_so(): void {
		$this->store(
			'quick',
			array(
				'blogs_processed' => 12,
				'blogs_found'     => 3867,
			)
		);

		$this->assertStringContainsString( '✔ Success, jobs in 12 of 3867 blogs', $this->render() );
	}

	public function test_a_failed_last_run_says_what_broke(): void {
		$this->store( 'quick', array( 'error' => 'Job xy died.' ) );

		$this->assertStringContainsString( '✘ The run stopped with an error.', $this->render() );
	}

	/**
	 * This screen is the one place the message is shown in full — which is why the error mail
	 * links here instead of quoting it. Folded away, because on a bad day it is an array dump.
	 */
	public function test_the_message_is_here_in_full(): void {
		$this->store( 'quick', array( 'error' => "Job xy died.\nAt /srv/www/current/some/file.php:42" ) );

		$html = $this->render();

		$this->assertStringContainsString( '<details>', $html );
		$this->assertStringContainsString( 'At /srv/www/current/some/file.php:42', $html );
	}

	public function test_the_report_can_be_linked_to(): void {
		$this->assertStringContainsString( 'id="bmsc-runs"', $this->render() );
		$this->assertSame( 'https://example.org/wp-admin/network/settings.php#bmsc-runs', Network_Settings::url() );
	}

	/**
	 * The result column is about the last run, not about the week.
	 */
	public function test_an_older_failure_does_not_change_the_result(): void {
		$this->store( 'quick', array( 'error' => 'Job xy died.' ) );
		$this->store( 'quick', array() );

		$html = $this->render();

		$this->assertStringContainsString( '✔ Success', $html );
		$this->assertStringNotContainsString( '✘ The run stopped', $html );
		$this->assertStringContainsString( 'Today: 2 runs, 1 failed', $html ); // but the day strip still knows.
	}

	public function test_a_running_run_is_shown_as_running(): void {
		Run_Log::start( 'quick' );

		$this->assertStringContainsString( 'running…', $this->render() );
	}

	public function test_the_strip_has_one_mark_per_retained_day(): void {
		$this->store( 'quick', array() );

		$this->assertSame( Run_Log::RETENTION_DAYS, substr_count( $this->render(), 'title=' ) );
	}

	public function test_a_day_where_nothing_ran_is_marked(): void {
		$this->store( 'quick', array( 'started' => time() - 2 * DAY_IN_SECONDS ) );
		$this->store( 'quick', array() );

		$html = $this->render();

		$this->assertStringContainsString( 'Yesterday: nothing ran', $html );
		$this->assertStringContainsString( '#996800', $html ); // the amber "missed" mark.
	}

	/**
	 * Days before the first record are not a gap, they are the time before this existed.
	 */
	public function test_days_before_the_first_run_are_not_marked_as_missed(): void {
		$this->store( 'quick', array() );

		$html = $this->render();

		$this->assertStringContainsString( 'nothing was recorded yet', $html );
		$this->assertStringNotContainsString( ': nothing ran', $html ); // no tooltip says it (the legend does).
	}

	/**
	 * A tooltip is one line and a day can hold two dozen runs, so it names the problem the short
	 * way — the message is in the fold, once, for the run that is still the current state.
	 */
	public function test_a_day_tooltip_counts_runs_and_names_the_problem(): void {
		$this->store( 'quick', array( 'error' => "Job xy died.\nAt /srv/www/current/some/file.php:42" ) );
		$this->store( 'quick', array() );

		$html = $this->render();

		$this->assertStringContainsString( 'Today: 2 runs, 1 failed — The run stopped with an error.', $html );
		$this->assertStringNotContainsString( 'title="Today: 2 runs, 1 failed — Job xy died.', $html );
	}

	/**
	 * A record from an older version lacks fields; the screen must still render.
	 */
	public function test_a_record_without_fields_still_renders(): void {
		$GLOBALS['bmsc_test_network_options'][1][ Run_Log::OPTION ] = array(
			'legacy' => array( array( 'name' => 'legacy' ) ),
		);

		$html = $this->render();

		$this->assertStringContainsString( '<strong>legacy</strong>', $html );
		$this->assertStringContainsString( 'never finished', $html );
	}

	public function test_it_escapes_what_it_prints(): void {
		$this->store( 'quick', array( 'error' => '<script>alert(1)</script>' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	private function render(): string {
		ob_start();
		Network_Settings::render();
		return (string) ob_get_clean();
	}

	/**
	 * @param string $name      Run name.
	 * @param array  $overrides Fields to change.
	 */
	private function store( string $name, array $overrides ): void {
		Run_Log::save(
			array_merge(
				array(
					'id'               => uniqid( '', true ),
					'name'             => $name,
					'started'          => time() - 100,
					'finished'         => time(),
					'duration_seconds' => 12.5,
					'blogs_found'      => 0,
					'blogs_processed'  => 0,
					'error_count'      => 0,
					'error'            => '',
				),
				$overrides
			)
		);
	}
}
