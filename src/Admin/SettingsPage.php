<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Module;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Status\StatusStore;
use Kasumi\AIGenerator\Status\StatsTracker;
use function __;
use function add_query_arg;
use function add_settings_field;
use function add_settings_section;
use function add_submenu_page;
use function admin_url;
use function update_option;
use function check_admin_referer;
use function checked;
use function disabled;
use function current_time;
use function current_user_can;
use function date_i18n;
use function delete_transient;
use function delete_user_meta;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_attr__;
use function esc_url;
use function esc_url_raw;
use function get_current_user_id;
use function get_option;
use function get_permalink;
use function get_post_types;
use function get_posts;
use function get_transient;
use function get_user_meta;
use function get_users;
use function home_url;
use function map_deep;
use function human_time_diff;
use function number_format_i18n;
use function printf;
use function register_setting;
use function rest_url;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function selected;
use function settings_errors;
use function settings_fields;
use function submit_button;
use function time;
use function update_user_meta;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script;
use function wp_kses;
use function wp_kses_post;
use function wp_localize_script;
use function wp_parse_args;
use function wp_safe_redirect;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_strip_all_tags;
use function wp_unslash;

use const DAY_IN_SECONDS;
use const MINUTE_IN_SECONDS;
use const WEEK_IN_SECONDS;

/**
 * Panel konfiguracyjny modułu AI Content.
 */
class SettingsPage
{
    private const PAGE_SLUG = "kasumi-ai-generator-ai-content";
    private const SUPPORT_DISMISS_META = "kasumi_ai_support_hidden_until";
    private ?array $automation_snapshot = null;

    /**
     * Returns the slug of the settings subpage so other components can stay in sync.
     */
    public static function get_page_slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function register_menu(): void
    {
        add_submenu_page(
            "options-general.php",
            __("AI Content", "kasumi-ai-generator"),
            __("AI Content", "kasumi-ai-generator"),
            "manage_options",
            self::PAGE_SLUG,
            [$this, "render_page"],
        );
    }

    public function register_settings(): void
    {
        register_setting(Options::OPTION_GROUP, Options::OPTION_NAME, [
            "type" => "array",
            "sanitize_callback" => [Options::class, "sanitize"],
            "default" => Options::defaults(),
        ]);

        add_action("wp_ajax_kasumi_ai_save_settings", [
            $this,
            "handle_ajax_save_settings",
        ]);

        // Rejestruj hook dla akcji banera wsparcia
        add_action("admin_post_kasumi_ai_support_card", [
            $this,
            "handle_support_card_action",
        ]);
        add_action("admin_post_kasumi_ai_cron_control", [
            $this,
            "handle_cron_control_action",
        ]);

        $this->register_api_section();
        $this->register_content_section();
        $this->register_image_section();
        $this->register_comments_section();
        $this->register_misc_section();
        $this->register_diagnostics_section();
    }

    public function handle_support_card_action(): void
    {
        if (!current_user_can("manage_options")) {
            wp_die(
                esc_html__(
                    "Brak uprawnień.",
                    "kasumi-ai-generator",
                ),
            );
        }

        check_admin_referer("kasumi_ai_support_card");

        $action = sanitize_text_field(
            wp_unslash($_POST["kasumi_ai_support_action"] ?? ""),
        );
        $user_id = get_current_user_id();

        if ("dismiss" === $action) {
            update_user_meta(
                $user_id,
                self::SUPPORT_DISMISS_META,
                time() + WEEK_IN_SECONDS,
            );
        } elseif ("reset" === $action) {
            delete_user_meta($user_id, self::SUPPORT_DISMISS_META);
        }

        wp_safe_redirect(
            admin_url("options-general.php?page=" . self::PAGE_SLUG),
        );
        exit();
    }

    public function handle_cron_control_action(): void
    {
        if (!current_user_can("manage_options")) {
            wp_die(
                esc_html__(
                    "Brak uprawnień.",
                    "kasumi-ai-generator",
                ),
            );
        }

        check_admin_referer("kasumi_ai_cron_control");

        $action = sanitize_key($_POST["kasumi_ai_cron_action"] ?? "");
        $redirect = admin_url("options-general.php?page=" . self::PAGE_SLUG);
        $scheduler = Module::instance()
            ?->get_scheduler();

        if (!$scheduler) {
            $this->set_cron_notice(
                "error",
                __(
                    "Automatyzacja jest chwilowo niedostępna. Odśwież stronę i spróbuj ponownie.",
                    "kasumi-ai-generator",
                ),
            );
            wp_safe_redirect($redirect);
            exit();
        }

        $type = "success";
        $message = "";

        switch ($action) {
            case "start":
                $scheduler->resume();
                $message = __(
                    "Automatyzacja została uruchomiona.",
                    "kasumi-ai-generator",
                );
                break;
            case "stop":
                $scheduler->pause();
                $message = __(
                    "Automatyzacja została zatrzymana.",
                    "kasumi-ai-generator",
                );
                break;
            case "restart":
                $scheduler->restart();
                $message = __(
                    "Zadania WP-Cron zostały odświeżone.",
                    "kasumi-ai-generator",
                );
                break;
            case "run-post":
                if (!$scheduler->run_post_now(false)) {
                    $this->set_cron_notice(
                        "error",
                        __(
                            "Nie udało się wymusić publikacji. Sprawdź konfigurację API i logi.",
                            "kasumi-ai-generator",
                        ),
                    );
                    wp_safe_redirect($redirect);
                    exit();
                }
                $message = __(
                    "Rozpoczęto natychmiastowe generowanie posta.",
                    "kasumi-ai-generator",
                );
                break;
            case "run-schedules":
                $processed = $scheduler->run_manual_queue_now(false, 5);
                $message = __(
                    "W kolejce zadań sprawdzono oczekujące wpisy.",
                    "kasumi-ai-generator",
                );
                if ($processed > 0) {
                    $message .=
                        " " .
                        sprintf(
                            /* translators: %d: number of schedule jobs forced to run. */
                            __(
                                "Wymuszono %d zadań.",
                                "kasumi-ai-generator",
                            ),
                            $processed,
                        );
                }
                break;
            default:
                $type = "error";
                $message = __(
                    "Nieobsługiwana akcja automatyzacji.",
                    "kasumi-ai-generator",
                );
        }

        if ("" !== $message) {
            $this->set_cron_notice($type, $message);
        }

        wp_safe_redirect($redirect);
        exit();
    }

    public function handle_ajax_save_settings(): void
    {
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __(
                    "Brak uprawnień do zapisywania ustawień.",
                    "kasumi-ai-generator",
                ),
            ], 403);
        }

        check_ajax_referer("kasumi_ai_save_settings", "nonce");

        $raw_values = isset($_POST[Options::OPTION_NAME])
            ? wp_unslash($_POST[Options::OPTION_NAME])
            : [];

        if (is_array($raw_values)) {
            $raw_values = map_deep($raw_values, "sanitize_text_field");
        } else {
            $raw_values = [];
        }

        $sanitized = Options::sanitize($raw_values);
        update_option(Options::OPTION_NAME, $sanitized);

        wp_send_json_success([
            "message" => __(
                "Ustawienia zostały zapisane.",
                "kasumi-ai-generator",
            ),
            "nonce" => wp_create_nonce("kasumi_ai_save_settings"),
            "settingsNonce" => wp_create_nonce(
                Options::OPTION_GROUP . "-options",
            ),
        ]);
    }

    public function enqueue_assets(string $hook): void
    {
        if ("settings_page_" . self::PAGE_SLUG !== $hook) {
            return;
        }

        wp_enqueue_style("wp-color-picker");

        // Bootstrap Icons
        $bootstrap_icons_path =
            KASUMI_AI_PATH .
            "vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css";
        $bootstrap_icons_url =
            KASUMI_AI_URL .
            "vendor/twbs/bootstrap-icons/font/bootstrap-icons.min.css";

        if (file_exists($bootstrap_icons_path)) {
            wp_enqueue_style(
                "bootstrap-icons",
                $bootstrap_icons_url,
                [],
                "1.13.1",
            );
        }

        wp_enqueue_script(
            "kasumi-ai-preview",
            KASUMI_AI_URL . "assets/js/ai-preview.js",
            ["wp-api-fetch"],
            KASUMI_AI_VERSION,
            true,
        );

        wp_localize_script("kasumi-ai-preview", "kasumiAiPreview", [
            "ajaxUrl" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("kasumi_ai_preview"),
            "i18n" => [
                "loading" => __(
                    "Generowanie w toku…",
                    "kasumi-ai-generator",
                ),
                "error" => __(
                    "Coś poszło nie tak. Spróbuj ponownie.",
                    "kasumi-ai-generator",
                ),
            ],
        ]);

        wp_enqueue_script(
            "kasumi-ai-admin-ui",
            KASUMI_AI_URL . "assets/js/admin-ui.js",
            [
                "jquery",
                "jquery-ui-tabs",
                "jquery-ui-tooltip",
                "wp-color-picker",
                "wp-util",
                "wp-api-fetch",
            ],
            KASUMI_AI_VERSION,
            true,
        );

        wp_localize_script("kasumi-ai-admin-ui", "kasumiAiAdmin", [
            "ajaxUrl" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("kasumi_ai_models"),
            "i18n" => [
                "fetching" => __(
                    "Ładowanie modeli…",
                    "kasumi-ai-generator",
                ),
                "noModels" => __(
                    "Brak modeli",
                    "kasumi-ai-generator",
                ),
                "error" => __(
                    "Nie udało się pobrać modeli.",
                    "kasumi-ai-generator",
                ),
                "primaryLinkSelect" => __(
                    "Najpierw wybierz stronę z listy.",
                    "kasumi-ai-generator",
                ),
            ],
            "settingsSave" => [
                "ajaxUrl" => admin_url("admin-ajax.php"),
                "action" => "kasumi_ai_save_settings",
                "nonce" => wp_create_nonce("kasumi_ai_save_settings"),
                "messageSuccess" => __(
                    "Ustawienia zostały zapisane.",
                    "kasumi-ai-generator",
                ),
                "messageError" => __(
                    "Nie udało się zapisać ustawień. Spróbuj ponownie.",
                    "kasumi-ai-generator",
                ),
            ],
            "scheduler" => $this->get_scheduler_settings(),
            "automation" => $this->get_automation_ui_config(),
            "logs" => [
                "filterSelector" => "#kasumi-log-level-filter",
                "refreshSelector" => "#kasumi-refresh-logs",
            ],
            "settingsActions" => [
                "restUrl" => esc_url_raw(rest_url("kasumi/v1/settings")),
                "nonce" => wp_create_nonce("wp_rest"),
                "messages" => [
                    "exportSuccess" => __(
                        "Ustawienia zostały wyeksportowane.",
                        "kasumi-ai-generator",
                    ),
                    "exportError" => __(
                        "Błąd podczas eksportu ustawień.",
                        "kasumi-ai-generator",
                    ),
                    "importSuccess" => __(
                        "Ustawienia zostały zaimportowane.",
                        "kasumi-ai-generator",
                    ),
                    "importError" => __(
                        "Błąd podczas importu ustawień.",
                        "kasumi-ai-generator",
                    ),
                    "resetSuccess" => __(
                        "Ustawienia zostały zresetowane.",
                        "kasumi-ai-generator",
                    ),
                    "resetError" => __(
                        "Błąd podczas resetowania ustawień.",
                        "kasumi-ai-generator",
                    ),
                ],
                "confirmReset" => __(
                    "Czy na pewno chcesz zresetować wszystkie ustawienia do domyślnych? Ta operacja jest nieodwracalna.",
                    "kasumi-ai-generator",
                ),
            ],
        ]);

        wp_enqueue_style(
            "kasumi-ai-admin",
            KASUMI_AI_URL . "assets/css/admin.css",
            [],
            KASUMI_AI_VERSION,
        );

        // Dodaj dynamiczne zmienne CSS dla admin color scheme
        $this->add_admin_color_scheme_variables();
    }

    /**
     * Dodaje dynamiczne zmienne CSS dla aktualnego schematu kolorów WordPress.
     */
    private function add_admin_color_scheme_variables(): void
    {
        global $_wp_admin_css_colors;

        $color_scheme = get_user_option("admin_color", get_current_user_id());
        if (
            empty($color_scheme) ||
            !isset($_wp_admin_css_colors[$color_scheme])
        ) {
            $color_scheme = "fresh";
        }

        $scheme = $_wp_admin_css_colors[$color_scheme] ?? null;
        if (!$scheme) {
            return;
        }

        // Pobierz kolory z schematu
        $colors = $scheme->colors ?? [];
        // Dla większości schematów: colors[0] = base, colors[1] = highlight, colors[2] = link focus
        $base_color = $colors[0] ?? "#23282d";
        $highlight_color = $colors[1] ?? ($colors[0] ?? "#0073aa"); // Drugi kolor to zazwyczaj highlight
        $link_color = $highlight_color;
        $link_focus =
            $colors[2] ?? $this->adjust_color_brightness($highlight_color, 10); // Trzeci kolor to często link focus

        // Oblicz warianty kolorów
        $darker_10 = $this->adjust_color_brightness($highlight_color, -10);
        $darker_20 = $this->adjust_color_brightness($highlight_color, -20);
        $darker_30 = $this->adjust_color_brightness($highlight_color, -30);
        $lighter_10 = $this->adjust_color_brightness($highlight_color, 10);
        $lighter_20 = $this->adjust_color_brightness($highlight_color, 20);

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
            esc_attr($highlight_color),
            esc_attr($darker_10),
            esc_attr($darker_20),
            esc_attr($darker_30),
            esc_attr($lighter_10),
            esc_attr($lighter_20),
            esc_attr($base_color),
            esc_attr($link_color),
            esc_attr($link_focus),
        );

        wp_add_inline_style("kasumi-ai-admin", $css);
    }

    /**
     * Dostosowuje jasność koloru hex.
     *
     * @param string $hex_color Kolor w formacie hex (np. #0073aa).
     * @param int    $percent   Procent zmiany jasności (-100 do 100).
     * @return string Kolor w formacie hex.
     */
    private function adjust_color_brightness(
        string $hex_color,
        int $percent,
    ): string {
        $hex_color = ltrim($hex_color, "#");

        if (strlen($hex_color) === 3) {
            $hex_color =
                $hex_color[0] .
                $hex_color[0] .
                $hex_color[1] .
                $hex_color[1] .
                $hex_color[2] .
                $hex_color[2];
        }

        $r = hexdec(substr($hex_color, 0, 2));
        $g = hexdec(substr($hex_color, 2, 2));
        $b = hexdec(substr($hex_color, 4, 2));

        $r = max(0, min(255, $r + ($r * $percent) / 100));
        $g = max(0, min(255, $g + ($g * $percent) / 100));
        $b = max(0, min(255, $b + ($b * $percent) / 100));

        return "#" .
            str_pad(dechex((int) $r), 2, "0", STR_PAD_LEFT) .
            str_pad(dechex((int) $g), 2, "0", STR_PAD_LEFT) .
            str_pad(dechex((int) $b), 2, "0", STR_PAD_LEFT);
    }

    private function register_api_section(): void
    {
        $section = "kasumi_ai_api";

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-key"></i> ' .
                    __("Klucze API", "kasumi-ai-generator"),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Dodaj klucze OpenAI i Pixabay wykorzystywane do generowania treści i grafik.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        // Dostawca AI - PIERWSZE POLE
        $this->add_field(
            "ai_provider",
            __("Dostawca AI", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "openai" => __(
                        "Tylko OpenAI",
                        "kasumi-ai-generator",
                    ),
                    "gemini" => __(
                        "Tylko Google Gemini",
                        "kasumi-ai-generator",
                    ),
                    "auto" => __(
                        "Automatyczny (OpenAI → Gemini)",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "W trybie automatycznym system próbuje najpierw OpenAI, a w razie braku odpowiedzi przełącza się na Gemini.",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-provider-selector",
            ],
        );

        // Pola OpenAI - pokazywane gdy wybrano OpenAI lub Auto
        $this->add_field(
            "openai_api_key",
            __("OpenAI API Key", "kasumi-ai-generator"),
            $section,
            [
                "type" => "password",
                "placeholder" => "sk-***",
                "description" => sprintf(
                    /* translators: %s is a link to the OpenAI dashboard. */
                    __(
                        "Pobierz klucz w %s.",
                        "kasumi-ai-generator",
                    ),
                    sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url("https://platform.openai.com/account/api-keys"),
                        esc_html__(
                            "panelu OpenAI",
                            "kasumi-ai-generator",
                        ),
                    ),
                ),
                "help" => __(
                    "Umożliwia korzystanie z modeli GPT-4.1 / GPT-4o.",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-openai-fields",
            ],
        );

        $this->add_field(
            "openai_model",
            __("Model OpenAI", "kasumi-ai-generator"),
            $section,
            [
                "type" => "model-select",
                "provider" => "openai",
                "help" => __(
                    "Lista modeli z konta OpenAI (np. GPT-4.1, GPT-4o).",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-openai-fields",
            ],
        );

        // Pola Gemini - pokazywane gdy wybrano Gemini lub Auto
        $this->add_field(
            "gemini_api_key",
            __("Gemini API Key", "kasumi-ai-generator"),
            $section,
            [
                "type" => "password",
                "placeholder" => "AIza***",
                "description" => sprintf(
                    /* translators: %s is a link to the Google AI Studio page. */
                    __(
                        "Wygeneruj klucz w %s.",
                        "kasumi-ai-generator",
                    ),
                    sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url("https://aistudio.google.com/app/apikey"),
                        esc_html__(
                            "Google AI Studio",
                            "kasumi-ai-generator",
                        ),
                    ),
                ),
                "help" => __(
                    "Obsługuje modele Gemini 2.x flash/pro.",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-gemini-fields",
            ],
        );

        $this->add_field(
            "system_prompt",
            __("System prompt", "kasumi-ai-generator"),
            $section,
            [
                "type" => "textarea",
                "description" => __(
                    "Instrukcje przekazywane jako system prompt dla modeli OpenAI i Gemini.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "gemini_model",
            __("Model Gemini", "kasumi-ai-generator"),
            $section,
            [
                "type" => "model-select",
                "provider" => "gemini",
                "description" => __(
                    "Wybierz model z Google Gemini (flash, pro, image).",
                    "kasumi-ai-generator",
                ),
                "help" => __(
                    "Lista pobierana jest bezpośrednio z API na podstawie klucza.",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-gemini-fields",
            ],
        );

        // Pixabay API Key - NA KOŃCU
        $this->add_field(
            "pixabay_api_key",
            __("Pixabay API Key", "kasumi-ai-generator"),
            $section,
            [
                "placeholder" => "12345678-abcdef...",
                "description" => sprintf(
                    /* translators: %s link to Pixabay API page. */
                    __(
                        'Klucz API Pixabay używany do pobierania obrazów w trybie serwerowym. Darmowe API pobierzesz z %s.',
                        "kasumi-ai-generator",
                    ),
                    sprintf(
                        '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
                        esc_url("https://pixabay.com/api/docs/"),
                        esc_html__("strony Pixabay", "kasumi-ai-generator"),
                    ),
                ),
            ],
        );
    }

    private function register_content_section(): void
    {
        $section = "kasumi_ai_content";
        $author_choices = $this->get_author_select_choices();

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-file-earmark-text"></i> ' .
                    __(
                        "Konfiguracja treści",
                        "kasumi-ai-generator",
                    ),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Ogólne ustawienia generowania wpisów, kategorii i harmonogramu.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        $this->add_field(
            "topic_strategy",
            __("Strategia tematów", "kasumi-ai-generator"),
            $section,
            [
                "description" => __(
                    "Krótka instrukcja na temat tematów artykułów.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "target_category",
            __("Kategoria docelowa", "kasumi-ai-generator"),
            $section,
            [
                "type" => "category-select",
                "description" => __(
                    "Wybierz kategorię, która ma otrzymywać nowe treści.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "default_post_status",
            __("Status wpisów", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "draft" => __("Szkic", "kasumi-ai-generator"),
                    "publish" => __(
                        "Publikuj automatycznie",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "Określ czy wpis ma być szkicem czy publikacją.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "default_author_mode",
            __("Tryb domyślnego autora", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "none" => __(
                        "Brak – pozostaw według harmonogramu",
                        "kasumi-ai-generator",
                    ),
                    "fixed" => __(
                        "Stały autor",
                        "kasumi-ai-generator",
                    ),
                    "random_list" => __(
                        "Losowo z listy autorów",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "Służy podczas generowania automatycznego/WP-Cron. Harmonogram z własnym autorem nadal ma pierwszeństwo.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "default_author_id",
            __("Stały autor wpisów", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" =>
                    [
                        "" => __(
                            "— Wybierz autora —",
                            "kasumi-ai-generator",
                        ),
                    ] + $author_choices,
                "description" => __(
                    "Wybierz osobę używaną w trybie „Stały autor”.",
                    "kasumi-ai-generator",
                ),
                "class" => "kasumi-author-control kasumi-author-control--fixed",
            ],
        );

        $this->add_field(
            "default_author_pool",
            __("Pula autorów do losowania", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => $author_choices,
                "multiple" => true,
                "size" => 6,
                "description" => __(
                    "Zaznacz kilku autorów – przy każdym wpisie Kasumi wylosuje jedną osobę (tryb „Losowo z listy”).",
                    "kasumi-ai-generator",
                ),
                "class" =>
                    "kasumi-author-control kasumi-author-control--random",
            ],
        );

        $this->add_field(
            "schedule_interval_hours",
            __("Interwał generowania (h)", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "min" => 72,
                "description" => __(
                    "Wpisz docelową liczbę godzin (min. 72). System losuje publikację w przedziale 3‑7 dni i dopasuje ją do najlepszych godzin (np. 9:00, 11:30).",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "word_count_min",
            __("Min. liczba słów", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "min" => 200,
            ],
        );

        $this->add_field(
            "word_count_max",
            __("Maks. liczba słów", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "min" => 200,
            ],
        );

        $this->add_field(
            "link_keywords",
            __(
                "Słowa kluczowe do linkowania",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "description" => __(
                    "Lista słów rozdzielona przecinkami wykorzystywana przy linkach wewnętrznych.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "enable_internal_linking",
            __(
                "Włącz linkowanie wewnętrzne",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "checkbox",
            ],
        );

        $this->add_field(
            "primary_links",
            __("Główne linki wewnętrzne", "kasumi-ai-generator"),
            $section,
            [
                "type" => "primary-links",
                "pages" => $this->get_primary_link_page_choices(),
                "description" => __(
                    "Dodaj kluczowe strony (oferta, landing) i powiązane frazy. Kasumi będzie próbować linkować do nich w pierwszej kolejności.",
                    "kasumi-ai-generator",
                ),
            ],
        );
    }

    private function register_image_section(): void
    {
        $section = "kasumi_ai_images";

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-image"></i> ' .
                    __(
                        "Grafiki wyróżniające",
                        "kasumi-ai-generator",
                    ),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Parametry zdjęć Pixabay i nadpisów tworzonych przez Imagick.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        $this->add_field(
            "enable_featured_images",
            __(
                "Generuj grafiki wyróżniające",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "checkbox",
            ],
        );

        $this->add_field(
            "image_generation_mode",
            __("Tryb generowania grafik", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "server" => __(
                        "Serwerowy (Pixabay + nakładka)",
                        "kasumi-ai-generator",
                    ),
                    "remote" => __(
                        "Zdalne (API AI)",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "W trybie serwerowym obrazy pochodzą z Pixabay i są modyfikowane lokalnie. Tryb zdalny generuje obraz przez API AI (OpenAI lub Gemini).",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_remote_provider",
            __("Provider obrazów zdalnych", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "openai" => __(
                        "OpenAI (DALL-E)",
                        "kasumi-ai-generator",
                    ),
                    "gemini" => __(
                        "Gemini (Imagen)",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "Używane tylko, gdy wybrano tryb zdalny. Wybierz provider do generowania obrazów przez API.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_server_engine",
            __("Silnik serwerowy", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "imagick" => __(
                        "Imagick (zalecany)",
                        "kasumi-ai-generator",
                    ),
                    "gd" => __(
                        "Biblioteka GD",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "Używane tylko, gdy wybrano tryb serwerowy. Wybierz bibliotekę dostępna na Twoim hostingu.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_template",
            __("Szablon grafiki", "kasumi-ai-generator"),
            $section,
            [
                "description" => __(
                    "Możesz odwołać się do {{title}} i {{summary}}.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_overlay_color",
            __("Kolor nakładki (HEX)", "kasumi-ai-generator"),
            $section,
            [
                "type" => "color-picker",
            ],
        );

        $this->add_field(
            "image_overlay_opacity",
            __("Moc nakładki (%)", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "min" => 0,
                "max" => 100,
                "step" => 5,
                "description" => __(
                    "Wyższa wartość oznacza bardziej wyrazistą (ciemniejszą) nakładkę.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_style",
            __("Styl kompozycji", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "modern" => __(
                        "Nowoczesny",
                        "kasumi-ai-generator",
                    ),
                    "classic" => __(
                        "Klasyczny",
                        "kasumi-ai-generator",
                    ),
                    "oldschool" => __(
                        "Oldschool",
                        "kasumi-ai-generator",
                    ),
                ],
                "description" => __(
                    "Dobiera krój pisma, kerning i proporcje tekstu. Wszystkie style wspierają polskie znaki.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_text_enabled",
            __("Wyświetl tekst na grafice", "kasumi-ai-generator"),
            $section,
            [
                "type" => "checkbox",
                "description" => __(
                    "Odznacz, aby pozostawić wyłącznie zdjęcie z Pixabay lub zdalnego generatora.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_text_alignment",
            __("Wyrównanie tekstu", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "left" => __("Lewo", "kasumi-ai-generator"),
                    "center" => __(
                        "Środek",
                        "kasumi-ai-generator",
                    ),
                    "right" => __("Prawo", "kasumi-ai-generator"),
                ],
                "description" => __(
                    "Kontroluje układ względem osi poziomej.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_text_vertical",
            __("Pozycja pionowa tekstu", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "top" => __("Góra", "kasumi-ai-generator"),
                    "middle" => __(
                        "Środek",
                        "kasumi-ai-generator",
                    ),
                    "bottom" => __("Dół", "kasumi-ai-generator"),
                ],
                "description" => __(
                    "Nakładka zachowuje bezpieczne marginesy niezależnie od położenia.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_canvas_preset",
            __(
                "Szybkie ustawienia proporcji",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "canvas-presets",
                "placeholder" => __(
                    "— Wybierz gotowy rozmiar —",
                    "kasumi-ai-generator",
                ),
                "presets" => [
                    [
                        "label" => sprintf(
                            /* translators: 1: image width in px, 2: image height in px. */
                            __('16:9 – %1$s × %2$s px', 'kasumi-ai-generator'),
                            number_format_i18n(1200),
                            number_format_i18n(675),
                        ),
                        "width" => 1200,
                        "height" => 675,
                    ],
                    [
                        "label" => sprintf(
                            /* translators: 1: image width in px, 2: image height in px. */
                            __('4:3 – %1$s × %2$s px', 'kasumi-ai-generator'),
                            number_format_i18n(1200),
                            number_format_i18n(900),
                        ),
                        "width" => 1200,
                        "height" => 900,
                    ],
                    [
                        "label" => sprintf(
                            /* translators: 1: image width in px, 2: image height in px. */
                            __('1:1 – %1$s × %2$s px', 'kasumi-ai-generator'),
                            number_format_i18n(1200),
                            number_format_i18n(1200),
                        ),
                        "width" => 1200,
                        "height" => 1200,
                    ],
                    [
                        "label" => sprintf(
                            /* translators: 1: image width in px, 2: image height in px. */
                            __('2:3 – %1$s × %2$s px', 'kasumi-ai-generator'),
                            number_format_i18n(1200),
                            number_format_i18n(1800),
                        ),
                        "width" => 1200,
                        "height" => 1800,
                    ],
                ],
                "description" => __(
                    "Wybierz gotowe proporcje — pola poniżej zostaną uzupełnione automatycznie.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_canvas_width",
            __("Szerokość grafiki (px)", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "description" => __(
                    "Grafika zostanie przeskalowana/przycięta do tej szerokości.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "image_canvas_height",
            __("Wysokość grafiki (px)", "kasumi-ai-generator"),
            $section,
            [
                "type" => "number",
                "description" => __(
                    "Pozwala zachować określone proporcje (np. 675 px dla 16:9).",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "pixabay_query",
            __("Słowa kluczowe Pixabay", "kasumi-ai-generator"),
            $section,
        );

        $this->add_field(
            "pixabay_orientation",
            __("Orientacja Pixabay", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "horizontal" => __(
                        "Pozioma",
                        "kasumi-ai-generator",
                    ),
                    "vertical" => __(
                        "Pionowa",
                        "kasumi-ai-generator",
                    ),
                ],
            ],
        );
    }

    private function register_comments_section(): void
    {
        $section = "kasumi_ai_comments";

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-chat-left-text"></i> ' .
                    __("Komentarze AI", "kasumi-ai-generator"),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Steruj liczbą i częstotliwością komentarzy generowanych przez AI.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        $this->add_field(
            "comments_enabled",
            __("Generuj komentarze", "kasumi-ai-generator"),
            $section,
            [
                "type" => "checkbox",
            ],
        );

        $this->add_field(
            "comment_frequency",
            __("Częstotliwość", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "dense" => __(
                        "Intensywnie po publikacji",
                        "kasumi-ai-generator",
                    ),
                    "normal" => __(
                        "Stałe tempo",
                        "kasumi-ai-generator",
                    ),
                    "slow" => __(
                        "Sporadyczne komentarze",
                        "kasumi-ai-generator",
                    ),
                ],
            ],
        );

        $this->add_field(
            "comment_min",
            __(
                "Minimalna liczba komentarzy",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "number",
                "min" => 1,
            ],
        );

        $this->add_field(
            "comment_max",
            __(
                "Maksymalna liczba komentarzy",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "number",
                "min" => 1,
            ],
        );

        $this->add_field(
            "comment_status",
            __("Status komentarzy", "kasumi-ai-generator"),
            $section,
            [
                "type" => "select",
                "choices" => [
                    "approve" => __(
                        "Zatwierdzone",
                        "kasumi-ai-generator",
                    ),
                    "hold" => __(
                        "Oczekujące",
                        "kasumi-ai-generator",
                    ),
                ],
            ],
        );

        $this->add_field(
            "comment_author_prefix",
            __("Prefiks pseudonimu", "kasumi-ai-generator"),
            $section,
            [
                "description" => __(
                    "Opcjonalne. Gdy puste, AI generuje dowolne pseudonimy (np. mix PL/EN).",
                    "kasumi-ai-generator",
                ),
            ],
        );
    }

    private function register_misc_section(): void
    {
        $section = "kasumi_ai_misc";

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-gear"></i> ' .
                    __("Pozostałe", "kasumi-ai-generator"),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Logowanie, tryb podglądu oraz powiadomienia.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        $this->add_field(
            "plugin_enabled",
            __("Włącz wtyczkę", "kasumi-ai-generator"),
            $section,
            [
                "type" => "checkbox",
                "description" => __(
                    "Wyłączenie wstrzymuje wszystkie automatyczne zadania (generowanie postów, komentarzy, harmonogramów).",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "enable_logging",
            __("Włącz logowanie zdarzeń", "kasumi-ai-generator"),
            $section,
            [
                "type" => "checkbox",
            ],
        );

        $this->add_field(
            "status_logging",
            __("Pokaż status na stronie", "kasumi-ai-generator"),
            $section,
            [
                "type" => "checkbox",
            ],
        );

        $this->add_field(
            "preview_mode",
            __(
                "Tryb podglądu (bez publikacji)",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "checkbox",
                "description" => __(
                    "W tym trybie AI generuje treści tylko do logów.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "debug_email",
            __("E-mail raportowy", "kasumi-ai-generator"),
            $section,
            [
                "type" => "email",
                "description" => __(
                    "Adres otrzymujący krytyczne błędy modułu.",
                    "kasumi-ai-generator",
                ),
            ],
        );

        $this->add_field(
            "delete_tables_on_deactivation",
            __(
                "Usuń tabele przy deaktywacji",
                "kasumi-ai-generator",
            ),
            $section,
            [
                "type" => "checkbox",
                "description" => __(
                    "UWAGA: Po deaktywacji wtyczki wszystkie dane harmonogramów zostaną trwale usunięte!",
                    "kasumi-ai-generator",
                ),
            ],
        );

        // Dodaj przyciski import/export/reset
        add_settings_field(
            "kasumi_ai_settings_actions",
            __("Zarządzanie ustawieniami", "kasumi-ai-generator"),
            function (): void {
                $this->render_settings_actions();
            },
            self::PAGE_SLUG,
            $section,
        );
    }

    private function register_diagnostics_section(): void
    {
        $section = "kasumi_ai_diag";

        add_settings_section(
            $section,
            wp_kses_post(
                '<i class="bi bi-bug"></i> ' .
                    __(
                        "Diagnostyka środowiska",
                        "kasumi-ai-generator",
                    ),
            ),
            function (): void {
                printf(
                    '<p><i class="bi bi-info-circle"></i> %s</p>',
                    esc_html__(
                        "Sprawdź czy serwer spełnia wymagania wtyczki.",
                        "kasumi-ai-generator",
                    ),
                );
            },
            self::PAGE_SLUG,
        );

        add_settings_field(
            "kasumi_ai_diag_report",
            __("Status serwera", "kasumi-ai-generator"),
            function (): void {
                $this->render_diagnostics();
            },
            self::PAGE_SLUG,
            $section,
        );

        add_settings_field(
            "kasumi_ai_logs",
            __("Logi wtyczki", "kasumi-ai-generator"),
            function (): void {
                $this->render_logs_section();
            },
            self::PAGE_SLUG,
            $section,
        );
    }

    public function render_page(): void
    {
        if (!current_user_can("manage_options")) {
            return;
        }

        $user_id = get_current_user_id();
        $support_hidden_until = (int) get_user_meta(
            $user_id,
            self::SUPPORT_DISMISS_META,
            true,
        );
        $show_support_card = $support_hidden_until <= time();
        $install_time = (int) get_option("kasumi_ai_install_time", time());
        $days_using = max(
            1,
            (int) floor((time() - $install_time) / DAY_IN_SECONDS),
        );
        $automation = $this->get_automation_snapshot();
        $cron_notice = $this->consume_cron_notice();
        ?>
		<div class="wrap">
			<?php settings_errors(); ?>
			<?php if ($cron_notice): ?>
				<div class="notice notice-<?php echo esc_attr(
        $cron_notice["type"],
    ); ?>">
					<p><?php echo esc_html($cron_notice["message"]); ?></p>
				</div>
			<?php endif; ?>
			<h1><i class="bi bi-robot"></i> <?php esc_html_e(
       "Kasumi AI – konfiguracja",
       "kasumi-ai-generator",
   ); ?></h1>
			<p class="description"><i class="bi bi-sliders"></i> <?php esc_html_e(
       "Steruj integracjami API, harmonogramem generowania treści, komentarzy oraz grafik.",
       "kasumi-ai-generator",
   ); ?></p>
			<?php if ($show_support_card): ?>
				<div class="kasumi-support-card">
					<div class="kasumi-support-card__text">
						<p class="description" style="margin-top:0;"><?php esc_html_e(
          "Kasumi rozwijam po godzinach – jeśli automatyzacja oszczędza Ci czas, możesz postawić mi symboliczną kawę.",
          "kasumi-ai-generator",
      ); ?></p>
						<h2 style="margin:8px 0 12px;"><?php esc_html_e(
          "Postaw kawę twórcy Kasumi",
          "kasumi-ai-generator",
      ); ?></h2>
						<p style="margin:0;color:var(--wp-admin-text-color-dark);"><?php esc_html_e(
          "Wspierasz koszty API, serwera i rozwój nowych modułów (bez reklam i paywalla).",
          "kasumi-ai-generator",
      ); ?></p>
					</div>
					<div class="kasumi-support-card__actions">
						<form class="kasumi-support-card__dismiss" method="post" action="<?php echo esc_url(
          admin_url("admin-post.php"),
      ); ?>">
							<?php wp_nonce_field("kasumi_ai_support_card"); ?>
							<input type="hidden" name="action" value="kasumi_ai_support_card">
							<input type="hidden" name="kasumi_ai_support_action" value="dismiss">
							<button type="submit" class="button-link button-link-delete"><i class="bi bi-eye-slash"></i> <?php esc_html_e(
           "Ukryj na 7 dni",
           "kasumi-ai-generator",
       ); ?></button>
						</form>
						<p style="font-weight:600;margin-bottom:12px;"><i class="bi bi-heart-fill" style="color: var(--wp-admin-notification-color);"></i> <?php esc_html_e(
          "Dziękuję za każdą kawę!",
          "kasumi-ai-generator",
      ); ?></p>
						<div class="kasumi-support-card__button">
							<a class="button button-primary" href="https://buymeacoffee.com/kemuricodes" target="_blank" rel="noopener noreferrer"><?php esc_html_e(
           "Postaw mi kawę",
           "kasumi-ai-generator",
       ); ?></a>
						</div>
						<p style="margin-top:12px;font-size:12px;color:var(--wp-admin-text-color);opacity:0.8;"><?php esc_html_e(
          "Obsługiwane przez buymeacoffee.com",
          "kasumi-ai-generator",
      ); ?></p>
					</div>
				</div>
			<?php else: ?>
				<div class="kasumi-support-reminder">
					<p style="margin:0;">
						<?php
        $support_link = sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url("https://buymeacoffee.com/kemuricodes"),
            esc_html__("postawić kawę", "kasumi-ai-generator"),
        );
        $support_message = sprintf(
            /* translators: 1: number of days using Kasumi, 2: coffee link */
            __(
                'Korzystasz z Kasumi od %1$s dni. Jeśli narzędzie Ci pomaga, możesz zawsze %2$s.',
                "kasumi-ai-generator",
            ),
            number_format_i18n($days_using),
            $support_link,
        );
        echo wp_kses_post($support_message);
        ?>
					</p>
					<form method="post" action="<?php echo esc_url(
         admin_url("admin-post.php"),
     ); ?>">
						<?php wp_nonce_field("kasumi_ai_support_card"); ?>
						<input type="hidden" name="action" value="kasumi_ai_support_card">
						<input type="hidden" name="kasumi_ai_support_action" value="reset">
						<button type="submit" class="button button-secondary"><i class="bi bi-eye"></i> <?php esc_html_e(
          "Pokaż ponownie kartę wsparcia",
          "kasumi-ai-generator",
      ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<div class="kasumi-overview-grid">
				<div class="card kasumi-about">
					<h2><i class="bi bi-info-circle"></i> <?php esc_html_e(
         "O wtyczce",
         "kasumi-ai-generator",
     ); ?></h2>
					<p><?php esc_html_e(
         "Kasumi automatyzuje generowanie wpisów WordPress, komentarzy i grafik AI. Wybierz dostawcę (OpenAI lub Gemini), skonfiguruj harmonogram i podglądaj efekty na żywo.",
         "kasumi-ai-generator",
     ); ?></p>
					<ul>
						<li><i class="bi bi-person"></i> <?php esc_html_e(
          "Autor: Marcin Dymek (KemuriCodes)",
          "kasumi-ai-generator",
      ); ?></li>
						<li><i class="bi bi-envelope"></i> <?php esc_html_e(
          "Kontakt: contact@kemuri.codes",
          "kasumi-ai-generator",
      ); ?></li>
					</ul>
				</div>
				<div class="card kasumi-info-card">
					<h2><i class="bi bi-link-45deg"></i> <?php esc_html_e(
         "Szybkie linki",
         "kasumi-ai-generator",
     ); ?></h2>
					<ul>
						<li><i class="bi bi-box-arrow-up-right"></i> <a href="<?php echo esc_url(
          "https://platform.openai.com/account/api-keys",
      ); ?>" target="_blank" rel="noopener"><?php esc_html_e(
    "Panel OpenAI",
    "kasumi-ai-generator",
); ?></a></li>
						<li><i class="bi bi-box-arrow-up-right"></i> <a href="<?php echo esc_url(
          "https://aistudio.google.com/app/apikey",
      ); ?>" target="_blank" rel="noopener"><?php esc_html_e(
    "Google AI Studio",
    "kasumi-ai-generator",
); ?></a></li>
						<li><i class="bi bi-envelope"></i> <a href="mailto:contact@kemuri.codes"><?php esc_html_e(
          "Wsparcie KemuriCodes",
          "kasumi-ai-generator",
      ); ?></a></li>
					</ul>
				</div>
				<?php if (Options::get("status_logging")): ?>
					<?php
     $status = StatusStore::all();
     $date_format = get_option("date_format") . " " . get_option("time_format");
     $now = current_time("timestamp");
     $next_run = $status["next_post_run"]
         ? sprintf(
             "%s (%s)",
             date_i18n($date_format, (int) $status["next_post_run"]),
             sprintf(
                 /* translators: %s – relative time */
                 __("za %s", "kasumi-ai-generator"),
                 human_time_diff($now, (int) $status["next_post_run"]),
             ),
         )
         : __("Brak zaplanowanych zadań", "kasumi-ai-generator");
     $last_error = $status["last_error"]
         ? $status["last_error"]
         : __("Brak błędów", "kasumi-ai-generator");
     ?>
					<div class="card kasumi-ai-status">
						<h2><i class="bi bi-activity"></i> <?php esc_html_e(
          "Status modułu AI",
          "kasumi-ai-generator",
      ); ?></h2>
                        <?php if ( ! empty( $status['automation_notice'] ) ) : ?>
                            <div class="notice notice-warning kasumi-status-note">
                                <p><?php echo esc_html( (string) $status['automation_notice'] ); ?></p>
                            </div>
                        <?php endif; ?>
						<ul>
							<li><i class="bi bi-file-text"></i> <?php esc_html_e(
           "Ostatni post ID:",
           "kasumi-ai-generator",
       ); ?> <strong><?php echo esc_html(
     (string) ($status["last_post_id"] ?? "–"),
 ); ?></strong></li>
							<li><i class="bi bi-clock-history"></i> <?php esc_html_e(
           "Ostatnie uruchomienie:",
           "kasumi-ai-generator",
       ); ?> <strong><?php echo $status["last_post_time"]
     ? esc_html(date_i18n($date_format, (int) $status["last_post_time"]))
     : esc_html__("Brak", "kasumi-ai-generator"); ?></strong></li>
							<li><i class="bi bi-calendar-event"></i> <?php esc_html_e(
           "Następne zadanie:",
           "kasumi-ai-generator",
       ); ?> <strong><?php echo esc_html($next_run); ?></strong></li>
							<li><i class="bi bi-chat-dots"></i> <?php esc_html_e(
           "Kolejka komentarzy:",
           "kasumi-ai-generator",
       ); ?> <strong><?php echo esc_html(
     (string) ($status["queued_comment_jobs"] ?? 0),
 ); ?></strong></li>
							<li><i class="bi bi-exclamation-triangle"></i> <?php esc_html_e(
           "Ostatni błąd:",
           "kasumi-ai-generator",
       ); ?> <strong><?php echo esc_html($last_error); ?></strong></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
			<div class="kasumi-automation-row">
				<?php $this->render_automation_card($automation); ?>
			</div>

			<form action="<?php echo esc_url(admin_url("options.php")); ?>" method="post" data-kasumi-settings-form>
				<?php settings_fields(Options::OPTION_GROUP); ?>
				<div id="kasumi-ai-tabs" class="kasumi-ai-tabs">
					<ul>
						<li><a href="#kasumi-tab-api"><i class="bi bi-key"></i> <?php esc_html_e(
          "Integracje API",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-content"><i class="bi bi-file-earmark-text"></i> <?php esc_html_e(
          "Treści i harmonogram",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-images"><i class="bi bi-image"></i> <?php esc_html_e(
          "Grafiki AI",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-comments"><i class="bi bi-chat-left-text"></i> <?php esc_html_e(
          "Komentarze AI",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-stats"><i class="bi bi-bar-chart"></i> <?php esc_html_e(
          "Statystyki",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-advanced"><i class="bi bi-gear"></i> <?php esc_html_e(
          "Zaawansowane",
          "kasumi-ai-generator",
      ); ?></a></li>
						<li><a href="#kasumi-tab-diagnostics"><i class="bi bi-bug"></i> <?php esc_html_e(
          "Diagnostyka",
          "kasumi-ai-generator",
      ); ?></a></li>
					</ul>
					<div id="kasumi-tab-api" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_api"); ?>
					</div>
					<div id="kasumi-tab-content" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_content"); ?>
						<?php $this->render_schedule_manager_panel(); ?>
					</div>
					<div id="kasumi-tab-images" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_images"); ?>
					</div>
					<div id="kasumi-tab-comments" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_comments"); ?>
					</div>
					<div id="kasumi-tab-stats" class="kasumi-tab-panel">
						<?php $this->render_stats_tab(); ?>
					</div>
					<div id="kasumi-tab-advanced" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_misc"); ?>
					</div>
					<div id="kasumi-tab-diagnostics" class="kasumi-tab-panel">
						<?php $this->render_section("kasumi_ai_diag"); ?>
					</div>
				</div>
				<div class="kasumi-settings-notice" data-kasumi-settings-notice style="display:none;" role="status" aria-live="polite"></div>
				<div class="kasumi-settings-submit">
					<?php
					    submit_button(
					        esc_html__("Zapisz ustawienia", "kasumi-ai-generator"),
					        "primary",
					        "kasumi_ai_save",
					        false,
					        ["data-kasumi-save-button" => "1"],
					    );
					?>
					<span class="spinner" aria-hidden="true" data-kasumi-settings-spinner></span>
				</div>
			</form>

			<details class="kasumi-preview-details">
				<summary><i class="bi bi-eye"></i> <?php esc_html_e(
        "Podgląd wygenerowanej treści i grafiki",
        "kasumi-ai-generator",
    ); ?></summary>
				<div class="card kasumi-ai-preview-box">
					<p><i class="bi bi-info-circle"></i> <?php esc_html_e(
         "Wygeneruj przykładowy tekst lub obrazek, aby przetestować konfigurację bez publikacji.",
         "kasumi-ai-generator",
     ); ?></p>
					<div class="kasumi-ai-preview-actions">
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-text"><i class="bi bi-file-text"></i> <?php esc_html_e(
          "Przykładowy tekst",
          "kasumi-ai-generator",
      ); ?></button>
						<button type="button" class="button button-secondary" id="kasumi-ai-preview-image"><i class="bi bi-image"></i> <?php esc_html_e(
          "Podgląd grafiki",
          "kasumi-ai-generator",
      ); ?></button>
					</div>
					<div id="kasumi-ai-preview-output" class="kasumi-ai-preview-output" aria-live="polite"></div>
				</div>
			</details>
		</div>
		<?php
    }

    private function add_field(
        string $key,
        string $label,
        string $section,
        array $args = [],
    ): void {
        $defaults = [
            "type" => "text",
            "description" => "",
            "choices" => [],
            "min" => null,
            "max" => null,
            "step" => null,
            "placeholder" => "",
            "help" => "",
            "multiple" => false,
            "size" => null,
        ];

        $args = wp_parse_args($args, $defaults);

        if (!empty($args["help"])) {
            $label .= sprintf(
                ' <button type="button" class="kasumi-help dashicons dashicons-editor-help" data-kasumi-tooltip="%s" aria-label="%s"></button>',
                esc_attr($args["help"]),
                esc_attr(wp_strip_all_tags($label)),
            );
        }

        // Przechowaj klasę w args dla późniejszego użycia w render_section
        $field_id = "kasumi_ai_" . $key;

        add_settings_field(
            $field_id,
            wp_kses_post($label),
            function () use ($key, $args): void {
                $this->render_field($key, $args);
            },
            self::PAGE_SLUG,
            $section,
            $args, // Przekaż args jako szósty parametr
        );
    }

    private function render_field(string $key, array $args): void
    {
        $value = Options::get($key);
        $type = sanitize_key((string) ($args["type"] ?? "text"));
        if ("" === $type) {
            $type = "text";
        }

        switch ($type) {
            case "textarea":
                printf(
                    '<textarea name="%s[%s]" rows="3" class="large-text">%3$s</textarea>',
                    esc_attr(Options::OPTION_NAME),
                    esc_attr($key),
                    esc_textarea((string) $value),
                );
                break;
            case "select":
                $is_multiple = !empty($args["multiple"]);
                $field_name = sprintf(
                    '%s[%s]%3$s',
                    Options::OPTION_NAME,
                    $key,
                    $is_multiple ? "[]" : "",
                );
                $select_attributes = [];
                if ($is_multiple) {
                    $select_attributes["multiple"] = true;
                    if (!empty($args["size"])) {
                        $select_attributes["size"] = (int) $args["size"];
                    }
                }

                echo '<select name="' .
                    esc_attr($field_name) .
                    '"' .
                    $this->build_attribute_string($select_attributes) .
                    ">";

                $selected_values = $is_multiple
                    ? array_map("strval", is_array($value) ? $value : [])
                    : (string) $value;

                foreach ($args["choices"] as $option_value => $label) {
                    $is_selected = $is_multiple
                        ? in_array(
                            (string) $option_value,
                            $selected_values,
                            true,
                        )
                        : ((string) $option_value === $selected_values);
                    ?>
					<option value="<?php echo esc_attr( (string) $option_value ); ?>"<?php selected($is_selected); ?>><?php echo esc_html($label); ?></option>
					<?php
                }

                echo "</select>";
                break;
            case "checkbox":
                printf(
                    '<label><input type="checkbox" name="%s[%s]" value="1" %3$s> %4$s</label>',
                    esc_attr(Options::OPTION_NAME),
                    esc_attr($key),
                    checked(!empty($value), true, false),
                    esc_html__("Aktywne", "kasumi-ai-generator"),
                );
                break;
            case "model-select":
                $provider = $args["provider"] ?? "openai";
                $current = (string) $value;
                echo '<div class="kasumi-model-control" data-provider="' .
                    esc_attr($provider) .
                    '" data-autoload="1">';
                printf(
                    '<select name="%s[%s]" data-kasumi-model="%3$s" data-current-value="%4$s" class="regular-text">',
                    esc_attr(Options::OPTION_NAME),
                    esc_attr($key),
                    esc_attr($provider),
                    esc_attr($current),
                );
                if ($current) {
                    printf(
                        '<option value="%1$s">%1$s</option>',
                        esc_html($current),
                    );
                } else {
                    echo '<option value="">' .
                        esc_html__(
                            "Wybierz model…",
                            "kasumi-ai-generator",
                        ) .
                        "</option>";
                }
                echo "</select>";
                printf(
                    '<button type="button" class="button kasumi-refresh-models" data-provider="%s"><i class="bi bi-arrow-clockwise"></i> %s</button>',
                    esc_attr($provider),
                    esc_html__(
                        "Odśwież listę",
                        "kasumi-ai-generator",
                    ),
                );
                echo '<span class="spinner kasumi-model-spinner" aria-hidden="true"></span>';
                echo "</div>";
                break;
            case "category-select":
                $categories = get_categories([
                    "hide_empty" => false,
                    "orderby" => "name",
                    "order" => "ASC",
                ]);
                printf(
                    '<select name="%s[%s]" class="regular-text">',
                    esc_attr(Options::OPTION_NAME),
                    esc_attr($key),
                );
                echo '<option value="">' .
                    esc_html__(
                        "— Wybierz kategorię —",
                        "kasumi-ai-generator",
                    ) .
                    "</option>";
                foreach ($categories as $category) {
                    printf(
                        '<option value="%s" %s>%3$s</option>',
                        esc_attr((string) $category->term_id),
                        selected($value, (string) $category->term_id, false),
                        esc_html($category->name),
                    );
                }
                echo "</select>";
                break;
            case "color-picker":
                $color_value = (string) $value;
                // WordPress color picker wymaga # na początku
                if (
                    !empty($color_value) &&
                    "#" !== substr($color_value, 0, 1)
                ) {
                    $color_value = "#" . $color_value;
                }
                $field_id = "kasumi_ai_" . $key;
                printf(
                    '<input type="text" id="%s" name="%s[%3$s]" value="%4$s" class="wp-color-picker-field" data-default-color="%5$s" />',
                    esc_attr($field_id),
                    esc_attr(Options::OPTION_NAME),
                    esc_attr($key),
                    esc_attr($color_value),
                    esc_attr($color_value ?: "#1b1f3b"),
                );
                break;
			case "primary-links":
				$links = is_array($value) ? $value : [];
				$pages =
					$args["pages"] ?? $this->get_primary_link_page_choices();
                $allowed_row_html = $this->get_primary_link_row_allowed_html();
                $template_id = "kasumi-primary-links-template-" . uniqid();
                $next_index = count($links);
                echo '<div class="kasumi-primary-links" data-kasumi-primary-links data-template="#' .
                    esc_attr($template_id) .
                    '" data-next-index="' .
                    esc_attr((string) $next_index) .
                    '">';
                echo '<table class="widefat striped">';
                echo "<thead><tr><th>" .
                    esc_html__(
                        "Adres URL",
                        "kasumi-ai-generator",
                    ) .
                    "</th><th>" .
                    esc_html__(
                        "Frazy kluczowe",
                        "kasumi-ai-generator",
                    ) .
                    "</th><th></th></tr></thead>";
                echo "<tbody data-kasumi-primary-links-body>";

                if (!empty($links)) {
                    foreach ($links as $index => $entry) {
                        echo wp_kses(
                            $this->render_primary_link_row_html(
                                (string) $index,
                                (array) $entry,
                                $pages,
                            ),
                            $allowed_row_html,
                        );
                    }
                }

                echo "</tbody></table>";
                printf(
                    '<button type="button" class="button kasumi-primary-links-add" data-action="add-primary-link"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> %s</button>',
                    esc_html__(
                        "Dodaj wiersz",
                        "kasumi-ai-generator",
                    ),
                );
                echo "</div>";
                echo '<script type="text/html" id="' .
                    esc_attr($template_id) .
                    '">';
                echo wp_kses(
                    $this->render_primary_link_row_html(
                        "__INDEX__",
                        [],
                        $pages,
                        true,
                    ),
                    $allowed_row_html,
                );
                echo "</script>";
                    break;
			case "canvas-presets":
				$presets = $args["presets"] ?? array();
				$placeholder =
					$args["placeholder"] ??
					__(
						"— Wybierz gotowy rozmiar —",
						"kasumi-ai-generator",
					);

				printf(
					'<select class="kasumi-canvas-preset" data-kasumi-canvas-preset aria-label="%s">',
					esc_attr__(
						"Szybkie ustawienia proporcji",
						"kasumi-ai-generator",
					)
				);
				printf(
					'<option value="">%s</option>',
					esc_html( $placeholder )
				);

				foreach ( $presets as $preset ) {
					$label_text = (string) ( $preset['label'] ?? '' );
					$width      = (int) ( $preset['width'] ?? 0 );
					$height     = (int) ( $preset['height'] ?? 0 );

					if ( '' === $label_text || $width <= 0 || $height <= 0 ) {
						continue;
					}

					echo sprintf(
						'<option value="%s" data-width="%2$d" data-height="%3$d">%4$s</option>',
						esc_attr( $label_text ),
						(int) $width,
						(int) $height,
						esc_html( $label_text )
					);
				}

				echo "</select>";
				break;
                default:
                    $input_attributes = [
                        "type" => $type,
                        "class" => "regular-text",
                        "name" => sprintf(
                            "%s[%s]",
                            Options::OPTION_NAME,
                            $key,
                        ),
                        "value" => (string) $value,
                        "placeholder" => (string) ($args["placeholder"] ?? ""),
                    ];

                    if (isset($args["min"])) {
                        $input_attributes["min"] = (string) $args["min"];
                    }

                    if (isset($args["max"])) {
                        $input_attributes["max"] = (string) $args["max"];
                    }

                    if (isset($args["step"])) {
                        $input_attributes["step"] = (string) $args["step"];
                    }

                    echo "<input" .
                        $this->build_attribute_string($input_attributes) .
                        ">";
        }

        if (!empty($args["description"])) {
            printf(
                '<p class="description">%s</p>',
                wp_kses_post($args["description"]),
            );
        }
    }

    /**
     * @param array<string, string|int|bool> $attributes
     */
    private function build_attribute_string(array $attributes): string
    {
        $output = "";

        foreach ($attributes as $name => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $output .= " " . esc_attr($name);
                }
                continue;
            }

            $output .= sprintf(
                ' %s="%s"',
                esc_attr($name),
                esc_attr((string) $value),
            );
        }

        return $output;
    }

    /**
     * @param array{url?: string, anchors?: array<int, string>|string} $entry
     * @param array<int, array{label: string, url: string}>             $pages
     */
    private function render_primary_link_row_html(
        string $index,
        array $entry,
        array $pages,
        bool $is_template = false,
    ): string {
        $url = (string) ($entry["url"] ?? "");
        $anchors_value = "";

        if (isset($entry["anchors"])) {
            if (is_array($entry["anchors"])) {
                $anchors_value = implode(", ", $entry["anchors"]);
            } else {
                $anchors_value = (string) $entry["anchors"];
            }
        }

        $url_name = sprintf(
            "%s[primary_links][%s][url]",
            Options::OPTION_NAME,
            $index,
        );
        $anchors_name = sprintf(
            "%s[primary_links][%s][anchors]",
            Options::OPTION_NAME,
            $index,
        );

        ob_start();
        ?>
		<tr class="kasumi-primary-links-row">
			<td class="kasumi-primary-links-cell">
				<input type="url" class="regular-text" name="<?php echo esc_attr(
        $url_name,
    ); ?>" value="<?php echo esc_attr(
    $url,
); ?>" placeholder="https://example.com/oferta" data-link-url>
				<div class="kasumi-primary-links-picker">
					<select data-primary-link-select>
						<option value=""><?php esc_html_e(
          "— Wybierz stronę —",
          "kasumi-ai-generator",
      ); ?></option>
						<?php foreach ($pages as $page): ?>
							<option value="<?php echo esc_attr($page["url"]); ?>" <?php echo $is_template
    ? ""
    : selected($url, $page["url"], false); ?>>
								<?php echo esc_html($page["label"]); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button button-secondary" data-action="fill-primary-link">
						<?php esc_html_e("Wstaw adres", "kasumi-ai-generator"); ?>
					</button>
				</div>
			</td>
			<td class="kasumi-primary-links-cell">
				<textarea name="<?php echo esc_attr(
        $anchors_name,
    ); ?>" rows="2" placeholder="<?php esc_attr_e(
    "np. poradnik marketingowy, oferta usług, kontakt",
    "kasumi-ai-generator",
); ?>"><?php echo esc_textarea($anchors_value); ?></textarea>
				<p class="description"><?php esc_html_e(
        "Frazy rozdziel przecinkami – wybierz 2-4 naturalne warianty.",
        "kasumi-ai-generator",
    ); ?></p>
			</td>
			<td class="kasumi-primary-links-actions">
				<button type="button" class="button-link-delete kasumi-primary-links-remove" data-action="remove-primary-link">
					<?php esc_html_e("Usuń", "kasumi-ai-generator"); ?>
				</button>
			</td>
			</tr>
			<?php return (string) ob_get_clean();
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function get_primary_link_row_allowed_html(): array
    {
        return [
            "tr" => ["class" => true],
            "td" => ["class" => true],
            "div" => ["class" => true],
            "input" => [
                "type" => true,
                "class" => true,
                "name" => true,
                "value" => true,
                "placeholder" => true,
                "data-link-url" => true,
            ],
            "select" => [
                "class" => true,
                "name" => true,
                "data-primary-link-select" => true,
            ],
            "option" => [
                "value" => true,
                "selected" => true,
            ],
            "textarea" => [
                "name" => true,
                "rows" => true,
                "placeholder" => true,
            ],
            "button" => [
                "type" => true,
                "class" => true,
                "data-action" => true,
            ],
            "p" => ["class" => true],
        ];
    }

    /**
     * Renderuje pojedynczą sekcję Settings API.
     *
     * @param string $section_id Section identifier.
     */
    private function render_section(string $section_id): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        if (empty($wp_settings_sections[self::PAGE_SLUG][$section_id])) {
            return;
        }

        $section = $wp_settings_sections[self::PAGE_SLUG][$section_id];

        if (!empty($section["title"])) {
            printf("<h2>%s</h2>", wp_kses_post($section["title"]));
        }

        if (!empty($section["callback"])) {
            call_user_func($section["callback"], $section);
        }

        if (empty($wp_settings_fields[self::PAGE_SLUG][$section_id])) {
            return;
        }

        echo '<table class="form-table" role="presentation">';

        foreach (
            (array) $wp_settings_fields[self::PAGE_SLUG][$section_id]
            as $field
        ) {
            $class_attr = "";
            if (!empty($field["args"]["class"])) {
                $class_attr = sprintf(
                    ' class="%s"',
                    esc_attr($field["args"]["class"]),
                );
            }
            printf("<tr%s>", $class_attr);
            echo '<th scope="row">';
            if (!empty($field["args"]["label_for"])) {
                echo '<label for="' .
                    esc_attr($field["args"]["label_for"]) .
                    '">' .
                    wp_kses_post($field["title"]) .
                    "</label>";
            } else {
                echo wp_kses_post($field["title"]);
            }
            echo "</th><td>";
            call_user_func($field["callback"], $field["args"]);
            echo "</td></tr>";
        }

        echo "</table>";
    }

    private function render_schedule_manager_panel(): void
    {
        ?>
		<div class="kasumi-schedule-panel">
			<h3><i class="bi bi-calendar-check"></i> <?php esc_html_e(
       "Planowanie wpisów i harmonogram",
       "kasumi-ai-generator",
   ); ?></h3>
			<p class="description"><i class="bi bi-info-circle"></i> <?php esc_html_e(
       "Twórz własne zadania – wybierz autora, typ wpisu, status i dokładną datę publikacji. Kasumi wygeneruje treść w wybranym momencie.",
       "kasumi-ai-generator",
   ); ?></p>
			<div id="kasumi-schedule-manager" class="kasumi-schedule-grid">
				<div class="kasumi-schedule-form-column">
					<div data-kasumi-schedule-alert class="notice notice-success" style="display:none;"></div>
					<div data-kasumi-schedule-form role="form" aria-live="polite">
						<div class="kasumi-field">
							<label for="kasumi-schedule-title"><?php esc_html_e(
           "Tytuł roboczy",
           "kasumi-ai-generator",
       ); ?></label>
								<input type="text" id="kasumi-schedule-title" name="postTitle" class="regular-text" placeholder="<?php esc_attr_e(
	           "np. Raport trendów e-commerce 2026",
	           "kasumi-ai-generator",
	       ); ?>">
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-status"><?php esc_html_e(
           "Status zadania",
           "kasumi-ai-generator",
       ); ?></label>
							<select id="kasumi-schedule-status" name="status">
								<option value="draft"><?php esc_html_e(
            "Szkic (bez daty)",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="scheduled"><?php esc_html_e(
            "Zaplanowane",
            "kasumi-ai-generator",
        ); ?></option>
							</select>
						</div>
						<div class="kasumi-field-grid">
							<div>
								<label for="kasumi-schedule-post-type"><?php esc_html_e(
            "Typ wpisu",
            "kasumi-ai-generator",
        ); ?></label>
								<select id="kasumi-schedule-post-type" name="postType"></select>
							</div>
							<div>
								<label for="kasumi-schedule-post-status"><?php esc_html_e(
            "Status WordPress",
            "kasumi-ai-generator",
        ); ?></label>
								<select id="kasumi-schedule-post-status" name="postStatus">
									<option value="draft"><?php esc_html_e(
             "Szkic",
             "kasumi-ai-generator",
         ); ?></option>
									<option value="publish"><?php esc_html_e(
             "Publikuj automatycznie",
             "kasumi-ai-generator",
         ); ?></option>
								</select>
							</div>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-author"><?php esc_html_e(
           "Autor wpisu",
           "kasumi-ai-generator",
       ); ?></label>
							<select id="kasumi-schedule-author" name="authorId" data-placeholder="<?php esc_attr_e(
           "— Wybierz autora —",
           "kasumi-ai-generator",
       ); ?>"></select>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-date"><?php esc_html_e(
           "Data publikacji",
           "kasumi-ai-generator",
       ); ?></label>
							<input type="datetime-local" id="kasumi-schedule-date" name="publishAt">
							<p class="description"><?php esc_html_e(
           "Wymagane, gdy status ustawisz na „Zaplanowane”. Czas zostanie zapisany w strefie WordPress.",
           "kasumi-ai-generator",
       ); ?></p>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-model"><?php esc_html_e(
           "Model AI (opcjonalnie)",
           "kasumi-ai-generator",
       ); ?></label>
							<select id="kasumi-schedule-model" name="model" data-placeholder="<?php esc_attr_e(
           "Auto (globalny)",
           "kasumi-ai-generator",
       ); ?>"></select>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-system"><?php esc_html_e(
           "System prompt (opcjonalnie)",
           "kasumi-ai-generator",
       ); ?></label>
							<textarea id="kasumi-schedule-system" name="systemPrompt" rows="3" class="large-text" placeholder="<?php esc_attr_e(
           "Pozostaw pusty aby użyć globalnego ustawienia.",
           "kasumi-ai-generator",
       ); ?>"></textarea>
						</div>
						<div class="kasumi-field">
							<label for="kasumi-schedule-user"><?php esc_html_e(
           "Polecenie dla AI",
           "kasumi-ai-generator",
       ); ?></label>
							<textarea id="kasumi-schedule-user" name="userPrompt" rows="5" class="large-text" placeholder="<?php esc_attr_e(
           "Opisz temat, słowa kluczowe, ton wypowiedzi itd.",
           "kasumi-ai-generator",
       ); ?>"></textarea>
						</div>
						<div class="kasumi-field kasumi-actions">
							<button type="button" class="button button-primary" data-kasumi-schedule-submit><i class="bi bi-save"></i> <?php esc_html_e(
           "Zapisz zadanie",
           "kasumi-ai-generator",
       ); ?></button>
							<button type="button" class="button" data-kasumi-reset-form><i class="bi bi-x-circle"></i> <?php esc_html_e(
           "Wyczyść formularz",
           "kasumi-ai-generator",
       ); ?></button>
						</div>
					</div>
				</div>
				<div class="kasumi-schedule-list-column">
					<div class="kasumi-schedule-toolbar">
						<label>
							<span><?php esc_html_e("Status", "kasumi-ai-generator"); ?></span>
							<select data-kasumi-filter="status">
								<option value=""><?php esc_html_e(
            "Wszystkie",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="draft"><?php esc_html_e(
            "Szkice",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="scheduled"><?php esc_html_e(
            "Zaplanowane",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="running"><?php esc_html_e(
            "W trakcie",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="completed"><?php esc_html_e(
            "Wykonane",
            "kasumi-ai-generator",
        ); ?></option>
								<option value="failed"><?php esc_html_e(
            "Błędy",
            "kasumi-ai-generator",
        ); ?></option>
							</select>
						</label>
						<label>
							<span><?php esc_html_e("Autor", "kasumi-ai-generator"); ?></span>
							<select data-kasumi-filter="author" data-placeholder="<?php esc_attr_e(
           "Wszyscy",
           "kasumi-ai-generator",
       ); ?>">
								<option value=""><?php esc_html_e(
            "Wszyscy",
            "kasumi-ai-generator",
        ); ?></option>
							</select>
						</label>
						<label class="kasumi-search-field">
							<span class="screen-reader-text"><?php esc_html_e(
           "Szukaj",
           "kasumi-ai-generator",
       ); ?></span>
							<input type="search" placeholder="<?php esc_attr_e(
           "Szukaj po tytule/poleceniu…",
           "kasumi-ai-generator",
       ); ?>" data-kasumi-filter="search">
						</label>
							<div class="kasumi-toolbar-actions">
								<button type="button" class="button" data-kasumi-refresh><i class="bi bi-arrow-clockwise"></i> <?php esc_html_e(
	          "Odśwież",
	          "kasumi-ai-generator",
	      ); ?></button>
							</div>
					</div>
					<div data-kasumi-schedule-table class="kasumi-schedule-table">
						<p class="description"><?php esc_html_e(
          "Brak zadań w kolejce.",
          "kasumi-ai-generator",
      ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
    }

    /**
     * @return array<string, mixed>
     */
    private function get_scheduler_settings(): array
    {
        return [
            "restUrl" => esc_url_raw(rest_url("kasumi/v1/schedules")),
            "nonce" => wp_create_nonce("wp_rest"),
            "postTypes" => $this->get_scheduler_post_types(),
            "authors" => $this->get_scheduler_authors(),
            "defaults" => [
                "status" => "scheduled",
                "postStatus" => (string) Options::get(
                    "default_post_status",
                    "draft",
                ),
                "systemPrompt" => (string) Options::get("system_prompt", ""),
            ],
            "i18n" => [
                "saveLabel" => __(
                    "Zapisz zadanie",
                    "kasumi-ai-generator",
                ),
                "save" => __(
                    "Zapisano zadanie.",
                    "kasumi-ai-generator",
                ),
                "updateLabel" => __(
                    "Zaktualizuj zadanie",
                    "kasumi-ai-generator",
                ),
                "saved" => __(
                    "Zapisano zadanie.",
                    "kasumi-ai-generator",
                ),
                "updated" => __(
                    "Zaktualizowano zadanie.",
                    "kasumi-ai-generator",
                ),
                "deleted" => __(
                    "Usunięto zadanie.",
                    "kasumi-ai-generator",
                ),
                "run" => __(
                    "Uruchomiono generowanie.",
                    "kasumi-ai-generator",
                ),
                "error" => __(
                    "Coś poszło nie tak. Sprawdź logi i spróbuj ponownie.",
                    "kasumi-ai-generator",
                ),
                "loading" => __(
                    "Wczytywanie…",
                    "kasumi-ai-generator",
                ),
                "empty" => __(
                    "Brak zaplanowanych zadań.",
                    "kasumi-ai-generator",
                ),
                "noDate" => __("Brak daty", "kasumi-ai-generator"),
                "deleteConfirm" => __(
                    "Czy na pewno usunąć to zadanie?",
                    "kasumi-ai-generator",
                ),
                "edit" => __("Edytuj", "kasumi-ai-generator"),
                "runAction" => __(
                    "Uruchom teraz",
                    "kasumi-ai-generator",
                ),
                "delete" => __("Usuń", "kasumi-ai-generator"),
                "taskLabel" => __(
                    "Zadanie",
                    "kasumi-ai-generator",
                ),
                "statusLabel" => __(
                    "Status",
                    "kasumi-ai-generator",
                ),
                "publishLabel" => __(
                    "Publikacja",
                    "kasumi-ai-generator",
                ),
                "statusMap" => [
                    "draft" => __("Szkic", "kasumi-ai-generator"),
                    "scheduled" => __(
                        "Zaplanowane",
                        "kasumi-ai-generator",
                    ),
                    "running" => __(
                        "W trakcie",
                        "kasumi-ai-generator",
                    ),
                    "completed" => __(
                        "Zakończone",
                        "kasumi-ai-generator",
                    ),
                    "failed" => __("Błąd", "kasumi-ai-generator"),
                ],
                "titleRequired" => __(
                    "Podaj tytuł zadania.",
                    "kasumi-ai-generator",
                ),
                "authorRequired" => __(
                    "Wybierz autora zadania.",
                    "kasumi-ai-generator",
                ),
                "dateRequired" => __(
                    "Ustaw dokładną datę publikacji.",
                    "kasumi-ai-generator",
                ),
            ],
            "models" => $this->get_scheduler_models(),
        ];
    }
    private function get_automation_ui_config(): array
    {
        return [
            "restUrl" => esc_url_raw(rest_url("kasumi/v1/automation")),
            "nonce" => wp_create_nonce("wp_rest"),
            "snapshot" => $this->get_automation_snapshot(),
            "i18n" => [
                "checking" => __(
                    "Sprawdzanie stanu automatyzacji…",
                    "kasumi-ai-generator",
                ),
                "success" => __(
                    "Status automatyzacji zaktualizowany.",
                    "kasumi-ai-generator",
                ),
                "error" => __(
                    "Nie udało się pobrać stanu automatyzacji. Spróbuj ponownie.",
                    "kasumi-ai-generator",
                ),
                "refreshing" => __(
                    "Odświeżanie…",
                    "kasumi-ai-generator",
                ),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_scheduler_post_types(): array
    {
        $post_types = [];

        foreach (
            get_post_types(["show_ui" => true], "objects")
            as $name => $object
        ) {
            $label = $object->labels->singular_name ?? $name;
            $post_types[] = [
                "value" => $name,
                "label" => $label,
            ];
        }

        return $post_types;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_scheduler_authors(): array
    {
        $list = [];

        foreach (
            get_users([
                "capability__in" => ["edit_posts"],
                "orderby" => "display_name",
                "order" => "ASC",
                "fields" => ["ID", "display_name", "user_login"],
            ])
            as $user
        ) {
            $list[] = [
                "id" => (string) $user->ID,
                "name" => $user->display_name ?: $user->user_login,
            ];
        }

        return $list;
    }

    /**
     * Build [id => label] map for select controls.
     *
     * @return array<string, string>
     */
    private function get_author_select_choices(): array
    {
        $choices = [];

        foreach ($this->get_scheduler_authors() as $author) {
            $choices[(string) $author["id"]] = $author["name"];
        }

        return $choices;
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    private function get_primary_link_page_choices(): array
    {
        $pages = get_posts([
            "post_type" => ["page"],
            "post_status" => "publish",
            "orderby" => "title",
            "order" => "ASC",
            "posts_per_page" => 200,
        ]);

        $list = [
            [
                "label" => __(
                    "Strona główna",
                    "kasumi-ai-generator",
                ),
                "url" => home_url("/"),
            ],
        ];

        foreach ($pages as $page) {
            $url = get_permalink($page);

            if (empty($url)) {
                continue;
            }

            $title =
                $page->post_title ?:
                __("(Bez tytułu)", "kasumi-ai-generator");

            $list[] = [
                "label" => $title,
                "url" => $url,
            ];
        }

        return $list;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_scheduler_models(): array
    {
        $models = array_filter(
            array_unique([
                (string) Options::get("openai_model", "gpt-4.1-mini"),
                (string) Options::get("gemini_model", "gemini-2.0-flash"),
            ]),
            static fn($model) => !empty($model),
        );

        if (empty($models)) {
            $models = ["gpt-4.1-mini", "gemini-2.0-flash"];
        }

        return array_map(
            static fn($model) => [
                "value" => $model,
                "label" => $model,
            ],
            $models,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_automation_snapshot(): array
    {
        if (null === $this->automation_snapshot) {
            $scheduler = Module::instance()
                ?->get_scheduler();
            $this->automation_snapshot = AutomationStatus::snapshot($scheduler);
        }

        return $this->automation_snapshot;
    }

    private function set_cron_notice(string $type, string $message): void
    {
        set_transient(
            $this->cron_notice_key(),
            [
                "type" => $type,
                "message" => $message,
            ],
            MINUTE_IN_SECONDS,
        );
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function consume_cron_notice(): ?array
    {
        $key = $this->cron_notice_key();
        $notice = get_transient($key);

        if ($notice) {
            delete_transient($key);
        }

        return is_array($notice) ? $notice : null;
    }

    private function cron_notice_key(): string
    {
        return "kasumi_ai_cron_notice_" . (string) get_current_user_id();
    }

    private function render_cron_button(
        string $action,
        string $label,
        string $classes,
        string $icon,
        bool $disabled
    ): void {
        ?>
			<form method="post" action="<?php echo esc_url(
        admin_url("admin-post.php"),
    ); ?>" data-kasumi-automation-form data-kasumi-automation-action="<?php echo esc_attr(
        $action,
    ); ?>">
			<?php wp_nonce_field("kasumi_ai_cron_control"); ?>
			<input type="hidden" name="action" value="kasumi_ai_cron_control">
			<input type="hidden" name="kasumi_ai_cron_action" value="<?php echo esc_attr(
        $action,
    ); ?>">
			<button type="submit" class="<?php echo esc_attr(
        $classes,
    ); ?>" data-kasumi-automation-action="<?php echo esc_attr(
        $action,
        ); ?>"<?php disabled($disabled); ?>>
				<i class="<?php echo esc_attr($icon); ?>"></i> <?php echo esc_html(
        $label,
    ); ?>
			</button>
		</form>
		<?php
    }

    private function render_stats_tab(): void
    {
        $stats = StatsTracker::all();
        $totals = $stats["totals"] ?? [];
        $daily_stats = StatsTracker::get_last_days(30);
        $date_format = (string) get_option("date_format", "Y-m-d");

        $total_posts = (int) ($totals["posts"] ?? 0);
        $total_images = (int) ($totals["images"] ?? 0);
        $total_comments = (int) ($totals["comments"] ?? 0);
        $total_input_tokens = (int) ($totals["input_tokens"] ?? 0);
        $total_output_tokens = (int) ($totals["output_tokens"] ?? 0);
        $total_tokens = (int) ($totals["total_tokens"] ?? 0);
        $total_cost = (float) ($totals["cost"] ?? 0.0);
        ?>
			<h2><i class="bi bi-bar-chart"></i> <?php esc_html_e(
      "Statystyki użycia API",
      "kasumi-ai-generator",
  ); ?></h2>

		<div class="kasumi-stats-overview">
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-file-text"></i> <?php esc_html_e(
        "Wygenerowane posty",
        "kasumi-ai-generator",
    ); ?></h3>
				<p><?php echo esc_html(number_format_i18n($total_posts)); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-image"></i> <?php esc_html_e(
        "Wygenerowane grafiki",
        "kasumi-ai-generator",
    ); ?></h3>
				<p><?php echo esc_html(number_format_i18n($total_images)); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-chat-dots"></i> <?php esc_html_e(
        "Wygenerowane komentarze",
        "kasumi-ai-generator",
    ); ?></h3>
				<p><?php echo esc_html(number_format_i18n($total_comments)); ?></p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-hash"></i> <?php esc_html_e(
        "Całkowita liczba tokenów",
        "kasumi-ai-generator",
    ); ?></h3>
				<p><?php echo esc_html(number_format_i18n($total_tokens)); ?></p>
					<p>
						<?php
        $tokens_summary_text = sprintf(
            "%1\$s %2\$s | %3\$s %4\$s",
            esc_html__("Wejście:", "kasumi-ai-generator"),
            number_format_i18n($total_input_tokens),
            esc_html__("Wyjście:", "kasumi-ai-generator"),
            number_format_i18n($total_output_tokens),
        );
        echo esc_html($tokens_summary_text);
	        ?>
					</p>
			</div>
			<div class="kasumi-stat-card">
				<h3><i class="bi bi-currency-dollar"></i> <?php esc_html_e(
        "Szacunkowy koszt",
        "kasumi-ai-generator",
    ); ?></h3>
				<p>$<?php echo esc_html(number_format($total_cost, 4, ".", "")); ?></p>
				<p><?php esc_html_e(
        "USD (szacunkowo)",
        "kasumi-ai-generator",
    ); ?></p>
			</div>
		</div>

        <h3 style="margin: 40px 0 20px 0;"><?php esc_html_e(
      "Użycie w ciągu ostatnich 30 dni",
      "kasumi-ai-generator",
  ); ?></h3>
        <?php
        $has_activity = array_reduce(
            $daily_stats,
            static function (bool $carry, array $day): bool {
                return $carry || array_sum($day) > 0;
            },
            false,
        );
        ?>
		<?php if (!$has_activity): ?>
			<p class="kasumi-chart-empty"><?php esc_html_e(
       "Brak danych dla ostatnich 30 dni. Wykres pojawi się po pierwszym wygenerowanym zadaniu.",
       "kasumi-ai-generator",
   ); ?></p>
		<?php else: ?>
			<div class="kasumi-stats-table-wrapper">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e("Data", "kasumi-ai-generator"); ?></th>
							<th><?php esc_html_e("Posty", "kasumi-ai-generator"); ?></th>
							<th><?php esc_html_e("Grafiki", "kasumi-ai-generator"); ?></th>
							<th><?php esc_html_e("Komentarze", "kasumi-ai-generator"); ?></th>
							<th><?php esc_html_e("Tokeny", "kasumi-ai-generator"); ?></th>
							<th><?php esc_html_e("Koszt (USD)", "kasumi-ai-generator"); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($daily_stats as $date => $day): ?>
							<?php
        $timestamp = strtotime((string) $date);
        $display_date = $timestamp
            ? date_i18n($date_format, $timestamp)
            : $date;
        $posts = number_format_i18n((int) ($day["posts"] ?? 0));
        $images = number_format_i18n((int) ($day["images"] ?? 0));
        $comments = number_format_i18n((int) ($day["comments"] ?? 0));
        $tokens = number_format_i18n((int) ($day["total_tokens"] ?? 0));
        $cost_value = number_format_i18n((float) ($day["cost"] ?? 0), 4);
        ?>
							<tr>
								<td><?php echo esc_html($display_date); ?></td>
								<td><?php echo esc_html($posts); ?></td>
								<td><?php echo esc_html($images); ?></td>
								<td><?php echo esc_html($comments); ?></td>
								<td><?php echo esc_html($tokens); ?></td>
								<td>$<?php echo esc_html($cost_value); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
        <?php
    }
    private function render_automation_card(array $automation): void
    {
        $state_label = (string) ($automation["status_label"] ?? "");
        $state = (string) ($automation["state"] ?? "");
        $available = (bool) ($automation["available"] ?? false);
        $paused = (bool) ($automation["paused"] ?? false);
        $meta = $automation["meta"] ?? [];
        $next_label = (string) ($meta["next_post"]["label"] ?? __("Brak", "kasumi-ai-generator"));
        $manual_label = (string) ($meta["manual"]["label"] ?? __("Brak", "kasumi-ai-generator"));
        $comment_label = (string) ($meta["comment"]["label"] ?? __("Brak", "kasumi-ai-generator"));
        $queue_label = (string) ($automation["queue"]["label"] ?? number_format_i18n(0));
        $last_run_label = (string) ($automation["last_run"]["label"] ?? __("Brak", "kasumi-ai-generator"));
        $last_error_label = (string) ($automation["last_error"] ?? __("Brak błędów", "kasumi-ai-generator"));
        $block_reason = (string) ($automation["block_reason"] ?? "");
        $notice = (string) ($automation["notice"] ?? "");
        $fetched_at = isset($automation["fetched_at"])
            ? (int) $automation["fetched_at"]
            : current_time("timestamp");
        $date_format = get_option("date_format") . " " . get_option("time_format");
        $fetched_label = date_i18n($date_format, $fetched_at);
        $disable_all = !$available;
        ?>
			<div class="card kasumi-automation-controls" data-kasumi-automation>
				<div class="kasumi-automation-header">
						<div class="kasumi-automation-status">
							<h2><i class="bi bi-repeat"></i> <?php esc_html_e(
        "Kontrola WP-Cron",
        "kasumi-ai-generator",
    ); ?></h2>
							<p class="kasumi-automation-state" data-kasumi-automation-state><?php echo esc_html(
        $state_label,
    ); ?></p>
						</div>
					<div class="kasumi-automation-header-actions">
						<button type="button" class="button button-secondary" data-kasumi-automation-refresh>
							<i class="bi bi-arrow-repeat"></i> <?php esc_html_e(
        "Odśwież status",
        "kasumi-ai-generator",
    ); ?>
							</button>
						</div>
					</div>
					<p class="description"><?php esc_html_e(
        "Startuj lub zatrzymuj automatyczne generowanie oraz wymuszaj natychmiastową publikację.",
        "kasumi-ai-generator",
    ); ?></p>
					<div class="kasumi-automation-message" data-kasumi-automation-message aria-live="polite" hidden="hidden"></div>
					<div class="notice notice-warning kasumi-automation-block" data-kasumi-automation-block<?php echo "" === $block_reason
        ? ' hidden="hidden"'
        : ""; ?>>
						<p><?php echo "" !== $block_reason ? esc_html($block_reason) : ""; ?></p>
					</div>
					<div class="notice notice-info kasumi-automation-notice" data-kasumi-automation-notice<?php echo "" === $notice
        ? ' hidden="hidden"'
        : ""; ?>>
						<p><?php echo "" !== $notice ? esc_html($notice) : ""; ?></p>
					</div>
					<div class="kasumi-automation-meta">
						<div>
							<span><?php esc_html_e(
        "Następny post",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="next_post" data-placeholder="<?php echo esc_attr__(
        "Brak",
        "kasumi-ai-generator",
    ); ?>"><?php echo esc_html($next_label); ?></strong>
						</div>
						<div>
							<span><?php esc_html_e(
        "Zadania ręczne",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="manual" data-placeholder="<?php echo esc_attr__(
        "Brak",
        "kasumi-ai-generator",
    ); ?>"><?php echo esc_html($manual_label); ?></strong>
						</div>
						<div>
							<span><?php esc_html_e(
        "Cron komentarzy",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="comment" data-placeholder="<?php echo esc_attr__(
        "Brak",
        "kasumi-ai-generator",
    ); ?>"><?php echo esc_html($comment_label); ?></strong>
						</div>
					</div>
					<div class="kasumi-automation-summary">
						<div>
							<span><?php esc_html_e(
        "Ostatnie uruchomienie",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="last_run" data-placeholder="<?php echo esc_attr__(
        "Brak",
        "kasumi-ai-generator",
    ); ?>"><?php echo esc_html($last_run_label); ?></strong>
						</div>
						<div>
							<span><?php esc_html_e(
        "Kolejka komentarzy",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="queue" data-placeholder="0"><?php echo esc_html(
        $queue_label,
    ); ?></strong>
						</div>
						<div>
							<span><?php esc_html_e(
        "Ostatni błąd",
        "kasumi-ai-generator",
    ); ?></span>
							<strong data-kasumi-automation-field="last_error" data-placeholder="<?php echo esc_attr__(
        "Brak błędów",
        "kasumi-ai-generator",
    ); ?>"><?php echo esc_html($last_error_label); ?></strong>
						</div>
					</div>
					<div class="kasumi-automation-actions">
						<?php
        $this->render_cron_button(
            "start",
            __("Start", "kasumi-ai-generator"),
            "button button-primary",
            "bi bi-play-fill",
            $disable_all || !$paused,
        );
        $this->render_cron_button(
            "stop",
            __("Zatrzymaj", "kasumi-ai-generator"),
            "button button-secondary",
            "bi bi-stop-fill",
            $disable_all || $paused,
        );
        $this->render_cron_button(
            "restart",
            __("Restartuj WP-Cron", "kasumi-ai-generator"),
            "button",
            "bi bi-arrow-clockwise",
            $disable_all,
        );
        $this->render_cron_button(
            "run-post",
            __("Publikuj teraz", "kasumi-ai-generator"),
            "button button-link",
            "bi bi-lightning-fill",
            $disable_all,
        );
        $this->render_cron_button(
            "run-schedules",
            __("Wykonaj harmonogram", "kasumi-ai-generator"),
            "button button-link",
            "bi bi-calendar-check",
            $disable_all,
        );
        ?>
					</div>
					<p class="kasumi-automation-updated">
						<?php
        printf(
            /* translators: %s last sync date. */
            esc_html__(
                "Ostatnia synchronizacja: %s",
                "kasumi-ai-generator",
            ),
            '<strong data-kasumi-automation-updated>' . esc_html($fetched_label) . "</strong>",
        );
        ?>
					</p>
				</div>
		<?php
    }

    private function render_diagnostics(): void
    {
        $report = $this->get_environment_report();

        echo '<ul class="kasumi-diag-list">';
        foreach ($report as $row) {
            printf(
                "<li><strong>%s:</strong> %s</li>",
                esc_html($row["label"]),
                wp_kses_post($row["value"]),
            );
        }
        echo "</ul>";
    }

    private function get_environment_report(): array
    {
        $php_ok = version_compare(PHP_VERSION, "8.1", ">=");
        $rows = [
            [
                "label" => __("Wersja PHP", "kasumi-ai-generator"),
                "value" => $php_ok
                    ? '<span class="kasumi-ok">' .
                        esc_html(PHP_VERSION) .
                        "</span>"
                    : '<span class="kasumi-error">' .
                        esc_html(PHP_VERSION) .
                        "</span>",
            ],
        ];

        $extensions = [
            "curl" => extension_loaded("curl"),
            "mbstring" => extension_loaded("mbstring"),
        ];

        foreach ($extensions as $extension => $enabled) {
            $rows[] = [
                "label" => sprintf(
                    /* translators: %s is the PHP extension name. */
                    __(
                        "Rozszerzenie %s",
                        "kasumi-ai-generator",
                    ),
                    strtoupper($extension),
                ),
                "value" => $enabled
                    ? '<span class="kasumi-ok">' .
                        esc_html__(
                            "dostępne",
                            "kasumi-ai-generator",
                        ) .
                        "</span>"
                    : '<span class="kasumi-error">' .
                        esc_html__("brak", "kasumi-ai-generator") .
                        "</span>",
            ];
        }

        return $rows;
    }

    private function render_logs_section(): void
    {
        $logger = new Logger();
        $logs = $logger->get_recent_logs(50);
        $level_filter = "";
        if (isset($_GET["log_level"])) {
            $level_filter = sanitize_text_field(
                wp_unslash((string) $_GET["log_level"]),
            ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        // Filtruj po poziomie jeśli wybrano
        if (
            !empty($level_filter) &&
            in_array($level_filter, ["info", "warning", "error"], true)
        ) {
            $logs = array_filter($logs, function ($log) use ($level_filter) {
                return $log["level"] === $level_filter;
            });
        }
        ?>
			<div class="kasumi-logs-section">
				<div class="kasumi-logs-toolbar" style="margin-bottom: 12px;">
					<select name="log_level" id="kasumi-log-level-filter" data-kasumi-log-filter style="margin-right: 8px;">
					<option value=""><?php esc_html_e(
         "Wszystkie poziomy",
         "kasumi-ai-generator",
     ); ?></option>
					<option value="info" <?php selected($level_filter, "info"); ?>><?php esc_html_e(
    "Info",
    "kasumi-ai-generator",
); ?></option>
					<option value="warning" <?php selected(
         $level_filter,
         "warning",
     ); ?>><?php esc_html_e(
    "Ostrzeżenia",
    "kasumi-ai-generator",
); ?></option>
					<option value="error" <?php selected(
         $level_filter,
         "error",
     ); ?>><?php esc_html_e(
    "Błędy",
    "kasumi-ai-generator",
); ?></option>
				</select>
					<button type="button" class="button" id="kasumi-refresh-logs" data-kasumi-log-refresh><i class="bi bi-arrow-clockwise"></i> <?php esc_html_e(
        "Odśwież",
        "kasumi-ai-generator",
    ); ?></button>
			</div>
			<?php if (empty($logs)): ?>
				<p><?php esc_html_e("Brak logów.", "kasumi-ai-generator"); ?></p>
			<?php else: ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 180px;"><?php esc_html_e(
           "Data/Czas",
           "kasumi-ai-generator",
       ); ?></th>
							<th style="width: 100px;"><?php esc_html_e(
           "Poziom",
           "kasumi-ai-generator",
       ); ?></th>
							<th><?php esc_html_e("Wiadomość", "kasumi-ai-generator"); ?></th>
							<th style="width: 200px;"><?php esc_html_e(
           "Kontekst",
           "kasumi-ai-generator",
       ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($logs as $log): ?>
							<tr>
								<td><?php echo esc_html($log["date"]); ?></td>
								<td>
									<span class="kasumi-log-level kasumi-log-level-<?php echo esc_attr(
             $log["level"],
         ); ?>">
										<?php echo esc_html(strtoupper($log["level"])); ?>
									</span>
								</td>
								<td><?php echo esc_html($log["message"]); ?></td>
								<td>
									<?php if (!empty($log["context"])): ?>
										<details>
											<summary><?php esc_html_e(
               "Pokaż",
               "kasumi-ai-generator",
           ); ?></summary>
											<pre style="font-size: 11px; max-height: 150px; overflow: auto;"><?php echo esc_html(
               wp_json_encode(
                   $log["context"],
                   JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
               ),
           ); ?></pre>
										</details>
									<?php else: ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			<?php
    }

    private function render_settings_actions(): void
    {
        ?>
			<div class="kasumi-settings-actions" data-kasumi-settings-actions style="margin-top: 16px;">
				<p class="description"><?php esc_html_e(
       "Eksportuj, importuj lub zresetuj ustawienia wtyczki.",
       "kasumi-ai-generator",
   ); ?></p>
			<div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
				<button type="button" class="button" id="kasumi-export-settings">
					<i class="bi bi-download"></i> <?php esc_html_e(
         "Eksportuj ustawienia",
         "kasumi-ai-generator",
     ); ?>
				</button>
				<button type="button" class="button" id="kasumi-import-settings">
					<i class="bi bi-upload"></i> <?php esc_html_e(
         "Importuj ustawienia",
         "kasumi-ai-generator",
     ); ?>
				</button>
				<button type="button" class="button button-secondary" id="kasumi-reset-settings">
					<i class="bi bi-arrow-counterclockwise"></i> <?php esc_html_e(
         "Resetuj do domyślnych",
         "kasumi-ai-generator",
     ); ?>
				</button>
				</div>
				<input type="file" id="kasumi-import-file" accept=".json" style="display: none;" />
			</div>
			<?php
    }
}
