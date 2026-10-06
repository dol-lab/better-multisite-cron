<?php
/**
 * The record of what cron did, and when.
 *
 * @package better-multisite-cron
 */

namespace Better_Multisite_Cron;

/**
 * Every run of `wp multisite-cron run`, kept in one network-option for a few days.
 *
 * Why an option and not a table: this is a handful of rows per day, written twice per run and
 * read by one CLI command and one admin screen. An option needs no schema, no migration and no
 * cleanup cron — pruning happens while writing, which is the only moment the data changes.
 *
 * Shaped as [ name => [ newest run, ..., oldest run ] ] so that two crontab entries running in
 * parallel keep their own history. Each name is written whole, so the (small) window in which two
 * runs finish at the same millisecond can cost one entry — never the cron itself.
 */
class Run_Log {

	/** @var string Network-option holding all recorded runs. */
	const OPTION = 'bmsc_runs';

	/** @var int Days a run is kept. The newest run per name is always kept, however old it is. */
	const RETENTION_DAYS = 7;

	/** @var int Hard cap per name, so a minutely cron cannot blow up the option. */
	const MAX_PER_NAME = 500;

	/** @var int Error messages are here to be read, not to be archived. */
	const MAX_ERROR_CHARS = 500;

	/** @var int "Operation not permitted": the process is there, we may just not signal it. */
	const EPERM = 1;

	/**
	 * Write down that a run started. Without this, "no errors were logged" and "nothing ever ran"
	 * look exactly the same from the outside.
	 *
	 * A record carries all of its fields from the first write on, even the ones only a finished run
	 * can fill in: readers (the status table, the admin screen) can then treat every record alike.
	 *
	 * @param string $name Name of the run (one per crontab entry).
	 * @return array The new record.
	 */
	public static function start( string $name ): array {
		$run = array(
			'id'               => uniqid(),
			'name'             => self::sanitize_name( $name ),
			'started'          => time(),
			'finished'         => null, // stays null if the process dies (fatal, OOM, kill, host reboot).
			'duration_seconds' => 0,
			'blogs_found'      => 0,
			'blogs_processed'  => 0,
			'error_count'      => 0,
			'error'            => '',
			'pid'              => getmypid(),
			'host'             => gethostname(),
		);
		return self::save( $run );
	}

	/**
	 * Store a record: replaces the run with the same id, or prepends it.
	 *
	 * Returns the record as it was stored, not as it was passed in — the caller keeps holding it
	 * (and hands it to whoever listens), so it must not carry a version of the truth nobody can
	 * read back.
	 *
	 * @param array $run One record, as returned by start().
	 * @return array The stored record.
	 */
	public static function save( array $run ): array {
		$run['error'] = self::shorten( (string) ( $run['error'] ?? '' ) );
		$name         = self::sanitize_name( $run['name'] ?? '' );
		$run['name']  = $name;

		$all  = self::read();
		$runs = array_values( $all[ $name ] ?? array() );
		$at   = array_search( $run['id'] ?? '', array_column( $runs, 'id' ), true );

		if ( false === $at ) {
			array_unshift( $runs, $run );
		} else {
			$runs[ $at ] = $run;
		}

		$all[ $name ] = self::prune( $runs );
		update_network_option( get_current_network_id(), self::OPTION, $all );

		return $run;
	}

	/**
	 * All recorded runs, newest first.
	 *
	 * @param string $name Only this run. Empty = all names.
	 * @return array [ name => [ run, ... ] ]
	 */
	public static function by_name( string $name = '' ): array {
		$all = self::read();
		if ( '' === $name ) {
			return $all;
		}
		$name = self::sanitize_name( $name );
		return isset( $all[ $name ] ) ? array( $name => $all[ $name ] ) : array();
	}

	/**
	 * The newest run per name, with a 'problem' key telling you whether it is fine.
	 *
	 * @param string $name    Only this run. Empty = all names.
	 * @param int    $max_age Complain if the run finished more than X seconds ago. 0 = don't check.
	 * @return array [ [ 'name' => string, ..., 'problem' => string ], ... ]
	 */
	public static function latest( string $name = '', int $max_age = 0 ): array {
		$latest = array();
		foreach ( self::by_name( $name ) as $runs ) {
			if ( empty( $runs ) ) {
				continue;
			}
			$run            = reset( $runs );
			$run['problem'] = self::problem( $run, $max_age );
			$latest[]       = $run;
		}
		return $latest;
	}

	/**
	 * @param array $run     One record.
	 * @param int   $max_age Complain if the run finished more than X seconds ago. 0 = don't check.
	 * @return string Empty if the run is fine.
	 */
	public static function problem( array $run, int $max_age = 0 ): string {
		if ( ! empty( $run['error'] ) ) {
			return $run['error'];
		}
		if ( ! empty( $run['error_count'] ) ) {
			return "{$run['error_count']} job(s) failed."; // should not happen: the message above covers it.
		}
		if ( empty( $run['finished'] ) ) {
			return self::is_still_running( $run )
				? ''
				: 'Started, but never finished (killed, timed out or out of memory).';
		}
		$age = time() - (int) $run['finished'];
		if ( $max_age > 0 && $age > $max_age ) {
			return "Last run finished $age seconds ago, which is more than max_age ($max_age).";
		}
		return '';
	}

	/**
	 * What went wrong, without repeating a word of what the run said.
	 *
	 * An error message quotes whatever the failing job touched: urls, addresses, whatever ended up
	 * in an exception. That belongs where it already is, on the server behind a login. This is the
	 * version that may travel — into a mail, a chat message, a monitoring ping.
	 *
	 * @param array $run     One record.
	 * @param int   $max_age Complain if the run finished more than X seconds ago. 0 = don't check.
	 * @return string Empty if the run is fine.
	 */
	public static function summary( array $run, int $max_age = 0 ): string {
		$problem = self::problem( $run, $max_age );

		if ( '' === $problem ) {
			return '';
		}
		if ( empty( $run['finished'] ) ) {
			return 'The run died before it finished (killed, timed out or out of memory).';
		}
		if ( ! empty( $run['error_count'] ) ) {
			return sprintf(
				'Jobs failed or were skipped in %d of %d blogs.',
				(int) $run['error_count'],
				(int) ( $run['blogs_found'] ?? 0 )
			);
		}
		if ( ! empty( $run['error'] ) ) {
			return 'The run stopped with an error.';
		}
		return $problem; // the age complaint: numbers only, nothing quoted.
	}

	/**
	 * A long run is unfinished for hours, which is not a problem. Only meaningful on the machine
	 * the run was started on (pids are unique per host, and can be reused).
	 *
	 * Cron and the web server rarely run as the same user, and this is read from the web server:
	 * so "does this process exist" must not be answered with "may I signal it". /proc needs no
	 * permission over the process; posix_kill() reports a live process it may not touch as EPERM,
	 * which is a yes, not a no. Getting this wrong reports every running cron as killed.
	 *
	 * Where open_basedir does not cover /proc, looking is an E_WARNING on every page load, and the
	 * answer is "no" anyway — so ask quietly and take the posix_kill() route.
	 *
	 * @param array $run One record.
	 * @return bool
	 */
	public static function is_still_running( array $run ): bool {
		if ( empty( $run['pid'] ) || gethostname() !== ( $run['host'] ?? '' ) ) {
			return false;
		}
		$pid = (int) $run['pid'];

		if ( @is_dir( '/proc' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			return file_exists( "/proc/$pid" );
		}
		if ( ! function_exists( 'posix_kill' ) ) {
			return false;
		}
		return posix_kill( $pid, 0 ) || self::EPERM === posix_get_last_error();
	}

	/**
	 * @param string $name Raw name, as passed to --name.
	 * @return string
	 */
	public static function sanitize_name( string $name ): string {
		$name = sanitize_key( $name );
		return '' === $name ? 'default' : $name;
	}

	/**
	 * @return int Days a run is kept, see RETENTION_DAYS.
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days of runs are kept.
		 *
		 * @param int $days Default Run_Log::RETENTION_DAYS.
		 */
		return max( 1, (int) apply_filters( 'better_multisite_cron_retention_days', self::RETENTION_DAYS ) );
	}

	/**
	 * Error messages are here to be read, not to be archived — a dump of every failed blog belongs
	 * in the log file. The ellipsis is part of the deal: a cut message that does not say it was cut
	 * sends people looking for the end of a sentence that is not there.
	 *
	 * @param string $error The full message.
	 * @return string At most MAX_ERROR_CHARS characters.
	 */
	private static function shorten( string $error ): string {
		if ( mb_strlen( $error ) <= self::MAX_ERROR_CHARS ) {
			return $error;
		}
		return mb_substr( $error, 0, self::MAX_ERROR_CHARS - 1 ) . '…';
	}

	/**
	 * @return array The raw option, [ name => [ run, ... ] ].
	 */
	private static function read(): array {
		$all = get_network_option( get_current_network_id(), self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Drop what is too old to be interesting — except the newest run, which has to survive
	 * forever: a cron that stopped a month ago must not look like a cron that never existed.
	 *
	 * @param array $runs One name's records, newest first.
	 * @return array
	 */
	private static function prune( array $runs ): array {
		$oldest = time() - self::retention_days() * DAY_IN_SECONDS;
		$keep   = array_filter(
			$runs,
			fn( $run, $index ) => 0 === $index || ( $run['started'] ?? 0 ) >= $oldest,
			ARRAY_FILTER_USE_BOTH
		);
		return array_slice( array_values( $keep ), 0, self::MAX_PER_NAME );
	}
}
