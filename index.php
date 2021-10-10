<?php
/*
 * Plugin Name: Search Restrictions
 * Plugin URI: https://myrotvorets.center/
 * Description: Search Restrictions
 * Version: 1.0.0
 * Author: Myrotvorets
 * Author URI: https://myrotvorets.center/
 * License: MIT
 */

use Myrotvorets\WordPress\SearchRestrictions\Plugin;

if ( defined( 'ABSPATH' ) ) {
	if ( defined( 'VENDOR_PATH' ) ) {
		/** @psalm-suppress UnresolvableInclude, MixedOperand */
		require constant( 'VENDOR_PATH' ) . '/vendor/autoload.php'; // NOSONAR
	} elseif ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require __DIR__ . '/vendor/autoload.php';
	} elseif ( file_exists( ABSPATH . 'vendor/autoload.php' ) ) {
		/** @psalm-suppress UnresolvableInclude */
		require ABSPATH . 'vendor/autoload.php';
	}

	Plugin::instance();
}
