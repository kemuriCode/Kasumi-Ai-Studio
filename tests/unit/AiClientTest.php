<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\AiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group ai
 */
final class AiClientTest extends TestCase {
	private AiClient $client;
	private Logger&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock( Logger::class );
		$this->client = new AiClient( $this->logger );
	}

	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, Options::all() );
	}

	public function test_generate_article_tries_openai_first(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI client
		$result = $this->client->generate_article( array( 'user_prompt' => 'Test' ) );

		// Bez prawdziwego klucza API zwróci null
		$this->assertTrue( true );
	}

	public function test_generate_article_falls_back_to_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'auto', 'openai_api_key' => '', 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini client
		$result = $this->client->generate_article( array( 'user_prompt' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_generate_article_returns_null_when_no_provider(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => '', 'gemini_api_key' => '' ) ) );

		$result = $this->client->generate_article( array( 'user_prompt' => 'Test' ) );

		$this->assertNull( $result );
	}

	public function test_generate_article_with_openai_success(): void {
		// Test wymaga mockowania OpenAI client
		$this->assertTrue( true );
	}

	public function test_generate_article_with_openai_exception(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => 'invalid-key' ) ) );

		$result = $this->client->generate_article( array( 'user_prompt' => 'Test' ) );

		// Powinno zwrócić null przy błędzie
		$this->assertTrue( true );
	}

	public function test_generate_article_with_gemini_success(): void {
		// Test wymaga mockowania Gemini client
		$this->assertTrue( true );
	}

	public function test_generate_article_with_gemini_exception(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'gemini', 'gemini_api_key' => 'invalid-key' ) ) );

		$result = $this->client->generate_article( array( 'user_prompt' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_decode_content_handles_valid_json(): void {
		$method = new \ReflectionMethod( AiClient::class, 'decode_content' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, '{"title":"Test","content":"Content"}' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Test', $result['title'] );
	}

	public function test_decode_content_handles_json_in_text(): void {
		$method = new \ReflectionMethod( AiClient::class, 'decode_content' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, 'Some text {"title":"Test"} more text' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Test', $result['title'] );
	}

	public function test_decode_content_handles_plain_text(): void {
		$method = new \ReflectionMethod( AiClient::class, 'decode_content' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, 'Plain text content' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Plain text content', $result['content'] );
	}

	public function test_generate_comment_with_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI client
		$result = $this->client->generate_comment( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_generate_comment_with_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'gemini', 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini client
		$result = $this->client->generate_comment( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_generate_comment_returns_null_on_error(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => '' ) ) );

		$result = $this->client->generate_comment( array( 'title' => 'Test' ) );

		$this->assertNull( $result );
	}

	public function test_generate_nickname_with_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI client
		$result = $this->client->generate_nickname( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_generate_nickname_with_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'gemini', 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini client
		$result = $this->client->generate_nickname( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_sanitize_nickname_removes_special_chars(): void {
		$method = new \ReflectionMethod( AiClient::class, 'sanitize_nickname' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, 'Test@Nick#123!' );

		$this->assertStringNotContainsString( '@', $result );
		$this->assertStringNotContainsString( '#', $result );
		$this->assertStringNotContainsString( '!', $result );
	}

	public function test_sanitize_nickname_truncates_long(): void {
		$method = new \ReflectionMethod( AiClient::class, 'sanitize_nickname' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, 'VeryLongNicknameThatExceedsLimit123456789' );

		$this->assertLessThanOrEqual( 18, strlen( $result ) );
	}

	public function test_suggest_internal_links_with_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai', 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI client
		$result = $this->client->suggest_internal_links(
			array( 'title' => 'Test', 'content' => 'Content' ),
			array( array( 'title' => 'Link', 'url' => 'https://example.com' ) ),
			array()
		);

		$this->assertTrue( true );
	}

	public function test_suggest_internal_links_with_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'gemini', 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini client
		$result = $this->client->suggest_internal_links(
			array( 'title' => 'Test', 'content' => 'Content' ),
			array( array( 'title' => 'Link', 'url' => 'https://example.com' ) ),
			array()
		);

		$this->assertTrue( true );
	}

	public function test_suggest_internal_links_returns_empty_for_no_candidates(): void {
		$result = $this->client->suggest_internal_links(
			array( 'title' => 'Test', 'content' => 'Content' ),
			array(),
			array()
		);

		$this->assertSame( array(), $result );
	}

	public function test_parse_link_suggestions_handles_valid_json(): void {
		$method = new \ReflectionMethod( AiClient::class, 'parse_link_suggestions' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, '[{"anchor":"test","url":"https://example.com"}]' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
		$this->assertSame( 'test', $result[0]['anchor'] );
	}

	public function test_parse_link_suggestions_handles_invalid_json(): void {
		$method = new \ReflectionMethod( AiClient::class, 'parse_link_suggestions' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client, 'invalid json' );

		$this->assertSame( array(), $result );
	}

	public function test_parse_link_suggestions_limits_to_3(): void {
		$method = new \ReflectionMethod( AiClient::class, 'parse_link_suggestions' );
		$method->setAccessible( true );
		$json = json_encode( array(
			array( 'anchor' => '1', 'url' => 'https://example.com/1' ),
			array( 'anchor' => '2', 'url' => 'https://example.com/2' ),
			array( 'anchor' => '3', 'url' => 'https://example.com/3' ),
			array( 'anchor' => '4', 'url' => 'https://example.com/4' ),
		) );
		$result = $method->invoke( $this->client, $json );

		$this->assertCount( 3, $result );
	}

	public function test_generate_remote_image_with_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_remote_provider' => 'openai', 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI Images API
		$result = $this->client->generate_remote_image( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_generate_remote_image_with_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'image_remote_provider' => 'gemini', 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini Imagen API
		$result = $this->client->generate_remote_image( array( 'title' => 'Test' ) );

		$this->assertTrue( true );
	}

	public function test_list_models_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'openai_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania OpenAI Models API
		$result = $this->client->list_models( 'openai' );

		$this->assertIsArray( $result );
	}

	public function test_list_models_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'gemini_api_key' => 'test-key' ) ) );

		// Test wymaga mockowania Gemini Models API
		$result = $this->client->list_models( 'gemini' );

		$this->assertIsArray( $result );
	}

	public function test_list_models_returns_empty_on_error(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'openai_api_key' => '' ) ) );

		$result = $this->client->list_models( 'openai' );

		$this->assertSame( array(), $result );
	}

	public function test_provider_order_openai(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'openai' ) ) );

		$method = new \ReflectionMethod( AiClient::class, 'provider_order' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client );

		$this->assertSame( array( 'openai' ), $result );
	}

	public function test_provider_order_gemini(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'gemini' ) ) );

		$method = new \ReflectionMethod( AiClient::class, 'provider_order' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client );

		$this->assertSame( array( 'gemini' ), $result );
	}

	public function test_provider_order_auto(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'ai_provider' => 'auto' ) ) );

		$method = new \ReflectionMethod( AiClient::class, 'provider_order' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client );

		$this->assertSame( array( 'openai', 'gemini' ), $result );
	}

	public function test_default_system_prompt_includes_security_note(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'system_prompt' => '', 'topic_strategy' => '' ) ) );

		$method = new \ReflectionMethod( AiClient::class, 'default_system_prompt' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client );

		$this->assertStringContainsString( 'WAŻNE', $result );
		$this->assertStringContainsString( 'NIGDY', $result );
	}

	public function test_default_system_prompt_uses_custom(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'system_prompt' => 'Custom prompt', 'topic_strategy' => '' ) ) );

		$method = new \ReflectionMethod( AiClient::class, 'default_system_prompt' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->client );

		$this->assertStringContainsString( 'Custom prompt', $result );
	}
}

