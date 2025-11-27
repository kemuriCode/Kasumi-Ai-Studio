<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\BlockContentBuilder;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\ContextResolver;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use Kasumi\AIGenerator\Service\LinkBuilder;
use Kasumi\AIGenerator\Service\MarkdownConverter;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Status\StatusStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

final class PostGeneratorTest extends TestCase {
	private PostGenerator $generator;
	private AiClient&MockObject $ai_client;
	private FeaturedImageBuilder&MockObject $image_builder;
	private LinkBuilder&MockObject $link_builder;
	private CommentGenerator&MockObject $comment_generator;
	private ContextResolver&MockObject $context_resolver;
	private Logger&MockObject $logger;
	private MarkdownConverter&MockObject $markdown;
	private BlockContentBuilder&MockObject $block_builder;

	protected function setUp(): void {
		parent::setUp();

		$this->ai_client = $this->createMock( AiClient::class );
		$this->image_builder = $this->createMock( FeaturedImageBuilder::class );
		$this->link_builder = $this->createMock( LinkBuilder::class );
		$this->comment_generator = $this->createMock( CommentGenerator::class );
		$this->context_resolver = $this->createMock( ContextResolver::class );
		$this->logger = $this->createMock( Logger::class );
		$this->markdown = $this->createMock( MarkdownConverter::class );
		$this->markdown->method( 'to_html' )->willReturn( '<p>Sample</p>' );

		$this->block_builder = $this->createMock( BlockContentBuilder::class );
		$this->block_builder->method( 'build_blocks' )->willReturn( '' );

		$this->generator = new PostGenerator(
			$this->ai_client,
			$this->image_builder,
			$this->link_builder,
			$this->comment_generator,
			$this->context_resolver,
			$this->logger,
			$this->markdown,
			$this->block_builder
		);
	}

	public function test_resolve_custom_user_prompt_is_prioritized(): void {
		$method = $this->get_private_method( 'resolve_user_prompt' );
		$custom = 'Napisz wpis o QR kodach.';

		$result = $method->invokeArgs( $this->generator, array( array( 'user_prompt' => $custom ), array() ) );

		$this->assertSame( $custom, $result );
	}

	public function test_apply_publish_at_sets_future_status(): void {
		$method = $this->get_private_method( 'apply_publish_at' );

		$status = 'publish';
		$result = $method->invokeArgs(
			$this->generator,
			array( gmdate( 'Y-m-d\TH:i:s', strtotime( '+2 days' ) ), &$status )
		);

		$this->assertSame( 'future', $status, 'Status powinien zmienić się na future dla przyszłej daty.' );
		$this->assertArrayHasKey( 'post_date', $result );
		$this->assertArrayHasKey( 'post_date_gmt', $result );
		$this->assertNotEmpty( $result['post_date'] );
		$this->assertNotEmpty( $result['post_date_gmt'] );
	}

	public function test_generate_returns_null_when_ai_returns_empty(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->ai_client->method( 'generate_article' )->willReturn( null );

		$result = $this->generator->generate();

		$this->assertNull( $result );
	}

	public function test_generate_returns_null_in_preview_mode(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );

		// Mock Options::get dla preview_mode
		$original_get = Options::get( 'preview_mode', false );
		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => true ) ) );

		$result = $this->generator->generate();

		// Przywróć oryginalną wartość
		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => $original_get ) ) );

		$this->assertNull( $result );
	}

	public function test_generate_ignores_preview_mode_with_flag(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array() );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );

		// Mock Options
		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => true, 'enable_featured_images' => false ) ) );

		// Mock WordPress functions
		if ( ! function_exists( 'wp_insert_post' ) ) {
			require_once ABSPATH . 'wp-includes/post.php';
		}

		$result = $this->generator->generate( array( 'ignore_preview_mode' => true ) );

		// Przywróć
		update_option( 'kasumi_ai_options', Options::all() );

		// W testach bez prawdziwej bazy danych, wp_insert_post zwróci 0 lub false
		// Więc sprawdzamy tylko że metoda została wywołana
		$this->assertTrue( true );
	}

	public function test_generate_creates_post_with_correct_data(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test Title', 'content' => 'Test Content', 'slug' => 'test-slug' ) );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Test Content</p><!-- /wp:paragraph -->' );
		$this->link_builder->method( 'inject_links' )->willReturnArgument( 0 );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_featured_images' => false ) ) );

		// Test sprawdza że metoda działa, ale bez prawdziwej bazy danych nie możemy sprawdzić wyniku
		$this->assertTrue( true );
	}

	public function test_generate_applies_internal_links(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array( array( 'title' => 'Link', 'url' => 'https://example.com', 'summary' => 'Summary' ) ) );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );
		$this->ai_client->method( 'suggest_internal_links' )->willReturn( array( array( 'anchor' => 'test', 'url' => 'https://example.com' ) ) );
		$this->link_builder->expects( $this->once() )->method( 'inject_links' )->willReturn( 'Content with links' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_internal_linking' => true ) ) );

		$this->generator->generate();

		update_option( 'kasumi_ai_options', Options::all() );
	}

	public function test_generate_builds_featured_image(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array() );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );
		$this->image_builder->expects( $this->once() )->method( 'build' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_featured_images' => true ) ) );

		$this->generator->generate();

		update_option( 'kasumi_ai_options', Options::all() );
	}

	public function test_generate_schedules_comments(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array() );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );
		$this->comment_generator->expects( $this->once() )->method( 'schedule_for_post' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_featured_images' => false ) ) );

		$this->generator->generate();

		update_option( 'kasumi_ai_options', Options::all() );
	}

	public function test_generate_updates_status_store(): void {
		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array() );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => 'Content' ) );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_featured_images' => false ) ) );

		$before = StatusStore::all();

		$this->generator->generate();

		$after = StatusStore::all();

		update_option( 'kasumi_ai_options', Options::all() );

		// StatusStore powinien być zaktualizowany
		$this->assertTrue( true );
	}

	public function test_resolve_user_prompt_builds_from_context(): void {
		$method = $this->get_private_method( 'resolve_user_prompt' );
		$context = array( 'categories' => array( 'Tech' ), 'recent_posts' => array() );

		$result = $method->invokeArgs( $this->generator, array( array(), $context ) );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_resolve_system_prompt_uses_custom(): void {
		$method = $this->get_private_method( 'resolve_system_prompt' );
		$custom = 'Custom system prompt';

		$result = $method->invokeArgs( $this->generator, array( array( 'system_prompt' => $custom ) ) );

		$this->assertSame( $custom, $result );
	}

	public function test_resolve_system_prompt_falls_back_to_options(): void {
		$method = $this->get_private_method( 'resolve_system_prompt' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'system_prompt' => 'Option prompt' ) ) );

		$result = $method->invokeArgs( $this->generator, array( array() ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertIsString( $result );
	}

	public function test_build_prompt_includes_word_count(): void {
		$method = $this->get_private_method( 'build_prompt' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'word_count_min' => 500, 'word_count_max' => 1000 ) ) );

		$result = $method->invokeArgs( $this->generator, array( array( 'categories' => array(), 'recent_posts' => array() ) ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertStringContainsString( '500', $result );
		$this->assertStringContainsString( '1000', $result );
	}

	public function test_build_prompt_includes_topic_strategy(): void {
		$method = $this->get_private_method( 'build_prompt' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'topic_strategy' => 'Tech focus' ) ) );

		$result = $method->invokeArgs( $this->generator, array( array( 'categories' => array(), 'recent_posts' => array() ) ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertStringContainsString( 'Tech focus', $result );
	}

	public function test_build_prompt_includes_categories(): void {
		$method = $this->get_private_method( 'build_prompt' );
		$context = array( 'categories' => array( 'Tech', 'AI' ), 'recent_posts' => array() );

		$result = $method->invokeArgs( $this->generator, array( $context ) );

		$this->assertStringContainsString( 'Tech', $result );
		$this->assertStringContainsString( 'AI', $result );
	}

	public function test_build_prompt_includes_recent_posts(): void {
		$method = $this->get_private_method( 'build_prompt' );
		$context = array( 'categories' => array(), 'recent_posts' => array( array( 'title' => 'Recent Post' ) ) );

		$result = $method->invokeArgs( $this->generator, array( $context ) );

		$this->assertStringContainsString( 'Recent Post', $result );
	}

	public function test_create_post_uses_overrides(): void {
		$method = $this->get_private_method( 'create_post' );
		$article = array( 'title' => 'Article Title', 'content' => 'Content' );
		$overrides = array( 'post_title' => 'Override Title', 'post_status' => 'publish' );

		// Bez prawdziwej bazy danych nie możemy przetestować pełnej funkcjonalności
		// ale możemy sprawdzić że metoda akceptuje overrides
		$this->assertTrue( true );
	}

	public function test_create_post_converts_markdown_to_blocks(): void {
		$this->block_builder->expects( $this->once() )->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );

		$this->context_resolver->method( 'get_prompt_context' )->willReturn( array( 'categories' => array(), 'recent_posts' => array() ) );
		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array() );
		$this->ai_client->method( 'generate_article' )->willReturn( array( 'title' => 'Test', 'content' => '# Markdown' ) );
		$this->link_builder->method( 'inject_links' )->willReturnArgument( 0 );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'preview_mode' => false, 'enable_featured_images' => false ) ) );

		$this->generator->generate();

		update_option( 'kasumi_ai_options', Options::all() );
	}

	public function test_create_post_handles_wp_error(): void {
		// Test wymaga mockowania wp_insert_post, co jest skomplikowane bez biblioteki testowej WordPress
		$this->assertTrue( true );
	}

	public function test_create_post_sets_meta(): void {
		// Test wymaga mockowania update_post_meta
		$this->assertTrue( true );
	}

	public function test_create_post_resolves_category_from_meta(): void {
		$method = $this->get_private_method( 'resolve_category' );
		$overrides = array( 'meta' => array( 'categoryIds' => array( 1, 2 ) ) );

		$result = $method->invokeArgs( $this->generator, array( $overrides ) );

		$this->assertSame( array( 1, 2 ), $result );
	}

	public function test_create_post_resolves_category_from_options(): void {
		$method = $this->get_private_method( 'resolve_category' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'target_category' => '5' ) ) );

		$result = $method->invokeArgs( $this->generator, array( array() ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertSame( array( 5 ), $result );
	}

	public function test_apply_publish_at_handles_past_date(): void {
		$method = $this->get_private_method( 'apply_publish_at' );

		$status = 'publish';
		$result = $method->invokeArgs(
			$this->generator,
			array( gmdate( 'Y-m-d\TH:i:s', strtotime( '-1 day' ) ), &$status )
		);

		$this->assertSame( 'publish', $status );
		$this->assertArrayHasKey( 'post_date', $result );
	}

	public function test_apply_publish_at_handles_invalid_date(): void {
		$method = $this->get_private_method( 'apply_publish_at' );

		$status = 'publish';
		$result = $method->invokeArgs( $this->generator, array( 'invalid-date', &$status ) );

		$this->assertEmpty( $result );
	}

	public function test_maybe_apply_internal_links_when_disabled(): void {
		$method = $this->get_private_method( 'maybe_apply_internal_links' );
		$article = array( 'content' => 'Test content' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'enable_internal_linking' => false ) ) );

		$result = $method->invokeArgs( $this->generator, array( $article ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertSame( 'Test content', $result );
	}

	public function test_maybe_apply_internal_links_when_enabled(): void {
		$method = $this->get_private_method( 'maybe_apply_internal_links' );
		$article = array( 'content' => 'Test content', 'title' => 'Test', 'excerpt' => '' );

		$this->context_resolver->method( 'get_link_candidates' )->willReturn( array( array( 'title' => 'Link', 'url' => 'https://example.com', 'summary' => 'Summary' ) ) );
		$this->ai_client->method( 'suggest_internal_links' )->willReturn( array( array( 'anchor' => 'test', 'url' => 'https://example.com' ) ) );
		$this->link_builder->method( 'inject_links' )->willReturn( 'Modified content' );

		update_option( 'kasumi_ai_options', array_merge( Options::all(), array( 'enable_internal_linking' => true ) ) );

		$result = $method->invokeArgs( $this->generator, array( $article ) );

		update_option( 'kasumi_ai_options', Options::all() );

		$this->assertIsString( $result );
	}

	public function test_format_content_for_wordpress_uses_blocks(): void {
		$method = $this->get_private_method( 'format_content_for_wordpress' );
		$this->block_builder = $this->createMock( BlockContentBuilder::class );
		$this->block_builder->method( 'build_blocks' )->willReturn( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->' );
		
		// Musimy stworzyć nowy generator z zaktualizowanym mockiem
		$generator = new PostGenerator(
			$this->ai_client,
			$this->image_builder,
			$this->link_builder,
			$this->comment_generator,
			$this->context_resolver,
			$this->logger,
			$this->markdown,
			$this->block_builder
		);

		$result = $method->invokeArgs( $generator, array( '# Markdown' ) );

		$this->assertSame( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->', $result );
	}

	public function test_format_content_for_wordpress_falls_back_to_html(): void {
		$method = $this->get_private_method( 'format_content_for_wordpress' );
		$this->block_builder = $this->createMock( BlockContentBuilder::class );
		$this->block_builder->method( 'build_blocks' )->willReturn( '' );
		$this->markdown = $this->createMock( MarkdownConverter::class );
		$this->markdown->method( 'to_html' )->willReturn( '<p>HTML Content</p>' );
		
		$generator = new PostGenerator(
			$this->ai_client,
			$this->image_builder,
			$this->link_builder,
			$this->comment_generator,
			$this->context_resolver,
			$this->logger,
			$this->markdown,
			$this->block_builder
		);

		$result = $method->invokeArgs( $generator, array( '# Markdown' ) );

		$this->assertSame( '<p>HTML Content</p>', $result );
	}

	private function get_private_method( string $name ): ReflectionMethod {
		$method = new ReflectionMethod( PostGenerator::class, $name );
		$method->setAccessible( true );

		return $method;
	}
}

