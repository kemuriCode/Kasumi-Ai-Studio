<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Parsedown;

use function wp_kses_post;

/**
 * Serwis do konwersji Markdown do formatów WordPress.
 */
class MarkdownConverter {
	private Parsedown $parser;

	public function __construct() {
		$this->parser = new Parsedown();
		$this->parser->setSafeMode( false );
		$this->parser->setBreaksEnabled( true );
	}

	/**
	 * Konwertuje Markdown i sanituzuje HTML pod kątem bloków Gutenberg.
	 */
	public function to_block_ready_html( string $markdown ): string {
		$html = $this->to_html( $markdown );

		if ( '' === $html ) {
			return '';
		}

		return wp_kses_post( $html );
	}

	/**
	 * Konwertuje Markdown do HTML dla klasycznego edytora WordPress.
	 */
	public function to_html( string $markdown ): string {
		if ( empty( trim( $markdown ) ) ) {
			return '';
		}

		return $this->parser->text( $markdown );
	}

	/**
	 * Konwertuje Markdown do formatu dla Gutenberg.
	 * WordPress automatycznie przekształci HTML na bloki przy otwarciu w edytorze.
	 * Dla lepszej kompatybilności, zapisujemy jako HTML - Gutenberg obsługuje HTML natywnie.
	 */
	public function to_gutenberg_blocks( string $markdown ): string {
		if ( empty( trim( $markdown ) ) ) {
			return '';
		}

		// Konwertuj Markdown do HTML
		// Gutenberg automatycznie przekształci HTML na odpowiednie bloki
		// Dla lepszego efektu, możemy użyć bloku HTML lub po prostu zwrócić HTML
		$html = $this->parser->text( $markdown );

		// Opcjonalnie: możemy zwrócić HTML w bloku html lub po prostu HTML
		// WordPress automatycznie obsłuży HTML w Gutenbergu
		// Używamy prostego HTML - WordPress automatycznie go zintegruje
		return $html;
	}

	/**
	 * Sprawdza, czy treść zawiera Markdown.
	 */
	public function is_markdown( string $content ): bool {
		// Proste sprawdzenie podstawowych elementów Markdown
		$markdown_patterns = array(
			'/^#{1,6}\s+/m',              // Nagłówki
			'/^\*\s+/m',                  // Listy nieuporządkowane
			'/^\d+\.\s+/m',               // Listy uporządkowane
			'/\[.*?\]\(.*?\)/',           // Linki
			'/\*\*.*?\*\*|__.*?__/',      // Pogrubienie
			'/\*.*?\*|_.*?_/',            // Kursywa
			'/```/',                      // Bloki kodu
			'/^>\s+/m',                   // Cytaty
		);

		foreach ( $markdown_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Formatuje Markdown dla podglądu HTML.
	 */
	public function format_for_preview( string $markdown ): string {
		return $this->to_html( $markdown );
	}
}

