<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\ContextResolver;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use Kasumi\AIGenerator\Service\MarkdownConverter;

use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function sanitize_text_field;
use function sprintf;
use function wp_json_encode;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_strip_all_tags;
use function wp_trim_words;
use function wp_unslash;

use const JSON_PRETTY_PRINT;

/**
 * Obsługuje podglądy AI w panelu.
 */
class PreviewController {
	public function __construct(
		private AiClient $ai_client,
		private FeaturedImageBuilder $image_builder,
		private ContextResolver $context_resolver,
		private Logger $logger,
		private MarkdownConverter $markdown_converter
	) {}

	public function register(): void {
		add_action( 'wp_ajax_kasumi_ai_preview', array( $this, 'handle_preview' ) );
	}

	public function handle_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Brak uprawnień.', 'kasumi-full-ai-content-generator' ) ),
				403
			);
		}

		check_ajax_referer( 'kasumi_ai_preview', 'nonce' );

		$type = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'text' ) );

		if ( 'image' === $type ) {
			$this->handle_image_preview();

			return;
		}

		$this->handle_text_preview();
	}

	private function handle_text_preview(): void {
		$article = $this->generate_sample_article();

		if ( empty( $article ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Nie udało się wygenerować tekstu podglądu.', 'kasumi-full-ai-content-generator' ) )
			);
		}

		wp_send_json_success(
			array(
				'article' => $article,
			)
		);
	}

	private function handle_image_preview(): void {
		$article = $this->generate_sample_article();

		if ( empty( $article ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Brak treści do zbudowania grafiki.', 'kasumi-full-ai-content-generator' ) )
			);
		}

		$image = $this->image_builder->preview( $article );

		if ( empty( $image ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Nie udało się wygenerować grafiki poglądowej.', 'kasumi-full-ai-content-generator' ) )
			);
		}

		wp_send_json_success(
			array(
				'image'   => $image,
				'article' => $article,
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function generate_sample_article(): ?array {
		$context = $this->context_resolver->get_prompt_context();
		$prompt  = $this->build_preview_prompt( $context );

		$article = $this->ai_client->generate_article(
			array(
				'user_prompt'   => $prompt,
				'system_prompt' => Options::get( 'system_prompt' ),
			)
		);

		if ( empty( $article ) ) {
			$this->logger->warning( 'Podgląd AI: OpenAI zwróciło pustą odpowiedź.' );

			return null;
		}

		$content = $article['content'] ?? '';
		$excerpt = $article['excerpt'] ?? wp_trim_words( wp_strip_all_tags( $this->markdown_converter->to_html( $content ) ), 40 );

		return array(
			'title'   => wp_strip_all_tags( $article['title'] ?? '' ),
			'excerpt' => wp_strip_all_tags( $excerpt ),
			'content' => $this->markdown_converter->format_for_preview( $content ),
			'content_markdown' => $content, // Oryginalny Markdown
			'summary' => $article['summary'] ?? '',
		);
	}

	/**
	 * @param array{recent_posts: array<int, array<string, string>>, categories: array<int, string>} $context
	 */
	private function build_preview_prompt( array $context ): string {
		$goal = Options::get( 'topic_strategy', '' );
		$strategy_line = ! empty( $goal ) 
			? "Strategia: " . $goal . "\n"
			: "";

		return sprintf(
			"Stwórz artykuł pokazowy. %sKategorie referencyjne: %s\nPrzykładowe wcześniejsze wpisy: %s\n\nZwróć JSON {\"title\",\"slug\",\"excerpt\",\"content\",\"summary\"}.\nPole \"content\" musi być w formacie Markdown - użyj nagłówków (##, ###), list (-, *), pogrubienia (**tekst**), kursywy (*tekst*), linków [tekst](url), cytatów (>). Tekst ma być konkretny na konkretny temat - BEZ żadnych wstawek typu \"Wprowadzenie AI\" czy \"Zakończenie AI\". Zacznij od tematu i rozwiń go naturalnie.",
			$strategy_line,
			wp_json_encode( $context['categories'], JSON_PRETTY_PRINT ),
			wp_json_encode( $context['recent_posts'], JSON_PRETTY_PRINT )
		);
	}
}
