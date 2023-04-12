<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use WildWolf\Utils\Singleton;
use WP;
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
		add_filter( 'query_vars', [ $this, 'query_vars_cferror' ], 1 );
		add_action( 'request', [ $this, 'request_cferror' ], 4 );
		add_filter( 'wp_headers', [ $this, 'wp_headers' ], 10 );

		if ( ! is_user_logged_in() ) {
			add_filter( 'author_rewrite_rules', '__return_empty_array' );
			add_filter( 'date_rewrite_rules', '__return_empty_array' );
			add_action( 'parse_query', [ $this, 'parse_query' ], 5 );
			add_action( 'parse_request', [ $this, 'parse_request' ], 99 );
			add_filter( 'query_vars', [ $this, 'query_vars' ], 1 );
			add_filter( 'request', [ $this, 'request' ] );

			add_filter( 'cardfile_filter_search_params', [ $this, 'cardfile_filter_search_params' ], 10, 2 );

			$this->disable_feeds();

			if ( ! Utils::is_occupied_territory() ) {
				add_filter( 'posts_clauses', [ $this, 'posts_clauses' ], 10, 2 );
			}

			RateLimiter::instance();
		}
	}

	/**
	 * @param string[] $query_vars
	 * @return string[]
	 */
	public function query_vars_cferror( array $query_vars ): array {
		$query_vars[] = 'cferror';
		return $query_vars;
	}

	/**
	 * @param array<string, mixed> $qv
	 * @return array<string, mixed>
	 */
	public function request_cferror( array $qv ): array {
		if ( ! empty( $qv['cferror'] ) ) {
			$qv['error'] = (int) $qv['cferror'];
		}

		return $qv;
	}

	/**
	 * @param mixed[] $headers
	 * @return mixed[]
	 * @global WP_Query $wp_query
	 */
	public function wp_headers( array $headers ): array {
		/** @var WP_Query $wp_query */
		global $wp_query;

		$error = (int) $wp_query->get( 'cferror' );
		if ( $error >= 400 ) {
			$headers = array_merge( $headers, wp_get_nocache_headers() );
		}

		return $headers;
	}

	private function disable_feeds(): void {
		add_filter( 'feed_links_show_posts_feed', '__return_false' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
	}

	public function parse_query( WP_Query $query ): void {
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
				'nopaging'       => 1,
				'posts_per_page' => 1,
				'offset'         => 1,
				'cferror'        => 1,
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

	public function parse_request( WP $wp ): void {
		/** @var scalar */
		$post_type = $wp->query_vars['post_type'] ?? null;

		if ( 'criminal' === $post_type && 'RU' === Utils::get_country_code() && false === Utils::is_exception() ) {
			wp_die(
				'Лицам, находящимся на территории фашистской россии и её союзников, а также тем, кто находится на временно оккупированных ей территориях, доступ к сайту ограничен.',
				403
			);
		}
	}

	public function query_vars( array $query_vars ): array {
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

		return array_diff( $query_vars, $keys );
	}

	/**
	 * @psalm-param mixed[] $query_vars
	 * @psalm-return mixed[]
	 */
	public function request( array $query_vars ): array {
		if ( isset( $query_vars['post_type'] ) && 'criminal' === $query_vars['post_type'] ) {
			unset( $query_vars['paged'] );
		}

		return $query_vars;
	}

	/**
	 * @param string[] $params
	 * @return string[]
	 * @psalm-param SearchParams $params
	 * @psalm-return SearchParams
	 */
	public function cardfile_filter_search_params( array $params, WP_Query $query ): array {
		if ( ! is_user_logged_in() ) {
			$params['dob']  = '';
			$params['type'] = 'n';

			if ( $query->is_search() ) {
				if ( preg_match( '!путин\s*-*\s*хуйло!ui', $params['name'] ) ) {
					$params['name'] = '';
				} elseif ( ! preg_match( '!\p{L} \p{L}!u', $params['name'] ) ) {
					Utils::error( 400 );
				}
			}

			array_walk( $params, function ( string &$value ): void {
				$value = Utils::sanitize_field( $value );
			} );

			if ( $query->is_search() && empty( $params['name'] ) && empty( $params['country'] ) && empty( $params['address'] ) && empty( $params['phone'] ) && empty( $params['desc'] ) ) {
				Utils::error( 400 );
			}

			/** @psalm-var SearchParams $params */

			if ( Utils::is_occupied_territory() ) {
				$params['country'] = 'Россия';
			}
		}

		return $params;
	}

	/**
	 * @psalm-param Clauses $clauses
	 * @psalm-return Clauses
	 * @global wpdb $wpdb
	 */
	public function posts_clauses( array $clauses, WP_Query $query ): array {
		/** @var wpdb $wpdb */
		global $wpdb;

		if ( ! $query->is_search() && ! is_singular() && 'criminal' === $query->get( 'post_type' ) ) {
			if ( false === strpos( $clauses['join'], 'INNER JOIN criminals' ) ) {
				$clauses['join'] .= " INNER JOIN criminals ON criminals.id = {$wpdb->posts}.ID ";
			}

			$old_orderby        = $clauses['orderby'] ? ", {$clauses['orderby']}" : '';
			$clauses['orderby'] = "CASE WHEN criminals.country = 'Россия' THEN 0 ELSE 1 END ASC, {$wpdb->posts}.post_date DESC, criminals.id DESC{$old_orderby}";
		}

		return $clauses;
	}
}
