<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Service\AiClient;

use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

class ModelsController {
	public function __construct( private AiClient $ai_client ) {}

	public function register(): void {
		add_action( 'wp_ajax_kasumi_ai_models', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'kasumi-full-ai-content-generator' ) ), 403 );
		}

		check_ajax_referer( 'kasumi_ai_models', 'nonce' );

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'openai' ) );

		$models = $this->ai_client->list_models( $provider );

		if ( empty( $models ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Brak modeli lub błędny klucz API.', 'kasumi-full-ai-content-generator' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'models' => $models,
			)
		);
	}
}
