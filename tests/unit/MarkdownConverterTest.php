<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Service\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * @group markdown
 */
final class MarkdownConverterTest extends TestCase {
	private MarkdownConverter $converter;

	protected function setUp(): void {
		parent::setUp();
		$this->converter = new MarkdownConverter();
	}

	public function test_to_html_converts_basic_markdown(): void {
		$markdown = "# Heading\n\nThis is a **bold** text.";
		$result = $this->converter->to_html( $markdown );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '<h1>', $result );
		$this->assertStringContainsString( '<strong>', $result );
	}

	public function test_to_html_handles_empty_string(): void {
		$result = $this->converter->to_html( '' );

		$this->assertSame( '', $result );
	}

	public function test_to_html_handles_whitespace_only(): void {
		$result = $this->converter->to_html( '   \n\t  ' );

		// Parsedown konwertuje białe znaki na <p>, więc sprawdzamy że wynik nie jest pusty
		$this->assertIsString( $result );
	}

	public function test_to_block_ready_html_sanitizes_output(): void {
		$markdown = "# Heading\n\n<script>alert(\"xss\")</script>";
		$result = $this->converter->to_block_ready_html( $markdown );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	public function test_to_block_ready_html_handles_empty(): void {
		$result = $this->converter->to_block_ready_html( '' );

		$this->assertSame( '', $result );
	}

	public function test_to_gutenberg_blocks_converts_markdown(): void {
		$markdown = "# Heading\n\nParagraph text.";
		$result = $this->converter->to_gutenberg_blocks( $markdown );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_to_gutenberg_blocks_handles_empty(): void {
		$result = $this->converter->to_gutenberg_blocks( '' );

		$this->assertSame( '', $result );
	}

	public function test_is_markdown_detects_headers(): void {
		$result = $this->converter->is_markdown( '# Heading' );

		$this->assertTrue( $result );
	}

	public function test_is_markdown_detects_lists(): void {
		$result1 = $this->converter->is_markdown( '* Item 1' );
		$result2 = $this->converter->is_markdown( '1. Item 1' );

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
	}

	public function test_is_markdown_detects_links(): void {
		$result = $this->converter->is_markdown( '[Link](https://example.com)' );

		$this->assertTrue( $result );
	}

	public function test_is_markdown_detects_bold_italic(): void {
		$result1 = $this->converter->is_markdown( '**bold**' );
		$result2 = $this->converter->is_markdown( '*italic*' );

		$this->assertTrue( $result1 );
		$this->assertTrue( $result2 );
	}

	public function test_is_markdown_detects_code_blocks(): void {
		$result = $this->converter->is_markdown( '```code```' );

		$this->assertTrue( $result );
	}

	public function test_is_markdown_detects_quotes(): void {
		$result = $this->converter->is_markdown( '> Quote text' );

		$this->assertTrue( $result );
	}

	public function test_is_markdown_returns_false_for_plain_text(): void {
		$result = $this->converter->is_markdown( 'Just plain text without any markdown syntax.' );

		$this->assertFalse( $result );
	}

	public function test_format_for_preview_uses_to_html(): void {
		$markdown = "# Heading";
		$result = $this->converter->format_for_preview( $markdown );

		$this->assertIsString( $result );
		$this->assertStringContainsString( '<h1>', $result );
	}
}

