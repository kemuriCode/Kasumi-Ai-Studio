<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\BlockContentBuilder;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\ContextResolver;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use Kasumi\AIGenerator\Service\LinkBuilder;
use Kasumi\AIGenerator\Service\MarkdownConverter;
use Kasumi\AIGenerator\Service\PostGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PostGeneratorTest extends TestCase {
	private PostGenerator $generator;

	protected function setUp(): void {
		parent::setUp();

		/** @var AiClient&MockObject $ai_client */
		$ai_client = $this->createMock( AiClient::class );
		/** @var FeaturedImageBuilder&MockObject $image_builder */
		$image_builder = $this->createMock( FeaturedImageBuilder::class );
		/** @var LinkBuilder&MockObject $link_builder */
		$link_builder = $this->createMock( LinkBuilder::class );
		/** @var CommentGenerator&MockObject $comment_generator */
		$comment_generator = $this->createMock( CommentGenerator::class );
		/** @var ContextResolver&MockObject $context_resolver */
		$context_resolver = $this->createMock( ContextResolver::class );
		/** @var Logger&MockObject $logger */
		$logger = $this->createMock( Logger::class );
		/** @var MarkdownConverter&MockObject $markdown */
		$markdown = $this->createMock( MarkdownConverter::class );
		$markdown->method( 'to_html' )->willReturn( '<p>Sample</p>' );

		/** @var BlockContentBuilder&MockObject $block_builder */
		$block_builder = $this->createMock( BlockContentBuilder::class );
		$block_builder->method( 'build_blocks' )->willReturn( '' );

		$this->generator = new PostGenerator(
			$ai_client,
			$image_builder,
			$link_builder,
			$comment_generator,
			$context_resolver,
			$logger,
			$markdown,
			$block_builder
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

	private function get_private_method( string $name ): ReflectionMethod {
		$method = new ReflectionMethod( PostGenerator::class, $name );
		$method->setAccessible( true );

		return $method;
	}
}

