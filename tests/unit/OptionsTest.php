<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Options;
use PHPUnit\Framework\TestCase;

/**
 * @group options
 */
final class OptionsTest extends TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		// Przywróć domyślne opcje po każdym teście
		update_option( Options::OPTION_NAME, array() );
	}

	public function test_all_returns_defaults_when_empty(): void {
		delete_option( Options::OPTION_NAME );
		$result = Options::all();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'openai_api_key', $result );
		$this->assertArrayHasKey( 'openai_model', $result );
		$this->assertArrayHasKey( 'ai_provider', $result );
	}

	public function test_all_merges_with_saved(): void {
		$saved = array( 'openai_api_key' => 'test-key', 'openai_model' => 'gpt-4' );
		update_option( Options::OPTION_NAME, $saved );

		$result = Options::all();

		$this->assertSame( 'test-key', $result['openai_api_key'] );
		$this->assertSame( 'gpt-4', $result['openai_model'] );
		$this->assertArrayHasKey( 'gemini_api_key', $result );
	}

	public function test_get_returns_value(): void {
		update_option( Options::OPTION_NAME, array( 'openai_api_key' => 'test-key' ) );

		$result = Options::get( 'openai_api_key' );

		$this->assertSame( 'test-key', $result );
	}

	public function test_get_returns_default(): void {
		delete_option( Options::OPTION_NAME );

		// Options::get zwraca wartość z defaults() jeśli nie ma w zapisanych opcjach
		// Więc sprawdzamy że zwraca wartość z defaults, nie custom default
		$result = Options::get( 'openai_api_key' );

		$this->assertSame( Options::defaults()['openai_api_key'], $result );
	}

	public function test_defaults_returns_all_keys(): void {
		$defaults = Options::defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'openai_api_key', $defaults );
		$this->assertArrayHasKey( 'gemini_api_key', $defaults );
		$this->assertArrayHasKey( 'system_prompt', $defaults );
		$this->assertArrayHasKey( 'word_count_min', $defaults );
		$this->assertArrayHasKey( 'word_count_max', $defaults );
	}

	public function test_sanitize_sanitizes_openai_key(): void {
		$input = array( 'openai_api_key' => '  test-key  ' );
		$result = Options::sanitize( $input );

		$this->assertSame( 'test-key', $result['openai_api_key'] );
	}

	public function test_sanitize_sanitizes_gemini_key(): void {
		$input = array( 'gemini_api_key' => '  gemini-key  ' );
		$result = Options::sanitize( $input );

		$this->assertSame( 'gemini-key', $result['gemini_api_key'] );
	}

	public function test_sanitize_validates_provider(): void {
		$input1 = array( 'ai_provider' => 'openai' );
		$input2 = array( 'ai_provider' => 'gemini' );
		$input3 = array( 'ai_provider' => 'auto' );
		$input4 = array( 'ai_provider' => 'invalid' );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );
		$result3 = Options::sanitize( $input3 );
		$result4 = Options::sanitize( $input4 );

		$this->assertSame( 'openai', $result1['ai_provider'] );
		$this->assertSame( 'gemini', $result2['ai_provider'] );
		$this->assertSame( 'auto', $result3['ai_provider'] );
		$this->assertSame( 'openai', $result4['ai_provider'] ); // fallback do domyślnego
	}

	public function test_sanitize_validates_post_status(): void {
		$input1 = array( 'default_post_status' => 'draft' );
		$input2 = array( 'default_post_status' => 'publish' );
		$input3 = array( 'default_post_status' => 'invalid' );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );
		$result3 = Options::sanitize( $input3 );

		$this->assertSame( 'draft', $result1['default_post_status'] );
		$this->assertSame( 'publish', $result2['default_post_status'] );
		$this->assertSame( 'draft', $result3['default_post_status'] ); // fallback
	}

	public function test_sanitize_validates_image_mode(): void {
		$input1 = array( 'image_generation_mode' => 'server' );
		$input2 = array( 'image_generation_mode' => 'remote' );
		$input3 = array( 'image_generation_mode' => 'invalid' );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );
		$result3 = Options::sanitize( $input3 );

		$this->assertSame( 'server', $result1['image_generation_mode'] );
		$this->assertSame( 'remote', $result2['image_generation_mode'] );
		$this->assertSame( 'server', $result3['image_generation_mode'] ); // fallback
	}

	public function test_sanitize_validates_comment_frequency(): void {
		$input1 = array( 'comment_frequency' => 'dense' );
		$input2 = array( 'comment_frequency' => 'normal' );
		$input3 = array( 'comment_frequency' => 'slow' );
		$input4 = array( 'comment_frequency' => 'invalid' );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );
		$result3 = Options::sanitize( $input3 );
		$result4 = Options::sanitize( $input4 );

		$this->assertSame( 'dense', $result1['comment_frequency'] );
		$this->assertSame( 'normal', $result2['comment_frequency'] );
		$this->assertSame( 'slow', $result3['comment_frequency'] );
		$this->assertSame( 'dense', $result4['comment_frequency'] ); // fallback
	}

	public function test_sanitize_enforces_min_max_word_count(): void {
		$input1 = array( 'word_count_min' => 100, 'word_count_max' => 50 );
		$input2 = array( 'word_count_min' => 200, 'word_count_max' => 1000 );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );

		$this->assertGreaterThanOrEqual( 200, $result1['word_count_min'] );
		$this->assertGreaterThanOrEqual( $result1['word_count_min'], $result1['word_count_max'] );
		$this->assertSame( 200, $result2['word_count_min'] );
		$this->assertSame( 1000, $result2['word_count_max'] );
	}

	public function test_sanitize_enforces_min_max_comments(): void {
		$input1 = array( 'comment_min' => 0, 'comment_max' => 5 );
		$input2 = array( 'comment_min' => 3, 'comment_max' => 2 );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );

		$this->assertGreaterThanOrEqual( 1, $result1['comment_min'] );
		$this->assertGreaterThanOrEqual( $result2['comment_min'], $result2['comment_max'] );
	}

	public function test_sanitize_sanitizes_hex_color(): void {
		$input1 = array( 'image_overlay_color' => '1b1f3b' );
		$input2 = array( 'image_overlay_color' => '#1b1f3b' );
		$input3 = array( 'image_overlay_color' => 'invalid' );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );
		$result3 = Options::sanitize( $input3 );

		$this->assertSame( '1b1f3b', $result1['image_overlay_color'] );
		$this->assertSame( '1b1f3b', $result2['image_overlay_color'] );
		$this->assertSame( '1b1f3b', $result3['image_overlay_color'] ); // fallback
	}

	public function test_export_returns_valid_json(): void {
		update_option( Options::OPTION_NAME, array( 'openai_model' => 'gpt-4', 'word_count_min' => 500 ) );

		$json = Options::export();

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'openai_model', $decoded );
		$this->assertSame( 'gpt-4', $decoded['openai_model'] );
		// Klucze API nie powinny być w eksporcie
		$this->assertArrayNotHasKey( 'openai_api_key', $decoded );
		$this->assertArrayNotHasKey( 'gemini_api_key', $decoded );
		$this->assertArrayNotHasKey( 'pixabay_api_key', $decoded );
	}

	public function test_import_validates_structure(): void {
		$invalid_json = 'not json';
		$result = Options::import( $invalid_json );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}

	public function test_import_validates_array(): void {
		$invalid_json = '"not an array"';
		$result = Options::import( $invalid_json );

		$this->assertFalse( $result['success'] );
	}

	public function test_import_sanitizes_data(): void {
		$json = wp_json_encode( array( 'openai_model' => 'gpt-4', 'word_count_min' => 500 ) );
		$result = Options::import( $json );

		$this->assertTrue( $result['success'] );
		$imported = Options::get( 'openai_model' );
		$this->assertSame( 'gpt-4', $imported );
	}

	public function test_import_preserves_defaults_for_missing_keys(): void {
		$json = wp_json_encode( array( 'openai_model' => 'gpt-4' ) );
		Options::import( $json );

		// Sprawdź że pozostałe klucze mają domyślne wartości
		$this->assertSame( Options::defaults()['gemini_model'], Options::get( 'gemini_model' ) );
		$this->assertSame( Options::defaults()['word_count_min'], Options::get( 'word_count_min' ) );
	}

	public function test_defaults_includes_plugin_enabled(): void {
		$defaults = Options::defaults();

		$this->assertArrayHasKey( 'plugin_enabled', $defaults );
		$this->assertTrue( $defaults['plugin_enabled'] );
	}

	public function test_defaults_includes_delete_tables(): void {
		$defaults = Options::defaults();

		$this->assertArrayHasKey( 'delete_tables_on_deactivation', $defaults );
		$this->assertFalse( $defaults['delete_tables_on_deactivation'] );
	}

	public function test_sanitize_handles_plugin_enabled(): void {
		$input1 = array( 'plugin_enabled' => true );
		$input2 = array( 'plugin_enabled' => false );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );

		$this->assertTrue( $result1['plugin_enabled'] );
		$this->assertFalse( $result2['plugin_enabled'] );
	}

	public function test_sanitize_handles_delete_tables(): void {
		$input1 = array( 'delete_tables_on_deactivation' => true );
		$input2 = array( 'delete_tables_on_deactivation' => false );

		$result1 = Options::sanitize( $input1 );
		$result2 = Options::sanitize( $input2 );

		$this->assertTrue( $result1['delete_tables_on_deactivation'] );
		$this->assertFalse( $result2['delete_tables_on_deactivation'] );
	}
}

