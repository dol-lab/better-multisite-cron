<?php
namespace Better_Multisite_Cron;

use WP_CLI;

require_once dirname( __FILE__ ) . '/trait-multisite-cron-base.php';

class Multisite_Cron_Cli extends \WP_CLI_Command {

	use Multisite_Cron_Base;

	/**
	 * Run a cron-job for a specific url.
	 *
	 * @param string $url
	 * @return array [ 'response' => string, 'error' => string', 'issue' => string, 'cmd' => string' ]
	 */
	public function run_cron_for_url_now( $result, $args ) : array {

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

		$this->log( 'debug', "Running command: {$result['cmd']}" );

		$run = WP_CLI::runcommand(
			$result['cmd'],
			array(
				'return'     => 'all', // setting this true did not work for me...
				'exit_error' => false,
			)
		);

		// the the return code is 0, there was no error and stderr is is not an error (but an issue).
		$error_or_issue            = 0 === $run->return_code ? 'issue' : 'error';
		$result['response']        = $run->stdout;
		$result[ $error_or_issue ] = $run->stderr;
		return $result;
	}

	public function log( string $type, string $string ) {
		$types = array(
			'issue'   => fn( $s) => WP_CLI::warning( $s ),
			'notice'  => fn( $s) => WP_CLI::log( $s ),
			'error'   => fn( $s) => WP_CLI::error( $s, false ),
			'success' => fn( $s) => WP_CLI::success( $s ),
			'debug'   => fn( $s) => WP_CLI::debug( $s ),
			// 'warning' => fn($s) => WP_CLI::warning( $s ),
		);
		if ( ! isset( $types[ $type ] ) ) {
			$type   = 'error';
			$string = "Unknown log type[ $type ]! Original message: $string";
		}
		return $types[ $type ]( $string );
	}
}
