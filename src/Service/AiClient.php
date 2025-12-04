<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use Gemini\Client as GeminiClient;
use OpenAI\Client as OpenAIClient;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Status\StatsTracker;
use Kasumi\AIGenerator\Options;

use function __;
use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
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
	private ?array $last_gemini_usage = null;

	public function __construct( private Logger $logger ) {}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function generate_article( array $payload ): ?array {
		$skip_stats = ! empty( $payload['skip_stats'] );

		foreach ( $this->provider_order() as $provider ) {
			$result = 'gemini' === $provider
				? $this->generate_article_with_gemini( $payload, $skip_stats )
				: $this->generate_article_with_openai( $payload, $skip_stats );

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
	 * @param array<int, array{title: string, url: string, summary?: string, anchors?: array<int, string>, priority?: string}> $candidates
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
	private function generate_article_with_openai( array $payload, bool $skip_stats = false ): ?array {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		try {
			$model   = (string) ( $payload['model'] ?? $this->get_openai_model() );
			$response = $client->chat()->create(
				array(
					'model'       => $model,
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

		$article = $this->decode_content( $content );

		if ( $article ) {
			$usage = $this->extract_openai_usage_counts( $response );
			$this->record_usage(
				'article',
				'openai',
				$model,
				$usage['input_tokens'],
				$usage['output_tokens'],
				$skip_stats
			);
		}

		return $article;
	}

	private function generate_article_with_gemini( array $payload, bool $skip_stats = false ): ?array {
		$prompt = sprintf(
			"System: %s\n\nPolecenie:\n%s",
			$payload['system_prompt'] ?? $this->default_system_prompt(),
			$payload['user_prompt'] ?? ''
		);

		$model = (string) ( $payload['model'] ?? $this->get_gemini_model() );
		$text  = $this->gemini_generate_text( $prompt, $model );

		if ( empty( $text ) ) {
			return null;
		}

		$article = $this->decode_content( $text );

		if ( $article ) {
			$usage = $this->consume_gemini_usage();
			$this->record_usage(
				'article',
				'gemini',
				$model,
				$usage['input_tokens'],
				$usage['output_tokens'],
				$skip_stats
			);
		}

		return $article;
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
			return (string) $custom;
		}

		$fallback = __(
			"Jestem doświadczonym copywriterem i specjalistą od content marketingu. Tworzę teksty techniczne, marketingowe i kreatywne tak, aby były merytoryczne, zrozumiałe i użyteczne dla czytelnika.\n\nPisz w pierwszej osobie, w tonie profesjonalnym, ale spokojnym i przystępnym. Unikaj przesadnego entuzjazmu i nachalnego języka sprzedażowego. Zadbaj o naturalny rytm, mieszając krótsze i dłuższe zdania oraz akapity.\n\nTraktuj słowa kluczowe naturalnie, bez upychania ich na siłę. Pokazuj zarówno plusy, jak i minusy rozwiązań i wspieraj tezy przykładami z praktyki.\n\nDbaj o czytelną strukturę z nagłówkiem głównym, podtytułami i listami tam, gdzie to pomaga. Pogrubiaj tylko najważniejsze informacje.\n\nNie wspominaj o sztucznej inteligencji ani o procesie powstawania tekstu. Tekst ma brzmieć jak napisany przez człowieka. Używaj zwykłego myślnika zamiast pauzy typograficznej.",
			'kasumi-ai-generator'
		);

		return $fallback;
	}

	private function generate_comment_with_openai( array $context ): ?string {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		$user_prompt = sprintf(
			"Streszczenie wpisu:\n%s\n\nNapisz 1-2 zdania jako naturalny komentarz czytelnika w języku polskim. Mieszaj długość i rytm zdań, dodaj drobne potoczne wtrącenia i unikaj dosłownego powtarzania tytułu. Zero emoji i autopromocji.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		try {
			$model = $this->get_openai_model();
			$response = $client->chat()->create(
				array(
					'model'       => $model,
					'temperature' => 0.9,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'Jesteś aktywnym czytelnikiem dyskutującym pod artykułami. Każdy komentarz brzmi naturalnie, internetowo i inaczej niż poprzednie; nie używasz emoji ani marketingowych sloganów.',
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

		if ( '' === $text ) {
			return null;
		}

		$usage = $this->extract_openai_usage_counts( $response );
		$this->record_usage(
			'comment',
			'openai',
			$model,
			$usage['input_tokens'],
			$usage['output_tokens']
		);

		return $text;
	}

	private function generate_comment_with_gemini( array $context ): ?string {
		$prompt = sprintf(
			"System: Jesteś aktywnym czytelnikiem reagującym pod artykułami. Każdy komentarz brzmi inaczej, jest naturalny, potoczny i bez emoji ani marketingowego tonu.\n\nKontekst:\n%s\n\nPolecenie: napisz 1-2 zdania jako komentarz internetowy po polsku, mieszając długość zdań i dodając luźne wtrącenia.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		$model = $this->get_gemini_model();
		$text  = $this->gemini_generate_text( $prompt, $model );

		if ( empty( $text ) ) {
			return null;
		}

		$usage = $this->consume_gemini_usage();
		$this->record_usage(
			'comment',
			'gemini',
			$model,
			$usage['input_tokens'],
			$usage['output_tokens']
		);

		return $text;
	}

	private function generate_nickname_with_openai( array $context ): ?string {
		$client = $this->get_openai_client();

		if ( null === $client ) {
			return null;
		}

		$user_prompt = sprintf(
			"Kontekst wpisu:\n%s\n\nZaproponuj jeden unikalny pseudonim internetowy po polsku, angielsku lub w miksie. Użyj maks. 16 znaków, bez spacji ani emoji – możesz dodać cyfry lub znaki '-', '_'. Każdy nick ma mieć inny klimat (imię+cyfry, gra słowna, tech vibes, gaming inspo).",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		try {
			$response = $client->chat()->create(
				array(
					'model'       => $this->get_openai_model(),
					'temperature' => 1.0,
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'Jesteś kreatorem realistycznych nicków z różnych zakątków internetu. Każdy nick ma mieć inny wzorzec: imię+cyfry, hybrydy PL/EN, odniesienia do technologii, gier, muzyki lub zmyślne skróty. Zero emoji i spacji, zwracasz wyłącznie sam nick.',
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
			"Kontekst wpisu:\n%s\n\nPrzygotuj jeden unikalny pseudonim internetowy (max 16 znaków, bez spacji i emoji, cyfry są opcjonalne). Mieszaj style: imiona z tech-sufiksami, gamingowe ksywki, gry słowne, skróty PL/EN. Zwróć wyłącznie sam nick.",
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		$text = $this->gemini_generate_text( $prompt );
		$this->consume_gemini_usage();

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

		return $this->parse_link_suggestions(
			(string) ( $response->choices[0]->message->content ?? '' ),
			$this->get_candidate_urls( $candidates )
		);
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
			$this->consume_gemini_usage();
			return array();
		}

		$result = $this->parse_link_suggestions( $text, $this->get_candidate_urls( $candidates ) );
		$this->consume_gemini_usage();

		return $result;
	}

	private function build_links_prompt( array $article, array $candidates, string $hint_text ): string {
		$rules = array_filter(
			array(
				$this->has_primary_candidates( $candidates )
					? 'Linki z polem "priority":"primary" traktuj priorytetowo (wybierz maksymalnie 2, jeśli pasują do treści).'
					: '',
				'Jeśli kandydat ma pole "anchors", anchor musi dokładnie odpowiadać jednej z podanych fraz.',
				$hint_text,
				'Anchory muszą istnieć w treści i mieć 2-5 słów.',
				'Nie wolno wymyślać nowych adresów URL – korzystaj wyłącznie z listy.',
				'W razie braku dopasowań zwróć pustą tablicę ([]) zamiast wymyślać linków.',
			)
		);

		return sprintf(
			"Tytuł: %s\nLead: %s\nTreść:\n%s\n\nDostępne linki docelowe (JSON {\"title\",\"url\",\"summary\",\"anchors\",\"priority\"}):\n%s\n\nZasady:\n- %s\n\nWybierz maksymalnie 3 propozycje. Zwróć JSON w formacie [{\"anchor\":\"fragment tekstu\",\"url\":\"https://...\",\"title\":\"powód\"}]. W razie braku dopasowań odpowiedz pustą tablicą.",
			(string) ( $article['title'] ?? '' ),
			(string) ( $article['excerpt'] ?? '' ),
			(string) ( $article['content'] ?? '' ),
			wp_json_encode( $candidates, JSON_PRETTY_PRINT ),
			implode( "\n- ", $rules )
		);
	}

	private function parse_link_suggestions( string $raw, array $allowed_urls = array() ): array {
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

			if ( ! empty( $allowed_urls ) && ! in_array( $url, $allowed_urls, true ) ) {
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

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function has_primary_candidates( array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			if ( isset( $candidate['priority'] ) && 'primary' === $candidate['priority'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $candidates
	 * @return string[]
	 */
	private function get_candidate_urls( array $candidates ): array {
		$urls = array();

		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['url'] ) ) {
				continue;
			}

			$urls[] = (string) $candidate['url'];
		}

		return array_values( array_unique( $urls ) );
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

	private function gemini_generate_text( string $prompt, ?string $model_id = null ): ?string {
		$client = $this->get_gemini_client();

		if ( null === $client ) {
			return null;
		}

		try {
			$resolved_model = $model_id ?: $this->get_gemini_model();
			$model          = $client->generativeModel( model: $resolved_model );
			$response       = $model->generateContent( $prompt );
			$this->last_gemini_usage = $this->extract_gemini_usage_counts( $response );

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
	public function generate_remote_image( array $article, bool $skip_stats = false ): ?string {
		$provider = Options::get( 'image_remote_provider', 'openai' );

		if ( 'gemini' === $provider ) {
			return $this->generate_image_with_gemini( $article, $skip_stats );
		}

		return $this->generate_image_with_openai( $article, $skip_stats );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_image_with_openai( array $article, bool $skip_stats = false ): ?string {
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
			$model = 'gpt-image-1';
			$response = $client->images()->create(
				array(
					'model'           => $model,
					'prompt'          => $prompt,
					'size'            => '1024x1024',
					'response_format' => 'b64_json',
				)
			);
			$base64 = $response->data[0]->b64_json ?? '';

			$binary = $base64 ? base64_decode( $base64 ) : null;

			if ( $binary ) {
				$this->record_usage(
					'image',
					'openai',
					$model,
					0,
					1,
					$skip_stats
				);
			}

			return $binary;
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
	private function generate_image_with_gemini( array $article, bool $skip_stats = false ): ?string {
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
			
			$model_name = 'gemini-2.5-flash-image';
			$model = $client->generativeModel( model: $model_name )
				->withGenerationConfig( $generation_config );
			
			$response = $model->generateContent( $prompt );

			$parts = $response->candidates()[0]->content()->parts ?? array();
			
			foreach ( $parts as $part ) {
				if ( isset( $part->inlineData ) && isset( $part->inlineData->data ) ) {
					$base64 = $part->inlineData->data;
					$binary = base64_decode( $base64 );

					if ( $binary ) {
						$this->record_usage(
							'image',
							'gemini',
							$model_name,
							0,
							1,
							$skip_stats
						);
					}

					return $binary;
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

	/**
	 * Normalizuje usage OpenAI z dowolnego formatu.
	 *
	 * @param mixed $response
	 * @return array{input_tokens:int,output_tokens:int,total_tokens:int}
	 */
	private function extract_openai_usage_counts( $response ): array {
		$usage = $response->usage ?? null;

		if ( null === $usage && method_exists( $response, 'toArray' ) ) {
			$data  = $response->toArray();
			$usage = $data['usage'] ?? null;
		}

		return $this->normalize_usage_counts( $usage );
	}

	/**
	 * Zwraca ostatnie usage z Gemini.
	 *
	 * @param mixed $response
	 * @return array{input_tokens:int,output_tokens:int,total_tokens:int}
	 */
	private function extract_gemini_usage_counts( $response ): array {
		$metadata = $response->usageMetadata ?? null;

		if ( null === $metadata && method_exists( $response, 'toArray' ) ) {
			$data     = $response->toArray();
			$metadata = $data['usageMetadata'] ?? null;
		}

		return $this->normalize_usage_counts( $metadata );
	}

	/**
	 * Zwraca zapisane usage Gemini i resetuje cache.
	 */
	private function consume_gemini_usage(): array {
		if ( null === $this->last_gemini_usage ) {
			return array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'total_tokens'  => 0,
			);
		}

		$usage = $this->last_gemini_usage;
		$this->last_gemini_usage = null;

		return $usage;
	}

	/**
	 * @param mixed $usage
	 * @return array{input_tokens:int,output_tokens:int,total_tokens:int}
	 */
	private function normalize_usage_counts( $usage ): array {
		$prompt   = 0;
		$output   = 0;
		$total    = 0;

		if ( is_object( $usage ) ) {
			$prompt = (int) ( $usage->promptTokens ?? $usage->prompt_tokens ?? $usage->promptTokenCount ?? 0 );
			$output = (int) ( $usage->completionTokens ?? $usage->completion_tokens ?? $usage->candidatesTokenCount ?? $usage->outputTokenCount ?? 0 );
			$total  = (int) ( $usage->totalTokens ?? $usage->total_tokens ?? $usage->totalTokenCount ?? 0 );
		} elseif ( is_array( $usage ) ) {
			$prompt = (int) ( $usage['prompt_tokens'] ?? $usage['promptTokens'] ?? $usage['promptTokenCount'] ?? 0 );
			$output = (int) ( $usage['completion_tokens'] ?? $usage['completionTokens'] ?? $usage['candidatesTokenCount'] ?? $usage['outputTokenCount'] ?? 0 );
			$total  = (int) ( $usage['total_tokens'] ?? $usage['totalTokens'] ?? $usage['totalTokenCount'] ?? 0 );
		}

		if ( $total <= 0 ) {
			$total = $prompt + $output;
		}

		return array(
			'input_tokens'  => max( 0, $prompt ),
			'output_tokens' => max( 0, $output ),
			'total_tokens'  => max( 0, $total ),
		);
	}

	private function record_usage( string $type, string $provider, string $model, int $input_tokens, int $output_tokens, bool $skip_stats = false, ?float $cost_override = null ): void {
		if ( $skip_stats ) {
			return;
		}

		$input_tokens  = max( 0, $input_tokens );
		$output_tokens = max( 0, $output_tokens );
		$total_tokens  = max( 0, $input_tokens + $output_tokens );

		$data = array(
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
			'total_tokens'  => $total_tokens,
			'model'         => $model,
		);

		$data['cost'] = $cost_override ?? StatsTracker::calculate_cost(
			$provider,
			$model,
			$input_tokens,
			$output_tokens
		);

		StatsTracker::record( $type, $provider, $data );
	}
}
