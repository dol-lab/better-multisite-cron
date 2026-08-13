<?php
/**
 * Just enough WordPress to test the run log without a WordPress.
 *
 * @package better-multisite-cron
 */

$GLOBALS['bmsc_test_network_options'] = array();
$GLOBALS['bmsc_test_filters']         = array();
$GLOBALS['bmsc_test_actions']         = array();
$GLOBALS['bmsc_test_mails']           = array();

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function get_current_network_id() {
	return 1;
}

function get_network_option( $network_id, $option, $fallback = false ) {
	return $GLOBALS['bmsc_test_network_options'][ $network_id ][ $option ] ?? $fallback;
}

function update_network_option( $network_id, $option, $value ) {
	$GLOBALS['bmsc_test_network_options'][ $network_id ][ $option ] = $value;
	return true;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp ?? time() );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * Filters are pass-through, unless a test registers one in $GLOBALS['bmsc_test_filters'].
 */
function apply_filters( $tag, $value, ...$args ) {
	$filter = $GLOBALS['bmsc_test_filters'][ $tag ] ?? null;
	return null === $filter ? $value : $filter( $value, ...$args );
}

/**
 * Actions are only recorded, so a test can assert what was fired.
 */
function do_action( $tag, ...$args ) {
	$GLOBALS['bmsc_test_actions'][ $tag ][] = $args;
}

/**
 * Mails are only collected, so a test can assert what would have been sent.
 */
function wp_mail( $to, $subject, $message ) {
	$GLOBALS['bmsc_test_mails'][] = compact( 'to', 'subject', 'message' );
	return true;
}

function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function wp_timezone_string() {
	return 'UTC';
}

function network_admin_url( $path = '' ) {
	return "https://example.org/wp-admin/network/$path";
}

function human_time_diff( $from, $to = 0 ) {
	return abs( ( $to ? $to : time() ) - $from ) . ' seconds';
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === $number ? $single : $plural;
}

require_once dirname( __DIR__ ) . '/inc/trait-multisite-cron-base.php';
require_once dirname( __DIR__ ) . '/inc/class-network-settings.php';
require_once dirname( __DIR__ ) . '/inc/class-error-mail.php';
