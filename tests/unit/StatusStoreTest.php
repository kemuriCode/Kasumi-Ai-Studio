<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Status\StatusStore;
use PHPUnit\Framework\TestCase;

/**
 * @group status
 */
final class StatusStoreTest extends TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		delete_option( 'kasumi_ai_status' );
	}

	public function test_all_returns_defaults_when_empty(): void {
		delete_option( 'kasumi_ai_status' );
		$result = StatusStore::all();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'last_post_id', $result );
		$this->assertArrayHasKey( 'last_post_time', $result );
		$this->assertArrayHasKey( 'next_post_run', $result );
		$this->assertArrayHasKey( 'last_error', $result );
		$this->assertArrayHasKey( 'last_comment_time', $result );
		$this->assertArrayHasKey( 'queued_comment_jobs', $result );
	}

	public function test_all_merges_with_saved(): void {
		$saved = array(
			'last_post_id' => 123,
			'last_post_time' => 1234567890,
		);
		update_option( 'kasumi_ai_status', $saved );

		$result = StatusStore::all();

		$this->assertSame( 123, $result['last_post_id'] );
		$this->assertSame( 1234567890, $result['last_post_time'] );
		$this->assertArrayHasKey( 'next_post_run', $result );
	}

	public function test_set_updates_value(): void {
		StatusStore::set( 'last_post_id', 456 );

		$result = StatusStore::all();

		$this->assertSame( 456, $result['last_post_id'] );
	}

	public function test_merge_updates_multiple_values(): void {
		StatusStore::merge( array(
			'last_post_id' => 789,
			'last_post_time' => 9876543210,
		) );

		$result = StatusStore::all();

		$this->assertSame( 789, $result['last_post_id'] );
		$this->assertSame( 9876543210, $result['last_post_time'] );
	}

	public function test_merge_preserves_existing_values(): void {
		StatusStore::set( 'last_post_id', 111 );
		StatusStore::merge( array( 'last_post_time' => 222 ) );

		$result = StatusStore::all();

		$this->assertSame( 111, $result['last_post_id'] );
		$this->assertSame( 222, $result['last_post_time'] );
	}
}

