<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Rest;

use Kasumi\AIGenerator\Cron\Scheduler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function add_action;
use function array_merge;
use function current_user_can;

final class AutomationController {
	private const REST_NAMESPACE = 'kasumi/v1';
	private const BASE_ROUTE = '/automation';

	public function __construct( private Scheduler $scheduler ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::BASE_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		$actions = array(
			'start'         => array( $this, 'start_automation' ),
			'stop'          => array( $this, 'stop_automation' ),
			'restart'       => array( $this, 'restart_automation' ),
			'run-post'      => array( $this, 'run_post' ),
			'run-schedules' => array( $this, 'run_schedules' ),
		);

		foreach ( $actions as $path => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::BASE_ROUTE . '/' . $path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => $callback,
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond_with_status();
	}

	public function start_automation( WP_REST_Request $request ): WP_REST_Response {
		$this->scheduler->resume();

		return $this->respond_with_status(
			__( 'Automatyzacja została uruchomiona.', 'kasumi-full-ai-content-generator' )
		);
	}

	public function stop_automation( WP_REST_Request $request ): WP_REST_Response {
		$this->scheduler->pause();

		return $this->respond_with_status(
			__( 'Automatyzacja została zatrzymana.', 'kasumi-full-ai-content-generator' )
		);
	}

	public function restart_automation( WP_REST_Request $request ): WP_REST_Response {
		$this->scheduler->restart();

		return $this->respond_with_status(
			__( 'Zadania WP-Cron zostały odświeżone.', 'kasumi-full-ai-content-generator' )
		);
	}

	public function run_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$success = $this->scheduler->run_post_now( false );

		if ( ! $success ) {
			$status = $this->scheduler->get_status_snapshot();
			$reason = (string) ( $status['block_reason'] ?? '' );

			return $this->automation_error(
				'kasumi_run_post_failed',
				'' !== $reason
					? $reason
					: __( 'Nie udało się wymusić publikacji. Sprawdź konfigurację API i logi.', 'kasumi-full-ai-content-generator' )
			);
		}

		return $this->respond_with_status(
			__( 'Rozpoczęto natychmiastowe generowanie posta.', 'kasumi-full-ai-content-generator' )
		);
	}

	public function run_schedules( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$processed = $this->scheduler->run_manual_queue_now( false, 5 );
		$status    = $this->scheduler->get_status_snapshot();

		if ( 0 === $processed && ! empty( $status['block_reason'] ) ) {
			return $this->automation_error(
				'kasumi_run_schedule_failed',
				$status['block_reason']
			);
		}

		return $this->respond_with_status(
			__( 'W kolejce zadań sprawdzono oczekujące wpisy.', 'kasumi-full-ai-content-generator' ),
			array( 'processed' => $processed )
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	private function respond_with_status( string $message = '', array $extra = array() ): WP_REST_Response {
		$payload = array(
			'success' => true,
			'status'  => $this->scheduler->get_status_snapshot(),
		);

		if ( '' !== $message ) {
			$payload['message'] = $message;
		}

		if ( ! empty( $extra ) ) {
			$payload = array_merge( $payload, $extra );
		}

		return new WP_REST_Response( $payload );
	}

	private function automation_error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array( 'status' => $status )
		);
	}
}
