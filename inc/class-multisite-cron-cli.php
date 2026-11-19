<?php
namespace Better_Multisite_Cron;

use WP_CLI;

require_once __DIR__ . '/trait-multisite-cron-base.php';
require_once __DIR__ . '/class-process.php';

class Multisite_Cron_Cli extends \WP_CLI_Command {

	use Multisite_Cron_Base;

	/** @var bool Whether this run said that jobs cannot be stopped here. */
	private $warned_unstoppable = false;

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
	 * Check that this server can run `wp multisite-cron run`. Run it as the user cron runs as.
	 *
	 * Starts a job and stops one that is over time, as a run does. Exits with code 1 if anything is
	 * missing.
	 *
	 * ## OPTIONS
	 *
	 * [--log_file=<path>]
	 * : Also check that this file (--log_errors_to_file, --log_success_to_file) can be written.
	 *
	 * ## EXAMPLES
	 *
	 *     sudo -u www-data wp multisite-cron check --log_file=/srv/www/logs/better-cron.log
	 *
	 * @param array $positional_args Unused.
	 * @param array $assoc_args      See OPTIONS.
	 * @return void
	 */
	public function check( $positional_args, $assoc_args ) {
		$checks = array(
			'Multisite' => is_multisite()
				? array( 'status' => 'ok' )
				: array(
					'status' => 'error',
					'detail' => 'This only works on multisite.',
				),
		) + Process::check( WP_CLI\Utils\get_php_binary() );

		if ( ! empty( $assoc_args['log_file'] ) ) {
			$problem            = $this->log_file_problem( (string) $assoc_args['log_file'] );
			$checks['Log file'] = array(
				'status' => '' === $problem ? 'ok' : 'error',
				'detail' => $problem,
			);
		}

		$rows = array();
		foreach ( $checks as $check => $row ) {
			$rows[] = array( 'check' => $check ) + $row + array( 'detail' => '' );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'detail' ) );

		$count = array_count_values( array_column( $rows, 'status' ) );
		if ( ! empty( $count['error'] ) ) {
			WP_CLI::error( "{$count['error']} check(s) failed." );
		}
		WP_CLI::success( empty( $count['warning'] ) ? 'This server can run multisite-cron.' : 'This server can run multisite-cron, with the warnings above.' );
	}

	/**
	 * @param string $file Absolute path.
	 * @return string Empty if it can be written.
	 */
	private function log_file_problem( string $file ): string {
		if ( file_exists( $file ) ) {
			return is_writable( $file ) ? '' : "Not writable for this user: $file"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a log file, not the site's file system.
		}
		return is_writable( dirname( $file ) ) ? '' : 'Cannot be created, the directory is not writable for this user: ' . dirname( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- see above.
	}

	/**
	 * Said once per run, in the CLI only: it is about this server, not about a job.
	 *
	 * @param array $args The parsed arguments.
	 * @return void
	 */
	private function warn_if_unstoppable( array $args ) {
		if ( $this->warned_unstoppable || ! $args['max_seconds'] || Process::can_stop() ) {
			return;
		}
		$this->warned_unstoppable = true;
		WP_CLI::warning( 'proc_get_status() or proc_terminate() is disabled: jobs run without max_seconds. See `wp multisite-cron check`.' );
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

		$result['cmd'] = "cron event run --url={$result['site_url']} --due-now " . implode( ' ', $flags );

		$this->log( 'notice', "Running command: {$result['cmd']}" );

		$this->warn_if_unstoppable( $args );
		$run = Process::run( $this->command_line( $result['cmd'] ), (float) $args['max_seconds'] ); // not WP_CLI::runcommand(), see Process.
		// the the return code is 0, there was no error and stderr is is not an error (but an issue).
		$error_or_issue            = 0 === $run->return_code ? 'issue' : 'error';
		$result['response']        = $run->stdout;
		$result[ $error_or_issue ] = $run->stderr;
		return $result;
	}

	/**
	 * The command line WP_CLI::runcommand() would build: this PHP, this wp, and the global arguments this
	 * run got (--path, --quiet, ...), except those the command sets itself.
	 *
	 * @param string $command WP-CLI command, without `wp`.
	 * @return string
	 */
	private function command_line( string $command ): string {
		list( , , $runtime ) = WP_CLI::get_configurator()->parse_args( array_slice( $GLOBALS['argv'], 1 ) );
		foreach ( array_keys( $runtime ) as $key ) {
			if ( preg_match( '/(^|\s)--' . preg_quote( $key, '/' ) . '(=|\s|$)/', $command ) ) {
				unset( $runtime[ $key ] );
			}
		}
		return implode(
			' ',
			array( escapeshellarg( WP_CLI\Utils\get_php_binary() ), escapeshellarg( $GLOBALS['argv'][0] ), WP_CLI\Utils\assoc_args_to_str( $runtime ), $command )
		);
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
