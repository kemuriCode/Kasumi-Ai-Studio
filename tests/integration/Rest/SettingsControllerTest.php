<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Integration\Rest;

use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Rest\SettingsController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @group integration
 * @group rest
 */
final class SettingsControllerTest extends TestCase {
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new SettingsController();
		$this->controller->register();
	}

	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, array() );
	}

	public function test_export_returns_valid_json(): void {
		update_option( Options::OPTION_NAME, array( 'openai_model' => 'gpt-4' ) );

		$request = new WP_REST_Request( 'GET', '/kasumi/v1/settings/export' );
		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertIsString( $data['data'] );
		$decoded = json_decode( $data['data'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayNotHasKey( 'openai_api_key', $decoded );
	}

	public function test_import_validates_json(): void {
		$request = new WP_REST_Request( 'POST', '/kasumi/v1/settings/import' );
		$request->set_body_params( array( 'json' => 'invalid json' ) );
		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_success() );
	}

	public function test_import_requires_json_param(): void {
		$request = new WP_REST_Request( 'POST', '/kasumi/v1/settings/import' );
		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_success() );
	}

	public function test_import_sanitizes_data(): void {
		$json = wp_json_encode( array( 'openai_model' => 'gpt-4', 'word_count_min' => 500 ) );

		$request = new WP_REST_Request( 'POST', '/kasumi/v1/settings/import' );
		$request->set_body_params( array( 'json' => $json ) );
		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_success() );
		$imported = Options::get( 'openai_model' );
		$this->assertSame( 'gpt-4', $imported );
	}

	public function test_reset_removes_options(): void {
		update_option( Options::OPTION_NAME, array( 'openai_model' => 'gpt-4' ) );

		$request = new WP_REST_Request( 'POST', '/kasumi/v1/settings/reset' );
		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_success() );
		$options = get_option( Options::OPTION_NAME );
		$this->assertFalse( $options );
	}

	public function test_reset_requires_permission(): void {
		// Symuluj brak uprawnień
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/kasumi/v1/settings/reset' );
		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 403, $response->get_status() );
	}
}

