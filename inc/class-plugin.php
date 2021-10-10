<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use WildWolf\Utils\Singleton;
use WP;
use WP_Query;

/**
 * @psalm-type SearchParams = array{name: string, dob: string, country: string, address: string, phone: string, desc: string, type: string}
 */
final class Plugin {
	use Singleton;

	private function __construct() {
		add_action( 'init', [ $this, 'init' ] );
	}

	public function init(): void {
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
		if ( ! is_user_logged_in() ) {
			add_filter( 'author_rewrite_rules', '__return_empty_array' );
			add_filter( 'date_rewrite_rules', '__return_empty_array' );
			add_action( 'parse_query', [ $this, 'parse_query' ], 5 );
			add_action( 'parse_request', [ $this, 'parse_request' ], 99 );
			add_filter( 'query_vars', [ $this, 'query_vars' ], 0 );
			add_filter( 'request', [ $this, 'request' ] );
			add_action( 'wp', [ $this, 'wp' ] );

			add_filter( 'cardfile_filter_search_params', [ $this, 'cardfile_filter_search_params' ], 10, 2 );
		}
	}

	public function parse_query( WP_Query $query ): void {
		if ( 'criminal' === $query->get( 'post_type' ) ) {
			$whitelist = [
				'post_type'     => 1,
				'criminal'      => 1,
				'name'          => 1,
				'attachment'    => 1,
				'attachment_id' => 1,
				'tag'           => 1,
				'paged'         => 1,
				'preview_id'    => 1,
				'preview'       => 1,
			];

			/** @var mixed $value */
			foreach ( $query->query_vars as $name => &$value ) {
				if ( ! empty( $value ) && ! isset( $whitelist[ $name ] ) && 0 ) {
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

		if ( 'criminal' === $post_type ) {
			if ( 'RU' === Utils::get_country_code() ) {
				wp_die(
					'Лицам, находящимся на территории страны-агрессора и оккупированных ею территориях, страны, финансирующей и поставляющей оружие террористам, доступ к сайту ОГРАНИЧЕН.',
					403
				);
			}

			$wp->remove_query_var( 'feed' );
			$wp->remove_query_var( 'paged' );
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
		];

		return array_diff( $query_vars, $keys );
	}

	/**
	 * @psalm-param mixed[] $query_vars 
	 * @psalm-return mixed[]
	 */
	public function request( array $query_vars ): array {
		if ( ! is_user_logged_in() && isset( $query_vars['post_type'] ) && 'criminal' === $query_vars['post_type'] ) {
			unset( $query_vars['feed'] );
			unset( $query_vars['paged'] );
		}

		return $query_vars;
	}

	public function wp(): void {
		if ( is_singular( 'criminal' ) || is_post_type_archive( 'criminal' ) ) {
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
	}

	/**
	 * @param string[] $params
	 * @return string[]
	 * @psalm-param SearchParams $params 
	 * @psalm-return SearchParams
	 */
	public function cardfile_filter_search_params( array $params, WP_Query $query ): array {
		if ( ! is_user_logged_in() ) {
			$params['dob'] = '';

			if ( preg_match( '!путин\s*-*\s*хуйло!ui', $params['name'] ) ) {
				$params['name'] = '';
			} elseif ( ! preg_match( '!\p{L} \p{L}!u', $params['name'] ) ) {
				self::error( 400, $query );
			}

			if ( empty( $params['name'] ) && empty( $params['country'] ) && empty( $params['address'] ) && empty( $params['phone'] ) && empty( $params['desc'] ) ) {
				self::error( 400, $query );
			}

			array_walk( $params, function ( string &$value ): void {
				$value = Utils::sanitize_field( $value );
			} );

			/** @psalm-var SearchParams $params */
		}

		return $params;
	}

	private static function error( int $code, WP_Query $query ): void {
		if ( ! headers_sent() ) {
			$url = get_post_type_archive_link( 'criminal' );
			assert( is_string( $url ) );

			$url = add_query_arg( [ 'error' => $code ], $url );
			wp_safe_redirect( $url );
			exit;
		}

		$query->set( 'error', $code );
	}
}
