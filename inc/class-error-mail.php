<?php
/**
 * The mail you get when a run went wrong.
 *
 * @package better-multisite-cron
 */

namespace Better_Multisite_Cron;

require_once __DIR__ . '/class-run-log.php';
require_once __DIR__ . '/class-network-settings.php';

/**
 * "Cron broke" as an email, for installs where nobody reads a log file.
 *
 * It is a listener of 'better_multisite_cron_finished' and nothing else: sending mail is one way
 * to report a run, not a part of running one. So it is switched off with `--no-send_error_email`,
 * aimed with `--email_to`, and thrown out in one line (`remove_action`) by anyone whose monitoring
 * already asks `wp multisite-cron status`.
 *
 * It says what went wrong and where to read the rest (see Run_Log::summary): a mail travels
 * through servers and sits in inboxes, and an error message quotes whatever the failing job
 * touched. The message itself stays behind the network-admin login it was already behind.
 *
 * It reports what a finished run can tell. A process that was killed or ran out of memory never
 * gets here — that death is what the record (admin screen, `status`) and the crontab's own MAILTO
 * are for, and no mail written from a dying process could be trusted anyway.
 */
class Error_Mail {

	/** @var int Label column of the body, wide enough for the longest label below. */
	const LABEL_WIDTH = 10;

	/** @var int Prose is wrapped here: this is read in a terminal as often as in a mail client. */
	const LINE_WIDTH = 78;

	/**
	 * Mail the run, if it went wrong and somebody asked to hear about it.
	 *
	 * @param array $run    The finished record, see Run_Log::start().
	 * @param array $config The parsed arguments.
	 * @return bool Was a mail sent?
	 */
	public static function maybe_send( array $run, array $config ): bool {
		$summary = Run_Log::summary( $run );

		if ( empty( $config['send_error_email'] ) || '' === $summary ) {
			return false;
		}

		$to = (string) ( $config['email_to'] ?? '' );
		if ( ! is_email( $to ) ) {
			error_log( "BMSC: error, cron '{$run['name']}' failed, but email_to ('$to') is not an address." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the logger is per-run, this is not.
			return false;
		}

		return (bool) wp_mail( $to, self::subject( $run ), self::body( $run, $summary, $config ) );
	}

	/**
	 * Says which cron, on which install — the two things you need before opening the mail.
	 *
	 * @param array $run The finished record.
	 * @return string
	 */
	private static function subject( array $run ): string {
		$network = (string) get_network_option( get_current_network_id(), 'site_name', '' );

		return sprintf(
			/* translators: 1: network name, in brackets and empty on single networks, 2: name of the cron. */
			__( '%1$sMultisite cron "%2$s" failed', 'bmsc' ),
			'' === $network ? '' : "[$network] ",
			$run['name']
		);
	}

	/**
	 * The record, spelled out, plus where to look next. Plain text: this is read in a terminal
	 * as often as in a mail client.
	 *
	 * @param array  $run     The finished record.
	 * @param string $summary What went wrong, without quoting the run.
	 * @param array  $config  The parsed arguments.
	 * @return string
	 */
	private static function body( array $run, string $summary, array $config ): string {
		$fields = array(
			__( 'Cron', 'bmsc' )     => $run['name'],
			__( 'Problem', 'bmsc' )  => $summary,
			__( 'Started', 'bmsc' )  => self::stamp( $run['started'] ?? 0 ),
			__( 'Finished', 'bmsc' ) => self::stamp( $run['finished'] ?? 0 ) . sprintf( ' (%s s)', $run['duration_seconds'] ?? 0 ),
			__( 'Spaces', 'bmsc' )   => sprintf(
				/* translators: 1: Spaces which ran a job, 2: Spaces looked at. */
				__( '%1$d of %2$d ran a job', 'bmsc' ),
				(int) ( $run['blogs_processed'] ?? 0 ),
				(int) ( $run['blogs_found'] ?? 0 )
			),
			__( 'Host', 'bmsc' )     => sprintf( '%s (pid %s)', $run['host'] ?? '?', $run['pid'] ?? '?' ),
		);

		// A value can be several lines long (an error message usually is), so every line after the
		// first is indented to where the values start. One rule, and the block stays a block.
		$indent = str_repeat( ' ', self::LABEL_WIDTH );
		$body   = '';
		foreach ( $fields as $label => $value ) {
			$body .= str_pad( "$label:", self::LABEL_WIDTH ) . str_replace( "\n", "\n$indent", trim( (string) $value ) ) . "\n";
		}

		return $body . "\n" . self::where_to_look( $run, $config );
	}

	/**
	 * The places that have what this mail leaves out, and why it leaves it out.
	 *
	 * @param array $run    The finished record.
	 * @param array $config The parsed arguments.
	 * @return string
	 */
	private static function where_to_look( array $run, array $config ): string {
		$places = array(
			Network_Settings::url(),
			"wp multisite-cron status --name={$run['name']} --all",
		);

		// Only mention the error log if this run wrote to it: it is filled per failed blog, so a
		// run that died before touching a blog has no entry, and sending someone to look for one
		// is worse than staying quiet about it.
		if ( ! empty( $config['log_errors_to_file'] ) && ! empty( $run['error_count'] ) ) {
			$places[] = sprintf(
				/* translators: 1: path of the error log, 2: date and time, 3: timezone. */
				__( 'Error log %1$s, at the entry around %2$s %3$s', 'bmsc' ),
				$config['log_errors_to_file'],
				self::stamp( $run['started'] ?? 0 ),
				wp_timezone_string()
			);
		}

		// The sentence is wrapped, the places are not: a broken url is a url nobody can click.
		return wordwrap( __( 'What the run said is not in this mail: it can quote anything the failing job touched. It stays on the server, where it takes a login or a shell to read:', 'bmsc' ), self::LINE_WIDTH )
			. "\n\n  " . implode( "\n  ", $places ) . "\n";
	}

	/**
	 * @param int $timestamp Unix timestamp, 0 for "never happened".
	 * @return string
	 */
	private static function stamp( $timestamp ): string {
		return empty( $timestamp ) ? '–' : wp_date( 'Y-m-d H:i:s', (int) $timestamp );
	}
}
