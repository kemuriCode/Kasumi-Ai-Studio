<?php
/**
 * Plugin Name: KasumiAI - Full AI Content Generator
 * Plugin URI: https://wordpress.org/plugins/kasumi-ai-generator
 * Description: Nowoczesna wtyczka AI z pełnym wsparciem dla najnowszych modeli GPT-5.1, GPT-4o (OpenAI) oraz Gemini 3 (Google). Obsługuje także starsze modele (GPT-4.1, GPT-4o-mini, Gemini 2.0 Flash) - wybierz model odpowiedni dla Ciebie!
 * Author: Marcin Dymek (KemuriCodes)
 * Version: 0.1.8.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kasumi-ai-generator
 *
 * @package Kasumi\AIGenerator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KASUMI_AI_VERSION', '0.1.8.4' );
define( 'KASUMI_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'KASUMI_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'KASUMI_AI_DB_VERSION', '2024112701' );

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Kasumi AI wymaga PHP 8.2 lub wyższej wersji. Zaktualizuj środowisko, aby aktywować wtyczkę.', 'kasumi-ai-generator' )
			);
		}
	);

	return;
}

$kasumi_autoload = KASUMI_AI_PATH . 'vendor/autoload.php';

if ( ! file_exists( $kasumi_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Brak katalogu vendor. Uruchom composer install w folderze wtyczki Kasumi.', 'kasumi-ai-generator' )
			);
		}
	);

	return;
}

require_once $kasumi_autoload;

use Kasumi\AIGenerator\Admin\SettingsPage;
use Kasumi\AIGenerator\Installer\DatabaseMigrator;
use Kasumi\AIGenerator\Module;
use Kasumi\AIGenerator\Options;

register_activation_hook(
	__FILE__,
	static function (): void {
		DatabaseMigrator::migrate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( Options::get( 'delete_tables_on_deactivation', false ) ) {
			DatabaseMigrator::drop_tables();
			delete_option( 'kasumi_ai_db_version' );
		}
	}
);

add_action(
	'admin_init',
	static function (): void {
		if ( ! function_exists( 'extension_loaded' ) ) {
			return;
		}

		$missing = array();

		if ( ! extension_loaded( 'curl' ) ) {
			$missing[] = 'cURL';
		}

		if ( ! extension_loaded( 'mbstring' ) ) {
			$missing[] = 'mbstring';
		}

		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						wp_kses_post(
							sprintf(
								/* translators: %s list of extensions */
								__( 'Kasumi AI wymaga rozszerzeń PHP: %s. Skontaktuj się z administratorem serwera.', 'kasumi-ai-generator' ),
								implode( ', ', $missing )
							)
						)
					);
				}
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new Module() )->register();
	}
);

// Add a Settings shortcut on the plugins list.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . SettingsPage::get_page_slug() ) ),
			esc_html__( 'Ustawienia', 'kasumi-ai-generator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
);

// Append extra meta links below the plugin description.
add_filter(
	'plugin_row_meta',
	static function ( array $links, string $file ): array {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$row_meta = array(
			'coffee' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( 'https://buymeacoffee.com/kemuricodes' ),
				esc_html__( 'Postaw kawę', 'kasumi-ai-generator' )
			),
		);

		return array_merge( $links, $row_meta );
	},
	10,
	2
);
