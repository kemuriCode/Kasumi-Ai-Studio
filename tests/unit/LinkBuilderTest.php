<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Service\LinkBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @group links
 */
final class LinkBuilderTest extends TestCase {
	private LinkBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->builder = new LinkBuilder();
	}

	public function test_inject_links_skips_empty_suggestions(): void {
		$content = 'Some content here.';
		$result = $this->builder->inject_links( $content, array() );

		$this->assertSame( $content, $result );
	}

	public function test_inject_links_skips_empty_content(): void {
		$suggestions = array(
			array( 'anchor' => 'test', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( '', $suggestions );

		$this->assertSame( '', $result );
	}

	public function test_inject_links_replaces_existing_anchor(): void {
		$content = 'This is a test anchor in the text.';
		$suggestions = array(
			array( 'anchor' => 'test anchor', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		$this->assertStringContainsString( '<a href', $result );
		$this->assertStringContainsString( 'https://example.com', $result );
		$this->assertStringContainsString( 'test anchor', $result );
	}

	public function test_inject_links_appends_missing_anchor(): void {
		$content = 'This is some content.';
		$suggestions = array(
			array( 'anchor' => 'missing anchor', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		$this->assertStringContainsString( '<a href', $result );
		$this->assertStringContainsString( 'missing anchor', $result );
		$this->assertStringNotContainsString( 'This is some content.missing anchor', $result );
	}

	public function test_inject_links_escapes_url(): void {
		$content = 'Test anchor';
		$suggestions = array(
			array( 'anchor' => 'Test anchor', 'url' => 'https://example.com/?param="value"' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		$this->assertStringContainsString( 'https://example.com/', $result );
		// esc_url() escapuje URL, więc sprawdzamy że URL jest poprawnie escapowany
		$this->assertStringContainsString( 'href=', $result );
		// Sprawdzamy że nie ma nieescapowanych cudzysłowów w URL
		$this->assertStringNotContainsString( '?param="value"', $result );
	}

	public function test_inject_links_escapes_anchor_text(): void {
		$content = 'Test anchor';
		$suggestions = array(
			array( 'anchor' => 'Test <script>anchor</script>', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		// Gdy anchor nie jest w treści, jest dodawany na końcu z esc_html
		// Więc sprawdzamy że <script> jest escapowany
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_inject_links_handles_case_insensitive(): void {
		$content = 'This is a TEST anchor.';
		$suggestions = array(
			array( 'anchor' => 'test anchor', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		$this->assertStringContainsString( '<a href', $result );
	}

	public function test_inject_links_replaces_only_first_occurrence(): void {
		$content = 'Test anchor appears here. Test anchor appears again.';
		$suggestions = array(
			array( 'anchor' => 'Test anchor', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		$linkCount = substr_count( $result, '<a href' );
		$this->assertLessThanOrEqual( 1, $linkCount );
	}

	public function test_inject_links_skips_invalid_suggestions(): void {
		$content = 'Some content.';
		$suggestions = array(
			array( 'anchor' => '', 'url' => 'https://example.com' ),
			array( 'anchor' => 'Valid', 'url' => '' ),
			array( 'anchor' => 'Valid anchor', 'url' => 'https://example.com' ),
		);
		$result = $this->builder->inject_links( $content, $suggestions );

		// Tylko "Valid anchor" powinien być dodany (jako append, bo nie ma w treści)
		$this->assertStringContainsString( 'Valid anchor', $result );
		// "Valid" bez URL nie powinien być dodany
		$this->assertStringNotContainsString( '<a href="">Valid</a>', $result );
	}
}

