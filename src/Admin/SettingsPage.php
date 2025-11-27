<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;
use Kasumi\AIGenerator\Status\StatsTracker;

use function __;
use function add_query_arg;
use function add_settings_field;
use function add_settings_section;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function checked;
use function current_time;
use function current_user_can;
use function date_i18n;
use function delete_user_meta;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function get_current_user_id;
use function get_option;
use function get_post_types;
use function get_user_meta;
use function get_users;
use function human_time_diff;
use function number_format_i18n;
use function printf;
use function register_setting;
use function rest_url;
use function sanitize_text_field;
use function selected;
use function settings_fields;
use function submit_button;
use function time;
use function update_user_meta;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script;
use function wp_kses_post;
use function wp_localize_script;
use function wp_parse_args;
use function wp_safe_redirect;
use function wp_strip_all_tags;
use function wp_unslash;

use const DAY_IN_SECONDS;
use const WEEK_IN_SECONDS;

/**
 * Panel konfiguracyjny modułu AI Content.
 */
class SettingsPage {
	private const PAGE_SLUG = 'kasumi-full-ai-content-generator-ai-content';
	private const SUPPORT_DISMISS_META = 'kasumi_ai_support_hidden_until';

	public function register_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'AI Content', 'kasumi-full-ai-content-generator' ),
			__( 'AI Content', 'kasumi-full-ai-content-generator' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			Options::OPTION_GROUP,
			Options::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);

		// Rejestruj hook dla akcji banera wsparcia
		add_action( 'admin_post_kasumi_ai_support_card', array( $this, 'handle_support_card_action' ) );

		$this->register_api_section();
		$this->register_content_section();
		$this->register_image_section();
		$this->register_comments_section();
		$this->register_misc_section();
		$this->register_diagnostics_section();
	}

	public function handle_support_card_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'kasumi-full-ai-content-generator' ) );
		}

		check_admin_referer( 'kasumi_ai_support_card' );

		$action  = sanitize_text_field( wp_unslash( $_POST['kasumi_ai_support_action'] ?? '' ) );
		$user_id = get_current_user_id();

		if ( 'dismiss' === $action ) {
			update_user_meta( $user_id, self::SUPPORT_DISMISS_META, time() + WEEK_IN_SECONDS );
		} elseif ( 'reset' === $action ) {
			delete_user_meta( $user_id, self::SUPPORT_DISMISS_META );
		}

		wp_safe_redirect(
			admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
		);
		exit;
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		
		// Bootstrap Icons
		$bootstrap_icons_path = KASUMI_AI_PATH . 'vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css';
		$bootstrap_icons_url  = KASUMI_AI_URL . 'vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css';
		
		if ( file_exists( $bootstrap_icons_path ) ) {
			wp_enqueue_style(
				'bootstrap-icons',
				$bootstrap_icons_url,
				array(),
				'1.13.1'
			);
		}
		
		wp_enqueue_script(
			'chart-js',
			KASUMI_AI_URL . 'assets/js/chart.umd.min.js',
			array(),
			'4.4.0',
			true
		);

		wp_enqueue_script(
			'kasumi-ai-preview',
			KASUMI_AI_URL . 'assets/js/ai-preview.js',
			array( 'wp-api-fetch' ),
			KASUMI_AI_VERSION,
			true
		);

		wp_localize_script(
			'kasumi-ai-preview',
			'kasumiAiPreview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kasumi_ai_preview' ),
				'i18n'    => array(
					'loading' => __( 'Generowanie w toku…', 'kasumi-full-ai-content-generator' ),
					'error'   => __( 'Coś poszło nie tak. Spróbuj ponownie.', 'kasumi-full-ai-content-generator' ),
				),
			)
		);

		wp_enqueue_script(
			'kasumi-ai-admin-ui',
			KASUMI_AI_URL . 'assets/js/admin-ui.js',
			array( 'jquery', 'jquery-ui-tabs', 'jquery-ui-tooltip', 'wp-color-picker', 'wp-util' ),
			KASUMI_AI_VERSION,
			true
		);

		wp_localize_script(
			'kasumi-ai-admin-ui',
			'kasumiAiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'kasumi_ai_models' ),
				'i18n'    => array(
					'fetching' => __( 'Ładowanie modeli…', 'kasumi-full-ai-content-generator' ),
					'noModels' => __( 'Brak modeli', 'kasumi-full-ai-content-generator' ),
					'error'    => __( 'Nie udało się pobrać modeli.', 'kasumi-full-ai-content-generator' ),
				),
				'scheduler' => $this->get_scheduler_settings(),
			)
		);

		wp_enqueue_style(
			'kasumi-ai-admin',
			KASUMI_AI_URL . 'assets/css/admin.css',
			array(),
			KASUMI_AI_VERSION
		);

		// Dodaj dynamiczne zmienne CSS dla admin color scheme
		$this->add_admin_color_scheme_variables();
	}

	/**
	 * Dodaje dynamiczne zmienne CSS dla aktualnego schematu kolorów WordPress.
	 */
	private function add_admin_color_scheme_variables(): void {
		global $_wp_admin_css_colors;

		$color_scheme = get_user_option( 'admin_color', get_current_user_id() );
		if ( empty( $color_scheme ) || ! isset( $_wp_admin_css_colors[ $color_scheme ] ) ) {
			$color_scheme = 'fresh';
		}

		$scheme = $_wp_admin_css_colors[ $color_scheme ] ?? null;
		if ( ! $scheme ) {
			return;
		}

		// Pobierz kolory z schematu
		$colors = $scheme->colors ?? array();
		// Dla większości schematów: colors[0] = base, colors[1] = highlight, colors[2] = link focus
		$base_color = $colors[0] ?? '#23282d';
		$highlight_color = $colors[1] ?? ( $colors[0] ?? '#0073aa' ); // Drugi kolor to zazwyczaj highlight
		$link_color = $highlight_color;
		$link_focus = $colors[2] ?? $this->adjust_color_brightness( $highlight_color, 10 ); // Trzeci kolor to często link focus

		// Oblicz warianty kolorów
		$darker_10 = $this->adjust_color_brightness( $highlight_color, -10 );
		$darker_20 = $this->adjust_color_brightness( $highlight_color, -20 );
		$darker_30 = $this->adjust_color_brightness( $highlight_color, -30 );
		$lighter_10 = $this->adjust_color_brightness( $highlight_color, 10 );
		$lighter_20 = $this->adjust_color_brightness( $highlight_color, 20 );

		// Generuj CSS z zmiennymi
		$css = sprintf(
			':root {
				--wp-admin-theme-color: %s;
				--wp-admin-theme-color-darker-10: %s;
				--wp-admin-theme-color-darker-20: %s;
				--wp-admin-theme-color-darker-30: %s;
				--wp-admin-theme-color-lighter-10: %s;
				--wp-admin-theme-color-lighter-20: %s;
				--wp-admin-base-color: %s;
				--wp-admin-link-color: %s;
				--wp-admin-link-focus-color: %s;
			}',
			esc_attr( $highlight_color ),
			esc_attr( $darker_10 ),
			esc_attr( $darker_20 ),
			esc_attr( $darker_30 ),
			esc_attr( $lighter_10 ),
			esc_attr( $lighter_20 ),
			esc_attr( $base_color ),
			esc_attr( $link_color ),
			esc_attr( $link_focus )
		);

		wp_add_inline_style( 'kasumi-ai-admin', $css );
	}

	/**
	 * Dostosowuje jasność koloru hex.
	 *
	 * @param string $hex_color Kolor w formacie hex (np. #0073aa).
	 * @param int    $percent   Procent zmiany jasności (-100 do 100).
	 * @return string Kolor w formacie hex.
	 */
	private function adjust_color_brightness( string $hex_color, int $percent ): string {
		$hex_color = ltrim( $hex_color, '#' );

		if ( strlen( $hex_color ) === 3 ) {
			$hex_color = $hex_color[0] . $hex_color[0] . $hex_color[1] . $hex_color[1] . $hex_color[2] . $hex_color[2];
		}

		$r = hexdec( substr( $hex_color, 0, 2 ) );
		$g = hexdec( substr( $hex_color, 2, 2 ) );
		$b = hexdec( substr( $hex_color, 4, 2 ) );

		$r = max( 0, min( 255, $r + ( $r * $percent / 100 ) ) );
		$g = max( 0, min( 255, $g + ( $g * $percent / 100 ) ) );
		$b = max( 0, min( 255, $b + ( $b * $percent / 100 ) ) );

		return '#' . str_pad( dechex( (int) $r ), 2, '0', STR_PAD_LEFT ) .
			str_pad( dechex( (int) $g ), 2, '0', STR_PAD_LEFT ) .
			str_pad( dechex( (int) $b ), 2, '0', STR_PAD_LEFT );
	}

	private function register_api_section(): void {
		$section = 'kasumi_ai_api';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-key"></i> ' . __( 'Klucze API', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Dodaj klucze OpenAI i Pixabay wykorzystywane do generowania treści i grafik.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		// Dostawca AI - PIERWSZE POLE
		$this->add_field(
			'ai_provider',
			__( 'Dostawca AI', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'openai' => __( 'Tylko OpenAI', 'kasumi-full-ai-content-generator' ),
					'gemini' => __( 'Tylko Google Gemini', 'kasumi-full-ai-content-generator' ),
					'auto'   => __( 'Automatyczny (OpenAI → Gemini)', 'kasumi-full-ai-content-generator' ),
				),
				'description' => __( 'W trybie automatycznym system próbuje najpierw OpenAI, a w razie braku odpowiedzi przełącza się na Gemini.', 'kasumi-full-ai-content-generator' ),
				'class'       => 'kasumi-provider-selector',
			)
		);

		// Pola OpenAI - pokazywane gdy wybrano OpenAI lub Auto
		$this->add_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'password',
				'placeholder' => 'sk-***',
				'description' => sprintf(
					/* translators: %s is a link to the OpenAI dashboard. */
					__( 'Pobierz klucz w %s.', 'kasumi-full-ai-content-generator' ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( 'https://platform.openai.com/account/api-keys' ),
						esc_html__( 'panelu OpenAI', 'kasumi-full-ai-content-generator' )
					)
				),
				'help'        => __( 'Umożliwia korzystanie z modeli GPT-4.1 / GPT-4o.', 'kasumi-full-ai-content-generator' ),
				'class'       => 'kasumi-openai-fields',
			)
		);

		$this->add_field(
			'openai_model',
			__( 'Model OpenAI', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'      => 'model-select',
				'provider'  => 'openai',
				'help'      => __( 'Lista modeli z konta OpenAI (np. GPT-4.1, GPT-4o).', 'kasumi-full-ai-content-generator' ),
				'class'     => 'kasumi-openai-fields',
			)
		);

		// Pola Gemini - pokazywane gdy wybrano Gemini lub Auto
		$this->add_field(
			'gemini_api_key',
			__( 'Gemini API Key', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'password',
				'placeholder' => 'AIza***',
				'description' => sprintf(
					/* translators: %s is a link to the Google AI Studio page. */
					__( 'Wygeneruj klucz w %s.', 'kasumi-full-ai-content-generator' ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( 'https://aistudio.google.com/app/apikey' ),
						esc_html__( 'Google AI Studio', 'kasumi-full-ai-content-generator' )
					)
				),
				'help'        => __( 'Obsługuje modele Gemini 2.x flash/pro.', 'kasumi-full-ai-content-generator' ),
				'class'       => 'kasumi-gemini-fields',
			)
		);

		$this->add_field(
			'system_prompt',
			__( 'System prompt', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'textarea',
				'description' => __( 'Instrukcje przekazywane jako system prompt dla modeli OpenAI i Gemini.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'gemini_model',
			__( 'Model Gemini', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'      => 'model-select',
				'provider'  => 'gemini',
				'description' => __( 'Wybierz model z Google Gemini (flash, pro, image).', 'kasumi-full-ai-content-generator' ),
				'help'        => __( 'Lista pobierana jest bezpośrednio z API na podstawie klucza.', 'kasumi-full-ai-content-generator' ),
				'class'     => 'kasumi-gemini-fields',
			)
		);

		// Pixabay API Key - NA KOŃCU
		$this->add_field(
			'pixabay_api_key',
			__( 'Pixabay API Key', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'placeholder' => '12345678-abcdef...',
				'description' => __( 'Klucz API Pixabay używany do pobierania obrazów w trybie serwerowym.', 'kasumi-full-ai-content-generator' ),
			)
		);
	}

	private function register_content_section(): void {
		$section = 'kasumi_ai_content';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-file-earmark-text"></i> ' . __( 'Konfiguracja treści', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Ogólne ustawienia generowania wpisów, kategorii i harmonogramu.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'topic_strategy',
			__( 'Strategia tematów', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'description' => __( 'Krótka instrukcja na temat tematów artykułów.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'target_category',
			__( 'Kategoria docelowa', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'category-select',
				'description' => __( 'Wybierz kategorię, która ma otrzymywać nowe treści.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'default_post_status',
			__( 'Status wpisów', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'draft'   => __( 'Szkic', 'kasumi-full-ai-content-generator' ),
					'publish' => __( 'Publikuj automatycznie', 'kasumi-full-ai-content-generator' ),
				),
				'description' => __( 'Określ czy wpis ma być szkicem czy publikacją.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'schedule_interval_hours',
			__( 'Interwał generowania (h)', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'number',
				'min'         => 72,
				'description' => __( 'Wpisz docelową liczbę godzin (min. 72). System losuje publikację w przedziale 3‑7 dni i dopasuje ją do najlepszych godzin (np. 9:00, 11:30).', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'word_count_min',
			__( 'Min. liczba słów', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 200,
			)
		);

		$this->add_field(
			'word_count_max',
			__( 'Maks. liczba słów', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 200,
			)
		);

		$this->add_field(
			'link_keywords',
			__( 'Słowa kluczowe do linkowania', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'description' => __( 'Lista słów rozdzielona przecinkami wykorzystywana przy linkach wewnętrznych.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'enable_internal_linking',
			__( 'Włącz linkowanie wewnętrzne', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);
	}

	private function register_image_section(): void {
		$section = 'kasumi_ai_images';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-image"></i> ' . __( 'Grafiki wyróżniające', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Parametry zdjęć Pixabay i nadpisów tworzonych przez Imagick.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'enable_featured_images',
			__( 'Generuj grafiki wyróżniające', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'image_generation_mode',
			__( 'Tryb generowania grafik', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'server' => __( 'Serwerowy (Pixabay + nakładka)', 'kasumi-full-ai-content-generator' ),
					'remote' => __( 'Zdalne (API AI)', 'kasumi-full-ai-content-generator' ),
				),
				'description' => __( 'W trybie serwerowym obrazy pochodzą z Pixabay i są modyfikowane lokalnie. Tryb zdalny generuje obraz przez API AI (OpenAI lub Gemini).', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'image_remote_provider',
			__( 'Provider obrazów zdalnych', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'openai' => __( 'OpenAI (DALL-E)', 'kasumi-full-ai-content-generator' ),
					'gemini' => __( 'Gemini (Imagen)', 'kasumi-full-ai-content-generator' ),
				),
				'description' => __( 'Używane tylko, gdy wybrano tryb zdalny. Wybierz provider do generowania obrazów przez API.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'image_server_engine',
			__( 'Silnik serwerowy', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'select',
				'choices'     => array(
					'imagick' => __( 'Imagick (zalecany)', 'kasumi-full-ai-content-generator' ),
					'gd'      => __( 'Biblioteka GD', 'kasumi-full-ai-content-generator' ),
				),
				'description' => __( 'Używane tylko, gdy wybrano tryb serwerowy. Wybierz bibliotekę dostępna na Twoim hostingu.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'image_template',
			__( 'Szablon grafiki', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'description' => __( 'Możesz odwołać się do {{title}} i {{summary}}.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'image_overlay_color',
			__( 'Kolor nakładki (HEX)', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'color-picker',
			)
		);

		$this->add_field(
			'pixabay_query',
			__( 'Słowa kluczowe Pixabay', 'kasumi-full-ai-content-generator' ),
			$section
		);

		$this->add_field(
			'pixabay_orientation',
			__( 'Orientacja Pixabay', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'horizontal' => __( 'Pozioma', 'kasumi-full-ai-content-generator' ),
					'vertical'   => __( 'Pionowa', 'kasumi-full-ai-content-generator' ),
				),
			)
		);
	}

	private function register_comments_section(): void {
		$section = 'kasumi_ai_comments';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-chat-left-text"></i> ' . __( 'Komentarze AI', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Steruj liczbą i częstotliwością komentarzy generowanych przez AI.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'comments_enabled',
			__( 'Generuj komentarze', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'comment_frequency',
			__( 'Częstotliwość', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'dense'  => __( 'Intensywnie po publikacji', 'kasumi-full-ai-content-generator' ),
					'normal' => __( 'Stałe tempo', 'kasumi-full-ai-content-generator' ),
					'slow'   => __( 'Sporadyczne komentarze', 'kasumi-full-ai-content-generator' ),
				),
			)
		);

		$this->add_field(
			'comment_min',
			__( 'Minimalna liczba komentarzy', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 1,
			)
		);

		$this->add_field(
			'comment_max',
			__( 'Maksymalna liczba komentarzy', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'number',
				'min'  => 1,
			)
		);

		$this->add_field(
			'comment_status',
			__( 'Status komentarzy', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'    => 'select',
				'choices' => array(
					'approve' => __( 'Zatwierdzone', 'kasumi-full-ai-content-generator' ),
					'hold'    => __( 'Oczekujące', 'kasumi-full-ai-content-generator' ),
				),
			)
		);

		$this->add_field(
			'comment_author_prefix',
			__( 'Prefiks pseudonimu', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'description' => __( 'Opcjonalne. Gdy puste, AI generuje dowolne pseudonimy (np. mix PL/EN).', 'kasumi-full-ai-content-generator' ),
			)
		);
	}

	private function register_misc_section(): void {
		$section = 'kasumi_ai_misc';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-gear"></i> ' . __( 'Pozostałe', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Logowanie, tryb podglądu oraz powiadomienia.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		$this->add_field(
			'plugin_enabled',
			__( 'Włącz wtyczkę', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'checkbox',
				'description' => __( 'Wyłączenie wstrzymuje wszystkie automatyczne zadania (generowanie postów, komentarzy, harmonogramów).', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'enable_logging',
			__( 'Włącz logowanie zdarzeń', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'status_logging',
			__( 'Pokaż status na stronie', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type' => 'checkbox',
			)
		);

		$this->add_field(
			'preview_mode',
			__( 'Tryb podglądu (bez publikacji)', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'checkbox',
				'description' => __( 'W tym trybie AI generuje treści tylko do logów.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'debug_email',
			__( 'E-mail raportowy', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'email',
				'description' => __( 'Adres otrzymujący krytyczne błędy modułu.', 'kasumi-full-ai-content-generator' ),
			)
		);

		$this->add_field(
			'delete_tables_on_deactivation',
			__( 'Usuń tabele przy deaktywacji', 'kasumi-full-ai-content-generator' ),
			$section,
			array(
				'type'        => 'checkbox',
				'description' => __( 'UWAGA: Po deaktywacji wtyczki wszystkie dane harmonogramów zostaną trwale usunięte!', 'kasumi-full-ai-content-generator' ),
			)
		);

		// Dodaj przyciski import/export/reset
		add_settings_field(
			'kasumi_ai_settings_actions',
			__( 'Zarządzanie ustawieniami', 'kasumi-full-ai-content-generator' ),
			function (): void {
				$this->render_settings_actions();
			},
			self::PAGE_SLUG,
			$section
		);
	}

	private function register_diagnostics_section(): void {
		$section = 'kasumi_ai_diag';

		add_settings_section(
			$section,
			wp_kses_post( '<i class="bi bi-bug"></i> ' . __( 'Diagnostyka środowiska', 'kasumi-full-ai-content-generator' ) ),
			function (): void {
				printf(
					'<p><i class="bi bi-info-circle"></i> %s</p>',
					esc_html__( 'Sprawdź czy serwer spełnia wymagania wtyczki.', 'kasumi-full-ai-content-generator' )
				);
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'kasumi_ai_diag_report',
			__( 'Status serwera', 'kasumi-full-ai-content-generator' ),
			function (): void {
				$this->render_diagnostics();
			},
			self::PAGE_SLUG,
			$section
		);

		add_settings_field(
			'kasumi_ai_logs',
			__( 'Logi wtyczki', 'kasumi-full-ai-content-generator' ),
			function (): void {
				$this->render_logs_section();
			},
			self::PAGE_SLUG,
			$section
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id              = get_current_user_id();
		$support_hidden_until = (int) get_user_meta( $user_id, self::SUPPORT_DISMISS_META, true );
		$show_support_card    = $support_hidden_until <= time();
		$install_time         = (int) get_option( 'kasumi_ai_install_time', time() );
		$days_using           = max( 1, (int) floor( ( time() - $install_time ) / DAY_IN_SECONDS ) );

		?>
		<div class="wrap">
			<h1><i class="bi bi-robot"></i> <?php esc_html_e( 'Kasumi AI – konfiguracja', 'kasumi-full-ai-content-generator' ); ?></h1>
			<p class="description"><i class="bi bi-sliders"></i> <?php esc_html_e( 'Steruj integracjami API, harmonogramem generowania treści, komentarzy oraz grafik.', 'kasumi-full-ai-content-generator' ); ?></p>
			<?php if ( $show_support_card ) : ?>
				<div class="kasumi-support-card">
					<div class="kasumi-support-card__text">
						<p class="description" style="margin-top:0;"><?php esc_html_e( 'Kasumi rozwijam po godzinach – jeśli automatyzacja oszczędza Ci czas, możesz postawić mi symboliczną kawę.', 'kasumi-full-ai-content-generator' ); ?></p>
						<h2 style="margin:8px 0 12px;"><?php esc_html_e( 'Postaw kawę twórcy Kasumi', 'kasumi-full-ai-content-generator' ); ?></h2>
						<p style="margin:0;color:var(--wp-admin-text-color-dark);"><?php esc_html_e( 'Wspierasz koszty API, serwera i rozwój nowych modułów (bez reklam i paywalla).', 'kasumi-full-ai-content-generator' ); ?></p>
					</div>
					<div class="kasumi-support-card__actions">
						<form class="kasumi-support-card__dismiss" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'kasumi_ai_support_card' ); ?>
							<input type="hidden" name="action" value="kasumi_ai_support_card">
							<input type="hidden" name="kasumi_ai_support_action" value="dismiss">
							<button type="submit" class="button-link button-link-delete"><i class="bi bi-eye-slash"></i> <?php esc_html_e( 'Ukryj na 7 dni', 'kasumi-full-ai-content-generator' ); ?></button>
						</form>
						<p style="font-weight:600;margin-bottom:12px;"><i class="bi bi-heart-fill" style="color: var(--wp-admin-notification-color);"></i> <?php esc_html_e( 'Dziękuję za każdą kawę!', 'kasumi-full-ai-content-generator' ); ?></p>
						<div class="kasumi-support-card__button">
							<a class="button button-primary" href="https://buymeacoffee.com/kemuricodes" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Postaw mi kawę', 'kasumi-full-ai-content-generator' ); ?></a>
						</div>
						<p style="margin-top:12px;font-size:12px;color:var(--wp-admin-text-color);opacity:0.8;"><?php esc_html_e( 'Obsługiwane przez buymeacoffee.com', 'kasumi-full-ai-content-generator' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="kasumi-support-reminder">
					<p style="margin:0;">
						<?php
						printf(
							wp_kses_post(
								__( 'Korzystasz z Kasumi od %1$s dni. Jeśli narzędzie Ci pomaga, możesz zawsze %2$s.', 'kasumi-full-ai-content-generator' )
							),
							number_format_i18n( $days_using ),
							sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								esc_url( 'https://buymeacoffee.com/kemuricodes' ),
								esc_html__( 'postawić kawę', 'kasumi-full-ai-content-generator' )
							)
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'kasumi_ai_support_card' ); ?>
						<input type="hidden" name="action" value="kasumi_ai_support_card">
						<input type="hidden" name="kasumi_ai_support_action" value="reset">
						<button type="submit" class="button button-secondary"><i class="bi bi-eye"></i> <?php esc_html_e( 'Pokaż ponownie kartę wsparcia', 'kasumi-full-ai-content-generator' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<div class="kasumi-overview-grid">
				<div class="card kasumi-about">
					<h2><i class="bi bi-info-circle"></i> <?php esc_html_e( 'O wtyczce', 'kasumi-full-ai-content-generator' ); ?></h2>
					<p><?php esc_html_e( 'Kasumi automatyzuje generowanie wpisów WordPress, komentarzy i grafik AI. Wybierz dostawcę (OpenAI lub Gemini), skonfiguruj harmonogram i podglądaj efekty na żywo.', 'kasumi-full-ai-content-generator' ); ?></p>
					<ul>
						<li><i class="bi bi-person"></i> <?php esc_html_e( 'Autor: Marcin Dymek (KemuriCodes)', 'kasumi-full-ai-content-generator' ); ?></li>
						<li><i class="bi bi-envelope"></i> <?php esc_html_e( 'Kontakt: contact@kemuri.codes', 'kasumi-full-ai-content-generator' ); ?></li>
					</ul>
				</div>
				<div class="card kasumi-info-card">
					<h2><i class="bi bi-link-45deg"></i> <?php esc_html_e( 'Szybkie linki', 'kasumi-full-ai-content-generator' ); ?></h2>
					<ul>
						<li><i class="bi bi-box-arrow-up-right"></i> <a href="<?php echo esc_url( 'https://platform.openai.com/account/api-keys' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Panel OpenAI', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><i class="bi bi-box-arrow-up-right"></i> <a href="<?php echo esc_url( 'https://aistudio.google.com/app/apikey' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google AI Studio', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><i class="bi bi-envelope"></i> <a href="mailto:contact@kemuri.codes"><?php esc_html_e( 'Wsparcie KemuriCodes', 'kasumi-full-ai-content-generator' ); ?></a></li>
					</ul>
				</div>
				<?php if ( Options::get( 'status_logging' ) ) : ?>
					<?php
					$status      = StatusStore::all();
					$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
					$now         = current_time( 'timestamp' );
					$next_run    = $status['next_post_run']
						? sprintf(
							'%s (%s)',
							date_i18n( $date_format, (int) $status['next_post_run'] ),
							sprintf(
								/* translators: %s – relative time */
								__( 'za %s', 'kasumi-full-ai-content-generator' ),
								human_time_diff( $now, (int) $status['next_post_run'] )
							)
						)
						: __( 'Brak zaplanowanych zadań', 'kasumi-full-ai-content-generator' );
					$last_error  = $status['last_error']
						? $status['last_error']
						: __( 'Brak błędów', 'kasumi-full-ai-content-generator' );
					?>
					<div class="card kasumi-ai-status">
						<h2><i class="bi bi-activity"></i> <?php esc_html_e( 'Status modułu AI', 'kasumi-full-ai-content-generator' ); ?></h2>
						<ul>
							<li><i class="bi bi-file-text"></i> <?php esc_html_e( 'Ostatni post ID:', 'kasumi-full-ai-content-generator' ); ?> <strong><?php echo esc_html( (string) ( $status['last_post_id'] ?? '–' ) ); ?></strong></li>
							<li><i class="bi bi-clock-history"></i> <?php esc_html_e( 'Ostatnie uruchomienie:', 'kasumi-full-ai-content-generator' ); ?> <strong><?php echo $status['last_post_time'] ? esc_html( date_i18n( $date_format, (int) $status['last_post_time'] ) ) : esc_html__( 'Brak', 'kasumi-full-ai-content-generator' ); ?></strong></li>
							<li><i class="bi bi-calendar-event"></i> <?php esc_html_e( 'Następne zadanie:', 'kasumi-full-ai-content-generator' ); ?> <strong><?php echo esc_html( $next_run ); ?></strong></li>
							<li><i class="bi bi-chat-dots"></i> <?php esc_html_e( 'Kolejka komentarzy:', 'kasumi-full-ai-content-generator' ); ?> <strong><?php echo esc_html( (string) ( $status['queued_comment_jobs'] ?? 0 ) ); ?></strong></li>
							<li><i class="bi bi-exclamation-triangle"></i> <?php esc_html_e( 'Ostatni błąd:', 'kasumi-full-ai-content-generator' ); ?> <strong><?php echo esc_html( $last_error ); ?></strong></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php settings_fields( Options::OPTION_GROUP ); ?>
				<div id="kasumi-ai-tabs" class="kasumi-ai-tabs">
					<ul>
						<li><a href="#kasumi-tab-api"><i class="bi bi-key"></i> <?php esc_html_e( 'Integracje API', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-content"><i class="bi bi-file-earmark-text"></i> <?php esc_html_e( 'Treści i harmonogram', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-images"><i class="bi bi-image"></i> <?php esc_html_e( 'Grafiki AI', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-comments"><i class="bi bi-chat-left-text"></i> <?php esc_html_e( 'Komentarze AI', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-stats"><i class="bi bi-bar-chart"></i> <?php esc_html_e( 'Statystyki', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-advanced"><i class="bi bi-gear"></i> <?php esc_html_e( 'Zaawansowane', 'kasumi-full-ai-content-generator' ); ?></a></li>
						<li><a href="#kasumi-tab-diagnostics"><i class="bi bi-bug"></i> <?php esc_html_e( 'Diagnostyka', 'kasumi-full-ai-content-generator' ); ?></a></li>
					</ul>
					<div id="kasumi-tab-api" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_api' ); ?>
					</div>
					<div id="kasumi-tab-content" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_content' ); ?>
						<?php $this->render_schedule_manager_panel(); ?>
					</div>
					<div id="kasumi-tab-images" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_images' ); ?>
					</div>
					<div id="kasumi-tab-comments" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_comments' ); ?>
					</div>
					<div id="kasumi-tab-stats" class="kasumi-tab-panel">
						<?php $this->render_stats_tab(); ?>
					</div>
					<div id="kasumi-tab-advanced" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_misc' ); ?>
					</div>
					<div id="kasumi-tab-diagnostics" class="kasumi-tab-panel">
						<?php $this->render_section( 'kasumi_ai_diag' ); ?>
					</div>
				</div>
				<?php submit_button(); ?>
			</form>

			<details class="kasumi-preview-details">
				<summary><i class="bi bi-eye"></i> <?php esc_html_e( 'Podgląd wygenerowanej treści i grafiki', 'kasumi-full-ai-content-generator' ); ?></summary>
				<div class="card kasumi-ai-preview-box">
					<p><i class="bi bi-info-circle"></i> <?php esc_html_e( 'Wygeneruj przykładowy tekst lub obrazek, aby przetestować konfigurację bez publikacji.', 'kasumi-full-ai-content-generator' ); ?></p>
					<div class="kasumi-ai-preview-actions">
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-text"><i class="bi bi-file-text"></i> <?php esc_html_e( 'Przykładowy tekst', 'kasumi-full-ai-content-generator' ); ?></button>
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-image"><i class="bi bi-image"></i> <?php esc_html_e( 'Podgląd grafiki', 'kasumi-full-ai-content-generator' ); ?></button>
					</div>
					<div id="kasumi-ai-preview-output" class="kasumi-ai-preview-output" aria-live="polite"></div>
				</div>
			</details>
		</div>
		<?php
	}

	private function add_field( string $key, string $label, string $section, array $args = array() ): void {
		$defaults = array(
			'type'        => 'text',
			'description' => '',
			'choices'     => array(),
			'min'         => null,
			'placeholder' => '',
			'help'        => '',
		);

		$args = wp_parse_args( $args, $defaults );

		if ( ! empty( $args['help'] ) ) {
			$label .= sprintf(
				' <button type="button" class="kasumi-help dashicons dashicons-editor-help" data-kasumi-tooltip="%s" aria-label="%s"></button>',
				esc_attr( $args['help'] ),
				esc_attr( wp_strip_all_tags( $label ) )
			);
		}

		// Przechowaj klasę w args dla późniejszego użycia w render_section
		$field_id = 'kasumi_ai_' . $key;
		
		add_settings_field(
			$field_id,
			wp_kses_post( $label ),
			function () use ( $key, $args ): void {
				$this->render_field( $key, $args );
			},
			self::PAGE_SLUG,
			$section,
			$args // Przekaż args jako szósty parametr
		);
	}

	private function render_field( string $key, array $args ): void {
		$value = Options::get( $key );
		$type  = $args['type'];

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea name="%1$s[%2$s]" rows="3" class="large-text">%3$s</textarea>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_textarea( (string) $value )
				);
				break;
			case 'select':
				printf(
					'<select name="%1$s[%2$s]">',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key )
				);

				foreach ( $args['choices'] as $option_value => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $label )
					);
				}

				echo '</select>';
				break;
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					checked( ! empty( $value ), true, false ),
					esc_html__( 'Aktywne', 'kasumi-full-ai-content-generator' )
				);
				break;
			case 'model-select':
				$provider = $args['provider'] ?? 'openai';
				$current  = (string) $value;
				echo '<div class="kasumi-model-control" data-provider="' . esc_attr( $provider ) . '" data-autoload="1">';
				printf(
					'<select name="%1$s[%2$s]" data-kasumi-model="%3$s" data-current-value="%4$s" class="regular-text">',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $provider ),
					esc_attr( $current )
				);
				if ( $current ) {
					printf( '<option value="%1$s">%1$s</option>', esc_html( $current ) );
				} else {
					echo '<option value="">' . esc_html__( 'Wybierz model…', 'kasumi-full-ai-content-generator' ) . '</option>';
				}
				echo '</select>';
				printf(
					'<button type="button" class="button kasumi-refresh-models" data-provider="%s"><i class="bi bi-arrow-clockwise"></i> %s</button>',
					esc_attr( $provider ),
					esc_html__( 'Odśwież listę', 'kasumi-full-ai-content-generator' )
				);
				echo '<span class="spinner kasumi-model-spinner" aria-hidden="true"></span>';
				echo '</div>';
				break;
			case 'category-select':
				$categories = get_categories( array(
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				) );
				printf(
					'<select name="%1$s[%2$s]" class="regular-text">',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key )
				);
				echo '<option value="">' . esc_html__( '— Wybierz kategorię —', 'kasumi-full-ai-content-generator' ) . '</option>';
				foreach ( $categories as $category ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $category->term_id ),
						selected( $value, (string) $category->term_id, false ),
						esc_html( $category->name )
					);
				}
				echo '</select>';
				break;
			case 'color-picker':
				$color_value = (string) $value;
				// WordPress color picker wymaga # na początku
				if ( ! empty( $color_value ) && '#' !== substr( $color_value, 0, 1 ) ) {
					$color_value = '#' . $color_value;
				}
				$field_id = 'kasumi_ai_' . $key;
				printf(
					'<input type="text" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="wp-color-picker-field" data-default-color="%5$s" />',
					esc_attr( $field_id ),
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $color_value ),
					esc_attr( $color_value ?: '#1b1f3b' )
				);
				break;
			default:
				printf(
					'<input type="%5$s" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" %6$s>',
					esc_attr( Options::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( (string) $value ),
					esc_attr( (string) $args['placeholder'] ),
					esc_attr( $type ),
					null !== $args['min'] ? 'min="' . esc_attr( (string) $args['min'] ) . '"' : ''
				);
		}

		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses_post( $args['description'] )
			);
		}
	}

	/**
	 * Renderuje pojedynczą sekcję Settings API.
	 *
	 * @param string $section_id Section identifier.
	 */
	private function render_section( string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( empty( $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ];

		if ( ! empty( $section['title'] ) ) {
			printf( '<h2>%s</h2>', wp_kses_post( $section['title'] ) );
		}

		if ( ! empty( $section['callback'] ) ) {
			call_user_func( $section['callback'], $section );
		}

		if ( empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		echo '<table class="form-table" role="presentation">';

		foreach ( (array) $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] as $field ) {
			$field_class = ! empty( $field['args']['class'] ) ? esc_attr( $field['args']['class'] ) : '';
			printf( '<tr%s>', $field_class ? ' class="' . $field_class . '"' : '' );
			echo '<th scope="row">';
			if ( ! empty( $field['args']['label_for'] ) ) {
				echo '<label for="' . esc_attr( $field['args']['label_for'] ) . '">' . wp_kses_post( $field['title'] ) . '</label>';
			} else {
				echo wp_kses_post( $field['title'] );
			}
			echo '</th><td>';
			call_user_func( $field['callback'], $field['args'] );
			echo '</td></tr>';
		}

		echo '</table>';
	}

	private function render_schedule_manager_panel(): void {
		?>
		<div class="kasumi-schedule-panel">
			<h3><i class="bi bi-calendar-check"></i> <?php esc_html_e( 'Planowanie wpisów i harmonogram', 'kasumi-full-ai-content-generator' ); ?></h3>
			<p class="description"><i class="bi bi-info-circle"></i> <?php esc_html_e( 'Twórz własne zadania – wybierz autora, typ wpisu, status i dokładną datę publikacji. Kasumi wygeneruje treść w wybranym momencie.', 'kasumi-full-ai-content-generator' ); ?></p>
			<div id="kasumi-schedule-manager" class="kasumi-schedule-grid">
				<div class="kasumi-schedule-form-column">
					<div data-kasumi-schedule-alert class="notice notice-success" style="display:none;"></div>
					<form data-kasumi-schedule-form>
						<div class="kasumi-field">
							<label for="kasumi-schedule-title"><?php esc_html_e( 'Tytuł roboczy', 'kasumi-full-ai-content-generator' ); ?></label>
							<input type="text" id="kasumi-schedule-title" name="postTitle" class="regular-text" placeholder="<?php esc_attr_e( 'np. Strategie QR kodów na eventach', 'kasumi-full-ai-content-generator' ); ?>">
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-status"><?php esc_html_e( 'Status zadania', 'kasumi-full-ai-content-generator' ); ?></label>
							<select id="kasumi-schedule-status" name="status">
								<option value="draft"><?php esc_html_e( 'Szkic (bez daty)', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="scheduled"><?php esc_html_e( 'Zaplanowane', 'kasumi-full-ai-content-generator' ); ?></option>
							</select>
						</div>
						<div class="kasumi-field-grid">
							<div>
								<label for="kasumi-schedule-post-type"><?php esc_html_e( 'Typ wpisu', 'kasumi-full-ai-content-generator' ); ?></label>
								<select id="kasumi-schedule-post-type" name="postType"></select>
							</div>
							<div>
								<label for="kasumi-schedule-post-status"><?php esc_html_e( 'Status WordPress', 'kasumi-full-ai-content-generator' ); ?></label>
								<select id="kasumi-schedule-post-status" name="postStatus">
									<option value="draft"><?php esc_html_e( 'Szkic', 'kasumi-full-ai-content-generator' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publikuj automatycznie', 'kasumi-full-ai-content-generator' ); ?></option>
								</select>
							</div>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-author"><?php esc_html_e( 'Autor wpisu', 'kasumi-full-ai-content-generator' ); ?></label>
							<select id="kasumi-schedule-author" name="authorId" data-placeholder="<?php esc_attr_e( '— Wybierz autora —', 'kasumi-full-ai-content-generator' ); ?>"></select>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-date"><?php esc_html_e( 'Data publikacji', 'kasumi-full-ai-content-generator' ); ?></label>
							<input type="datetime-local" id="kasumi-schedule-date" name="publishAt">
							<p class="description"><?php esc_html_e( 'Wymagane, gdy status ustawisz na „Zaplanowane”. Czas zostanie zapisany w strefie WordPress.', 'kasumi-full-ai-content-generator' ); ?></p>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-model"><?php esc_html_e( 'Model AI (opcjonalnie)', 'kasumi-full-ai-content-generator' ); ?></label>
							<select id="kasumi-schedule-model" name="model" data-placeholder="<?php esc_attr_e( 'Auto (globalny)', 'kasumi-full-ai-content-generator' ); ?>"></select>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-system"><?php esc_html_e( 'System prompt (opcjonalnie)', 'kasumi-full-ai-content-generator' ); ?></label>
							<textarea id="kasumi-schedule-system" name="systemPrompt" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Pozostaw pusty aby użyć globalnego ustawienia.', 'kasumi-full-ai-content-generator' ); ?>"></textarea>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-user"><?php esc_html_e( 'Polecenie dla AI', 'kasumi-full-ai-content-generator' ); ?></label>
							<textarea id="kasumi-schedule-user" name="userPrompt" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Opisz temat, słowa kluczowe, ton wypowiedzi itd.', 'kasumi-full-ai-content-generator' ); ?>"></textarea>
						</div>
						<div class="kasumi-field kasumi-actions">
							<button type="submit" class="button button-primary" data-kasumi-schedule-submit><i class="bi bi-save"></i> <?php esc_html_e( 'Zapisz zadanie', 'kasumi-full-ai-content-generator' ); ?></button>
							<button type="button" class="button" data-kasumi-reset-form><i class="bi bi-x-circle"></i> <?php esc_html_e( 'Wyczyść formularz', 'kasumi-full-ai-content-generator' ); ?></button>
						</div>
					</form>
				</div>
				<div class="kasumi-schedule-list-column">
					<div class="kasumi-schedule-toolbar">
						<label>
							<span><?php esc_html_e( 'Status', 'kasumi-full-ai-content-generator' ); ?></span>
							<select data-kasumi-filter="status">
								<option value=""><?php esc_html_e( 'Wszystkie', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="draft"><?php esc_html_e( 'Szkice', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="scheduled"><?php esc_html_e( 'Zaplanowane', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="running"><?php esc_html_e( 'W trakcie', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Wykonane', 'kasumi-full-ai-content-generator' ); ?></option>
								<option value="failed"><?php esc_html_e( 'Błędy', 'kasumi-full-ai-content-generator' ); ?></option>
							</select>
						</label>
						<label>
							<span><?php esc_html_e( 'Autor', 'kasumi-full-ai-content-generator' ); ?></span>
							<select data-kasumi-filter="author" data-placeholder="<?php esc_attr_e( 'Wszyscy', 'kasumi-full-ai-content-generator' ); ?>">
								<option value=""><?php esc_html_e( 'Wszyscy', 'kasumi-full-ai-content-generator' ); ?></option>
							</select>
						</label>
						<label class="kasumi-search-field">
							<span class="screen-reader-text"><?php esc_html_e( 'Szukaj', 'kasumi-full-ai-content-generator' ); ?></span>
							<input type="search" placeholder="<?php esc_attr_e( 'Szukaj po tytule/poleceniu…', 'kasumi-full-ai-content-generator' ); ?>" data-kasumi-filter="search">
						</label>
						<button type="button" class="button" data-kasumi-refresh><i class="bi bi-arrow-clockwise"></i> <?php esc_html_e( 'Odśwież', 'kasumi-full-ai-content-generator' ); ?></button>
					</div>
					<div data-kasumi-schedule-table class="kasumi-schedule-table">
						<p class="description"><?php esc_html_e( 'Brak zadań w kolejce.', 'kasumi-full-ai-content-generator' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_scheduler_settings(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'kasumi/v1/schedules' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'postTypes' => $this->get_scheduler_post_types(),
			'authors'   => $this->get_scheduler_authors(),
			'defaults'  => array(
				'status'       => 'scheduled',
				'postStatus'   => (string) Options::get( 'default_post_status', 'draft' ),
				'systemPrompt' => (string) Options::get( 'system_prompt', '' ),
			),
			'i18n'      => array(
				'save'        => __( 'Zapisano zadanie.', 'kasumi-full-ai-content-generator' ),
				'updated'     => __( 'Zaktualizowano zadanie.', 'kasumi-full-ai-content-generator' ),
				'deleted'     => __( 'Usunięto zadanie.', 'kasumi-full-ai-content-generator' ),
				'run'         => __( 'Uruchomiono generowanie.', 'kasumi-full-ai-content-generator' ),
				'error'       => __( 'Coś poszło nie tak. Sprawdź logi i spróbuj ponownie.', 'kasumi-full-ai-content-generator' ),
				'loading'     => __( 'Wczytywanie…', 'kasumi-full-ai-content-generator' ),
				'empty'       => __( 'Brak zaplanowanych zadań.', 'kasumi-full-ai-content-generator' ),
				'noDate'      => __( 'Brak daty', 'kasumi-full-ai-content-generator' ),
				'deleteConfirm' => __( 'Czy na pewno usunąć to zadanie?', 'kasumi-full-ai-content-generator' ),
				'edit'        => __( 'Edytuj', 'kasumi-full-ai-content-generator' ),
				'runAction'   => __( 'Uruchom teraz', 'kasumi-full-ai-content-generator' ),
				'delete'      => __( 'Usuń', 'kasumi-full-ai-content-generator' ),
				'taskLabel'   => __( 'Zadanie', 'kasumi-full-ai-content-generator' ),
				'statusLabel' => __( 'Status', 'kasumi-full-ai-content-generator' ),
				'publishLabel'=> __( 'Publikacja', 'kasumi-full-ai-content-generator' ),
				'statusMap'   => array(
					'draft'     => __( 'Szkic', 'kasumi-full-ai-content-generator' ),
					'scheduled' => __( 'Zaplanowane', 'kasumi-full-ai-content-generator' ),
					'running'   => __( 'W trakcie', 'kasumi-full-ai-content-generator' ),
					'completed' => __( 'Zakończone', 'kasumi-full-ai-content-generator' ),
					'failed'    => __( 'Błąd', 'kasumi-full-ai-content-generator' ),
				),
			),
			'models'    => $this->get_scheduler_models(),
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function get_scheduler_post_types(): array {
		$post_types = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
			$label         = $object->labels->singular_name ?? $name;
			$post_types[] = array(
				'value' => $name,
				'label' => $label,
			);
		}

		return $post_types;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function get_scheduler_authors(): array {
		$list = array();

		foreach ( get_users(
			array(
				'capability__in' => array( 'edit_posts' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_login' ),
			)
		) as $user ) {
			$list[] = array(
				'id'   => (string) $user->ID,
				'name' => $user->display_name ?: $user->user_login,
			);
		}

		return $list;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function get_scheduler_models(): array {
		$models = array_filter(
			array_unique(
				array(
					(string) Options::get( 'openai_model', 'gpt-4.1-mini' ),
					(string) Options::get( 'gemini_model', 'gemini-2.0-flash' ),
				)
			),
			static fn( $model ) => ! empty( $model )
		);

		if ( empty( $models ) ) {
			$models = array( 'gpt-4.1-mini', 'gemini-2.0-flash' );
		}

		return array_map(
			static fn( $model ) => array(
				'value' => $model,
				'label' => $model,
			),
			$models
		);
	}

	private function render_stats_tab(): void {
		$stats = StatsTracker::all();
		$totals = $stats['totals'] ?? array();
		$daily_stats = StatsTracker::get_last_days( 30 );
		
		$total_posts = (int) ( $totals['posts'] ?? 0 );
		$total_images = (int) ( $totals['images'] ?? 0 );
		$total_comments = (int) ( $totals['comments'] ?? 0 );
		$total_input_tokens = (int) ( $totals['input_tokens'] ?? 0 );
		$total_output_tokens = (int) ( $totals['output_tokens'] ?? 0 );
		$total_tokens = (int) ( $totals['total_tokens'] ?? 0 );
		$total_cost = (float) ( $totals['cost'] ?? 0.0 );

		?>
		<h2><i class="bi bi-bar-chart"></i> <?php esc_html_e( 'Statystyki użycia API', 'kasumi-full-ai-content-generator' ); ?></h2>
		
		<div class="kasumi-stats-overview">
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-file-text"></i> <?php esc_html_e( 'Wygenerowane posty', 'kasumi-full-ai-content-generator' ); ?></h3>
				<p><?php echo esc_html( number_format_i18n( $total_posts ) ); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-image"></i> <?php esc_html_e( 'Wygenerowane grafiki', 'kasumi-full-ai-content-generator' ); ?></h3>
				<p><?php echo esc_html( number_format_i18n( $total_images ) ); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-chat-dots"></i> <?php esc_html_e( 'Wygenerowane komentarze', 'kasumi-full-ai-content-generator' ); ?></h3>
				<p><?php echo esc_html( number_format_i18n( $total_comments ) ); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-hash"></i> <?php esc_html_e( 'Całkowita liczba tokenów', 'kasumi-full-ai-content-generator' ); ?></h3>
				<p><?php echo esc_html( number_format_i18n( $total_tokens ) ); ?></p>
				<p>
					<?php 
					printf(
						esc_html__( 'Wejście: %s | Wyjście: %s', 'kasumi-full-ai-content-generator' ),
						number_format_i18n( $total_input_tokens ),
						number_format_i18n( $total_output_tokens )
					);
					?>
				</p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-currency-dollar"></i> <?php esc_html_e( 'Szacunkowy koszt', 'kasumi-full-ai-content-generator' ); ?></h3>
				<p>$<?php echo esc_html( number_format( $total_cost, 4, '.', '' ) ); ?></p>
				<p><?php esc_html_e( 'USD (szacunkowo)', 'kasumi-full-ai-content-generator' ); ?></p>
			</div>
		</div>

		<h3 style="margin: 40px 0 20px 0;"><?php esc_html_e( 'Użycie w ciągu ostatnich 30 dni', 'kasumi-full-ai-content-generator' ); ?></h3>
		
		<div class="kasumi-chart-container">
			<canvas id="kasumi-tokens-chart" style="max-height: 300px;"></canvas>
		</div>

		<div class="kasumi-chart-container">
			<canvas id="kasumi-cost-chart" style="max-height: 300px;"></canvas>
		</div>

		<div class="kasumi-chart-container" style="margin-bottom: 0;">
			<canvas id="kasumi-activity-chart" style="max-height: 300px;"></canvas>
		</div>

		<script>
		(function() {
			const dailyData = <?php echo wp_json_encode( $daily_stats ); ?>;
			const dates = Object.keys( dailyData );
			const tokensData = dates.map( date => dailyData[ date ].total_tokens || 0 );
			const costData = dates.map( date => dailyData[ date ].cost || 0 );
			const postsData = dates.map( date => dailyData[ date ].posts || 0 );
			const imagesData = dates.map( date => dailyData[ date ].images || 0 );
			const commentsData = dates.map( date => dailyData[ date ].comments || 0 );

			// Funkcja pomocnicza do konwersji hex na rgba
			function hexToRgba(hex, alpha) {
				const r = parseInt(hex.slice(1, 3), 16);
				const g = parseInt(hex.slice(3, 5), 16);
				const b = parseInt(hex.slice(5, 7), 16);
				return `rgba(${r}, ${g}, ${b}, ${alpha})`;
			}

			// Pobierz kolor z CSS variable
			const themeColor = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-theme-color').trim() || '#0073aa';
			const successColor = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-success-color').trim() || '#178239';
			const notificationColor = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-notification-color').trim() || '#d54e21';

			// Wykres tokenów
			if ( typeof Chart !== 'undefined' && document.getElementById( 'kasumi-tokens-chart' ) ) {
				new Chart( document.getElementById( 'kasumi-tokens-chart' ), {
					type: 'line',
					data: {
						labels: dates,
						datasets: [{
							label: '<?php echo esc_js( __( 'Tokeny', 'kasumi-full-ai-content-generator' ) ); ?>',
							data: tokensData,
							borderColor: themeColor,
							backgroundColor: hexToRgba(themeColor, 0.1),
							tension: 0.4
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: true,
						plugins: {
							title: {
								display: true,
								text: '<?php echo esc_js( __( 'Użycie tokenów w czasie', 'kasumi-full-ai-content-generator' ) ); ?>'
							}
						},
						scales: {
							y: {
								beginAtZero: true
							}
						}
					}
				});
			}

			// Wykres kosztów
			if ( typeof Chart !== 'undefined' && document.getElementById( 'kasumi-cost-chart' ) ) {
				new Chart( document.getElementById( 'kasumi-cost-chart' ), {
					type: 'bar',
					data: {
						labels: dates,
						datasets: [{
							label: '<?php echo esc_js( __( 'Koszt (USD)', 'kasumi-full-ai-content-generator' ) ); ?>',
							data: costData,
							backgroundColor: themeColor
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: true,
						plugins: {
							title: {
								display: true,
								text: '<?php echo esc_js( __( 'Dzienne koszty API', 'kasumi-full-ai-content-generator' ) ); ?>'
							}
						},
						scales: {
							y: {
								beginAtZero: true
							}
						}
					}
				});
			}

			// Wykres aktywności
			if ( typeof Chart !== 'undefined' && document.getElementById( 'kasumi-activity-chart' ) ) {
				new Chart( document.getElementById( 'kasumi-activity-chart' ), {
					type: 'bar',
					data: {
						labels: dates,
						datasets: [
							{
								label: '<?php echo esc_js( __( 'Posty', 'kasumi-full-ai-content-generator' ) ); ?>',
								data: postsData,
								backgroundColor: themeColor
							},
							{
								label: '<?php echo esc_js( __( 'Grafiki', 'kasumi-full-ai-content-generator' ) ); ?>',
								data: imagesData,
								backgroundColor: successColor
							},
							{
								label: '<?php echo esc_js( __( 'Komentarze', 'kasumi-full-ai-content-generator' ) ); ?>',
								data: commentsData,
								backgroundColor: notificationColor
							}
						]
					},
					options: {
						responsive: true,
						maintainAspectRatio: true,
						plugins: {
							title: {
								display: true,
								text: '<?php echo esc_js( __( 'Dzienna aktywność', 'kasumi-full-ai-content-generator' ) ); ?>'
							}
						},
						scales: {
							y: {
								beginAtZero: true,
								stacked: false
							}
						}
					}
				});
			}
		})();
		</script>
		<?php
	}

	private function render_diagnostics(): void {
		$report = $this->get_environment_report();

		echo '<ul class="kasumi-diag-list">';
		foreach ( $report as $row ) {
			printf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( $row['label'] ),
				wp_kses_post( $row['value'] )
			);
		}
		echo '</ul>';
	}

	private function get_environment_report(): array {
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$rows   = array(
			array(
				'label' => __( 'Wersja PHP', 'kasumi-full-ai-content-generator' ),
				'value' => $php_ok
					? '<span class="kasumi-ok">' . esc_html( PHP_VERSION ) . '</span>'
					: '<span class="kasumi-error">' . esc_html( PHP_VERSION ) . '</span>',
			),
		);

		$extensions = array(
			'curl'     => extension_loaded( 'curl' ),
			'mbstring' => extension_loaded( 'mbstring' ),
		);

		foreach ( $extensions as $extension => $enabled ) {
			$rows[] = array(
				/* translators: %s is the PHP extension name. */
				'label' => sprintf( __( 'Rozszerzenie %s', 'kasumi-full-ai-content-generator' ), strtoupper( $extension ) ),
				'value' => $enabled
					? '<span class="kasumi-ok">' . esc_html__( 'dostępne', 'kasumi-full-ai-content-generator' ) . '</span>'
					: '<span class="kasumi-error">' . esc_html__( 'brak', 'kasumi-full-ai-content-generator' ) . '</span>',
			);
		}

		return $rows;
	}

	private function render_logs_section(): void {
		$logger = new Logger();
		$logs = $logger->get_recent_logs( 50 );
		$level_filter = sanitize_text_field( $_GET['log_level'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Filtruj po poziomie jeśli wybrano
		if ( ! empty( $level_filter ) && in_array( $level_filter, array( 'info', 'warning', 'error' ), true ) ) {
			$logs = array_filter(
				$logs,
				function ( $log ) use ( $level_filter ) {
					return $log['level'] === $level_filter;
				}
			);
		}

		?>
		<div class="kasumi-logs-section">
			<div class="kasumi-logs-toolbar" style="margin-bottom: 12px;">
				<select name="log_level" id="kasumi-log-level-filter" style="margin-right: 8px;">
					<option value=""><?php esc_html_e( 'Wszystkie poziomy', 'kasumi-full-ai-content-generator' ); ?></option>
					<option value="info" <?php selected( $level_filter, 'info' ); ?>><?php esc_html_e( 'Info', 'kasumi-full-ai-content-generator' ); ?></option>
					<option value="warning" <?php selected( $level_filter, 'warning' ); ?>><?php esc_html_e( 'Ostrzeżenia', 'kasumi-full-ai-content-generator' ); ?></option>
					<option value="error" <?php selected( $level_filter, 'error' ); ?>><?php esc_html_e( 'Błędy', 'kasumi-full-ai-content-generator' ); ?></option>
				</select>
				<button type="button" class="button" id="kasumi-refresh-logs"><i class="bi bi-arrow-clockwise"></i> <?php esc_html_e( 'Odśwież', 'kasumi-full-ai-content-generator' ); ?></button>
			</div>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'Brak logów.', 'kasumi-full-ai-content-generator' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 180px;"><?php esc_html_e( 'Data/Czas', 'kasumi-full-ai-content-generator' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Poziom', 'kasumi-full-ai-content-generator' ); ?></th>
							<th><?php esc_html_e( 'Wiadomość', 'kasumi-full-ai-content-generator' ); ?></th>
							<th style="width: 200px;"><?php esc_html_e( 'Kontekst', 'kasumi-full-ai-content-generator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['date'] ); ?></td>
								<td>
									<span class="kasumi-log-level kasumi-log-level-<?php echo esc_attr( $log['level'] ); ?>">
										<?php echo esc_html( strtoupper( $log['level'] ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
								<td>
									<?php if ( ! empty( $log['context'] ) ) : ?>
										<details>
											<summary><?php esc_html_e( 'Pokaż', 'kasumi-full-ai-content-generator' ); ?></summary>
											<pre style="font-size: 11px; max-height: 150px; overflow: auto;"><?php echo esc_html( wp_json_encode( $log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
										</details>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			const filter = document.getElementById('kasumi-log-level-filter');
			const refreshBtn = document.getElementById('kasumi-refresh-logs');
			
			if (filter) {
				filter.addEventListener('change', function() {
					const url = new URL(window.location.href);
					if (this.value) {
						url.searchParams.set('log_level', this.value);
					} else {
						url.searchParams.delete('log_level');
					}
					window.location.href = url.toString();
				});
			}
			
			if (refreshBtn) {
				refreshBtn.addEventListener('click', function() {
					window.location.reload();
				});
			}
		})();
		</script>
		<?php
	}

	private function render_settings_actions(): void {
		$rest_url = rest_url( 'kasumi/v1/settings' );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="kasumi-settings-actions" style="margin-top: 16px;">
			<p class="description"><?php esc_html_e( 'Eksportuj, importuj lub zresetuj ustawienia wtyczki.', 'kasumi-full-ai-content-generator' ); ?></p>
			<div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
				<button type="button" class="button" id="kasumi-export-settings">
					<i class="bi bi-download"></i> <?php esc_html_e( 'Eksportuj ustawienia', 'kasumi-full-ai-content-generator' ); ?>
				</button>
				<button type="button" class="button" id="kasumi-import-settings">
					<i class="bi bi-upload"></i> <?php esc_html_e( 'Importuj ustawienia', 'kasumi-full-ai-content-generator' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="kasumi-reset-settings">
					<i class="bi bi-arrow-counterclockwise"></i> <?php esc_html_e( 'Resetuj do domyślnych', 'kasumi-full-ai-content-generator' ); ?>
				</button>
			</div>
			<input type="file" id="kasumi-import-file" accept=".json" style="display: none;" />
		</div>
		<script>
		(function() {
			const restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			const nonce = <?php echo wp_json_encode( $nonce ); ?>;

			// Eksport
			const exportBtn = document.getElementById('kasumi-export-settings');
			if (exportBtn) {
				exportBtn.addEventListener('click', async function() {
					try {
						const response = await fetch(restUrl + '/export', {
							method: 'GET',
							headers: {
								'X-WP-Nonce': nonce
							}
						});
						const data = await response.json();
						
						if (data.success && data.data) {
							const blob = new Blob([data.data], { type: 'application/json' });
							const url = URL.createObjectURL(blob);
							const a = document.createElement('a');
							a.href = url;
							a.download = 'kasumi-ai-settings-' + new Date().toISOString().split('T')[0] + '.json';
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
							URL.revokeObjectURL(url);
							
							alert('<?php echo esc_js( __( 'Ustawienia zostały wyeksportowane.', 'kasumi-full-ai-content-generator' ) ); ?>');
						} else {
							alert('<?php echo esc_js( __( 'Błąd podczas eksportu ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
						}
					} catch (error) {
						console.error('Export error:', error);
						alert('<?php echo esc_js( __( 'Błąd podczas eksportu ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
					}
				});
			}

			// Import
			const importBtn = document.getElementById('kasumi-import-settings');
			const importFile = document.getElementById('kasumi-import-file');
			if (importBtn && importFile) {
				importBtn.addEventListener('click', function() {
					importFile.click();
				});
				
				importFile.addEventListener('change', async function(e) {
					const file = e.target.files[0];
					if (!file) return;
					
					const reader = new FileReader();
					reader.onload = async function(event) {
						try {
							const json = event.target.result;
							const response = await fetch(restUrl + '/import', {
								method: 'POST',
								headers: {
									'Content-Type': 'application/json',
									'X-WP-Nonce': nonce
								},
								body: JSON.stringify({ json: json })
							});
							const data = await response.json();
							
							if (data.success) {
								alert('<?php echo esc_js( __( 'Ustawienia zostały zaimportowane.', 'kasumi-full-ai-content-generator' ) ); ?>');
								window.location.reload();
							} else {
								alert(data.message || '<?php echo esc_js( __( 'Błąd podczas importu ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
							}
						} catch (error) {
							console.error('Import error:', error);
							alert('<?php echo esc_js( __( 'Błąd podczas importu ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
						}
					};
					reader.readAsText(file);
				});
			}

			// Reset
			const resetBtn = document.getElementById('kasumi-reset-settings');
			if (resetBtn) {
				resetBtn.addEventListener('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Czy na pewno chcesz zresetować wszystkie ustawienia do domyślnych? Ta operacja jest nieodwracalna.', 'kasumi-full-ai-content-generator' ) ); ?>')) {
						return;
					}
					
					fetch(restUrl + '/reset', {
						method: 'POST',
						headers: {
							'X-WP-Nonce': nonce
						}
					})
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							alert('<?php echo esc_js( __( 'Ustawienia zostały zresetowane.', 'kasumi-full-ai-content-generator' ) ); ?>');
							window.location.reload();
						} else {
							alert(data.message || '<?php echo esc_js( __( 'Błąd podczas resetowania ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
						}
					})
					.catch(error => {
						console.error('Reset error:', error);
						alert('<?php echo esc_js( __( 'Błąd podczas resetowania ustawień.', 'kasumi-full-ai-content-generator' ) ); ?>');
					});
				});
			}
		})();
		</script>
		<?php
	}
}
