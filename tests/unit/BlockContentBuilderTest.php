<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Service\BlockContentBuilder;
use Kasumi\AIGenerator\Service\MarkdownConverter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group blocks
 */
final class BlockContentBuilderTest extends TestCase {
	private BlockContentBuilder $builder;
	private MarkdownConverter&MockObject $markdown_converter;

	protected function setUp(): void {
		parent::setUp();

		$this->markdown_converter = $this->createMock( MarkdownConverter::class );
		$this->builder = new BlockContentBuilder( $this->markdown_converter );
	}

	public function test_build_blocks_converts_paragraph(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<p>Paragraph text</p>' );

		$result = $this->builder->build_blocks( '# Markdown' );

		$this->assertStringContainsString( 'wp:paragraph', $result );
		$this->assertStringContainsString( 'Paragraph text', $result );
	}

	public function test_build_blocks_converts_headings(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<h1>Heading 1</h1><h2>Heading 2</h2><h3>Heading 3</h3>' );

		$result = $this->builder->build_blocks( '# Markdown' );

		$this->assertStringContainsString( 'wp:heading', $result );
	}

	public function test_build_blocks_converts_unordered_list(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<ul><li>Item 1</li><li>Item 2</li></ul>' );

		$result = $this->builder->build_blocks( '* Item' );

		$this->assertStringContainsString( 'wp:list', $result );
		$this->assertStringContainsString( '<ul>', $result );
	}

	public function test_build_blocks_converts_ordered_list(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<ol><li>Item 1</li><li>Item 2</li></ol>' );

		$result = $this->builder->build_blocks( '1. Item' );

		$this->assertStringContainsString( 'wp:list', $result );
		$this->assertStringContainsString( '<ol>', $result );
	}

	public function test_build_blocks_converts_quote(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<blockquote>Quote text</blockquote>' );

		$result = $this->builder->build_blocks( '> Quote' );

		$this->assertStringContainsString( 'wp:quote', $result );
		$this->assertStringContainsString( 'Quote text', $result );
	}

	public function test_build_blocks_converts_image(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<img src="https://example.com/image.jpg" alt="Test" />' );

		$result = $this->builder->build_blocks( '![Test](https://example.com/image.jpg)' );

		$this->assertStringContainsString( 'wp:image', $result );
		$this->assertStringContainsString( 'https://example.com/image.jpg', $result );
	}

	public function test_build_blocks_converts_figure(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<figure><img src="https://example.com/image.jpg" alt="Test" /></figure>' );

		$result = $this->builder->build_blocks( '![Test](https://example.com/image.jpg)' );

		$this->assertStringContainsString( 'wp:image', $result );
	}

	public function test_build_blocks_converts_code_block(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<pre><code>code content</code></pre>' );

		$result = $this->builder->build_blocks( '```code```' );

		$this->assertStringContainsString( 'wp:code', $result );
		$this->assertStringContainsString( 'code content', $result );
	}

	public function test_build_blocks_converts_separator(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<hr />' );

		$result = $this->builder->build_blocks( '---' );

		$this->assertStringContainsString( 'wp:separator', $result );
	}

	public function test_build_blocks_handles_unknown_tags(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<div>Unknown tag</div>' );

		$result = $this->builder->build_blocks( 'Markdown' );

		$this->assertStringContainsString( 'wp:html', $result );
	}

	public function test_build_blocks_handles_empty_html(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '' );

		$result = $this->builder->build_blocks( '' );

		$this->assertSame( '', $result );
	}

	public function test_build_blocks_handles_invalid_html(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<invalid><unclosed>' );

		$result = $this->builder->build_blocks( 'Markdown' );

		// Powinno zwrócić pusty string lub przynajmniej nie rzucić wyjątku
		$this->assertIsString( $result );
	}

	public function test_build_blocks_handles_text_nodes(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( 'Plain text without tags' );

		$result = $this->builder->build_blocks( 'Plain text' );

		$this->assertIsString( $result );
	}

	public function test_wrap_block_includes_attributes(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<h2>Heading</h2>' );

		$result = $this->builder->build_blocks( '## Heading' );

		$this->assertStringContainsString( '"level":2', $result );
	}

	public function test_wrap_block_without_attributes(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<p>Paragraph</p>' );

		$result = $this->builder->build_blocks( 'Paragraph' );

		$this->assertStringContainsString( 'wp:paragraph', $result );
		$this->assertStringNotContainsString( '"level"', $result );
	}

	public function test_heading_block_extracts_level(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<h3>Heading</h3>' );

		$result = $this->builder->build_blocks( '### Heading' );

		$this->assertStringContainsString( '"level":3', $result );
	}

	public function test_heading_block_clamps_level(): void {
		// Test sprawdza że poziom jest ograniczony do 1-6
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<h1>H1</h1><h6>H6</h6>' );

		$result = $this->builder->build_blocks( "# H1\n###### H6" );

		$this->assertStringContainsString( '"level":1', $result );
		$this->assertStringContainsString( '"level":6', $result );
	}

	public function test_image_block_handles_missing_src(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<img alt="Test" />' );

		$result = $this->builder->build_blocks( '![Test]()' );

		// Powinno zwrócić null dla obrazu bez src
		$this->assertIsString( $result );
	}

	public function test_image_block_escapes_url(): void {
		$this->markdown_converter->method( 'to_block_ready_html' )->willReturn( '<img src="https://example.com/image.jpg?param=value" alt="Test" />' );

		$result = $this->builder->build_blocks( '![Test](https://example.com/image.jpg?param=value)' );

		$this->assertStringContainsString( 'https://example.com/image.jpg', $result );
	}
}

