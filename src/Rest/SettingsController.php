<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Rest;

use Kasumi\AIGenerator\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function current_user_can;
use function register_rest_route;

/**
 * REST API kontroler dla ustawień wtyczki.
 */
final class SettingsController {
	private const NAMESPACE = 'kasumi/v1';
	private const ROUTE = 'settings';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::ROUTE . '/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::ROUTE . '/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::ROUTE . '/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Sprawdza uprawnienia użytkownika.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Eksportuje ustawienia jako JSON.
	 */
	public function export_settings( WP_REST_Request $request ): WP_REST_Response {
		$json = Options::export();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $json,
			),
			200
		);
	}

	/**
	 * Importuje ustawienia z JSON.
	 */
	public function import_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$json = $request->get_param( 'json' );

		if ( empty( $json ) ) {
			return new WP_Error(
				'missing_json',
				__( 'Brak danych JSON do zaimportowania.', 'kasumi-ai-generator' ),
				array( 'status' => 400 )
			);
		}

		$result = Options::import( $json );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'import_failed',
				$result['message'],
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * Resetuje ustawienia do domyślnych.
	 */
	public function reset_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$deleted = delete_option( Options::OPTION_NAME );

		if ( ! $deleted ) {
			return new WP_Error(
				'reset_failed',
				__( 'Nie udało się zresetować ustawień.', 'kasumi-ai-generator' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Ustawienia zostały zresetowane do domyślnych.', 'kasumi-ai-generator' ),
			),
			200
		);
	}
}

