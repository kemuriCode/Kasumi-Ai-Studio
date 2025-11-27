<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use function __;
use function get_bloginfo;
use function get_permalink;
use function get_posts;
use function get_terms;
use function home_url;
use function is_array;
use function wp_strip_all_tags;
use function wp_trim_words;

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
				'suppress_filters' => true,
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
	 * @return array<int, array{title: string, url: string, summary: string}>
	 */
	public function get_link_candidates(): array {
		$posts = get_posts(
			array(
				'post_status'      => 'publish',
				'numberposts'      => 6,
				'orderby'          => 'rand',
				'suppress_filters' => true,
			)
		);

		$candidates = array();

		foreach ( $posts as $post ) {
			$url = get_permalink( $post );

			if ( empty( $url ) ) {
				continue;
			}

			$candidates[] = array(
				'title'   => $post->post_title,
				'url'     => $url,
				'summary' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 24 ),
			);
		}

		$candidates[] = array(
			'title'   => get_bloginfo( 'name' ),
			'url'     => home_url( '/' ),
			'summary' => __( 'Strona główna Kasumi – generator treści i kodów QR.', 'kasumi-full-ai-content-generator' ),
		);

		return $candidates;
	}
}
