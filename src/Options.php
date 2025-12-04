<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator;

use function __;
use function absint;
use function array_filter;
use function array_map;
use function array_unique;
use function array_slice;
use function array_merge;
use function explode;
use function get_option;
use function get_user_by;
use function is_array;
use function is_string;
use function esc_url_raw;
use function max;
use function min;
use function sanitize_hex_color_no_hash;
use function sanitize_text_field;
use function trim;
use function update_option;
use function wp_parse_args;

/**
 * Pomocnicza klasa do obsługi opcji modułu AI.
 */
final class Options {
	public const OPTION_NAME = 'kasumi_ai_options';
	public const OPTION_GROUP = 'kasumi-full-ai-content-generator-settings';

	/**
	 * Zwraca pełną tablicę ustawień z domyślnymi wartościami.
	 */
	public static function all(): array {
		$options = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
	}

	/**
	 * Pobiera konkretną wartość z konfiguracji.
	 *
	 * @param string $key Nazwa klucza.
	 * @param mixed  $default Wartość domyślna.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return $all[ $key ] ?? $default;
	}

	/**
	 * Domyślne wartości ustawień.
	 */
	public static function defaults(): array {
		return array(
			'openai_api_key'          => '',
			'openai_model'           => 'gpt-4.1-mini',
			'ai_provider'            => 'openai',
			'gemini_api_key'         => '',
			'gemini_model'           => 'gemini-2.0-flash',
			'pixabay_api_key'         => '',
			'system_prompt'           => __(
				"Jestem doświadczonym copywriterem i specjalistą od content marketingu. Tworzę teksty techniczne, marketingowe i kreatywne tak, aby były merytoryczne, zrozumiałe i użyteczne dla czytelnika.\n\nPisz w pierwszej osobie, w tonie profesjonalnym, ale spokojnym i przystępnym. Unikaj przesadnego entuzjazmu i nachalnego języka sprzedażowego. Zadbaj o naturalny rytm: mieszaj krótsze i dłuższe zdania, a akapity buduj tak, aby prowadziły czytelnika krok po kroku przez temat.\n\nTraktuj słowa kluczowe jak naturalny element wypowiedzi, nie upychaj ich na siłę. Pokazuj zarówno plusy, jak i minusy rozwiązań. Wspieraj tezy konkretnymi przykładami oraz odwołaniami do praktyki.\n\nDbaj o logiczną strukturę z nagłówkiem głównym i sekcjami z podtytułami, stosuj listy tam, gdzie poprawiają czytelność i pogrubiaj tylko najważniejsze informacje.\n\nUnikaj wstawek o sztucznej inteligencji i metakomentarzy o tym, jak tekst powstał. Tekst ma brzmieć jak napisany przez człowieka. Nie używaj pauz typograficznych, stosuj zwykły myślnik.",
				'kasumi-full-ai-content-generator'
			),
			'topic_strategy'          => '',
			'target_category'         => '',
			'default_author_mode'     => 'none',
			'default_author_id'       => 0,
			'default_author_pool'     => array(),
			'default_post_status'     => 'draft',
			'schedule_interval_hours' => 84,
			'word_count_min'          => 600,
			'word_count_max'          => 1200,
			'enable_internal_linking' => true,
			'primary_links'           => array(),
			'enable_logging'          => true,
			'enable_featured_images'  => true,
			'image_generation_mode'   => 'server',
			'image_remote_provider'   => 'openai',
			'image_server_engine'     => 'imagick',
			'image_template'          => 'Kasumi AI – {{title}}',
			'image_overlay_color'     => '1b1f3b',
			'image_overlay_opacity'   => 75,
			'image_style'             => 'modern',
			'image_text_alignment'    => 'center',
			'image_text_vertical'     => 'middle',
			'image_text_enabled'      => true,
			'image_canvas_width'      => 1200,
			'image_canvas_height'     => 675,
			'pixabay_query'           => 'qr code technology interface',
			'pixabay_orientation'     => 'horizontal',
			'link_keywords'           => '',
			'comments_enabled'        => true,
			'comment_frequency'       => 'dense',
			'comment_min'             => 6,
			'comment_max'             => 12,
			'comment_status'          => 'approve',
			'comment_author_prefix'   => '',
			'status_logging'          => true,
			'preview_mode'            => false,
			'debug_email'             => '',
			'plugin_enabled'          => true,
			'delete_tables_on_deactivation' => false,
			'automation_paused'       => false,
		);
	}

	/**
	 * Sanityzacja danych zapisanych w Settings API.
	 *
	 * @param array $values Dane z formularza.
	 */
	public static function sanitize( $values ): array {
		$values = is_array( $values ) ? $values : array();
		$defaults = self::defaults();
		$sanitized = array();

		foreach ( array_keys( $defaults ) as $key ) {
			$sanitized[ $key ] = $defaults[ $key ];
		}

		$sanitized['openai_api_key'] = trim( (string) ( $values['openai_api_key'] ?? '' ) );
		$sanitized['openai_model']   = sanitize_text_field( $values['openai_model'] ?? $defaults['openai_model'] );
		$provider = $values['ai_provider'] ?? $defaults['ai_provider'];
		$sanitized['ai_provider'] = in_array( $provider, array( 'openai', 'gemini', 'auto' ), true ) ? $provider : $defaults['ai_provider'];
		$sanitized['gemini_api_key'] = trim( (string) ( $values['gemini_api_key'] ?? '' ) );
		$sanitized['gemini_model']   = sanitize_text_field( $values['gemini_model'] ?? $defaults['gemini_model'] );
		$sanitized['pixabay_api_key'] = trim( (string) ( $values['pixabay_api_key'] ?? '' ) );
		$sanitized['system_prompt'] = sanitize_text_field( $values['system_prompt'] ?? '' );
		$sanitized['topic_strategy'] = sanitize_text_field( $values['topic_strategy'] ?? $defaults['topic_strategy'] );
		$sanitized['target_category'] = sanitize_text_field( $values['target_category'] ?? '' );
		$author_mode = $values['default_author_mode'] ?? $defaults['default_author_mode'];
		$sanitized['default_author_mode'] = in_array( $author_mode, array( 'none', 'fixed', 'random_list' ), true )
			? $author_mode
			: $defaults['default_author_mode'];
		$sanitized['default_author_id'] = self::sanitize_author_id_value( $values['default_author_id'] ?? 0 );
		$sanitized['default_author_pool'] = self::sanitize_author_pool( $values['default_author_pool'] ?? array() );
		$sanitized['default_post_status'] = in_array( $values['default_post_status'] ?? '', array( 'draft', 'publish' ), true ) ? $values['default_post_status'] : $defaults['default_post_status'];
		$sanitized['schedule_interval_hours'] = max( 72, absint( $values['schedule_interval_hours'] ?? $defaults['schedule_interval_hours'] ) );
		$sanitized['word_count_min'] = max( 200, absint( $values['word_count_min'] ?? $defaults['word_count_min'] ) );
		$sanitized['word_count_max'] = max(
			$sanitized['word_count_min'],
			absint( $values['word_count_max'] ?? $defaults['word_count_max'] )
		);
		$sanitized['enable_internal_linking'] = ! empty( $values['enable_internal_linking'] );
		$sanitized['primary_links']           = self::sanitize_primary_links( $values['primary_links'] ?? array() );
		$sanitized['enable_logging'] = ! empty( $values['enable_logging'] );
		$sanitized['enable_featured_images'] = ! empty( $values['enable_featured_images'] );
		$image_mode = $values['image_generation_mode'] ?? $defaults['image_generation_mode'];
		$sanitized['image_generation_mode'] = in_array( $image_mode, array( 'server', 'remote' ), true ) ? $image_mode : $defaults['image_generation_mode'];
		$image_remote_provider = $values['image_remote_provider'] ?? $defaults['image_remote_provider'];
		$sanitized['image_remote_provider'] = in_array( $image_remote_provider, array( 'openai', 'gemini' ), true ) ? $image_remote_provider : $defaults['image_remote_provider'];
		$image_engine = $values['image_server_engine'] ?? $defaults['image_server_engine'];
		$sanitized['image_server_engine'] = in_array( $image_engine, array( 'imagick', 'gd' ), true ) ? $image_engine : $defaults['image_server_engine'];
		$sanitized['image_template'] = sanitize_text_field( $values['image_template'] ?? $defaults['image_template'] );
		$color = sanitize_hex_color_no_hash( $values['image_overlay_color'] ?? $defaults['image_overlay_color'] );
		$sanitized['image_overlay_color'] = $color ? $color : $defaults['image_overlay_color'];
		$opacity = (int) ( $values['image_overlay_opacity'] ?? $defaults['image_overlay_opacity'] );
		$sanitized['image_overlay_opacity'] = min( 100, max( 0, $opacity ) );
		$image_style = $values['image_style'] ?? $defaults['image_style'];
		$sanitized['image_style'] = in_array( $image_style, array( 'modern', 'classic', 'oldschool' ), true ) ? $image_style : $defaults['image_style'];
		$text_alignment = $values['image_text_alignment'] ?? $defaults['image_text_alignment'];
		$sanitized['image_text_alignment'] = in_array( $text_alignment, array( 'left', 'center', 'right' ), true ) ? $text_alignment : $defaults['image_text_alignment'];
		$text_vertical = $values['image_text_vertical'] ?? $defaults['image_text_vertical'];
		$sanitized['image_text_vertical'] = in_array( $text_vertical, array( 'top', 'middle', 'bottom' ), true ) ? $text_vertical : $defaults['image_text_vertical'];
		$sanitized['image_text_enabled'] = ! empty( $values['image_text_enabled'] );
		$width = absint( $values['image_canvas_width'] ?? $defaults['image_canvas_width'] );
		$height = absint( $values['image_canvas_height'] ?? $defaults['image_canvas_height'] );
		$sanitized['image_canvas_width']  = min( 4000, max( 640, $width ) );
		$sanitized['image_canvas_height'] = min( 4000, max( 360, $height ) );
		$sanitized['pixabay_query'] = sanitize_text_field( $values['pixabay_query'] ?? $defaults['pixabay_query'] );
		$sanitized['pixabay_orientation'] = in_array(
			$values['pixabay_orientation'] ?? '',
			array( 'horizontal', 'vertical' ),
			true
		) ? $values['pixabay_orientation'] : $defaults['pixabay_orientation'];
		$sanitized['link_keywords'] = sanitize_text_field( $values['link_keywords'] ?? '' );
		$sanitized['comments_enabled'] = ! empty( $values['comments_enabled'] );
		$sanitized['comment_frequency'] = in_array(
			$values['comment_frequency'] ?? '',
			array( 'dense', 'normal', 'slow' ),
			true
		) ? $values['comment_frequency'] : $defaults['comment_frequency'];
		$sanitized['comment_min'] = max( 1, absint( $values['comment_min'] ?? $defaults['comment_min'] ) );
		$sanitized['comment_max'] = max(
			$sanitized['comment_min'],
			absint( $values['comment_max'] ?? $defaults['comment_max'] )
		);
		$sanitized['comment_status'] = in_array(
			$values['comment_status'] ?? '',
			array( 'approve', 'hold' ),
			true
		) ? $values['comment_status'] : $defaults['comment_status'];
		$sanitized['comment_author_prefix'] = sanitize_text_field(
			$values['comment_author_prefix'] ?? $defaults['comment_author_prefix']
		);
		$sanitized['status_logging'] = ! empty( $values['status_logging'] );
		$sanitized['preview_mode'] = ! empty( $values['preview_mode'] );
		$sanitized['debug_email'] = sanitize_text_field( $values['debug_email'] ?? '' );
		$sanitized['plugin_enabled'] = ! empty( $values['plugin_enabled'] );
		$sanitized['delete_tables_on_deactivation'] = ! empty( $values['delete_tables_on_deactivation'] );
		$sanitized['automation_paused'] = ! empty( $values['automation_paused'] );

		return $sanitized;
	}

	private static function sanitize_author_id_value( $value ): int {
		$id = absint( $value );

		if ( $id <= 0 ) {
			return 0;
		}

		return get_user_by( 'id', $id ) ? $id : 0;
	}

	private static function sanitize_author_pool( $value ): array {
		$pool = array();

		if ( is_string( $value ) ) {
			$value = array_filter(
				array_map( 'trim', explode( ',', $value ) ),
				static fn( $chunk ) => '' !== $chunk
			);
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$id = self::sanitize_author_id_value( $item );

				if ( $id > 0 ) {
					$pool[] = $id;
				}
			}
		}

		return array_values( array_unique( $pool ) );
	}

	private static function sanitize_primary_links( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$links = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) ( $entry['url'] ?? '' ) ) );

			if ( '' === $url ) {
				continue;
			}

			$anchors = array();
			$raw     = $entry['anchors'] ?? array();

			if ( is_string( $raw ) ) {
				$raw = array_filter(
					array_map( 'trim', explode( ',', $raw ) ),
					static fn( $chunk ) => '' !== $chunk
				);
			}

			if ( is_array( $raw ) ) {
				foreach ( $raw as $anchor ) {
					$anchor = sanitize_text_field( $anchor );

					if ( '' !== $anchor ) {
						$anchors[] = $anchor;
					}
				}
			}

			$links[] = array(
				'url'     => $url,
				'anchors' => array_values( array_unique( $anchors ) ),
			);
		}

		return array_slice( $links, 0, 10 );
	}

	/**
	 * Eksportuje ustawienia do JSON (bez kluczy API).
	 *
	 * @return string JSON string.
	 */
	public static function export(): string {
		$options = self::all();
		// Usuń klucze API dla bezpieczeństwa
		unset( $options['openai_api_key'], $options['gemini_api_key'], $options['pixabay_api_key'] );

		return wp_json_encode( $options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Importuje ustawienia z JSON.
	 *
	 * @param string $json JSON string.
	 * @return array{success: bool, message: string, data?: array}
	 */
	public static function import( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'message' => __( 'Nieprawidłowy format JSON.', 'kasumi-full-ai-content-generator' ),
			);
		}

		if ( ! is_array( $data ) ) {
			return array(
				'success' => false,
				'message' => __( 'Dane muszą być tablicą.', 'kasumi-full-ai-content-generator' ),
			);
		}

		// Sanityzacja przez sanitize()
		$sanitized = self::sanitize( $data );

		// Zapisz
		update_option( self::OPTION_NAME, $sanitized );

		return array(
			'success' => true,
			'message' => __( 'Ustawienia zostały zaimportowane.', 'kasumi-full-ai-content-generator' ),
			'data'    => $sanitized,
		);
	}

	/**
	 * Aktualizuje istniejące ustawienia, zachowując pozostałe wartości.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		$current = self::all();
		$merged  = array_merge( $current, $values );

		update_option( self::OPTION_NAME, self::sanitize( $merged ) );
	}

}
