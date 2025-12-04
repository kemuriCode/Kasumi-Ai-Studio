<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use function esc_html;
use function esc_url;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function trim;

/**
 * Wstrzykuje linki wygenerowane przez AI do treści artykułu.
 */
class LinkBuilder {
	/**
	 * @param array<int, array{anchor: string, url: string, title?: string}> $suggestions
	 */
	public function inject_links( string $content, array $suggestions ): string {
		if ( empty( $suggestions ) || '' === trim( $content ) ) {
			return $content;
		}

		foreach ( $suggestions as $suggestion ) {
			$anchor = trim( (string) ( $suggestion['anchor'] ?? '' ) );
			$url    = trim( (string) ( $suggestion['url'] ?? '' ) );

			if ( '' === $anchor || '' === $url ) {
				continue;
			}

			$pattern = '/' . preg_quote( $anchor, '/' ) . '/i';

			if ( preg_match( $pattern, $content ) ) {
				$replacement = '<a href="' . esc_url( $url ) . '">$0</a>';
				$content     = preg_replace( $pattern, $replacement, $content, 1 ) ?? $content;
				continue;
			}
		}

		return $content;
	}
}
