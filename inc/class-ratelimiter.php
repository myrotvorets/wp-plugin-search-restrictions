<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use Redis;
use WildWolf\Utils\Singleton;
use WP;

final class RateLimiter {
	use Singleton;

	public const RATELIMIT_PERIOD = 86400;
	public const RATELIMIT_LIMIT  = 100;

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
		/** @var scalar */
		$post_type = $wp->query_vars['post_type'] ?? null;

		if ( 'criminal' === $post_type && $this->redis ) {
			/** @var mixed */
			$ip = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables
			if ( ! $ip || ! is_string( $ip ) ) {
				return;
			}

			$ratelimit_period = (int) apply_filters( 'secenh_ratelimit_period', self::RATELIMIT_PERIOD );
			$ratelimit_limit  = (int) apply_filters( 'secenh_ratelimit_limit', self::RATELIMIT_LIMIT );

			$key = 'secenh_view_ratelimit_' . $ip;
			if ( ! $this->redis->exists( $key ) ) {
				$this->redis->set( $key, 1, $ratelimit_period );
			} else {
				$total_calls = $this->redis->incr( $key );
				if ( $total_calls > $ratelimit_limit ) {
					Utils::error( 429 );
				}
			}
		}
	}
}
