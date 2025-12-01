<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;

use function array_rand;
use function array_unique;
use function array_values;
use function current_time;
use function get_comments;
use function get_post;
use function get_post_meta;
use function get_posts;
use function home_url;
use function is_array;
use function mb_strtolower;
use function preg_replace;
use function sanitize_email;
use function sanitize_title;
use function similar_text;
use function time;
use function trim;
use function update_post_meta;
use function wp_generate_uuid4;
use function wp_insert_comment;
use function wp_parse_url;
use function wp_rand;

use const HOUR_IN_SECONDS;
use const PHP_URL_HOST;

/**
 * Generuje komentarze osadzane pod postami AI.
 */
class CommentGenerator {
	private const META_PLAN = '_kasumi_ai_comment_plan';
	private const META_CONTEXT = '_kasumi_ai_comment_context';
	private array $recent_comment_cache = array();

	public function __construct(
		private AiClient $ai_client,
		private Logger $logger
	) {}

	/**
	 * @param array<string, mixed> $article
	 */
	public function schedule_for_post( int $post_id, array $article ): void {
		if ( ! Options::get( 'comments_enabled' ) ) {
			return;
		}

		$min     = max( 1, (int) Options::get( 'comment_min', 3 ) );
		$max     = max( $min, (int) Options::get( 'comment_max', 6 ) );
		$planned = wp_rand( $min, $max );

		$frequency = Options::get( 'comment_frequency', 'normal' );

		$window_hours = match ( $frequency ) {
			'dense'  => 36,
			'normal' => 96,
			default  => 168,
		};

		$interval = max( 1800, (int) ( $window_hours * HOUR_IN_SECONDS / max( 1, $planned ) ) );
		$start    = time() + 1800;
		$plan     = array();

		for ( $i = 0; $i < $planned; $i++ ) {
			$plan[] = array(
				'timestamp' => $start + ( $i * $interval ),
				'status'    => 'pending',
			);
		}

		update_post_meta( $post_id, self::META_PLAN, $plan );
		update_post_meta(
			$post_id,
			self::META_CONTEXT,
			array(
				'title'   => $article['title'] ?? '',
				'excerpt' => $article['excerpt'] ?? '',
				'summary' => $article['summary'] ?? '',
			)
		);

		StatusStore::merge(
			array(
				'queued_comment_jobs' => $this->count_pending_jobs(),
			)
		);
	}

	public function process_queue(): void {
		if ( ! Options::get( 'comments_enabled' ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_PLAN,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $posts ) ) {
			return;
		}

		$processed = 0;
		$now       = time();

		foreach ( $posts as $post_id ) {
			$plan = get_post_meta( $post_id, self::META_PLAN, true );

			if ( ! is_array( $plan ) ) {
				continue;
			}

			$context = $this->get_context( (int) $post_id );

			foreach ( $plan as $index => $entry ) {
				if ( 'pending' !== ( $entry['status'] ?? '' ) ) {
					continue;
				}

				if ( (int) $entry['timestamp'] > $now ) {
					continue;
				}

				$comment_text = $this->generate_unique_comment_for_post( (int) $post_id, $context );

				if ( empty( $comment_text ) ) {
					continue;
				}

				$comment_id = $this->insert_comment( (int) $post_id, $comment_text, $context );

				if ( $comment_id ) {
					$plan[ $index ]['status']     = 'done';
					$plan[ $index ]['comment_id'] = $comment_id;
					$processed++;
				}

				if ( $processed >= 3 ) {
					break 2;
				}
			}

			update_post_meta( $post_id, self::META_PLAN, $plan );
		}

		if ( $processed > 0 ) {
			StatusStore::merge(
				array(
					'last_comment_time'   => time(),
					'queued_comment_jobs' => $this->count_pending_jobs(),
				)
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_context( int $post_id ): array {
		$context = get_post_meta( $post_id, self::META_CONTEXT, true );

		if ( is_array( $context ) ) {
			return $context;
		}

		$post = get_post( $post_id );

		return array(
			'title'   => $post?->post_title,
			'excerpt' => $post?->post_excerpt,
		);
	}

	private function insert_comment( int $post_id, string $content, array $context ): ?int {
		$author_name   = $this->resolve_comment_author( $post_id, $context );
		$host          = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
		$email         = sanitize_email(
			sanitize_title( $author_name ) . '+' . wp_generate_uuid4() . '@' . $host
		);

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author_name,
			'comment_content'      => $content,
			'comment_author_email' => $email,
			'comment_approved'     => 'approve' === Options::get( 'comment_status', 'approve' ) ? 1 : 0,
			'comment_date'         => current_time( 'mysql' ),
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( $comment_id ) {
			unset( $this->recent_comment_cache[ $post_id ] );
			$this->logger->info(
				'Dodano komentarz AI.',
				array(
					'post_id'    => $post_id,
					'comment_id' => $comment_id,
				)
			);

			return (int) $comment_id;
		}

		return null;
	}

	private function count_pending_jobs(): int {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_PLAN,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$total = 0;

		foreach ( $posts as $post_id ) {
			$plan = get_post_meta( $post_id, self::META_PLAN, true );

			if ( ! is_array( $plan ) ) {
				continue;
			}

			foreach ( $plan as $entry ) {
				if ( 'pending' === ( $entry['status'] ?? '' ) ) {
					$total++;
				}
			}
		}

		return $total;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function generate_unique_comment_for_post( int $post_id, array $context ): ?string {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$comment_text = $this->ai_client->generate_comment( $context );

			if ( empty( $comment_text ) ) {
				continue;
			}

			if ( ! $this->looks_like_duplicate_comment( $post_id, $comment_text ) ) {
				return $comment_text;
			}
		}

		return null;
	}

	private function looks_like_duplicate_comment( int $post_id, string $content ): bool {
		$needle = $this->normalize_comment_text( $content );

		if ( '' === $needle ) {
			return true;
		}

		foreach ( $this->get_recent_comments( $post_id ) as $comment ) {
			$existing = $this->normalize_comment_text( (string) $comment->comment_content );

			if ( '' === $existing ) {
				continue;
			}

			if ( $existing === $needle ) {
				return true;
			}

			similar_text( $existing, $needle, $similarity );

			if ( $similarity >= 88 ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_comment_text( string $content ): string {
		$trimmed = trim( preg_replace( '/\s+/u', ' ', $content ) ?? $content );

		return mb_strtolower( $trimmed );
	}

	/**
	 * @return \WP_Comment[]
	 */
	private function get_recent_comments( int $post_id, int $limit = 12 ): array {
		if ( isset( $this->recent_comment_cache[ $post_id ] ) ) {
			return $this->recent_comment_cache[ $post_id ];
		}

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'number'  => $limit,
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
				'type'    => 'comment',
			)
		);

		$this->recent_comment_cache[ $post_id ] = $comments;

		return $comments;
	}

	/**
	 * @return string[]
	 */
	private function get_recent_comment_authors( int $post_id ): array {
		$authors = array();

		foreach ( $this->get_recent_comments( $post_id, 15 ) as $comment ) {
			$name = trim( (string) $comment->comment_author );

			if ( '' === $name ) {
				continue;
			}

			$authors[] = $name;
		}

		return array_values( array_unique( $authors ) );
	}

	/**
	 * @param array<string, mixed> $context
	 * @param string[]             $recent_authors
	 */
	private function generate_unique_nickname( array $context, array $recent_authors ): ?string {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$nickname = $this->ai_client->generate_nickname( $context );

			if ( empty( $nickname ) ) {
				continue;
			}

			if ( $this->is_nickname_unique_against_list( $recent_authors, $nickname ) ) {
				return $nickname;
			}
		}

		return null;
	}

	/**
	 * @param string[] $recent_authors
	 */
	private function is_nickname_unique_against_list( array $recent_authors, string $nickname ): bool {
		$needle = $this->normalize_nickname( $nickname );

		if ( '' === $needle ) {
			return false;
		}

		foreach ( $recent_authors as $author ) {
			if ( $this->normalize_nickname( $author ) === $needle ) {
				return false;
			}
		}

		return true;
	}

	private function normalize_nickname( string $nickname ): string {
		return mb_strtolower( trim( $nickname ) );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function resolve_comment_author( int $post_id, array $context ): string {
		$recent_authors = $this->get_recent_comment_authors( $post_id );
		$nickname       = $this->generate_unique_nickname( $context, $recent_authors );

		if ( $nickname ) {
			return $nickname;
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$fallback = $this->build_fallback_nickname();

			if ( $this->is_nickname_unique_against_list( $recent_authors, $fallback ) ) {
				return $fallback;
			}
		}

		return $this->build_fallback_nickname();
	}

	private function build_fallback_nickname(): string {
		$prefix = trim( (string) Options::get( 'comment_author_prefix', '' ) );

		if ( '' !== $prefix ) {
			return $prefix . wp_rand( 10, 999 );
		}

		$pool = array(
			'PixelNomad',
			'DataBasia',
			'NeoMarek',
			'LunaWriter',
			'CodeAnka',
			'VRBartek',
			'TechMati',
			'SkyEwa',
		);

		$nickname = $pool[ array_rand( $pool ) ];

		return $nickname . wp_rand( 1, 99 );
	}
}
