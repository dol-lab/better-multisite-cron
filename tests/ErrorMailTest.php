<?php

use Better_Multisite_Cron\Error_Mail;
use PHPUnit\Framework\TestCase;

/**
 * When a run is worth a mail, and what that mail says.
 */
final class ErrorMailTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bmsc_test_network_options'] = array();
		$GLOBALS['bmsc_test_mails']           = array();
	}

	public function test_a_failed_run_is_mailed(): void {
		$sent = Error_Mail::maybe_send( $this->record( array( 'error' => 'over_time' ) ), $this->config() );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $GLOBALS['bmsc_test_mails'] );
		$this->assertSame( 'ops@example.org', $GLOBALS['bmsc_test_mails'][0]['to'] );
	}

	public function test_a_run_without_a_problem_is_not_mailed(): void {
		$this->assertFalse( Error_Mail::maybe_send( $this->record(), $this->config() ) );
		$this->assertSame( array(), $GLOBALS['bmsc_test_mails'] );
	}

	/**
	 * error_count without an error message still means jobs failed, see Run_Log::problem().
	 */
	public function test_failed_jobs_alone_are_worth_a_mail(): void {
		Error_Mail::maybe_send( $this->record( array( 'error_count' => 3 ) ), $this->config() );

		$this->assertStringContainsString(
			'Jobs failed or were skipped in 3 of 3867 blogs.',
			$GLOBALS['bmsc_test_mails'][0]['message']
		);
	}

	/**
	 * The whole point of the summary: a message quotes whatever the failing job touched, and a
	 * mail is the one place that must not carry it.
	 */
	public function test_the_message_itself_never_leaves_the_server(): void {
		$run = $this->record( array( 'error' => 'Failed for user erika.musterfrau@example.org on /secret-blog/' ) );

		Error_Mail::maybe_send( $run, $this->config() );
		$mail = $GLOBALS['bmsc_test_mails'][0];

		$this->assertStringNotContainsString( 'erika.musterfrau', $mail['message'] . $mail['subject'] );
		$this->assertStringNotContainsString( 'secret-blog', $mail['message'] . $mail['subject'] );
		$this->assertStringContainsString( 'The run stopped with an error.', $mail['message'] );
	}

	/**
	 * Which is only defensible if the mail says where the message is.
	 */
	public function test_the_mail_links_to_the_network_admin_report(): void {
		Error_Mail::maybe_send( $this->record( array( 'error' => 'boom' ) ), $this->config() );

		$this->assertStringContainsString(
			'https://example.org/wp-admin/network/settings.php#bmsc-runs',
			$GLOBALS['bmsc_test_mails'][0]['message']
		);
	}

	public function test_no_mail_when_switched_off(): void {
		$config = array_merge( $this->config(), array( 'send_error_email' => false ) );

		$this->assertFalse( Error_Mail::maybe_send( $this->record( array( 'error' => 'boom' ) ), $config ) );
		$this->assertSame( array(), $GLOBALS['bmsc_test_mails'] );
	}

	public function test_no_mail_without_a_usable_address(): void {
		$config = array_merge( $this->config(), array( 'email_to' => 'not-an-address' ) );

		$this->assertFalse( Error_Mail::maybe_send( $this->record( array( 'error' => 'boom' ) ), $config ) );
		$this->assertSame( array(), $GLOBALS['bmsc_test_mails'] );
	}

	/**
	 * The subject has to say which cron on which install, before anyone opens the mail.
	 */
	public function test_the_subject_names_the_network_and_the_cron(): void {
		$GLOBALS['bmsc_test_network_options'][1]['site_name'] = 'Example';

		Error_Mail::maybe_send( $this->record( array( 'error' => 'boom' ) ), $this->config() );

		$this->assertSame( '[Example] Multisite cron "quick" failed', $GLOBALS['bmsc_test_mails'][0]['subject'] );
	}

	public function test_the_body_spells_out_the_record(): void {
		Error_Mail::maybe_send( $this->record( array( 'error' => 'over_time' ) ), $this->config() );
		$body = $GLOBALS['bmsc_test_mails'][0]['message'];

		$this->assertStringContainsString( 'Problem:  The run stopped with an error.', $body );
		$this->assertStringContainsString( '12 of 3867 ran a job', $body );
		$this->assertStringContainsString( 'web01 (pid 4711)', $body );
		$this->assertStringContainsString( 'wp multisite-cron status --name=quick', $body );
	}

	public function test_the_body_points_at_the_error_log(): void {
		Error_Mail::maybe_send( $this->record( array( 'error_count' => 2 ) ), $this->config() );

		$this->assertStringContainsString( '/srv/log/cron.log', $GLOBALS['bmsc_test_mails'][0]['message'] );
	}

	/**
	 * The error log gets an entry per failed blog, so a run that died before reaching one wrote
	 * nothing there. Sending someone to look for that entry is worse than staying quiet.
	 */
	public function test_a_run_that_wrote_no_error_log_does_not_point_at_one(): void {
		Error_Mail::maybe_send( $this->record( array( 'error' => 'Stopping: Invalid arguments' ) ), $this->config() );

		$this->assertStringNotContainsString( '/srv/log/cron.log', $GLOBALS['bmsc_test_mails'][0]['message'] );
	}

	/**
	 * A record from an older version has fewer fields; a missing one must not fatal the mail.
	 */
	public function test_a_thin_record_still_produces_a_mail(): void {
		$run = array(
			'name'  => 'quick',
			'error' => 'boom',
		);

		$this->assertTrue( Error_Mail::maybe_send( $run, $this->config() ) );
		$this->assertStringContainsString( '0 of 0 ran a job', $GLOBALS['bmsc_test_mails'][0]['message'] );
	}

	/**
	 * @param array $overrides Fields to change.
	 * @return array A finished, successful record.
	 */
	private function record( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 'abc',
				'name'             => 'quick',
				'started'          => 1760000000,
				'finished'         => 1760000189,
				'duration_seconds' => 189.4,
				'blogs_found'      => 3867,
				'blogs_processed'  => 12,
				'error_count'      => 0,
				'error'            => '',
				'pid'              => 4711,
				'host'             => 'web01',
			),
			$overrides
		);
	}

	/**
	 * @return array The parts of the run config this listener reads.
	 */
	private function config(): array {
		return array(
			'send_error_email'   => true,
			'email_to'           => 'ops@example.org',
			'log_errors_to_file' => '/srv/log/cron.log',
		);
	}
}
