<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Cron\Scheduler;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Service\ScheduleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @group scheduler
 */
final class SchedulerTest extends TestCase {
	private Scheduler $scheduler;
	private PostGenerator&MockObject $post_generator;
	private CommentGenerator&MockObject $comment_generator;
	private Logger&MockObject $logger;
	private ScheduleService&MockObject $schedule_service;

	protected function setUp(): void {
		parent::setUp();

		$this->post_generator = $this->createMock( PostGenerator::class );
		$this->comment_generator = $this->createMock( CommentGenerator::class );
		$this->logger = $this->createMock( Logger::class );
		$this->schedule_service = $this->createMock( ScheduleService::class );

		$this->scheduler = new Scheduler(
			$this->post_generator,
			$this->comment_generator,
			$this->logger,
			$this->schedule_service
		);
	}

	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, array() );
	}

	public function test_ensure_schedules_skips_when_plugin_disabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => false ) );

		$this->scheduler->ensure_schedules();

		// Sprawdź że nie ma wyjątku
		$this->assertTrue( true );
	}

	public function test_handle_post_generation_skips_when_plugin_disabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => false ) );

		$this->post_generator->expects( $this->never() )->method( 'generate' );

		$this->scheduler->handle_post_generation();
	}

	public function test_handle_comment_generation_skips_when_plugin_disabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => false ) );

		$this->comment_generator->expects( $this->never() )->method( 'process_queue' );

		$this->scheduler->handle_comment_generation();
	}

	public function test_handle_manual_schedules_skips_when_plugin_disabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => false ) );

		$this->schedule_service->expects( $this->never() )->method( 'run_due' );

		$this->scheduler->handle_manual_schedules();
	}
}

