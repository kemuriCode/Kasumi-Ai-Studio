<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Gemini\Client as GeminiClient;
use OpenAI\Client as OpenAIClient;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;

use function array_filter;
use function array_slice;
use function basename;
use function base64_decode;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strlen;
use function str_contains;
use function trim;
use function wp_json_encode;

use const JSON_PRETTY_PRINT;

/**
 * Wrapper wokół klienta OpenAI z obsługą wyjątków.
 */
class AiClient {
	private ?OpenAIClient $openai_client = null;
	private ?GeminiClient $gemini_client = null;

	public function __construct( private Logger $logger ) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function generate_article( array $payload ): ?array {
		foreach ( $this->provider_order() as $provider ) {
			$result = 'gemini' === $provider
				? $this->generate_article_with_gemini( $payload )
				: $this->generate_article_with_openai( $payload );

			if ( $result ) {
				return $result;
			}
		}

		$this->logger->warning( 'Pominięto generowanie posta – brak aktywnego dostawcy AI.' );

		return null;
	}

	/**
	 * Generuje krótki komentarz bazując na treści posta.
	 *
	 * @param array{title?: string, excerpt?: string, summary?: string} $context
	 */
	public function generate_comment( array $context ): ?string {
		foreach ( $this->provider_order() as $provider ) {
			$result = 'gemini' === $provider
				? $this->generate_comment_with_gemini( $context )
				: $this->generate_comment_with_openai( $context );

			if ( ! empty( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function generate_nickname( array $context = array() ): ?string {
		foreach ( $this->provider_order() as $provider ) {
			$result = 'gemini' === $provider
				? $this->generate_nickname_with_gemini( $context )
				: $this->generate_nickname_with_openai( $context );

			if ( ! empty( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $article
	 * @param array<int, array{title: string, url: string, summary?: string}> $candidates
	 * @param string[] $hints
	 * @return array<int, array{anchor: string, url: string, title?: string}>
	 */
	public function suggest_internal_links( array $article, array $candidates, array $hints = array() ): array {
		if ( empty( $candidates ) ) {
			return array();
		}

		foreach ( $this->provider_order() as $provider ) {
			$result = 'gemini' === $provider
				? $this->suggest_links_with_gemini( $article, $candidates, $hints )
				: $this->suggest_links_with_openai( $article, $candidates, $hints );

			if ( ! empty( $result ) ) {
				return $result;
			}
		}

		return array();
	}

	private function provider_order(): array {
		$preference = (string) Options::get( 'ai_provider', 'openai' );

		return match ( $preference ) {
			'gemini' => array( 'gemini' ),
			'auto'   => array( 'openai', 'gemini' ),
			default  => array( 'openai' ),
		};
	}

	/**
	 * @return array{title?: string, slug?: string, excerpt?: string, content?: string, summary?: string}|null
	 */
	private function generate_article_with_openai( array $payload ): ?array {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		try {
			$response = $client->chat()->create(
				array(
					'model'       => $payload['model'] ?? $this->get_openai_model(),
					'temperature' => 0.7,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => $payload['system_prompt'] ?? $this->default_system_prompt(),
						),
						array(
							'role'    => 'user',
							'content' => $payload['user_prompt'] ?? '',
						),
					),
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->logger->error(
				'Nie udało się wygenerować treści OpenAI.',
				array( 'exception' => $throwable->getMessage() )
			);

			return null;
		}

		$content = $response->choices[0]->message->content ?? '';

		return $this->decode_content( $content );
	}

	private function generate_article_with_gemini( array $payload ): ?array {
		$prompt = sprintf(
			"System: %s\n\nPolecenie:\n%s",
			$payload['system_prompt'] ?? $this->default_system_prompt(),
			$payload['user_prompt'] ?? ''
		);

		$text = $this->gemini_generate_text( $prompt );

		if ( empty( $text ) ) {
			return null;
		}

		return $this->decode_content( $text );
	}

	private function decode_content( string $raw ): ?array {
		if ( empty( $raw ) ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			return $data;
		}

		if ( preg_match( '/\{.*\}/s', $raw, $matches ) ) {
			$data = json_decode( $matches[0], true );

			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return array(
			'content' => $raw,
		);
	}

	private function default_system_prompt(): string {
		$custom = Options::get( 'system_prompt', '' );

		if ( ! empty( $custom ) ) {
			return $custom;
		}

		$base_prompt = Options::get( 'topic_strategy', '' );

		// Jeśli użytkownik nie podał nic, używamy tylko zabezpieczenia
		$default = '';
		
		if ( ! empty( $base_prompt ) ) {
			$default = $base_prompt;
		}

		// Zabezpieczenie przed wstawkami AI - WAŻNE!
		$security_note = "WAŻNE: Piszesz konkretny tekst na konkretny temat. NIGDY nie dodawaj wstawek typu \"Wprowadzenie AI\", \"Zakończenie AI\", \"Wygenerowano przez AI\", \"Ten tekst został stworzony przez...\" ani żadnych innych wzmianek o tym, że treść została wygenerowana. Tekst ma wyglądać jak napisany przez człowieka eksperta. Zacznij od tematu, rozwiń go, zakończ naturalnie - bez żadnych meta-informacji o procesie generowania. Formatuj treść w Markdown.";

		return ! empty( $default ) 
			? $default . "\n\n" . $security_note
			: $security_note;
	}

	private function generate_comment_with_openai( array $context ): ?string {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		$user_prompt = sprintf(
			"Streszczenie wpisu:\n%s\n\nNapisz 1-2 zdania w języku polskim pod wpisem na blogu.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		try {
			$response = $client->chat()->create(
				array(
					'model'       => $this->get_openai_model(),
					'temperature' => 0.5,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'Tworzysz krótkie komentarze oddające entuzjazm czytelników, bez emoji.',
						),
						array(
							'role'    => 'user',
							'content' => $user_prompt,
						),
					),
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Komentarz AI nie został wygenerowany.',
				array( 'exception' => $throwable->getMessage() )
			);

			return null;
		}

		$text = trim( (string) ( $response->choices[0]->message->content ?? '' ) );

		return $text ?: null;
	}

	private function generate_comment_with_gemini( array $context ): ?string {
		$prompt = sprintf(
			"System: Tworzysz krótkie komentarze oddające entuzjazm czytelników, bez emoji.\n\nKontekst:\n%s\n\nOdpowiedz jednym komentarzem (1-2 zdania).",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		return $this->gemini_generate_text( $prompt );
	}

	private function generate_nickname_with_openai( array $context ): ?string {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		$user_prompt = sprintf(
			"Kontekst wpisu:\n%s\n\nZaproponuj jeden unikalny pseudonim internetowy po polsku, angielsku lub ich mixie. Użyj maksymalnie 16 znaków, bez spacji i emoji. Akceptowane są cyfry na końcu.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		try {
			$response = $client->chat()->create(
				array(
					'model'       => $this->get_openai_model(),
					'temperature' => 0.9,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'Jesteś kreatorem realistycznych nicków używanych w komentarzach internetowych. Preferuj połączenia imion, technologii i cyfr (np. PixelBasia, MartaVR88, CodeNomad). Odpowiadasz wyłącznie samym nickiem.',
						),
						array(
							'role'    => 'user',
							'content' => $user_prompt,
						),
					),
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się wygenerować pseudonimu.',
				array( 'exception' => $throwable->getMessage() )
			);

			return null;
		}

		return $this->sanitize_nickname( (string) ( $response->choices[0]->message->content ?? '' ) );
	}

	private function generate_nickname_with_gemini( array $context ): ?string {
		$prompt = sprintf(
			"Kontekst wpisu:\n%s\n\nPrzygotuj jeden unikalny pseudonim internetowy po polsku, angielsku lub w miksie (max 16 znaków, bez spacji i emoji, cyfry na końcu są ok). Zwróć wyłącznie nick.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		$text = $this->gemini_generate_text( $prompt );

		return $this->sanitize_nickname( $text );
	}

	private function sanitize_nickname( ?string $raw ): ?string {
		$nickname = trim( (string) $raw );
		$nickname = preg_replace( '/[^a-zA-Z0-9ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', '', $nickname ) ?? $nickname;

		if ( strlen( $nickname ) > 18 ) {
			$nickname = mb_substr( $nickname, 0, 18 );
		}

		return $nickname ?: null;
	}

	private function suggest_links_with_openai( array $article, array $candidates, array $hints ): array {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return array();
		}

		$hint_text = empty( $hints )
			? 'Dobierz anchory bazując na najważniejszych frazach z tekstu.'
			: sprintf(
				'Preferowane słowa kluczowe: %s.',
				implode( ', ', $hints )
			);

		$user_prompt = $this->build_links_prompt( $article, $candidates, $hint_text );

		try {
			$response = $client->chat()->create(
				array(
					'model'       => $this->get_openai_model(),
					'temperature' => 0.4,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'Jesteś specjalistą SEO. Zwracasz wyłącznie poprawny JSON z propozycjami linków wewnętrznych do podanych URL.',
						),
						array(
							'role'    => 'user',
							'content' => $user_prompt,
						),
					),
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się uzyskać propozycji linków od OpenAI.',
				array( 'exception' => $throwable->getMessage() )
			);

			return array();
		}

		return $this->parse_link_suggestions( (string) ( $response->choices[0]->message->content ?? '' ) );
	}

	private function suggest_links_with_gemini( array $article, array $candidates, array $hints ): array {
		$hint_text = empty( $hints )
			? 'Dobierz anchory bazując na najważniejszych frazach z tekstu.'
			: sprintf(
				'Preferowane słowa kluczowe: %s.',
				implode( ', ', $hints )
			);

		$prompt = sprintf(
			"System: Jesteś specjalistą SEO. Zwracasz wyłącznie poprawny JSON.\nPolecenie:\n%s",
			$this->build_links_prompt( $article, $candidates, $hint_text )
		);

		$text = $this->gemini_generate_text( $prompt );

		if ( empty( $text ) ) {
			return array();
		}

		return $this->parse_link_suggestions( $text );
	}

	private function build_links_prompt( array $article, array $candidates, string $hint_text ): string {
		return sprintf(
			"Tytuł: %s\nLead: %s\nTreść:\n%s\n\nDostępne linki docelowe:\n%s\n\nWybierz maksymalnie 3 propozycje. Zwróć JSON w formacie [{\"anchor\":\"fragment tekstu\",\"url\":\"https://...\",\"title\":\"powód\"}]. Anchory muszą istnieć w treści i mieć 2-5 słów. W razie braku oczywistych dopasowań wybierz stronę główną i anchor typu 'oferta Kasumi'. %s",
			(string) ( $article['title'] ?? '' ),
			(string) ( $article['excerpt'] ?? '' ),
			(string) ( $article['content'] ?? '' ),
			wp_json_encode( $candidates, JSON_PRETTY_PRINT ),
			$hint_text
		);
	}

	private function parse_link_suggestions( string $raw ): array {
		$raw = trim( $raw );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) && preg_match( '/\[[\s\S]+\]/', $raw, $matches ) ) {
			$data = json_decode( $matches[0], true );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$suggestions = array();

		foreach ( $data as $entry ) {
			$anchor = trim( (string) ( $entry['anchor'] ?? '' ) );
			$url    = trim( (string) ( $entry['url'] ?? '' ) );

			if ( '' === $anchor || '' === $url ) {
				continue;
			}

			$suggestions[] = array(
				'anchor' => $anchor,
				'url'    => $url,
				'title'  => $entry['title'] ?? '',
			);

			if ( count( $suggestions ) >= 3 ) {
				break;
			}
		}

		return $suggestions;
	}

	private function get_openai_client(): ?OpenAIClient {
		if ( null !== $this->openai_client ) {
			return $this->openai_client;
		}

		$api_key = Options::get( 'openai_api_key', '' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$this->openai_client = \OpenAI::factory()
			->withApiKey( $api_key )
			->make();

		return $this->openai_client;
	}

	private function get_gemini_client(): ?GeminiClient {
		if ( null !== $this->gemini_client ) {
			return $this->gemini_client;
		}

		$api_key = Options::get( 'gemini_api_key', '' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$this->gemini_client = \Gemini::client( $api_key );

		return $this->gemini_client;
	}

	private function gemini_generate_text( string $prompt ): ?string {
		$client = $this->get_gemini_client();

		if ( null === $client ) {
			return null;
		}

		try {
			$model    = $client->generativeModel( model: $this->get_gemini_model() );
			$response = $model->generateContent( $prompt );

			return trim( $response->text() );
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Żądanie Gemini nie powiodło się.',
				array( 'exception' => $throwable->getMessage() )
			);
		}

		return null;
	}

	private function get_gemini_model(): string {
		$model = (string) Options::get( 'gemini_model', 'gemini-2.0-flash' );

		return $model ?: 'gemini-2.0-flash';
	}

	private function get_openai_model(): string {
		$model = (string) Options::get( 'openai_model', 'gpt-4.1-mini' );

		return $model ?: 'gpt-4.1-mini';
	}

	/**
	 * @param array<string, mixed> $article
	 */
	public function generate_remote_image( array $article ): ?string {
		$provider = Options::get( 'image_remote_provider', 'openai' );

		if ( 'gemini' === $provider ) {
			return $this->generate_image_with_gemini( $article );
		}

		return $this->generate_image_with_openai( $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_image_with_openai( array $article ): ?string {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		$prompt = sprintf(
			"Create a clean featured image for a blog post.\nTitle: %s\nSummary: %s\nKeywords: %s\nStyle: modern, flat colors, easy to read text overlay.",
			(string) ( $article['title'] ?? '' ),
			wp_json_encode(
				array(
					'summary' => $article['summary'] ?? $article['excerpt'] ?? '',
				),
				JSON_PRETTY_PRINT
			),
			(string) Options::get( 'pixabay_query', 'qr code marketing' )
		);

		try {
			$response = $client->images()->create(
				array(
					'model'           => 'gpt-image-1',
					'prompt'          => $prompt,
					'size'            => '1024x1024',
					'response_format' => 'b64_json',
				)
			);
			$base64 = $response->data[0]->b64_json ?? '';

			return $base64 ? base64_decode( $base64 ) : null;
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się wygenerować grafiki przez OpenAI Images API.',
				array( 'exception' => $throwable->getMessage() )
			);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_image_with_gemini( array $article ): ?string {
		$client = $this->get_gemini_client();

		if ( null === $client ) {
			return null;
		}

		$prompt = sprintf(
			"Create a clean featured image for a blog post.\nTitle: %s\nSummary: %s\nKeywords: %s\nStyle: modern, flat colors, easy to read text overlay.",
			(string) ( $article['title'] ?? '' ),
			wp_json_encode(
				array(
					'summary' => $article['summary'] ?? $article['excerpt'] ?? '',
				),
				JSON_PRETTY_PRINT
			),
			(string) Options::get( 'pixabay_query', 'qr code marketing' )
		);

		try {
			// ImageConfig dla obrazu 16:9 (1200x675) - standard dla featured images
			$image_config = new \Gemini\Data\ImageConfig( aspectRatio: '16:9' );
			$generation_config = new \Gemini\Data\GenerationConfig( imageConfig: $image_config );
			
			$model = $client->generativeModel( model: 'gemini-2.5-flash-image' )
				->withGenerationConfig( $generation_config );
			
			$response = $model->generateContent( $prompt );

			$parts = $response->candidates()[0]->content()->parts ?? array();
			
			foreach ( $parts as $part ) {
				if ( isset( $part->inlineData ) && isset( $part->inlineData->data ) ) {
					$base64 = $part->inlineData->data;
					return base64_decode( $base64 );
				}
			}

			return null;
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się wygenerować grafiki przez Gemini Imagen API.',
				array( 'exception' => $throwable->getMessage() )
			);
		}

		return null;
	}

	/**
	 * @return array<int, array{id: string, label: string}>
	 */
	public function list_models( string $provider ): array {
		return 'gemini' === $provider ? $this->list_gemini_models() : $this->list_openai_models();
	}

	/**
	 * @return array<int, array{id: string, label: string}>
	 */
	private function list_openai_models(): array {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return array();
		}

		try {
			$response = $client->models()->list();
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się pobrać listy modeli OpenAI.',
				array( 'exception' => $throwable->getMessage() )
			);

			return array();
		}

		$models = array();

		foreach ( array_slice( $response->data, 0, 50 ) as $model ) {
			$id = $model->id ?? '';

			if ( '' === $id ) {
				continue;
			}

			if ( ! str_contains( $id, 'gpt' ) && ! str_contains( $id, 'o' ) ) {
				continue;
			}

			$models[] = array(
				'id'    => $id,
				'label' => $id,
			);
		}

		return $models;
	}

	/**
	 * @return array<int, array{id: string, label: string}>
	 */
	private function list_gemini_models(): array {
		$client = $this->get_gemini_client();

		if ( null === $client ) {
			return array();
		}

		try {
			$response = $client->models()->list( pageSize: 50 );
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się pobrać listy modeli Gemini.',
				array( 'exception' => $throwable->getMessage() )
			);

			return array();
		}

		$models = array();

		foreach ( $response->models as $model ) {
			$name = $model->name ?? '';

			if ( '' === $name ) {
				continue;
			}

			$slug  = basename( $name );
			$label = $model->displayName ?: $slug;

			$models[] = array(
				'id'    => $slug,
				'label' => $label,
			);
		}

		return $models;
	}
}
