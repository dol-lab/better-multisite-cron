<?php
/**
 *
 * THIS IS NOT IMPLEMENTED YET!
 */

namespace Better_Multisite_Cron;

require_once __DIR__ . '/trait-multisite-cron-base.php';

class Multisite_Cron_Backend {

	use Multisite_Cron_Base;

	public function run_cron_for_url_now( $result, $args ): array {
		// $command = $site_url.'/wp-cron.php?doing_wp_cron'; // does not work for private sites.
		// wp_remote_get( $command );
		$result['cmd']   = 'no command';
		$result['error'] = "No provider vor {$result['site_url']}, extend the class and implement " . __FUNCTION__;
		return $result;
	}

	public function log( string $type, string $string ) {
		// do some logging here!
	}
}
