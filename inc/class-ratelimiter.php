<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use Redis;
use WildWolf\Utils\Singleton;
use WP;

final class RateLimiter {
	use Singleton;

	public const RATELIMIT_PERIOD_VIEW   = 86400;
	public const RATELIMIT_LIMIT_VIEW    = 100;
	public const RATELIMIT_PERIOD_SEARCH = 3600;
	public const RATELIMIT_LIMIT_SEARCH  = 10;

	private ?Redis $redis = null;

	private function __construct() {
		if ( class_exists( Redis::class ) && defined( 'REDIS_HOST' ) && defined( 'REDIS_PORT' ) && defined( 'REDIS_PASSWORD' ) && ! empty( REDIS_HOST ) ) {
			$this->redis = new Redis();
			$success     = $this->redis->connect( (string) REDIS_HOST, (int) REDIS_PORT );
			if ( $success && ! empty( REDIS_PASSWORD ) ) {
				$success = $this->redis->auth( (string) REDIS_PASSWORD );
			}

			if ( $success ) {
				$this->init();
			}
		}
	}

	public function init(): void {
		if ( ! is_user_logged_in() ) {
			add_action( 'parse_request', [ $this, 'parse_request' ] );
		}
	}

	public function parse_request( WP $wp ): void {
		if ( ! $this->redis ) {
			return;
		}

		/** @var scalar */
		$post_type = $wp->query_vars['post_type'] ?? null;
		/** @var mixed */
		$criminal = $wp->query_vars['criminal'] ?? null;
		/** @var mixed */
		$cf = $wp->query_vars['cf'] ?? null;

		$is_view   = 'criminal' === $post_type && is_string( $criminal ) && ! empty( $criminal );
		$is_search = 'criminal' === $post_type && is_array( $cf ) && ! empty( $cf );

		if ( $is_view || $is_search ) {
			/** @var mixed */
			$ip = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables
			if ( ! $ip || ! is_string( $ip ) ) {
				return;
			}

			$what   = $is_view ? 'view' : 'search';
			$period = $is_view ? self::RATELIMIT_PERIOD_VIEW : self::RATELIMIT_PERIOD_SEARCH;
			$limit  = $is_view ? self::RATELIMIT_LIMIT_VIEW : self::RATELIMIT_LIMIT_SEARCH;

			$ratelimit_period = (int) apply_filters( "secenh_ratelimit_period_{$what}", $period );
			$ratelimit_limit  = (int) apply_filters( "secenh_ratelimit_limit_{$what}", $limit );

			if ( $ratelimit_limit < 0 || $ratelimit_period < 0 ) {
				return;
			}

			$prefix = md5( (string) get_option( 'siteurl', '' ) );
			$key    = sprintf( '%s:secenh_ratelimit:%s:%s', $prefix, $what, $ip );
			if ( ! $this->redis->exists( $key ) ) {
				$this->redis->set( $key, 1, $ratelimit_period );
			} else {
				$total_calls = $this->redis->incr( $key );
				if ( $total_calls > $ratelimit_limit ) {
					do_action( 'secenh_ratelimited', $ip, $what, $total_calls, $ratelimit_limit, $ratelimit_period );
					Utils::error( 429 );
				}
			}
		}
	}
}
