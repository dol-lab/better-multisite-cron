<?php
namespace Better_Multisite_Cron;

use Exception;

require_once __DIR__ . '/class-run-log.php';

trait Multisite_Cron_Base {

	/** @var array The record of the current run, see Run_Log. */
	private $run = array();

	/** @var string|null Freed by catch_fatal(), so an out-of-memory death can still be reported. */
	private $memory_reserve = null;

	/**
	 *
	 * Overwrite the function in your class!
	 * (Everything but run() and status() is protected, so WP-CLI does not turn it into a subcommand.)
	 *
	 * @param array $result
	 * @param array $args
	 * @return array
	 */
	protected function run_cron_for_url_now( array $result, $args ): array {
		$result['cmd']   = 'no command';
		$result['error'] = "No provider vor {$result['site_url']}, extend the class and implement " . __FUNCTION__;
		return $result;
	}

	/**
	 * Overwrite this function in your class!
	 *
	 * @param string $string
	 * @return void
	 */
	protected function log( string $type, string $string ) {
		error_log( "BMSC: $type, $string" );
	}

	/**
	 * Called at the very end of run(). Overwrite it to signal failure to the outside world
	 * (the CLI class halts with exit-code 1, so cron/systemd/monitoring can see it).
	 *
	 * @param bool  $has_errors Did this run produce any error?
	 * @param array $config     The parsed arguments.
	 * @return void
	 */
	protected function after_run( bool $has_errors, array $config ) {}

	/**
	 * wp multisite-cron run
	 */
	public function run( $positional_args, $assoc_args ) {

		$defaults = array(
			'always_add_blog_ids'       => '1', // comma-separated list of blog IDs to always include.
			'debug'                     => false, // more verbose output.
			'email_to'                  => get_network_option( get_current_network_id(), 'admin_email', '' ), // who hears about a failed run.
			'exit_on_error'             => true, // CLI only. exit with code 1 if anything went wrong.
			'include_archived'          => false, // run cron for archived blogs?
			'limit_last_updated_months' => null, // number. limit to blogs, which were updated in the last x months.
			'limit'                     => null, // limit to x blogs. null = no limit.
			'log_errors_to_file'        => '', // log errors to a file (absolute path). empty = no file-logging.
			'log_max_size'              => ( 1 * 1024 * 1024 * 20 ), // 20MB. max size of the log file.
			'log_success_to_file'       => '', // log success to a file (absolute path). empty = no file-logging.
			'log_verbose'               => false, // include args, query and per-blog cmd/response in log files.
			'max_seconds'               => 0, // don't run cron for the next blog, if it is over time. 0 = no limit.
			'name'                      => 'default', // name of this run. give each cron-entry its own, see: wp multisite-cron status.
			'order_by'                  => 'last_updated DESC, blog_id ASC', // run new blogs first, because they are more important?
			'overtime_is_error'         => false, // treat it as an error, if max_seconds was not enough to finish all jobs.
			'send_error_email'          => true, // mail email_to, if the run went wrong?
			'skip_all_plugins'          => false, // CLI only. careful: --skip-plugins (<-no underscore but - in the middle) does something else...
			'skip_all_themes'           => false, // CLI only.
		);

		$error_messages = array();
		$results        = array();
		$log_timestamp  = wp_date( 'Y-m-d H:i:s' );
		$config         = wp_parse_args( $assoc_args, $defaults );

		// --no-foo arrives as false, but --foo=false arrives as the string 'false'. Every argument
		// with a boolean default is a switch, so make both of them mean the same thing.
		foreach ( $defaults as $key => $default ) {
			if ( is_bool( $default ) ) {
				$config[ $key ] = filter_var( $config[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		// Before the try: a crash from here on has to leave an unfinished record behind.
		$this->start_run( $config['name'] );

		try {

			$invalid_args = array_diff( array_keys( $assoc_args ), array_keys( $defaults ) );
			if ( ! empty( $invalid_args ) ) {
				throw new Exception( 'Stopping: Invalid arguments passed to cli command: ' . implode( ', ', $invalid_args ), 1 );
			}

			if ( ! is_numeric( $config['max_seconds'] ) ) {
				throw new Exception( 'The max_seconds parameter is not numeric.' );
			}

			if ( $config['max_seconds'] ) {
				set_time_limit( round( $config['max_seconds'] * 1.1 + 20 ) );
			}
			$results          = $this->trigger_all_blogs( $config );
			$error_messages[] = $this->output( $config, $results );

			// File logging (after output, so CLI errors are visible even if file-logging fails).
			if ( $results['error_count'] ) {
				$this->maybe_log_to_file( $config['log_errors_to_file'], $config, $results, $log_timestamp );
			}
			if ( $this->count_processed( $results['blog_tasks'] ) ) {
				$this->maybe_log_to_file( $config['log_success_to_file'], $config, $results, $log_timestamp );
			}

		} catch ( \Throwable $th ) {
			$msg              = $config['debug'] ? $th : $th->getMessage();
			$error_messages[] = print_r( $msg, true );
		}

		$error_messages = array_filter( $error_messages );
		$has_errors     = ! empty( $error_messages );

		if ( $has_errors ) {
			$this->log( 'error', implode( "\n", $error_messages ) );
		}

		$this->finish_run( $results, implode( "\n", $error_messages ) );

		/**
		 * Fires once per run, after the record was written. Report a run wherever you want it: a
		 * logger, a monitoring ping, a chat message. The error mail (see Error_Mail) is one of these
		 * listeners, not a step of the run.
		 *
		 * @param array $run    The finished record, see Run_Log::start().
		 * @param array $config The parsed arguments.
		 */
		do_action( 'better_multisite_cron_finished', $this->run, $config );

		$this->after_run( $has_errors, $config ); // may exit.
	}

	/**
	 * Remember that a run started. Without this, "no errors were logged" and "nothing ever ran"
	 * look exactly the same from the outside.
	 *
	 * @param string $name Name of this run.
	 * @return void
	 */
	private function start_run( string $name ) {
		$this->memory_reserve = str_repeat( ' ', 256 * 1024 );
		register_shutdown_function( fn() => $this->catch_fatal() ); // a closure keeps catch_fatal private.

		$this->run = Run_Log::start( $name );
	}

	/**
	 * @param array  $results       Results from trigger_all_blogs() (empty if it threw).
	 * @param string $error_message All errors of this run, joined.
	 * @return void
	 */
	private function finish_run( array $results, string $error_message ) {
		$tasks = $results['blog_tasks'] ?? array();

		$this->run = Run_Log::save(
			array_merge(
				$this->run,
				array(
					'finished'         => time(),
					'duration_seconds' => $results['duration_all_seconds'] ?? 0,
					'blogs_found'      => count( $tasks ),
					'blogs_processed'  => $this->count_processed( $tasks ),
					'error_count'      => $results['error_count'] ?? 0,
					'error'            => $error_message,
				)
			)
		);
	}

	/**
	 * Runs on shutdown. A fatal or an out-of-memory kill never reaches the catch-block in run(),
	 * so this is the only place where such a death can still be written down.
	 *
	 * @return void
	 */
	private function catch_fatal() {
		$this->memory_reserve = null; // free some memory, in case we ran out of it.

		if ( empty( $this->run ) || ! empty( $this->run['finished'] ) ) {
			return; // run() got to the end, nothing to report here.
		}

		$last  = error_get_last();
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		$this->run['error'] = ( $last && in_array( $last['type'], $fatal, true ) )
			? "Fatal: {$last['message']} in {$last['file']}:{$last['line']}"
			: 'The process died before finishing (no PHP error recorded: killed, timed out or out of memory).';

		$this->run = Run_Log::save( $this->run );
		$this->log( 'error', $this->run['error'] );
	}

	/**
	 * @param array $tasks Blog tasks from trigger_all_blogs().
	 * @return int Blogs which actually ran something (everything else had no due job).
	 */
	private function count_processed( array $tasks ): int {
		return count( array_filter( $tasks, fn( $task ) => ! empty( $task['response'] ) ) );
	}

	/**
	 * Log results to CLI output.
	 *
	 * @param array  $config  Parsed arguments.
	 * @param array  $results Results from trigger_all_blogs().
	 * @return string Error message (empty string if no errors).
	 */
	protected function output( $config, $results ): string {

		$err             = '';
		$all_count       = count( $results['blog_tasks'] );
		$processed_tasks = array_filter( $results['blog_tasks'], fn( $a ) => ! empty( $a['response'] ) );
		$processed_count = count( $processed_tasks );

		if ( $processed_count ) {
			$processed_ids = implode( ',', array_map( fn( $a ) => $a['blog_id'], $processed_tasks ) );
			$this->log(
				'success',
				"Found $all_count Blogs. " .
				"Ran cron in $processed_count blogs " .
				"in {$results['duration_all_seconds']}s. Processed [$processed_ids]."
			);
		}

		if ( $results['error_count'] ) {
			$errors = array_filter( $results['blog_tasks'], fn( $a ) => $a['error'] ?? false );
			$err    = "{$results['error_count']} job(s) failed (or was/were skipped). "
				. print_r( $this->group_blog_tasks( $errors, true ), true );
		}

		$issues = array_filter( $results['blog_tasks'], fn( $a ) => $a['issue'] ?? false );
		if ( count( $issues ) ) {
			$this->log( 'issue', 'Found issues: ' . print_r( $issues, true ) );
		}

		return $err;
	}

	private function group_blog_tasks( array $tasks, bool $verbose = false ): array {
		$verbose_keys = array( 'cmd', 'response', 'site_url', 'issue' );
		$grouped      = array();

		foreach ( $tasks as $task ) {
			$blog_id = $task['blog_id'];
			unset( $task['blog_id'] );

			// In compact mode, strip per-blog detail so more entries hash-match.
			if ( ! $verbose ) {
				foreach ( $verbose_keys as $key ) {
					unset( $task[ $key ] );
				}
			}

			$hash = md5( json_encode( $task ) );
			if ( ! isset( $grouped[ $hash ] ) ) {
				$grouped[ $hash ]             = $task;
				$grouped[ $hash ]['blog_ids'] = array( $blog_id );
			} else {
				$grouped[ $hash ]['blog_ids'][] = $blog_id;
			}
		}

		return array_values( $grouped );
	}

	private function trigger_all_blogs( $args ) {
		/**
		 * @var wpdb $wpdb
		 */
		global $wpdb;
		// Multisite
		if ( ! defined( 'WP_ALLOW_MULTISITE' ) || WP_ALLOW_MULTISITE !== true ) {
			throw new Exception( 'This only works on multisite.', 1 );
		}

		$start_global = microtime( true );

		$blog_query = $this->make_blog_query( $args );
		$this->log( 'notice', 'Blog Query: ' . $blog_query );
		$results = $wpdb->get_results( $blog_query );

		if ( is_wp_error( $results ) ) {
			throw new Exception( 'An error occurred querying all blogs: ' . print_r( $results, true ), 1 );
		}

		if ( empty( $results ) ) {
			throw new Exception( 'Querying all blogs returned empty. Thats odd.', 1 );
		}

		$this->log( 'notice', 'Found ' . count( $results ) . ' blogs.' );

		$res = array(
			'args'                 => $args, // arguments used to trigger this command.
			'query_all_blogs'      => $blog_query, // the query used to retrieve the blogs.
			'error_count'          => 0, // number of blogs, which had an error.
			'duration_all_seconds' => 0, // total duration of the script.
			'blog_tasks'           => array(), // array of arrays (details about the execution)
		);

		foreach ( $results as $blog ) {

			$is_over_time        = $args['max_seconds'] > 0 && microtime( true ) - $start_global > $args['max_seconds'];
			$blog_result_default = array(
				'blog_id'   => $blog->blog_id,
				// 'error'   => $is_over_time ? 'over_time' : false,
				'over_time' => $is_over_time ? 1 : 0,
			);
			$blog_result         = $this->run_cron_for_blog( $blog_result_default, $blog, $args );

			if ( ! empty( $blog_result ) ) {
				$res['blog_tasks'][] = $blog_result;
			}
		}
		$res['duration_all_seconds'] = $this->round_seconds( microtime( true ) - $start_global );
		$res['error_count']          = count( array_filter( $res['blog_tasks'], fn( $a ) => ! empty( $a['error'] ) ) );
		return $res;
	}

	private function run_cron_for_blog( $result, $blog, $args ) {

		if ( apply_filters( 'better_multisite_cron_early_exit_over_time', $result['over_time'], $result, $args ) ) {
			return $this->maybe_add_overtime_error( $result, $args );
		}

		$start_blog = microtime( true );
		$this->log( 'debug', "Memory usage before blog {$result['blog_id']}: " . round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB' );

		switch_to_blog( $result['blog_id'] );
		wp_suspend_cache_addition( true );

		$jobs                = wp_get_ready_cron_jobs();
		$result['job_names'] = $this->get_names_from_jobs( $jobs );

		if ( ! empty( $result['job_names'] ) ) {
			$result['site_url'] = get_site_url( $result['blog_id'] );
			$result             = $this->maybe_add_overtime_error( $result, $args );

			/**
			 * This filter allows you to prevent/enable the cron-job for a specific blog.
			 * Enable: Make sure to check filter 'better_multisite_cron_early_exit_over_time' so this filter is reached.
			 */
			$result = apply_filters( 'better_multisite_cron_before_run', $result, $args, $blog );

			if ( empty( $result['error'] ) && ! $result['over_time'] ) {
				$result = $this->run_cron_for_url_now( $result, $args );
			}
		}

		wp_suspend_cache_addition( false );
		restore_current_blog();
		wp_cache_flush();
		$result['duration_blog_seconds'] = $this->round_seconds( microtime( true ) - $start_blog );

		if ( ! empty( $result['site_url'] ) ) {
			$this->log( 'notice', "Blog {$result['blog_id']} ({$result['site_url']}) finished in {$result['duration_blog_seconds']} seconds." );
		}
		return $result;
	}

	private function maybe_add_overtime_error( $result, $args ) {
		if ( $args['overtime_is_error'] && empty( $result['error'] ) && $result['over_time'] ) {
			$result['error'] = 'over_time';
		}
		return $result;
	}

	private function get_names_from_jobs( $jobs ) {
		// $jobs is nested like [ 'unix-timestamps like 1695990558' => [ 'job_names' => [ 'hash' => [ ... ] ] ] ]
		return array_unique( array_merge( ...array_map( fn( $a ) => array_keys( $a ), array_values( $jobs ) ) ) );
	}

	private function make_blog_query( $args ) {
		/**
		 * @var wpdb $wpdb
		 */
		global $wpdb;
		$maybe_limit = is_numeric( $args['limit'] ) ? 'limit ' . intval( $args['limit'] ) : '';

		$wheres   = array();
		$wheres[] = $args['include_archived'] ? '' : 'AND archived=0';
		$wheres[] = ! is_numeric( $args['limit_last_updated_months'] ) ? ''
			: 'AND last_updated > (now() - interval ' . intval( $args['limit_last_updated_months'] ) . ' month)';

		$oder_by = $this->sanitize_order_wp_blogs( $args['order_by'] );

		if ( ! empty( $args['always_add_blog_ids'] ) ) {
			$blog_ids_to_add = array_map( 'intval', explode( ',', $args['always_add_blog_ids'] ) );
			if ( ! empty( $blog_ids_to_add ) ) {
				$blog_ids_sql = implode( ',', $blog_ids_to_add );
				$wheres[]     = "OR blog_id IN ($blog_ids_sql)";
				// Adjust order_by to prioritize these blogs
				$case_statements = array();
				foreach ( $blog_ids_to_add as $index => $blog_id ) {
					// Higher priority for earlier IDs in the list, ensuring they come first.
					// The CASE statement will assign a higher number to items appearing earlier in the list.
					// DESC order means higher numbers come first.
					$case_statements[] = "WHEN {$blog_id} THEN " . ( count( $blog_ids_to_add ) - $index );
				}
				// Blogs not in the list get a lower priority (0 in this case).
				$oder_by = 'CASE blog_id ' . implode( ' ', $case_statements ) . ' ELSE 0 END DESC, ' . $oder_by;
			}
		}

		$where = implode( "\n", array_filter( $wheres ) );
		$query = "
			SELECT * FROM $wpdb->blogs
			WHERE deleted=0 AND (
				1=1
				$where
			)
			ORDER BY
			$oder_by
			$maybe_limit
		";
		// remove newlines and spaces.
		return preg_replace( '/\s+/', ' ', $query );
	}

	private function maybe_log_to_file( $log_file, $args, $log_data, $timestamp ) {

		if ( empty( $log_file ) ) {
			return; // nothing to do (false is considered empty too).
		}

		$this->log( 'notice', 'Logging to file: ' . $log_file );

		// Check if the log file exists, and create it if not.
		if ( ! file_exists( $log_file ) ) {
			$file = fopen( $log_file, 'w' );
			if ( false === $file ) {
				throw new Exception( "Failed to create log file '$log_file'.", 1 );
			}
			fclose( $file );
		}
		if ( filesize( $log_file ) > $args['log_max_size'] ) {
			$abs_path = realpath( $log_file );
			throw new Exception( "Log file [$abs_path] is too big.", 1 );
		}

		$verbose = ! empty( $args['log_verbose'] );

		// In compact mode, drop args and query (they rarely change between runs).
		if ( ! $verbose ) {
			unset( $log_data['args'] );
			unset( $log_data['query_all_blogs'] );
		}

		$log_data['blog_tasks'] = $this->group_blog_tasks( $log_data['blog_tasks'], $verbose );
		$log_message            = wp_json_encode( array( $timestamp => $log_data ) );

		// Append as JSON Lines (one JSON object per line, parseable with jq).
		if ( file_put_contents( $log_file, $log_message . "\n", FILE_APPEND | LOCK_EX ) === false ) {
			throw new Exception( 'Failed to create log file.', 1 );
		}
	}

	/**
	 *
	 * @param string $order_by table_name.column_name [ASC|DESC], table_name.column_name [ASC|DESC], ...
	 * @return string the original order_by string, if it is valid.
	 * @throws \Exception If invalid.
	 */
	private function sanitize_order_wp_blogs( string $order_by ) {
		$whitelist = array( 'asc', 'desc', 'blog_id', 'site_id', 'domain', 'path', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted', 'lang_id' );
		$chunks    = preg_split( '/[,\s]+/', $order_by );
		$chunks    = array_map( fn( $a ) => strtolower( trim( $a ) ), $chunks );
		$remaining = array_diff( $chunks, $whitelist );
		if ( ! empty( $remaining ) ) {
			throw new Exception( 'Invalid order_by part(s): ' . esc_html( implode( ', ', $remaining ) ), 1 );
		}
		return $order_by;
	}

	/**
	 * @param float $microtime Microtime value.
	 * @return float Rounded to 2 decimal places.
	 */
	private function round_seconds( $microtime ) {
		return round( $microtime * 100 ) / 100;
	}
}
