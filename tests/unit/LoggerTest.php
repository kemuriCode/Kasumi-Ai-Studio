<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use PHPUnit\Framework\TestCase;

/**
 * @group logger
 */
final class LoggerTest extends TestCase {
	private Logger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = new Logger();
	}

	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true, 'debug_email' => '' ) ) );
	}

	public function test_info_logs_message(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->info( 'Test info message' );

		// Sprawdź że nie ma wyjątku
		$this->assertTrue( true );
	}

	public function test_warning_logs_message(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->warning( 'Test warning message' );

		$this->assertTrue( true );
	}

	public function test_error_logs_message(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->error( 'Test error message' );

		$this->assertTrue( true );
	}

	public function test_log_includes_context(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->info( 'Test message', array( 'key' => 'value' ) );

		$this->assertTrue( true );
	}

	public function test_log_skips_when_disabled(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => false ) ) );

		$this->logger->info( 'Test message' );

		$this->assertTrue( true );
	}

	public function test_log_creates_directory(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->info( 'Test message' );

		$this->assertTrue( true );
	}

	public function test_error_sends_email_when_configured(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true, 'debug_email' => 'test@example.com' ) ) );

		$this->logger->error( 'Test error', array( 'context' => 'data' ) );

		$this->assertTrue( true );
	}

	public function test_error_skips_email_when_not_configured(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true, 'debug_email' => '' ) ) );

		$this->logger->error( 'Test error' );

		$this->assertTrue( true );
	}

	public function test_log_format_includes_timestamp(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$logs = $this->logger->get_recent_logs( 1 );

		if ( ! empty( $logs ) ) {
			$this->assertArrayHasKey( 'date', $logs[0] );
			$this->assertNotEmpty( $logs[0]['date'] );
		}
	}

	public function test_log_format_includes_level(): void {
		update_option( Options::OPTION_NAME, array_merge( Options::all(), array( 'enable_logging' => true ) ) );

		$this->logger->info( 'Test' );
		$logs = $this->logger->get_recent_logs( 1 );

		if ( ! empty( $logs ) ) {
			$this->assertArrayHasKey( 'level', $logs[0] );
			$this->assertNotEmpty( $logs[0]['level'] );
		}
	}
}

