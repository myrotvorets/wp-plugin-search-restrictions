<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use WildWolf\Utils\Singleton;
use WP_Post;
use WP_Query;
use wpdb;

/**
 * @psalm-type SearchParams = array{name: string, dob: string, country: string, address: string, phone: string, desc: string, type: string}
 * @psalm-type Clauses = array{where: string, groupby: string, join: string, orderby: string, distinct: string, fields: string, limits: string}
 */
final class Plugin {
	use Singleton;

	private function __construct() {
		add_action( 'init', [ $this, 'init' ] );
	}

	public function init(): void {
		add_filter( 'wp_headers', [ $this, 'wp_headers' ], 10 );

		if ( ! is_user_logged_in() ) {
			add_filter( 'author_rewrite_rules', '__return_empty_array' );
			add_filter( 'date_rewrite_rules', '__return_empty_array' );

			// Restrict available query variables for the `criminal` post type
			add_action( 'parse_query', [ $this, 'filter_query_vars' ], 5 );
			// Apply search restrictions for the `criminal` post type
			add_action( 'parse_query', [ $this, 'apply_search_restrictions' ], 11 );

			// Discard restricted query variables
			add_filter( 'request', [ $this, 'restrict_query_vars_globally' ], 1 );
			add_filter( 'request', [ $this, 'restrict_query_vars_for_criminals_search' ], 2 );

			// Short-circuit the query if the `error` query variable is set
			add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );

			$this->disable_feeds();

			RateLimiter::instance();
		}
	}

	/**
	 * @param mixed[] $headers
	 * @return mixed[]
	 * @global WP_Query $wp_query
	 */
	public function wp_headers( array $headers ): array {
		/** @var WP_Query $wp_query */
		global $wp_query;

		$error = (int) $wp_query->get( 'error' );
		switch ( $error ) {
			case 400:
				$headers['Cache-Control'] = 'public, max-age=3600';
				break;

			case 404:
				$headers['Cache-Control'] = 'public, max-age=600';
				break;

			default:
				if ( $error > 400 ) {
					$headers = array_merge( $headers, wp_get_nocache_headers() );
				}

				break;
		}

		return $headers;
	}

	private function disable_feeds(): void {
		add_filter( 'feed_links_show_posts_feed', '__return_false' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
	}

	public function filter_query_vars( WP_Query $query ): void {
		if ( 'criminal' === $query->get( 'post_type' ) ) {
			$query->set( 'no_found_rows', 1 );

			$whitelist = [
				'post_type'      => 1,
				'criminal'       => 1,
				'name'           => 1,
				'attachment'     => 1,
				'attachment_id'  => 1,
				'tag'            => 1,
				'paged'          => 1,
				'preview_id'     => 1,
				'preview'        => 1,
				'p'              => 1,
				'cf'             => 1,
				'nopaging'       => 1,  // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.nopaging_nopaging -- false positive
				'posts_per_page' => 1,
				'offset'         => 1,
				'error'          => 1,
			];

			/** @var mixed $value */
			foreach ( $query->query_vars as $name => &$value ) {
				if ( ! empty( $value ) && ! isset( $whitelist[ $name ] ) ) {
					switch ( gettype( $value ) ) {
						case 'array':
							$value = [];
							break;

						case 'boolean':
							$value = false;
							break;

						case 'integer':
						case 'double':
							$value = 0;
							break;

						default:
							$value = '';
							break;
					}

					unset( $query->query[ $name ] );
				}
			}

			unset( $value );

			$query->is_feed         = false;
			$query->is_date         = false;
			$query->is_year         = false;
			$query->is_month        = false;
			$query->is_day          = false;
			$query->is_time         = false;
			$query->is_author       = false;
			$query->is_category     = false;
			$query->is_tax          = $query->is_tag;
			$query->is_comment_feed = false;
			$query->is_trackback    = false;

			if ( $query->is_singular ) {
				$query->set( 'tag', '' );
				unset( $query->query['tag'] );
			}
		}
	}

	public function apply_search_restrictions( WP_Query $query ): void {
		if ( 'criminal' !== $query->get( 'post_type' ) ) {
			return;
		}

		$cf = $query->get( 'cf' );
		if ( ! is_array( $cf ) || empty( $cf ) ) {
			return;
		}

		$cf += [
			'name'    => '',
			'country' => '',
			'address' => '',
			'phone'   => '',
			'desc'    => '',
		];

		$cf['dob']  = '';
		$cf['type'] = 'f';

		/** @psalm-var SearchParams $cf */

		$cf['name']    = Utils::sanitize_name( $cf['name'] );
		$cf['country'] = Utils::sanitize_country( $cf['country'] );
		$cf['address'] = Utils::sanitize_address( $cf['address'] );
		$cf['phone']   = Utils::sanitize_phone( $cf['phone'] );
		$cf['desc']    = Utils::sanitize_description( $cf['desc'] );

		if ( $query->is_search() ) {
			if ( preg_match( '/^[\\p{Ps}\\p{Pi}`\'"]*\s*путин\s*-?\s*хуйло\s*[\\p{Pe}\\p{Pf}ʼ\'"]*$/iu', $cf['name'] ) ) {
				$cf['name'] = '';
			} elseif ( ! preg_match( '!\\p{L} \\p{L}!u', $cf['name'] ) ) {
				Utils::set_error( $query, 400 );
				return;
			}

			/** @psalm-suppress RiskyTruthyFalsyComparison */
			if ( empty( $cf['name'] ) && empty( $cf['country'] ) && empty( $cf['address'] ) && empty( $cf['phone'] ) && empty( $cf['desc'] ) ) {
				Utils::set_error( $query, 400 );
				return;
			}

			if ( mb_strlen( $cf['name'] ) > 255 || mb_strlen( $cf['country'] ) > 64 || mb_strlen( $cf['address'] ) > 255 || mb_strlen( $cf['phone'] ) > 64 || mb_strlen( $cf['desc'] ) > 8192 ) {
				Utils::set_error( $query, 400 );
				return;
			}
		}

		$query->set( 'cf', $cf );
	}

	public function restrict_query_vars_globally( array $query_vars ): array {
		/** @psalm-var list<string> */
		static $keys = [
			'm',
			'posts',
			'w',
			'withcomments',
			'withoutcomments',
			'search',
			'calendar',
			'more',
			'author',
			'order',
			'orderby',
			'year',
			'monthnum',
			'day',
			'hour',
			'minute',
			'second',
			'author_name',
			'subpost',
			'subpost_id',
			'taxonomy',
			'term',
			'cpage',
			'feed',
		];

		foreach ( $keys as $key ) {
			unset( $query_vars[ $key ] );
		}

		return $query_vars;
	}

	public function restrict_query_vars_for_criminals_search( array $query_vars ): array {
		if ( isset( $query_vars['post_type'] ) && 'criminal' === $query_vars['post_type'] ) {
			unset( $query_vars['paged'] );

			/** @var mixed */
			$cf = $query_vars['cf'] ?? null;
			if ( is_array( $cf ) && ! empty( $cf ) ) {
				unset(
					$query_vars['name'],
					$query_vars['attachment'],
					$query_vars['attachment_id'],
					$query_vars['preview'],
					$query_vars['preview_id'],
					$query_vars['p'],
				);
			}
		}

		return $query_vars;
	}

	/**
	 * Filters the posts array before the query takes place.
	 *
	 * Return a non-null value to bypass WordPress' default post queries.
	 *
	 * Filtering functions that require pagination information are encouraged to set
	 * the `found_posts` and `max_num_pages` properties of the WP_Query object,
	 * passed to the filter by reference. If WP_Query does not perform a database
	 * query, it will not have enough information to generate these values itself.
	 *
	 * @param WP_Post[]|int[]|null $posts Return an array of post data to short-circuit WP's query,
	 *                                    or null to allow WP to run its normal queries.
	 * @param WP_Query             $query The WP_Query instance (passed by reference).
	 * @return WP_Post[]|int[]|null
	 */
	public function posts_pre_query( $posts, WP_Query $query ) {
		if ( 'criminal' === $query->get( 'post_type' ) && (int) $query->get( 'error', 0 ) > 0 ) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			$posts                = [];
		}

		return $posts;
	}
}
