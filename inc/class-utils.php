<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use Normalizer;
use WP;
use WP_Query;

abstract class Utils {
	public static function generic_sanitize( string $s ): string {
		$s = mb_scrub( $s, 'utf-8' );
		return Normalizer::normalize( $s, Normalizer::FORM_C );
	}

	public static function normalize_spaces( string $s ): string {
		$s = preg_replace( '/\\s+/u', ' ', $s );
		return trim( $s );
	}

	public static function sanitize_name( string $s ): string {
		$s = static::generic_sanitize( $s );
		$s = preg_replace( '/[^\\p{L}\\p{N}\'-]/u', ' ', $s );
		return static::normalize_spaces( $s );
	}

	public static function sanitize_dob( string $s ): string {
		$s = static::generic_sanitize( $s );
		$s = preg_replace( '/[^\\d.-]/u', '', $s );
		$s = trim( $s, '-.' );

		if ( ! preg_match( '/^((\\d{4}-\\d{2}-\\d{2})|(\\d{2}\\.\\d{2}\\.\\d{4}))$/', $s ) ) {
			return '';
		}

		if ( '.' === $s[2] ) {
			$s = implode( '-', array_reverse( explode( '.', $s ) ) );
		}

		return $s;
	}

	public static function sanitize_country( string $s ): string {
		$s = static::generic_sanitize( $s );
		$s = preg_replace( '/[^\\p{L}\' -]/u', ' ', $s );
		return static::normalize_spaces( $s );
	}

	public static function sanitize_address( string $s ): string {
		$s = static::generic_sanitize( $s );
		$s = preg_replace( '/[^\\p{L}\\p{N}\\p{P} ]/u', ' ', $s );

		if ( ! preg_match( '![\\p{L}\\p{N}]!u', $s ) ) {
			return '';
		}

		return static::normalize_spaces( $s );
	}

	public static function sanitize_phone( string $s ): string {
		$s = preg_replace( '/[^0-9+;,]/u', '', $s );
		$s = preg_replace( '/[;,]/u', ' ', $s );
		return static::normalize_spaces( $s );
	}

	public static function sanitize_description( string $s ): string {
		$s = static::normalize_spaces( static::generic_sanitize( $s ) );
		if ( ! preg_match( '![\\p{L}\\p{N}]!u', $s ) ) {
			return '';
		}

		return $s;
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
