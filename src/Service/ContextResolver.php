<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Kasumi\AIGenerator\Options;

use function __;
use function get_bloginfo;
use function get_permalink;
use function get_post_field;
use function get_posts;
use function get_terms;
use function get_the_title;
use function home_url;
use function is_array;
use function trim;
use function url_to_postid;
use function wp_get_post_tags;
use function wp_parse_url;
use function wp_strip_all_tags;
use function wp_trim_words;

use WP_Post;
/**
 * Zapewnia dane kontekstowe dla promtów i linkowania.
 */
class ContextResolver {
	/**
	 * @return array{recent_posts: array<int, array<string, string>>, categories: array<int, string>}
	 */
	public function get_prompt_context(): array {
		$recent_posts = get_posts(
			array(
				'numberposts'      => 5,
				'post_status'      => 'publish',
				'orderby'          => 'date',
					'order'            => 'DESC',
			)
		);

		$categories = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 8,
			)
		);

		return array(
			'recent_posts' => array_map(
				static fn( $post ): array => array(
					'title'   => $post->post_title,
					'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 ),
				),
				$recent_posts
			),
			'categories'   => array_map(
				static fn( $term ): string => $term->name,
				is_array( $categories ) ? $categories : array()
			),
		);
	}

	/**
	 * @return array<int, array{title: string, url: string, summary: string, anchors: array<int, string>, priority: string}>
	 */
	public function get_link_candidates(): array {
		$primary = $this->get_primary_link_candidates();
		$tagged  = $this->get_tagged_post_candidates();

		if ( empty( $primary ) && empty( $tagged ) ) {
			return array();
		}

		$candidates = array_merge( $primary, $tagged );

		if ( ! empty( $primary ) ) {
			$candidates = array_merge( $candidates, $this->get_recent_posts_candidates() );

			$home_url = home_url( '/' );

			if ( ! $this->candidate_has_url( $candidates, $home_url ) ) {
				$candidates[] = array(
					'title'    => get_bloginfo( 'name' ),
					'url'      => $home_url,
					'summary'  => __( 'Strona główna Kasumi – generator treści i kodów QR.', 'kasumi-ai-generator' ),
					'anchors'  => array(
						__( 'Kasumi AI', 'kasumi-ai-generator' ),
						__( 'generator treści Kasumi', 'kasumi-ai-generator' ),
					),
					'priority' => 'secondary',
				);
			}
		}

		return $candidates;
	}

	/**
	 * @return array<int, array{title: string, url: string, summary: string, anchors: array<int, string>, priority: string}>
	 */
	private function get_primary_link_candidates(): array {
		$links = Options::get( 'primary_links', array() );

		if ( ! is_array( $links ) || empty( $links ) ) {
			return array();
		}

		$candidates = array();

		foreach ( $links as $link ) {
			if ( empty( $link['url'] ) ) {
				continue;
			}

			$url     = (string) $link['url'];
			$anchors = array();

			if ( ! empty( $link['anchors'] ) && is_array( $link['anchors'] ) ) {
				foreach ( $link['anchors'] as $anchor ) {
					$anchor = trim( (string) $anchor );

					if ( '' !== $anchor ) {
						$anchors[] = $anchor;
					}
				}
			}

			$candidates[] = array(
				'title'    => $this->resolve_primary_link_title( $url ),
				'url'      => $url,
				'summary'  => $this->build_primary_link_summary( $url, $anchors ),
				'anchors'  => $anchors,
				'priority' => 'primary',
			);
		}

		return $candidates;
	}

	/**
	 * @return array<int, string>
	 */
	public function get_primary_link_hints(): array {
		$links = Options::get( 'primary_links', array() );
		$anchors = array();

		if ( ! is_array( $links ) ) {
			return array();
		}

		foreach ( $links as $link ) {
			if ( empty( $link['anchors'] ) ) {
				continue;
			}

			$raw = is_array( $link['anchors'] ) ? $link['anchors'] : array( $link['anchors'] );

			foreach ( $raw as $anchor ) {
				$anchor = trim( (string) $anchor );

				if ( '' !== $anchor ) {
					$anchors[] = $anchor;
				}
			}
		}

		return array_unique( array_values( $anchors ) );
	}

	private function resolve_primary_link_title( string $url ): string {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$title = get_the_title( $post_id );

			if ( ! empty( $title ) ) {
				return $title;
			}
		}

		$parsed = wp_parse_url( $url );

		if ( ! empty( $parsed['host'] ) ) {
			$path = ! empty( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';

			return $parsed['host'] . $path;
		}

		return wp_strip_all_tags( $url );
	}

	/**
	 * @param string[] $anchors
	 */
	private function build_primary_link_summary( string $url, array $anchors ): string {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$excerpt = (string) get_post_field( 'post_excerpt', $post_id );

			if ( empty( $excerpt ) ) {
				$content = (string) get_post_field( 'post_content', $post_id );

				if ( ! empty( $content ) ) {
					$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 24 );
				}
			}

			if ( ! empty( $excerpt ) ) {
				return $excerpt;
			}
		}

		if ( ! empty( $anchors ) ) {
			return sprintf(
				/* translators: %s: comma separated list of preferred phrases. */
				__( 'Preferowane frazy: %s.', 'kasumi-ai-generator' ),
				implode( ', ', $anchors )
			);
		}

		return __( 'Ręcznie wskazany link kluczowy.', 'kasumi-ai-generator' );
	}

	/**
	 * @return array<int, array{title: string, url: string, summary: string, anchors: array<int, string>, priority: string}>
	 */
	private function get_tagged_post_candidates(): array {
		$candidates = array();
		$posts      = get_posts(
			array(
				'post_status'      => 'publish',
				'numberposts'      => 6,
					'orderby'          => 'rand',
			)
		);

		foreach ( $posts as $post ) {
			$candidate = $this->build_post_candidate( $post );

			if (
				$candidate &&
				! empty( $candidate['anchors'] )
			) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * @return array<int, array{title: string, url: string, summary: string, anchors: array<int, string>, priority: string}>
	 */
	private function get_recent_posts_candidates(): array {
		$candidates = array();
		$posts      = get_posts(
			array(
				'post_status'      => 'publish',
				'numberposts'      => 6,
					'orderby'          => 'rand',
			)
		);

		foreach ( $posts as $post ) {
			$candidate = $this->build_post_candidate( $post );

			if ( $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	private function build_post_candidate( WP_Post $post ): ?array {
		$url = get_permalink( $post );

		if ( empty( $url ) ) {
			return null;
		}

		return array(
			'title'    => $post->post_title,
			'url'      => $url,
			'summary'  => wp_trim_words( wp_strip_all_tags( $post->post_content ), 24 ),
			'anchors'  => $this->get_post_tag_anchors( $post->ID ),
			'priority' => 'secondary',
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function get_post_tag_anchors( int $post_id ): array {
		$tags = wp_get_post_tags(
			$post_id,
			array(
				'fields' => 'names',
			)
		);

		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return array();
		}

		$anchors = array_map( 'trim', $tags );
		$anchors = array_filter(
			$anchors,
			static fn( $anchor ) => '' !== $anchor
		);

		return array_unique( array_values( $anchors ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function candidate_has_url( array $candidates, string $url ): bool {
		foreach ( $candidates as $candidate ) {
			if ( isset( $candidate['url'] ) && $candidate['url'] === $url ) {
				return true;
			}
		}

		return false;
	}
}
