<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;

use function __;
use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_rand;
use function array_values;
use function current_time;
use function explode;
use function get_date_from_gmt;
use function get_user_by;
use function gmdate;
use function hash;
use function has_blocks;
use function is_array;
use function is_wp_error;
use function sanitize_title;
use function sprintf;
use function strtotime;
use function time;
use function trim;
use function update_post_meta;
use function wp_insert_post;
use function wp_json_encode;
use function wp_strip_all_tags;
use function wp_trim_words;

use const JSON_PRETTY_PRINT;

/**
 * Koordynuje generowanie postów, obrazków oraz komentarzy.
 */
class PostGenerator {
	public function __construct(
		private AiClient $ai_client,
		private FeaturedImageBuilder $image_builder,
		private LinkBuilder $link_builder,
		private CommentGenerator $comment_generator,
		private ContextResolver $context_resolver,
		private Logger $logger,
		private MarkdownConverter $markdown_converter,
		private BlockContentBuilder $block_content_builder
	) {}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public function generate( array $overrides = array() ): ?int {
		$context     = is_array( $overrides['context'] ?? null )
			? (array) $overrides['context']
			: $this->context_resolver->get_prompt_context();
		$user_prompt = $this->resolve_user_prompt( $overrides, $context );
		$system_prompt = $this->resolve_system_prompt( $overrides );

		$article = $this->ai_client->generate_article(
			array(
				'user_prompt'   => $user_prompt,
				'system_prompt' => $system_prompt,
				'model'         => $overrides['model'] ?? null,
			)
		);

		if ( ! is_array( $article ) || empty( $article['content'] ) ) {
			$this->logger->warning( 'OpenAI zwróciło pusty wynik, wpis nie został utworzony.' );

			return null;
		}

		if ( Options::get( 'preview_mode' ) && empty( $overrides['ignore_preview_mode'] ) ) {
			$this->logger->info(
				'Tryb podglądu AI – wygenerowano tekst bez zapisu.',
				array(
					'title' => $article['title'] ?? '',
				)
			);

			return null;
		}

		$article['content'] = $this->maybe_apply_internal_links( $article );

		$post_id = $this->create_post( $article, $overrides );

		if ( ! $post_id ) {
			return null;
		}

		if ( Options::get( 'enable_featured_images' ) ) {
			$this->image_builder->build( $post_id, $article );
		}

		$this->comment_generator->schedule_for_post( $post_id, $article );

		StatusStore::merge(
			array(
				'last_post_id'   => $post_id,
				'last_post_time' => time(),
			)
		);

		return $post_id;
	}

	private function resolve_user_prompt( array $overrides, array $context ): string {
		$custom = trim( (string) ( $overrides['user_prompt'] ?? '' ) );

		if ( '' !== $custom ) {
			return $custom;
		}

		return $this->build_prompt( $context );
	}

	private function resolve_system_prompt( array $overrides ): string {
		$custom = trim( (string) ( $overrides['system_prompt'] ?? '' ) );

		return '' !== $custom ? $custom : (string) Options::get( 'system_prompt' );
	}

	private function build_prompt( array $context ): string {
		$min  = (int) Options::get( 'word_count_min', 600 );
		$max  = (int) Options::get( 'word_count_max', 1200 );
		$goal = Options::get( 'topic_strategy', '' );

		$topic_line = ! empty( $goal ) 
			? "Strategia tematów: " . $goal . "\n"
			: "";

		return sprintf(
			"%sWymagana liczba słów: %d-%d.\nKontekst kategorii: %s\nOstatnie wpisy: %s\n\nZwróć poprawny JSON {\"title\",\"slug\",\"excerpt\",\"content\",\"summary\"}.\nPole \"content\" musi być w formacie Markdown - użyj nagłówków (##, ###), list (-, *), pogrubienia (**tekst**), kursywy (*tekst*), linków [tekst](url), cytatów (>). Tekst ma być konkretny na konkretny temat - BEZ żadnych wstawek typu \"Wprowadzenie AI\" czy \"Zakończenie AI\". Zacznij od tematu i rozwiń go naturalnie.",
			$topic_line,
			$min,
			$max,
			wp_json_encode( $context['categories'], JSON_PRETTY_PRINT ),
			wp_json_encode( $context['recent_posts'], JSON_PRETTY_PRINT )
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function create_post( array $article, array $overrides = array() ): ?int {
		$title   = wp_strip_all_tags( (string) ( $article['title'] ?? '' ) );
		$content = (string) ( $article['content'] ?? '' );

		// Konwertuj Markdown do odpowiedniego formatu
		$formatted_content = $this->format_content_for_wordpress( $content );

		$post_status = (string) ( $overrides['post_status'] ?? Options::get( 'default_post_status', 'draft' ) );

		$postarr = array(
			'post_title'   => '' !== trim( (string) ( $overrides['post_title'] ?? '' ) ) ? (string) $overrides['post_title'] : $title,
			'post_content' => $formatted_content,
			'post_excerpt' => (string) ( $article['excerpt'] ?? wp_trim_words( wp_strip_all_tags( $this->markdown_converter->to_html( $content ) ), 40 ) ),
			'post_status'  => $post_status,
			'post_name'    => sanitize_title( (string) ( $article['slug'] ?? $title ) ),
			'post_category' => $this->resolve_category( $overrides ),
			'post_type'    => (string) ( $overrides['post_type'] ?? 'post' ),
			'post_author'  => $this->resolve_default_author_id( $overrides ),
		);

		if ( ! empty( $overrides['publish_at'] ) ) {
			$postarr = array_merge(
				$postarr,
				$this->apply_publish_at( (string) $overrides['publish_at'], $post_status )
			);
			$postarr['post_status'] = $post_status;
		}

		$result = wp_insert_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Nie udało się zapisać posta wygenerowanego przez AI.',
				array( 'error' => $result->get_error_message() )
			);

			return null;
		}

		update_post_meta(
			$result,
			'_kasumi_ai_content_meta',
			array(
				'generated_at' => current_time( 'mysql' ),
				'prompt_hash'  => hash( 'sha256', $title . ( $article['summary'] ?? '' ) ),
			)
		);

		$this->logger->info(
			'Utworzono wpis AI.',
			array(
				'post_id' => $result,
				'title'   => $title,
			)
		);

		return (int) $result;
	}

	private function resolve_default_author_id( array $overrides ): int {
		if ( isset( $overrides['author_id'] ) ) {
			$requested = (int) $overrides['author_id'];

			return $this->is_valid_author_id( $requested ) ? $requested : 0;
		}

		$mode = (string) Options::get( 'default_author_mode', 'none' );

		if ( 'fixed' === $mode ) {
			$author_id = (int) Options::get( 'default_author_id', 0 );

			return $this->is_valid_author_id( $author_id ) ? $author_id : 0;
		}

		if ( 'random_list' === $mode ) {
			$pool = Options::get( 'default_author_pool', array() );

			if ( is_array( $pool ) ) {
				$valid_pool = array_values(
					array_filter(
						array_map(
							function ( $id ) {
								$author_id = (int) $id;

								return $this->is_valid_author_id( $author_id ) ? $author_id : null;
							},
							$pool
						),
						static fn( $author_id ) => ! empty( $author_id )
					)
				);

				if ( ! empty( $valid_pool ) ) {
					$random_key = array_rand( $valid_pool );

					return (int) $valid_pool[ $random_key ];
				}
			}
		}

		return 0;
	}

	private function is_valid_author_id( int $author_id ): bool {
		return $author_id > 0 && false !== get_user_by( 'id', $author_id );
	}

	private function get_link_keywords(): array {
		$list = (string) Options::get( 'link_keywords', '' );

		if ( empty( $list ) ) {
			return array();
		}

		return array_filter(
			array_map( 'trim', explode( ',', $list ) ),
			static fn( $keyword ) => '' !== $keyword
		);
	}

	private function resolve_category( array $overrides = array() ): array {
		if ( ! empty( $overrides['meta']['categoryIds'] ) && is_array( $overrides['meta']['categoryIds'] ) ) {
			return array_map( 'intval', (array) $overrides['meta']['categoryIds'] );
		}

		$category = (string) Options::get( 'target_category', '' );

		if ( empty( $category ) ) {
			return array();
		}

		return array( (int) $category );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function maybe_apply_internal_links( array $article ): string {
		if ( ! Options::get( 'enable_internal_linking' ) ) {
			return (string) ( $article['content'] ?? '' );
		}

		$content = (string) ( $article['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return $content;
		}

		$candidates  = $this->context_resolver->get_link_candidates();
		$anchor_hints = $this->context_resolver->get_primary_link_hints();
		$keyword_hints = $this->get_link_keywords();

		$hints = array_filter(
			array_unique(
				array_merge( $anchor_hints, $keyword_hints )
			),
			static fn( $hint ) => '' !== trim( $hint )
		);

		$suggestions = $this->ai_client->suggest_internal_links(
			array(
				'title'   => $article['title'] ?? '',
				'excerpt' => $article['excerpt'] ?? '',
				'content' => wp_strip_all_tags( $content ),
			),
			$candidates,
			$hints
		);

		if ( empty( $suggestions ) ) {
			return $content;
		}

		return $this->link_builder->inject_links( $content, $suggestions );
	}

	private function format_content_for_wordpress( string $markdown_content ): string {
		if ( empty( trim( $markdown_content ) ) ) {
			return '';
		}

		$blocks = $this->block_content_builder->build_blocks( $markdown_content );

		if ( '' !== $blocks ) {
			return $blocks;
		}

		$this->logger->warning(
			'BlockContentBuilder zwrócił pusty wynik – zapisano czysty HTML jako fallback.'
		);

		return $this->markdown_converter->to_html( $markdown_content );
	}

	private function apply_publish_at( string $publish_at, string &$status ): array {
		$timestamp = strtotime( $publish_at );

		if ( ! $timestamp ) {
			return array();
		}

		$gmt   = gmdate( 'Y-m-d H:i:s', $timestamp );
		$local = get_date_from_gmt( $gmt );

		if ( 'publish' === $status && $timestamp > time() ) {
			$status = 'future';
		}

		return array(
			'post_date'     => $local,
			'post_date_gmt' => $gmt,
		);
	}

}
