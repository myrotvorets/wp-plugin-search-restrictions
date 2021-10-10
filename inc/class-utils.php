<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

abstract class Utils {
	public static function sanitize_field( string $value ): string {
		$v = preg_replace( '/[^\p{L}]/u', ' ', $value );
		$v = preg_replace( '/\s+/u', ' ', $v );
		return trim( mb_strtolower( $v, 'utf-8' ) );
	}

	public static function get_country_code(): string {
		$keys = [
			'HTTP_CF_IP_COUNTRY',
			'HTTP_X_COUNTRY_CODE',
		];

		foreach ( $keys as $key ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && 'XX' !== $_SERVER[ $key ] && 2 === strlen( $_SERVER[ $key ] ) ) {
				return $_SERVER[ $key ];
			}
			// phpcs:enable
		}

		return 'XX';
	}

	public static function is_occupied_territory(): bool {
		/** @var mixed */
		$zone = $_SERVER['HTTP_X_PSB_ZONE'] ?? '';  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return ! empty( $zone ) 
			&& is_string( $zone )
			&& preg_match( '/\boccupied\b/', $zone )
			&& false === strpos( $zone, 'false-positive;' );
	}
}
