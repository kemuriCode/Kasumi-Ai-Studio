<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Rest;

use Kasumi\AIGenerator\Service\ScheduleService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function add_action;
use function current_user_can;
use function register_rest_route;

class SchedulesController {
	private const REST_NAMESPACE = 'kasumi/v1';
	private const ROUTE = '/schedules';

	public function __construct( private ScheduleService $schedule_service ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schedules' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_schedule' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_schedule' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_schedule' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE . '/(?P<id>[\d]+)/run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_schedule' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function get_schedules( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'status'   => $request->get_param( 'status' ),
			'author'   => $request->get_param( 'author' ),
			'search'   => $request->get_param( 'search' ),
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
		);

		$result = $this->schedule_service->list( $args );

		return new WP_REST_Response( $result );
	}

	public function create_schedule( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->extract_payload( $request );
		$item = $this->schedule_service->create( $data );

		return new WP_REST_Response( $item, 201 );
	}

	public function update_schedule( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		$existing = $this->schedule_service->find( $id );

		if ( ! $existing ) {
			return new WP_REST_Response( new WP_Error( 'kag_schedule_not_found', __( 'Nie znaleziono zadania.', 'kasumi-full-ai-content-generator' ), array( 'status' => 404 ) ) );
		}

		$item = $this->schedule_service->update( $id, $this->extract_payload( $request, true ) );

		return new WP_REST_Response( $item );
	}

	public function delete_schedule( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];

		if ( ! $this->schedule_service->delete( $id ) ) {
			return new WP_REST_Response( new WP_Error( 'kag_schedule_not_found', __( 'Nie znaleziono zadania.', 'kasumi-full-ai-content-generator' ), array( 'status' => 404 ) ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	public function run_schedule( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		$existing = $this->schedule_service->find( $id );

		if ( ! $existing ) {
			return new WP_REST_Response( new WP_Error( 'kag_schedule_not_found', __( 'Nie znaleziono zadania.', 'kasumi-full-ai-content-generator' ), array( 'status' => 404 ) ) );
		}

		$success = $this->schedule_service->run_now( $id );

		return new WP_REST_Response(
			array(
				'status'  => $success ? 'completed' : 'failed',
				'postId'  => $success ? ( $this->schedule_service->find( $id )['resultPostId'] ?? null ) : null,
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'edit_posts' );
	}

	private function extract_payload( WP_REST_Request $request, bool $partial = false ): array {
		$fields = array(
			'status'       => 'status',
			'post_type'    => 'postType',
			'post_status'  => 'postStatus',
			'post_title'   => 'postTitle',
			'publish_at'   => 'publishAt',
			'author_id'    => 'authorId',
			'system_prompt'=> 'systemPrompt',
			'user_prompt'  => 'userPrompt',
			'model'        => 'model',
			'template_slug'=> 'templateSlug',
			'meta'         => 'meta',
		);

		$data = array();

		foreach ( $fields as $db_key => $request_key ) {
			if ( $partial && null === $request->get_param( $request_key ) ) {
				continue;
			}

			$data[ $db_key ] = $request->get_param( $request_key );
		}

		return $data;
	}
}

