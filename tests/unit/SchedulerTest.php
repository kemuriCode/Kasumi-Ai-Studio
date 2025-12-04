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
		\wp_clear_scheduled_hook( Scheduler::POST_HOOK );
		\wp_clear_scheduled_hook( Scheduler::COMMENT_HOOK );
		\wp_clear_scheduled_hook( Scheduler::MANUAL_HOOK );
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

	public function test_run_post_now_respects_manual_pause_flag(): void {
		Options::update( array( 'automation_paused' => true ) );

		$this->post_generator
			->expects( $this->never() )
			->method( 'generate' );

		$this->assertFalse( $this->scheduler->run_post_now() );

		$this->post_generator
			->expects( $this->once() )
			->method( 'generate' )
			->willReturn( 123 );

		$this->assertTrue( $this->scheduler->run_post_now( false ) );
	}

	public function test_run_manual_queue_now_can_be_forced_when_paused(): void {
		Options::update( array( 'automation_paused' => true ) );

		$this->schedule_service
			->expects( $this->never() )
			->method( 'run_due' );

		$this->assertSame( 0, $this->scheduler->run_manual_queue_now() );

		$this->schedule_service
			->expects( $this->once() )
			->method( 'run_due' )
			->with( 5 )
			->willReturn( 2 );

		$this->assertSame( 2, $this->scheduler->run_manual_queue_now( false, 5 ) );
	}

	public function test_pause_and_resume_toggle_flag(): void {
		$this->scheduler->pause();
		$this->assertTrue( Options::get( 'automation_paused' ) );

		$this->scheduler->resume();
		$this->assertFalse( Options::get( 'automation_paused' ) );
	}
}
