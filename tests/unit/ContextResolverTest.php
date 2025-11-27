<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Service\ContextResolver;
use PHPUnit\Framework\TestCase;

/**
 * @group context
 */
final class ContextResolverTest extends TestCase {
	private ContextResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ContextResolver();
	}

	public function test_get_prompt_context_returns_recent_posts(): void {
		$result = $this->resolver->get_prompt_context();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'recent_posts', $result );
		$this->assertIsArray( $result['recent_posts'] );
	}

	public function test_get_prompt_context_returns_categories(): void {
		$result = $this->resolver->get_prompt_context();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertIsArray( $result['categories'] );
	}

	public function test_get_prompt_context_limits_posts_to_5(): void {
		$result = $this->resolver->get_prompt_context();

		$this->assertLessThanOrEqual( 5, count( $result['recent_posts'] ) );
	}

	public function test_get_prompt_context_limits_categories_to_8(): void {
		$result = $this->resolver->get_prompt_context();

		$this->assertLessThanOrEqual( 8, count( $result['categories'] ) );
	}

	public function test_get_prompt_context_formats_posts(): void {
		$result = $this->resolver->get_prompt_context();

		if ( ! empty( $result['recent_posts'] ) ) {
			$post = $result['recent_posts'][0];
			$this->assertArrayHasKey( 'title', $post );
			$this->assertArrayHasKey( 'excerpt', $post );
		}
	}

	public function test_get_link_candidates_returns_posts(): void {
		$result = $this->resolver->get_link_candidates();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_get_link_candidates_includes_homepage(): void {
		$result = $this->resolver->get_link_candidates();

		$has_homepage = false;
		$home_url = home_url( '/' );
		foreach ( $result as $candidate ) {
			if ( strpos( $candidate['url'], $home_url ) !== false ) {
				$has_homepage = true;
				break;
			}
		}

		$this->assertTrue( $has_homepage );
	}

	public function test_get_link_candidates_limits_to_6(): void {
		$result = $this->resolver->get_link_candidates();

		// +1 dla strony głównej
		$this->assertLessThanOrEqual( 7, count( $result ) );
	}

	public function test_get_link_candidates_formats_data(): void {
		$result = $this->resolver->get_link_candidates();

		if ( ! empty( $result ) ) {
			$candidate = $result[0];
			$this->assertArrayHasKey( 'title', $candidate );
			$this->assertArrayHasKey( 'url', $candidate );
			$this->assertArrayHasKey( 'summary', $candidate );
		}
	}

	public function test_get_link_candidates_skips_posts_without_url(): void {
		// Test sprawdza że metoda działa poprawnie
		// W rzeczywistości get_permalink zwraca URL lub false
		$result = $this->resolver->get_link_candidates();

		foreach ( $result as $candidate ) {
			$this->assertNotEmpty( $candidate['url'] );
		}
	}
}

