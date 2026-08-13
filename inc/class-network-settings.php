<?php
/**
 * "Did cron run?", answered in the network backend.
 *
 * @package better-multisite-cron
 */

namespace Better_Multisite_Cron;

require_once __DIR__ . '/class-run-log.php';

/**
 * A read-only report at the bottom of Network Admin → Settings.
 *
 * One table, one row per cron (per `--name`), because that is the thing that has a state: it ran
 * or it did not, it succeeded or it did not. Everything else is a column about it — including the
 * history, which is one mark per day rather than a second table. Anything more detailed is a job
 * for `wp multisite-cron status --all`.
 *
 * Deliberately not its own admin page: there is nothing to configure and nothing to submit, so it
 * needs no menu entry, no form and no capability check of its own (network settings already
 * require 'manage_network_options').
 *
 * It is also the one place the error messages are shown in full, which is why the error mail links
 * here instead of quoting them: this screen is behind that capability, a mailbox is not.
 */
class Network_Settings {

	/** @var string Anchor of the report, so a mail can link straight at it. */
	const ANCHOR = 'bmsc-runs';

	/** @var array[] How a day is drawn: mark, colour and what the tooltip calls it. */
	const DAY_MARKS = array(
		'ok'      => array( '✔', '#008a20' ),
		'failed'  => array( '✘', '#b32d2e' ),
		'missed'  => array( '·', '#996800' ),
		'not yet' => array( '·', '#c3c4c7' ),
	);

	/**
	 * @return string Deep link to this report, for anything that reports a run elsewhere.
	 */
	public static function url(): string {
		return network_admin_url( 'settings.php' ) . '#' . self::ANCHOR;
	}

	/**
	 * Print the report. Hooked to 'wpmu_options', which fires inside the settings form —
	 * so this must not open a form of its own.
	 *
	 * @return void
	 */
	public static function render() {
		$days = Run_Log::retention_days();
		$all  = Run_Log::by_name();

		printf( '<h2 id="%s">%s</h2>', esc_attr( self::ANCHOR ), esc_html__( 'Multisite Cron', 'bmsc' ) );

		if ( empty( $all ) ) {
			printf(
				'<p>%s <code>wp multisite-cron run</code></p>',
				esc_html__( 'No run recorded yet. Nothing has called this, or it never got far enough to write anything down:', 'bmsc' )
			);
			return;
		}

		echo '<table class="widefat striped" style="max-width:60em">';
		self::render_head(
			array(
				__( 'Cron', 'bmsc' ),
				__( 'Last run', 'bmsc' ),
				__( 'Result', 'bmsc' ),
				sprintf(
					/* translators: %d: number of days runs are kept. */
					__( 'Last %d days', 'bmsc' ),
					$days
				),
			)
		);
		echo '<tbody>';

		foreach ( $all as $name => $runs ) {
			self::render_row( (string) $name, $runs, $days );
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: the marks used in the day column, 2: the CLI command. */
					__( 'One mark per day, oldest first: %1$s. Runs are kept for %2$d days; %3$s lists them all.', 'bmsc' ),
					'✔ = ' . __( 'all runs fine', 'bmsc' ) . ', ✘ = ' . __( 'a run failed', 'bmsc' ) . ', · = ' . __( 'nothing ran', 'bmsc' ),
					$days,
					'wp multisite-cron status --all'
				)
			)
		);
	}

	/**
	 * One cron: how it is doing, and how it has been doing.
	 *
	 * @param string $name Run name.
	 * @param array  $runs That name's records, newest first.
	 * @param int    $days How many days to draw.
	 * @return void
	 */
	private static function render_row( string $name, array $runs, int $days ) {
		$last = reset( $runs );
		if ( empty( $last ) ) {
			return;
		}

		echo '<tr>';
		printf( '<td><strong>%s</strong></td>', esc_html( $name ) );
		$took = self::took( $last );
		printf(
			'<td>%s%s</td>',
			esc_html( self::when( $last ) ),
			$took ? '<br><span class="description">' . esc_html( $took ) . '</span>' : ''
		);
		printf( '<td>%s</td>', self::result_cell( $last ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in result_cell().
		printf( '<td>%s</td>', self::strip( $runs, $days ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in strip().
		echo '</tr>';
	}

	/**
	 * The result of the last run, which is the question this screen exists for.
	 *
	 * @param array $run The newest record.
	 * @return string Escaped cell content.
	 */
	private static function result_cell( array $run ): string {
		$problem = Run_Log::problem( $run );

		if ( '' !== $problem ) {
			return '<span style="color:#b32d2e">✘ ' . esc_html( Run_Log::summary( $run ) ) . '</span>'
				. self::message( $problem );
		}
		if ( empty( $run['finished'] ) ) {
			return '<span style="color:#996800">' . esc_html__( 'running…', 'bmsc' ) . '</span>';
		}
		return '<span style="color:#008a20">✔ ' . esc_html(
			sprintf(
				/* translators: 1: Spaces which ran a job, 2: Spaces looked at. */
				__( 'Success, jobs in %1$d of %2$d Spaces', 'bmsc' ),
				(int) ( $run['blogs_processed'] ?? 0 ),
				(int) ( $run['blogs_found'] ?? 0 )
			)
		) . '</span>';
	}

	/**
	 * What the run actually said, one click away. Folded, because on a bad day it is a few hundred
	 * characters of dumped array, and it must not push the rest of the table apart. Folded is also
	 * the point: the row above says what went wrong, this is for when that is not enough.
	 *
	 * @param string $problem The message, as recorded.
	 * @return string Escaped cell content.
	 */
	private static function message( string $problem ): string {
		return sprintf(
			'<details><summary style="cursor:pointer">%s</summary><pre style="white-space:pre-wrap;max-height:15em;overflow:auto;margin:.5em 0 0">%s</pre></details>',
			esc_html__( 'What it said', 'bmsc' ),
			esc_html( $problem )
		);
	}

	/**
	 * One mark per day, oldest first — so a bad night and a night where nothing ran at all are
	 * both one glance away, without a second table.
	 *
	 * @param array $runs That name's records.
	 * @param int   $days How many days to draw.
	 * @return string Escaped cell content.
	 */
	private static function strip( array $runs, int $days ): string {
		$by_day = array();
		foreach ( $runs as $run ) {
			$by_day[ wp_date( 'Y-m-d', (int) ( $run['started'] ?? 0 ) ) ][] = $run;
		}
		$first = wp_date( 'Y-m-d', (int) ( end( $runs )['started'] ?? time() ) );

		$marks = '';
		foreach ( array_reverse( range( 0, $days - 1 ) ) as $back ) {
			$stamp  = time() - $back * DAY_IN_SECONDS;
			$day    = wp_date( 'Y-m-d', $stamp );
			$of_day = $by_day[ $day ] ?? array();
			$state  = self::day_state( $of_day, $day < $first );

			list( $mark, $color ) = self::DAY_MARKS[ $state ];

			$marks .= sprintf(
				'<span style="color:%s" title="%s">%s</span>',
				esc_attr( $color ),
				esc_attr( self::day_title( $stamp, $back, $of_day, $state ) ),
				esc_html( $mark )
			);
		}

		return '<span style="font-family:monospace;font-size:1.2em;letter-spacing:.35em">' . $marks . '</span>';
	}

	/**
	 * @param array $of_day       That day's runs.
	 * @param bool  $before_first Is this day older than the first record?
	 * @return string A key of DAY_MARKS.
	 */
	private static function day_state( array $of_day, bool $before_first ): string {
		if ( empty( $of_day ) ) {
			return $before_first ? 'not yet' : 'missed'; // nothing recorded yet is not a gap.
		}
		return array_filter( $of_day, fn( $run ) => '' !== Run_Log::problem( $run ) ) ? 'failed' : 'ok';
	}

	/**
	 * @param int    $stamp  Any time on that day.
	 * @param int    $back   How many days ago.
	 * @param array  $of_day That day's runs.
	 * @param string $state  A key of DAY_MARKS.
	 * @return string Tooltip.
	 */
	private static function day_title( int $stamp, int $back, array $of_day, string $state ): string {
		$day = wp_date( 'D, Y-m-d', $stamp );
		if ( 0 === $back ) {
			$day = __( 'Today', 'bmsc' );
		}
		if ( 1 === $back ) {
			$day = __( 'Yesterday', 'bmsc' );
		}

		if ( 'not yet' === $state ) {
			return $day . ': ' . __( 'nothing was recorded yet', 'bmsc' );
		}
		if ( 'missed' === $state ) {
			return $day . ': ' . __( 'nothing ran', 'bmsc' );
		}

		// Summaries, not messages: a day can hold two dozen runs, and a tooltip is one line.
		$problems = array_values( array_unique( array_filter( array_map( fn( $run ) => Run_Log::summary( $run ), $of_day ) ) ) );
		$counts   = sprintf(
			/* translators: 1: runs that day, 2: how many of them failed. */
			_n( '%1$d run, %2$d failed', '%1$d runs, %2$d failed', count( $of_day ), 'bmsc' ),
			count( $of_day ),
			count( array_filter( $of_day, fn( $run ) => '' !== Run_Log::problem( $run ) ) )
		);

		return $day . ': ' . $counts . ( $problems ? ' — ' . implode( ' / ', $problems ) : '' );
	}

	/**
	 * @param string[] $headers Column titles.
	 * @return void
	 */
	private static function render_head( array $headers ) {
		echo '<thead><tr>';
		foreach ( $headers as $header ) {
			printf( '<th>%s</th>', esc_html( $header ) );
		}
		echo '</tr></thead>';
	}

	/**
	 * @param array $run One record.
	 * @return string When it started, absolute and relative.
	 */
	private static function when( array $run ): string {
		$started = (int) ( $run['started'] ?? 0 );
		if ( ! $started ) {
			return '–';
		}
		return sprintf(
			/* translators: 1: date, 2: human readable time difference. */
			__( '%1$s (%2$s ago)', 'bmsc' ),
			wp_date( 'Y-m-d H:i', $started ),
			human_time_diff( $started )
		);
	}

	/**
	 * @param array $run One record.
	 * @return string How long it took, in a unit a human can read.
	 */
	private static function took( array $run ): string {
		if ( empty( $run['finished'] ) ) {
			return '';
		}
		$seconds = (float) ( $run['duration_seconds'] ?? 0 );
		if ( $seconds >= HOUR_IN_SECONDS ) {
			/* translators: %s: duration in hours. */
			return sprintf( __( 'took %s h', 'bmsc' ), round( $seconds / HOUR_IN_SECONDS, 1 ) );
		}
		if ( $seconds >= MINUTE_IN_SECONDS ) {
			/* translators: %s: duration in minutes. */
			return sprintf( __( 'took %s min', 'bmsc' ), round( $seconds / MINUTE_IN_SECONDS ) );
		}
		/* translators: %s: duration in seconds. */
		return sprintf( __( 'took %s s', 'bmsc' ), round( $seconds ) );
	}
}
