<?php
namespace Better_Multisite_Cron;

use \WP_CLI;
use Exception;

trait Multisite_Cron_Base {

	/**
	 *
	 * Overwrite the function in your class!
	 *
	 * @param array $result
	 * @param array $args
	 * @return array
	 */
	public function run_cron_for_url_now( array $result, $args ) : array {
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
	public function log( string $type, string $string ) {
		error_log( "BMSC: $type, $string" );
	}

	/**
	 * wp multisite-cron run
	 */
	public function run( $args, $assoc_args ) {

		$defaults = array(
			'always_add_root_blog'      => true, // always add the root blog (first) to the list of blogs to run cron for.
			'debug'                     => false, // more verbose output.
			'email_to'                  => get_network_option( get_current_network_id(), 'admin_email' ),
			'include_archived'          => false, // run cron for archived blogs?
			'limit_last_updated_months' => null, // number. limit to blogs, which were updated in the last x months.
			'limit'                     => null, // limit to x blogs. null = no limit.
			'log_errors_to_file'        => false, // log errors to a file (absolute path). null = no logging.
			'log_max_size'              => ( 1 * 1024 * 1024 * 20 ), // 10MB. max size of the log file.
			'log_success_to_file'       => false, // WIP! log success to a file (absolute path). null = no logging.
			'max_seconds'               => 0, // don't run cron for the next blog, if it is over time. 0 = no limit.
			'order_by'                  => 'last_updated DESC, blog_id ASC', // run new blogs first, because they are more important?
			'overtime_is_error'         => false, // treat it as an error, if max_seconds was not enough to finish all jobs.
			'send_error_email'          => true, // send an email, if an error occurred?
			'skip_all_plugins'          => false, // CLI only. careful: --skip-plugins (<-no underscore but - in the middle) does something else...
			'skip_all_themes'           => false, // CLI only.
			'sleep_between'             => 0.02, // sleep x seconds between each blog.
		);

		$error_messages = array();
		$log_timestamp  = wp_date( 'Y-m-d H:i:s' );

		try {

			$args         = wp_parse_args( $assoc_args, $defaults ); // overwrite args...
			$invalid_args = array_diff( array_keys( $assoc_args ), array_keys( $defaults ) );
			if ( ! empty( $invalid_args ) ) {
				throw new Exception( 'Stopping: Invalid arguments passed to cli command: ' . implode( ', ', $invalid_args ), 1 );
			}
			$results          = $this->trigger_all_blogs( $args );     // Get the current timestamp.
			$error_messages[] = $this->output( $args, $results, $log_timestamp );

		} catch ( \Throwable $th ) {
			$msg              = $args['debug'] ? $th : $th->getMessage();
			$error_messages[] = print_r( $msg, true );
		}

		if ( ! empty( array_filter( $error_messages ) ) ) {
			$this->log( 'error', implode( "\n", $error_messages ) );
		}

		// outside the catch blog and after file-logging, so you have a chance to see if something went wrong logging.
		$this->maybe_send_email( $args, array_filter( $error_messages ), $log_timestamp );

		// $this->log( 'notice', 'Finished in ' . $results['duration_all_seconds'] . ' seconds.' );
	}

	public function output( $args, $results, $log_timestamp ) : string {

		$err           = '';
		$all_count     = count( $results['blog_tasks'] );
		$success_count = $all_count - $results['error_count'];

		if ( $success_count ) {
			// tasks with a response are considered successful.
			$processed_tasks = array_filter( $results['blog_tasks'], fn( $a ) => ! empty( $a['response'] ) );
			$processed_count = count( $processed_tasks );
			$processed_ids   = implode( ',', array_map( fn( $a ) => $a['blog_id'], $processed_tasks ) );
			$this->log(
				'success',
				"Found $all_count Blogs. " .
				"Ran cron in $processed_count blogs " .
				"in {$results['duration_all_seconds']}s. Processed [$processed_ids]."
			);
		}

		if ( $results['error_count'] ) {
			$errors  = array_filter( $results['blog_tasks'], fn( $a ) => $a['error'] ?? false );
			$err_msg = "{$results['error_count']} job(s) failed (or was/were skipped). "
				. print_r( $this->group_blog_tasks_by_blog_id( $errors ), true );
			$err     = $err_msg;
		}
		$issues = array_filter( $results['blog_tasks'], fn( $a ) => $a['issue'] ?? false );
		if ( count( $issues ) ) {
			$this->log( 'issue', 'Found issues: ' . print_r( $issues, true ) );
		}

		$this->maybe_log_to_file( $args, $results, $log_timestamp );
		return $err;
	}

	private function group_blog_tasks_by_blog_id( $errors ) {
		// group errors where all keys and values (but blog_id) are similar.
		$grouped = array();
		foreach ( $errors as $error ) {
			$blog_id = $error['blog_id'];
			unset( $error['blog_id'] );
			$hash = md5( json_encode( $error ) );
			if ( ! isset( $grouped[ $hash ] ) ) {
				$grouped[ $hash ]             = $error;
				$grouped[ $hash ]['blog_ids'] = array( $blog_id );
			} else {
				$grouped[ $hash ]['blog_ids'][] = $blog_id;
			}
		}
		// get rid of the hash.
		return array_values( $grouped );

	}

	private function maybe_send_email( array $args, array $error_messages, string $log_timestamp ) {
		if ( ! $args['send_error_email'] ) {
			return;
		}

		// validate email.
		if ( ! is_email( $args['email_to'] ) ) {
			$this->log( 'error', "Invalid email '{$args['email_to']}'." );
			return;
		}

		if ( empty( $error_messages ) ) {
			return;
		}

		$this->log( 'notice', "Sending error email to '{$args['email_to']}'." );
		$timezone        = wp_timezone_string();
		$maybe_check_log = $args['log_errors_to_file'] ? "Check log file '{$args['log_errors_to_file']}' with timestamp '$log_timestamp' $timezone." : '';
		wp_mail(
			$args['email_to'],
			'WP CLI Multisite Cron Errors.',
			implode( "\n", $error_messages )
			. "\n\n" . print_r( $args, true ) . "\n\n" . $maybe_check_log
		);

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

			$blog_result = $this->run_cron_for_blog( $blog_result_default, $blog, $args );

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

		switch_to_blog( $result['blog_id'] );
		wp_suspend_cache_addition( true );

		$jobs                = wp_get_ready_cron_jobs();
		$result['job_names'] = $this->get_names_from_jobs( $jobs );
		if ( empty( $result['job_names'] ) ) {
			return $result;
		}
		$result['site_url'] = get_site_url( $result['blog_id'] );

		// add over_time error, if there is no other error and the blog is over time.
		$result = $this->maybe_add_overtime_error( $result, $args );

		/**
		 * This filter allows you to prevent/enable the cron-job for a specific blog.
		 * Enable: Make sure to check filter 'better_multisite_cron_early_exit_over_time' to this filter is reached.
		 */
		$result = apply_filters( 'better_multisite_cron_before_run', $result, $args, $blog );
		// We exit, on over time or if there is an error.
		if ( ! empty( $result['error'] ) || $result['over_time'] ) {
			return $result;
		}

		$result = $this->run_cron_for_url_now( $result, $args );

		if ( $args['sleep_between'] > 0 ) {
			usleep( intval( $args['sleep_between'] ) );
		}

		wp_suspend_cache_addition( false );
		restore_current_blog();

		$result['duration_blog_seconds'] = $this->round_seconds( microtime( true ) - $start_blog );

		$this->log( 'notice', "Blog {$result['blog_id']} ({$result['site_url']}) finished in {$result['duration_blog_seconds']} seconds." );
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

		if ( $args['always_add_root_blog'] ) {
			$wheres[] = 'OR blog_id=1';
			$oder_by  = 'CASE blog_id WHEN 1 THEN 1 ELSE 0 END DESC,' . $oder_by;
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

	private function maybe_log_to_file( $args, $log_data, $timestamp ) {

		if ( empty( $args['log_errors_to_file'] ) || 0 === $log_data['error_count'] ) {
			return;
		}

		$log_file = $args['log_errors_to_file'];

		$this->log( 'notice', 'Logging errors to file: ' . $log_file );

		// Check if the log file exists, and create it if not.
		if ( ! file_exists( $log_file ) ) {
			$file = fopen( $log_file, 'w' );
			if ( $file === false ) {
				throw new Exception( "Failed to create log file '$log_file'.", 1 );
			}
			fclose( $file );
		}
		if ( filesize( $log_file ) > $args['log_max_size'] ) {
			$abs_path = realpath( $log_file );
			throw new Exception( "Log file [$abs_path] is too big.", 1 );
		}

		// Prepare the log message.
		$log_data['blog_tasks'] = $this->group_blog_tasks_by_blog_id( $log_data['blog_tasks'] );
		$log_message            = json_encode( array( $timestamp => $log_data ) );

		// Append the log message to the log file.
		if ( file_put_contents( $log_file, $log_message . "\n\n", FILE_APPEND | LOCK_EX ) === false ) {
			throw new Exception( 'Failed to create log file.', 1 );
		}
	}

	/**
	 *
	 * @param string $order_by table_name.column_name [ASC|DESC], table_name.column_name [ASC|DESC], ...
	 * @return string the original order_by string, if it is valid.
	 * @throws \Exception if invalid.
	 */
	private function sanitize_order_wp_blogs( string $order_by ) {
		$whitelist = array( 'asc', 'desc', 'blog_id', 'site_id', 'domain', 'path', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted', 'lang_id' );
		$chunks    = preg_split( '/[,\s]+/', $order_by );
		$chunks    = array_map( fn( $a ) => strtolower( trim( $a ) ), $chunks );
		$remaining = array_diff( $chunks, $whitelist );
		if ( ! empty( $remaining ) ) {
			throw new Exception( 'Invalid order_by part(s): ' . implode( ', ', $remaining ), 1 );
		}
		return $order_by;
	}

	private function round_seconds( $microtime ) {
		return round( $microtime * 100 ) / 100;
	}
}
