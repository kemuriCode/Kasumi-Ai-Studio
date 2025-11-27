<?php
/**
 * Plugin Name: Kasumi – Full AI Content Generator
 * Description: Automatyzuje generowanie wpisów, komentarzy i grafik przy użyciu OpenAI oraz Google Gemini.
 * Author: Marcin Dymek (KemuriCodes)
 * Version: 0.1.0
 * Text Domain: kasumi-ai-generator
 *
 * @package Kasumi\AIGenerator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KASUMI_AI_VERSION', '0.1.0' );
define( 'KASUMI_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'KASUMI_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'KASUMI_AI_DB_VERSION', '2024112701' );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Kasumi AI wymaga PHP 8.1 lub wyższej wersji. Zaktualizuj środowisko, aby aktywować wtyczkę.', 'kasumi-ai-generator' )
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

use Kasumi\AIGenerator\Installer\DatabaseMigrator;
use Kasumi\AIGenerator\Module;
register_activation_hook(
	__FILE__,
	static function (): void {
		DatabaseMigrator::migrate();
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
		load_plugin_textdomain(
			'kasumi-ai-generator',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		( new Module() )->register();
	}
);

// Dodaj link do ustawień na liście wtyczek
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=kasumi-ai-generator-ai-content' ) ),
			esc_html__( 'Ustawienia', 'kasumi-ai-generator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
);

// Dodaj linki w meta informacjach wtyczki (row meta)
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
