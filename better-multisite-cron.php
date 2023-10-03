<?php
/*
 * Plugin Name:  Better Multisite Cron
 * Plugin URI:   https://github.com/dol-lab/better-multisite-cron
 * Description:  Cron Runner for large multisite installs. Requires WP-CLI.
 * Version:      0.1
 * Author:       dol-lab (Vitus Schuhwerk)
 * Author URI:   https://github.com/dol-lab
 * Text Domain:  bmsc
 * License:      MIT License
*/

namespace Better_Multisite_Cron;

add_action(
	'cli_init',
	function() {
		require_once dirname( __FILE__ ) . '/inc/class-multisite-cron-cli.php';
		// run with wp multisite-cron run. See class-multisite-cron-base.php->run() for parameters.
		\WP_CLI::add_command( 'multisite-cron', __NAMESPACE__ . '\Multisite_Cron_Cli' );
	}
);
