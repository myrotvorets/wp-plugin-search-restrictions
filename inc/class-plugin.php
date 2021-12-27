<?php

namespace Myrotvorets\WordPress\SearchRestrictions;

use WildWolf\Utils\Singleton;
use WP;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Server;
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
		add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
		add_filter( 'rest_pre_dispatch', [ $this, 'rest_pre_dispatch' ], 10, 3 );
		if ( ! is_user_logged_in() ) {
			add_filter( 'author_rewrite_rules', '__return_empty_array' );
			add_filter( 'date_rewrite_rules', '__return_empty_array' );
			add_action( 'parse_query', [ $this, 'parse_query' ], 5 );
			add_action( 'parse_request', [ $this, 'parse_request' ], 99 );
			add_filter( 'query_vars', [ $this, 'query_vars' ], 0 );
			add_filter( 'request', [ $this, 'request' ] );

			add_filter( 'cardfile_filter_search_params', [ $this, 'cardfile_filter_search_params' ], 10, 2 );

			add_filter( 'status_header', [ $this, 'status_header' ], 10, 4 );

			$this->disable_feeds();

			if ( ! Utils::is_occupied_territory() ) {
				add_filter( 'posts_clauses', [ $this, 'posts_clauses' ], 10, 2 );
			}
		}

		$this->remove_unneeded_hooks();
	}

	private function remove_unneeded_hooks(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );

		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	private function disable_feeds(): void {
		add_filter( 'feed_links_show_posts_feed', '__return_false' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
	}

	public function parse_query( WP_Query $query ): void {
		if ( 'criminal' === $query->get( 'post_type' ) ) {
			$query->set( 'no_found_rows', 1 );

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
				'p'             => 1,
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

		if ( 'criminal' === $post_type && 'RU' === Utils::get_country_code() ) {
			wp_die(
				'Лицам, находящимся на территории страны-агрессора и оккупированных ею территориях, страны, финансирующей и поставляющей оружие террористам, доступ к сайту ОГРАНИЧЕН.',
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

		$query_vars[] = 'cferror';
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
					self::error( 400, $query );
				}
			}

			array_walk( $params, function ( string &$value ): void {
				$value = Utils::sanitize_field( $value );
			} );

			if ( $query->is_search() && empty( $params['name'] ) && empty( $params['country'] ) && empty( $params['address'] ) && empty( $params['phone'] ) && empty( $params['desc'] ) ) {
				self::error( 400, $query );
			}

			/** @psalm-var SearchParams $params */

			if ( Utils::is_occupied_territory() ) {
				$params['country'] = 'Россия';
			}
		}

		return $params;
	}

	private static function error( int $code, WP_Query $query ): void {
		if ( ! headers_sent() ) {
			$url = get_post_type_archive_link( 'criminal' );
			assert( is_string( $url ) );

			$url = add_query_arg( [ 'cferror' => $code ], $url );
			wp_safe_redirect( $url );
			exit;
		}

		$query->set( 'cferror', $code );
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

	/**
	 * @param mixed $result
	 * @return WP_Error|mixed
	 */
	public function rest_pre_dispatch( $result, WP_REST_Server $_srv, WP_REST_Request $request ) {
		$method = $request->get_method();
		$path   = $request->get_route();
	
		if ( ( 'GET' === $method || 'HEAD' === $method ) && preg_match( '!^/wp/v2/users(?:$|/)!', $path ) && ! current_user_can( 'list_users' ) ) {
			$result = new WP_Error(
				'rest_user_cannot_view',
				'Sorry, you are not allowed to use this API.',
				[ 'status' => rest_authorization_required_code() ]
			);
		}
	
		return $result;
	}

	/**
	 * @param string $status_header 
	 * @param int $code 
	 * @param string $description 
	 * @param string $protocol 
	 * @return string 
	 * @global WP_Query|null $wp_query
	 */
	public function status_header( $status_header, $code, $description, $protocol ) {
		/** @var WP_Query|null $wp_query */
		global $wp_query;

		if ( 200 === $code && $wp_query ) {
			$error = (int) $wp_query->get( 'cferror' );
			if ( $error >= 400 && $error < 500 ) {
				$status_desc = get_status_header_desc( $error );
				if ( empty( $status_desc ) ) {
					$status_desc = 'Error';
				}

				$status_header = "{$protocol} {$error} {$status_desc}";
			}
		}

		return $status_header;
	}
}
