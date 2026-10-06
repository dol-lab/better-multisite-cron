<?php
/*
 * Plugin Name:  Better Multisite Cron
 * Plugin URI:   https://github.com/dol-lab/better-multisite-cron
 * Description:  Cron Runner for large multisite installs. Requires WP-CLI.
 * Version:      0.3
 * Author:       dol-lab (Vitus Schuhwerk)
 * Author URI:   https://github.com/dol-lab
 * Text Domain:  bmsc
 * License:      MIT License
*/

namespace Better_Multisite_Cron;

add_action(
	'cli_init',
	function () {
		require_once __DIR__ . '/inc/class-multisite-cron-cli.php';
		/**
		 * See class-multisite-cron-base.php->run() for parameters.
		 *
		 * $ wp multisite-cron run --max_seconds=number
		 * Success: run cron on all blogs, last_updated first.
		 */
		\WP_CLI::add_command( 'multisite-cron', __NAMESPACE__ . '\Multisite_Cron_Cli' );
	}
);

/**
 * Mail a failed run to `--email_to`. Switched off per run with `--no-send_error_email`, or here
 * with a `remove_action( 'better_multisite_cron_finished', __NAMESPACE__ . '\send_error_mail' )`.
 *
 * @param array $run    The finished record, see Run_Log::start().
 * @param array $config The parsed arguments.
 * @return void
 */
function send_error_mail( array $run, array $config ) {
	require_once __DIR__ . '/inc/class-error-mail.php';
	Error_Mail::maybe_send( $run, $config );
}
add_action( 'better_multisite_cron_finished', __NAMESPACE__ . '\send_error_mail', 10, 2 );

/**
 * The report at the bottom of Network Admin → Settings. Loading happens in the callback,
 * so a plain page view never pays for it.
 */
add_action(
	'wpmu_options',
	function () {
		require_once __DIR__ . '/inc/class-network-settings.php';
		Network_Settings::render();
	}
);
