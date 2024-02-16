<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use WP;
use WP_Query;

abstract class Utils {
	public static function sanitize_field( string &$value ): void {
		$value = preg_replace( '/[^\p{L}]/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( mb_strtolower( $value, 'utf-8' ) );
	}

	public static function get_country_code(): string {
		$keys = [
			'HTTP_CF_IPCOUNTRY',
			'HTTP_X_COUNTRY_CODE',
		];

		foreach ( $keys as $key ) {
			/** @psalm-suppress RiskyTruthyFalsyComparison */
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && 'XX' !== $_SERVER[ $key ] && 2 === strlen( $_SERVER[ $key ] ) ) {
				return $_SERVER[ $key ];
			}
			// phpcs:enable
		}

		return 'XX';
	}

	public static function is_exception(): bool {
		/** @var mixed */
		$zone = $_SERVER['HTTP_X_PSB_ZONE'] ?? '';  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return ! empty( $zone )
			&& is_string( $zone )
			&& false !== strpos( $zone, 'false-positive;' );
	}

	public static function set_error( WP_Query $query, int $code ): void {
		/** @var WP $wp */
		global $wp;
		$query->set( 'error', $code );
		$query->set( 'cf', null );

		if ( $query->is_main_query() ) {
			$wp->set_query_var( 'error', $code );
		}
	}
}
