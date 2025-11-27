<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Service\ScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * @group schedule
 */
final class ScheduleServiceTest extends TestCase {
	private ScheduleService $service;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$logger     = $this->createMock( Logger::class );
		$post_gen   = $this->createMock( PostGenerator::class );

		$this->service = new ScheduleService( $wpdb, $logger, $post_gen );
	}

	public function test_prepare_payload_applies_defaults(): void {
		$result = $this->invoke_prepare_payload( array(), false );

		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertSame( 'draft', $result['post_status'] );
		$this->assertSame( '', $result['post_title'] );
		$this->assertSame( 0, $result['author_id'] );
	}

	public function test_to_gmt_round_trip(): void {
		$gmt = $this->invoke_to_gmt( '2030-01-05T10:15:00' );
		$this->assertNotNull( $gmt );

		$local = $this->invoke_from_gmt( $gmt );
		$this->assertNotNull( $local );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+/', $local );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function invoke_prepare_payload( array $payload, bool $partial ): array {
		$method = new \ReflectionMethod( ScheduleService::class, 'prepare_payload' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $payload, $partial );
	}

	private function invoke_to_gmt( string $date ): ?string {
		$method = new \ReflectionMethod( ScheduleService::class, 'to_gmt' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $date );
	}

	private function invoke_from_gmt( string $date ): ?string {
		$method = new \ReflectionMethod( ScheduleService::class, 'from_gmt' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $date );
	}
}

