<?php
namespace Better_Multisite_Cron;

use WP_CLI;

require_once __DIR__ . '/trait-multisite-cron-base.php';

class Multisite_Cron_Cli extends \WP_CLI_Command {

	use Multisite_Cron_Base;

	/** @var string[] Columns of `wp multisite-cron status`. */
	const FIELDS = array( 'name', 'started', 'finished', 'duration_seconds', 'blogs_processed', 'error_count', 'problem' );

	/**
	 * Report the recorded run(s) of `wp multisite-cron run`.
	 *
	 * Exits with code 1 if a run died, logged an error or is older than --max_age.
	 * That makes silence checkable: `wp multisite-cron status --name=quick --max_age=1800`.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Only look at the run with this name. Default: all recorded runs.
	 *
	 * [--max_age=<seconds>]
	 * : Complain if the last run finished more than X seconds ago. Default: 0 (don't check).
	 *
	 * [--all]
	 * : List every kept run instead of the last one per name.
	 *
	 * @param array $positional_args Unused.
	 * @param array $assoc_args      See OPTIONS.
	 * @return void
	 */
	public function status( $positional_args, $assoc_args ) {
		$name    = (string) ( $assoc_args['name'] ?? '' );
		$max_age = (int) ( $assoc_args['max_age'] ?? 0 );
		$runs    = empty( $assoc_args['all'] )
			? Run_Log::latest( $name, $max_age )
			: $this->all_runs( $name, $max_age );

		if ( empty( $runs ) ) {
			WP_CLI::error( 'No run recorded' . ( $name ? " for '$name'" : '' ) . '. Is `wp multisite-cron run` set up in cron?' );
		}

		WP_CLI\Utils\format_items( 'table', array_map( array( $this, 'for_table' ), $runs ), self::FIELDS );

		$problems = array_filter( array_column( $runs, 'problem' ) );
		if ( ! empty( $problems ) ) {
			WP_CLI::error( count( $problems ) . ' run(s) need attention.', false );
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( count( $runs ) . ' run(s), no problems.' );
	}

	/**
	 * @param string $name    Only this run. Empty = all names.
	 * @param int    $max_age Complain if a run finished more than X seconds ago. 0 = don't check.
	 * @return array Every kept run, newest first, with a 'problem' key.
	 */
	private function all_runs( string $name, int $max_age ): array {
		$all = array();
		foreach ( Run_Log::by_name( $name ) as $runs ) {
			foreach ( $runs as $run ) {
				$run['problem'] = Run_Log::problem( $run, $max_age );
				$all[]          = $run;
			}
		}
		return $all;
	}

	/**
	 * Timestamps are stored as integers, humans read dates. Also fills in every column: a record
	 * written by an older version must not turn the whole table into "Error: Invalid field".
	 *
	 * @param array $run One record.
	 * @return array
	 */
	private function for_table( array $run ): array {
		$run             = array_merge( array_fill_keys( self::FIELDS, '' ), $run );
		$run['started']  = empty( $run['started'] ) ? '' : wp_date( 'Y-m-d H:i:s', $run['started'] );
		$run['finished'] = empty( $run['finished'] ) ? '' : wp_date( 'Y-m-d H:i:s', $run['finished'] );
		return $run;
	}

	/**
	 * Signal failure to whatever started us (cron mails it, systemd marks the unit failed).
	 *
	 * @param bool  $has_errors Did this run produce any error?
	 * @param array $config     The parsed arguments.
	 * @return void
	 */
	protected function after_run( bool $has_errors, array $config ) {
		if ( $has_errors && $config['exit_on_error'] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Run a cron-job for a specific url.
	 *
	 * @param string $url
	 * @return array [ 'response' => string, 'error' => string', 'issue' => string, 'cmd' => string' ]
	 */
	protected function run_cron_for_url_now( $result, $args ): array {

		// check for required arguments.
		$flags = array_filter(
			array(
				'skip_all_plugins' => '--skip-plugins', // still runs cron-events added by plugins.
				'skip_all_themes'  => '--skip-themes',
			),
			fn( $k ) => false !== $args[ $k ],
			ARRAY_FILTER_USE_KEY
		);

		// add time limit. each command can not run longer than max_seconds.
		$flags[] = $args['max_seconds'] ? "--exec='set_time_limit( {$args['max_seconds']} );'" : '';

		$result['cmd'] = "cron event run --url={$result['site_url']} --due-now " . implode( ' ', $flags );

		$this->log( 'notice', "Running command: {$result['cmd']}" );

		$run = WP_CLI::runcommand(
			$result['cmd'],
			array(
				'return'     => 'all',
				'exit_error' => false,
				'launch'     => true,
			)
		);
		// the the return code is 0, there was no error and stderr is is not an error (but an issue).
		$error_or_issue            = 0 === $run->return_code ? 'issue' : 'error';
		$result['response']        = $run->stdout;
		$result[ $error_or_issue ] = $run->stderr;
		return $result;
	}

	protected function log( string $type, string $string ) {
		$types = array(
			'issue'   => fn( $s ) => WP_CLI::warning( $s ),
			'notice'  => fn( $s ) => WP_CLI::log( $s ),
			'error'   => fn( $s ) => WP_CLI::error( $s, false ),
			'success' => fn( $s ) => WP_CLI::success( $s ),
			'debug'   => fn( $s ) => WP_CLI::debug( $s ),
			// 'warning' => fn($s) => WP_CLI::warning( $s ),
		);
		if ( ! isset( $types[ $type ] ) ) {
			$type   = 'error';
			$string = "Unknown log type[ $type ]! Original message: $string";
		}
		return $types[ $type ]( $string );
	}
}
