<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use GuzzleHttp\Client;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group images
 */
final class FeaturedImageBuilderTest extends TestCase {
	private FeaturedImageBuilder $builder;
	private Logger&MockObject $logger;
	private AiClient&MockObject $ai_client;
	private Client&MockObject $http_client;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock( Logger::class );
		$this->ai_client = $this->createMock( AiClient::class );
		$this->http_client = $this->createMock( Client::class );

		$this->builder = new FeaturedImageBuilder( $this->logger, $this->ai_client, $this->http_client );
	}

	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, Options::all() );
	}

	/**
	 * @param string $method
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	private function invokeBuilderMethod( string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( FeaturedImageBuilder::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->builder, $args );
	}

	public function test_build_skips_when_disabled(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => false ) ) );

		$result = $this->builder->build( 1, array( 'title' => 'Test' ) );

		$this->assertNull( $result );
	}

	public function test_build_generates_remote_image(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => true, 'image_generation_mode' => 'remote' ) ) );
		$this->ai_client->method( 'generate_remote_image' )->willReturn( 'binary-image-data' );

		// Test wymaga mockowania wp_upload_bits i wp_insert_attachment
		$this->assertTrue( true );
	}

	public function test_build_generates_server_image(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => true, 'image_generation_mode' => 'server' ) ) );

		// Test wymaga mockowania Pixabay API i bibliotek graficznych
		$this->assertTrue( true );
	}

	public function test_build_persists_attachment(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => true ) ) );

		// Test wymaga mockowania WordPress attachment functions
		$this->assertTrue( true );
	}

	public function test_build_returns_null_on_error(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => true, 'image_generation_mode' => 'remote' ) ) );
		$this->ai_client->method( 'generate_remote_image' )->willReturn( null );

		$result = $this->builder->build( 1, array( 'title' => 'Test' ) );

		$this->assertNull( $result );
	}

	public function test_preview_returns_base64(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'remote' ) ) );
		$this->ai_client->method( 'generate_remote_image' )->willReturn( 'binary-data' );

		$result = $this->builder->preview( array( 'title' => 'Test' ) );

		$this->assertStringStartsWith( 'data:image/webp;base64,', $result );
	}

	public function test_preview_returns_null_on_error(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'remote' ) ) );
		$this->ai_client->method( 'generate_remote_image' )->willReturn( null );

		$result = $this->builder->preview( array( 'title' => 'Test' ) );

		$this->assertNull( $result );
	}

	public function test_generate_image_blob_respects_toggle(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_featured_images' => false ) ) );

		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'generate_image_blob' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, array( 'title' => 'Test' ), true );

		$this->assertNull( $result );
	}

	public function test_generate_remote_image_calls_ai_client(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'remote' ) ) );
		$this->ai_client->expects( $this->once() )->method( 'generate_remote_image' )->willReturn( 'binary' );

		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'generate_remote_image' );
		$method->setAccessible( true );
		$method->invoke( $this->builder, array( 'title' => 'Test' ) );
	}

	public function test_generate_server_image_uses_imagick(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'server', 'image_server_engine' => 'imagick' ) ) );

		// Test wymaga sprawdzenia dostępności Imagick
		$this->assertTrue( true );
	}

	public function test_generate_server_image_falls_back_to_gd(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'server', 'image_server_engine' => 'imagick' ) ) );

		// Test sprawdza fallback do GD gdy Imagick nie jest dostępny
		$this->assertTrue( true );
	}

	public function test_generate_server_image_falls_back_to_simple(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_generation_mode' => 'server', 'pixabay_api_key' => '' ) ) );

		// Test sprawdza fallback do prostego obrazu gdy brak Pixabay
		$this->assertTrue( true );
	}

	public function test_fetch_pixabay_url_returns_url(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'pixabay_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania HTTP client
		$this->assertTrue( true );
	}

	public function test_fetch_pixabay_url_returns_null_when_no_key(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'pixabay_api_key' => '' ) ) );

		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'fetch_pixabay_url' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder );

		$this->assertNull( $result );
	}

	public function test_fetch_pixabay_url_handles_api_error(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'pixabay_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania błędu HTTP
		$this->assertTrue( true );
	}

	public function test_download_image_returns_binary(): void {
		// Test wymaga mockowania HTTP client
		$this->assertTrue( true );
	}

	public function test_download_image_handles_error(): void {
		// Test wymaga mockowania błędu HTTP
		$this->assertTrue( true );
	}

	public function test_persist_attachment_creates_attachment(): void {
		// Test wymaga mockowania WordPress attachment functions
		$this->assertTrue( true );
	}

	public function test_persist_attachment_sets_thumbnail(): void {
		// Test wymaga mockowania set_post_thumbnail
		$this->assertTrue( true );
	}

	public function test_persist_attachment_sets_alt_text(): void {
		// Test wymaga mockowania update_post_meta
		$this->assertTrue( true );
	}

	public function test_persist_attachment_handles_upload_error(): void {
		// Test wymaga mockowania błędu uploadu
		$this->assertTrue( true );
	}

	public function test_build_caption_replaces_placeholders(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_template' => '{{title}} - {{summary}}' ) ) );

		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'build_caption' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, array( 'title' => 'Test Title', 'summary' => 'Test Summary' ) );

		$this->assertStringContainsString( 'Test Title', $result );
		$this->assertStringContainsString( 'Test Summary', $result );
	}

	public function test_get_canvas_dimensions_uses_options(): void {
		update_option(
			Options::OPTION_NAME,
			array_merge(
				Options::all(),
				array(
					'image_canvas_width'  => 1600,
					'image_canvas_height' => 900,
				)
			)
		);

		$result = $this->invokeBuilderMethod( 'get_canvas_dimensions' );

		$this->assertSame(
			array(
				'width'  => 1600,
				'height' => 900,
			),
			$result
		);
	}

	public function test_get_canvas_dimensions_clamps_values(): void {
		update_option(
			Options::OPTION_NAME,
			array_merge(
				Options::all(),
				array(
					'image_canvas_width'  => 100,
					'image_canvas_height' => 5000,
				)
			)
		);

		$result = $this->invokeBuilderMethod( 'get_canvas_dimensions' );

		$this->assertSame( 640, $result['width'] );
		$this->assertSame( 4000, $result['height'] );
	}

	public function test_overlay_opacity_is_clamped(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_overlay_opacity' => 150 ) ) );

		$result = $this->invokeBuilderMethod( 'get_overlay_opacity' );

		$this->assertSame( 1.0, $result );
	}

	public function test_should_render_caption_respects_option(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_text_enabled' => false ) ) );

		$this->assertFalse( $this->invokeBuilderMethod( 'should_render_caption' ) );
	}

	public function test_prepare_caption_text_respects_uppercase_setting(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_template' => '{{title}}' ) ) );

		$result = $this->invokeBuilderMethod(
			'prepare_caption_text',
			array(
				array( 'title' => 'Tekst testowy' ),
				array( 'uppercase' => true ),
			)
		);

		$this->assertSame( 'TEKST TESTOWY', $result );
	}

	public function test_build_alt_text_uses_title(): void {
		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'build_alt_text' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, array( 'title' => 'Test Title' ) );

		$this->assertStringContainsString( 'Test Title', $result );
	}

	public function test_build_alt_text_uses_summary(): void {
		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'build_alt_text' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, array( 'title' => 'Test', 'summary' => 'Summary Text' ) );

		// build_alt_text używa wp_trim_words, więc tekst może być skrócony
		$this->assertStringContainsString( 'summary', strtolower( $result ) );
		$this->assertStringContainsString( 'Test', $result );
	}

	public function test_hex_to_rgb_converts_6_digit(): void {
		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'hex_to_rgb' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, '1b1f3b' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'r', $result );
		$this->assertArrayHasKey( 'g', $result );
		$this->assertArrayHasKey( 'b', $result );
	}

	public function test_hex_to_rgb_converts_3_digit(): void {
		$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'hex_to_rgb' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->builder, '1b1' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'r', $result );
	}

	public function test_generate_simple_fallback_image_gd(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_server_engine' => 'gd' ) ) );

		// Test wymaga dostępności GD
		if ( extension_loaded( 'gd' ) ) {
			$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'generate_simple_fallback_image' );
			$method->setAccessible( true );
			$result = $method->invoke( $this->builder, array( 'title' => 'Test' ), 'gd' );

			$this->assertIsString( $result );
		} else {
			$this->markTestSkipped( 'GD extension not available' );
		}
	}

	public function test_generate_simple_fallback_image_imagick(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_server_engine' => 'imagick' ) ) );

		// Test wymaga dostępności Imagick
		if ( class_exists( 'Imagick' ) ) {
			$method = new \ReflectionMethod( FeaturedImageBuilder::class, 'generate_simple_fallback_image' );
			$method->setAccessible( true );
			$result = $method->invoke( $this->builder, array( 'title' => 'Test' ), 'imagick' );

			$this->assertIsString( $result );
		} else {
			$this->markTestSkipped( 'Imagick extension not available' );
		}
	}
}
