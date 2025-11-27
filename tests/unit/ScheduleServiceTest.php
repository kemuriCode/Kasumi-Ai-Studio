<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Service\ScheduleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use wpdb;

/**
 * @group schedule
 */
final class ScheduleServiceTest extends TestCase {
	private ScheduleService $service;
	private wpdb&MockObject $wpdb;
	private Logger&MockObject $logger;
	private PostGenerator&MockObject $post_generator;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = $this->createMock( wpdb::class );
		$this->wpdb->prefix = 'wp_';
		$this->logger = $this->createMock( Logger::class );
		$this->post_generator = $this->createMock( PostGenerator::class );

		$this->service = new ScheduleService( $this->wpdb, $this->logger, $this->post_generator );
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

	public function test_list_returns_empty_when_no_schedules(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules  ORDER BY publish_at IS NULL, publish_at ASC, id DESC LIMIT 20 OFFSET 0' );
		$this->wpdb->method( 'get_results' )->willReturn( array() );
		$this->wpdb->method( 'get_var' )->willReturn( '0' );

		$result = $this->service->list();

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_list_filters_by_status(): void {
		$row = array(
			'id' => 1,
			'status' => 'scheduled',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_results' )->willReturn( array( $row ) );
		$this->wpdb->method( 'get_var' )->willReturn( '1' );
		$this->wpdb->method( 'esc_like' )->willReturnArgument( 0 );

		$result = $this->service->list( array( 'status' => 'scheduled' ) );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'scheduled', $result['items'][0]['status'] );
	}

	public function test_list_filters_by_author(): void {
		$row = array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 5,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_results' )->willReturn( array( $row ) );
		$this->wpdb->method( 'get_var' )->willReturn( '1' );
		$this->wpdb->method( 'esc_like' )->willReturnArgument( 0 );

		$result = $this->service->list( array( 'author' => 5 ) );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 5, $result['items'][0]['authorId'] );
	}

	public function test_list_filters_by_search(): void {
		$row = array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test Title',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_results' )->willReturn( array( $row ) );
		$this->wpdb->method( 'get_var' )->willReturn( '1' );
		$this->wpdb->method( 'esc_like' )->willReturnArgument( 0 );

		$result = $this->service->list( array( 'search' => 'Test' ) );

		$this->assertCount( 1, $result['items'] );
	}

	public function test_list_pagination(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_results' )->willReturn( array() );
		$this->wpdb->method( 'get_var' )->willReturn( '25' );
		$this->wpdb->method( 'esc_like' )->willReturnArgument( 0 );

		$result = $this->service->list( array( 'per_page' => 10, 'page' => 2 ) );

		$this->assertSame( 25, $result['total'] );
	}

	public function test_list_ordering(): void {
		$row1 = array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test 1',
			'publish_at' => '2024-01-01 10:00:00',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);
		$row2 = array(
			'id' => 2,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test 2',
			'publish_at' => null,
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_results' )->willReturn( array( $row1, $row2 ) );
		$this->wpdb->method( 'get_var' )->willReturn( '2' );
		$this->wpdb->method( 'esc_like' )->willReturnArgument( 0 );

		$result = $this->service->list();

		$this->assertCount( 2, $result['items'] );
	}

	public function test_find_returns_null_for_invalid_id(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 999' );
		$this->wpdb->method( 'get_row' )->willReturn( null );

		$result = $this->service->find( 999 );

		$this->assertNull( $result );
	}

	public function test_find_returns_normalized_data(): void {
		$row = array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 5,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( $row );

		$result = $this->service->find( 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['postType'] );
		$this->assertSame( 'draft', $result['postStatus'] );
		$this->assertSame( 5, $result['authorId'] );
	}

	public function test_delete_returns_false_when_not_found(): void {
		$this->wpdb->method( 'delete' )->willReturn( false );

		$result = $this->service->delete( 999 );

		$this->assertFalse( $result );
	}

	public function test_delete_returns_true_on_success(): void {
		$this->wpdb->method( 'delete' )->willReturn( 1 );

		$result = $this->service->delete( 1 );

		$this->assertTrue( $result );
	}

	public function test_create_applies_defaults(): void {
		$this->wpdb->insert_id = 1;
		$this->wpdb->method( 'insert' )->willReturn( true );
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => '',
			'author_id' => 0,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
			'created_at' => '2024-01-01 10:00:00',
			'updated_at' => '2024-01-01 10:00:00',
		) );

		$result = $this->service->create( array() );

		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'post', $result['postType'] );
	}

	public function test_create_sanitizes_input(): void {
		$this->wpdb->insert_id = 1;
		$this->wpdb->method( 'insert' )->willReturn( true );
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'scheduled',
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Sanitized Title',
			'author_id' => 5,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
			'created_at' => '2024-01-01 10:00:00',
			'updated_at' => '2024-01-01 10:00:00',
		) );

		$result = $this->service->create( array(
			'status' => 'SCHEDULED',
			'post_type' => 'PAGE',
			'post_title' => '<script>alert("xss")</script>Title',
		) );

		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertSame( 'page', $result['postType'] );
	}

	public function test_create_sets_timestamps(): void {
		$this->wpdb->insert_id = 1;
		$this->wpdb->method( 'insert' )->willReturn( true );
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => '',
			'author_id' => 0,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
			'created_at' => '2024-01-01 10:00:00',
			'updated_at' => '2024-01-01 10:00:00',
		) );

		$result = $this->service->create( array() );

		$this->assertNotNull( $result['createdAt'] );
		$this->assertNotNull( $result['updatedAt'] );
	}

	public function test_update_partial_payload(): void {
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'scheduled',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Updated Title',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
			'updated_at' => '2024-01-01 11:00:00',
		) );

		$result = $this->service->update( 1, array( 'post_title' => 'Updated Title' ) );

		$this->assertSame( 'Updated Title', $result['postTitle'] );
	}

	public function test_update_empty_payload_returns_unchanged(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Original',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );

		$result = $this->service->update( 1, array() );

		$this->assertSame( 'Original', $result['postTitle'] );
	}

	public function test_update_sets_updated_at(): void {
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
			'updated_at' => '2024-01-01 11:00:00',
		) );

		$result = $this->service->update( 1, array( 'status' => 'scheduled' ) );

		$this->assertNotNull( $result['updatedAt'] );
	}

	public function test_run_due_processes_scheduled_items(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_col' )->willReturn( array( '1', '2' ) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'scheduled',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->post_generator->method( 'generate' )->willReturn( 123 );

		$result = $this->service->run_due( 3 );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_run_due_respects_limit(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_col' )->willReturn( array( '1' ) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'scheduled',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->post_generator->method( 'generate' )->willReturn( 123 );

		$result = $this->service->run_due( 1 );

		$this->assertLessThanOrEqual( 1, $result );
	}

	public function test_run_due_skips_future_dates(): void {
		$this->wpdb->method( 'prepare' )->willReturnCallback( function ( $query ) {
			return $query;
		} );
		$this->wpdb->method( 'get_col' )->willReturn( array() );

		$result = $this->service->run_due( 3 );

		$this->assertSame( 0, $result );
	}

	public function test_run_now_processes_immediately(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->post_generator->method( 'generate' )->willReturn( 123 );

		$result = $this->service->run_now( 1 );

		$this->assertTrue( $result );
	}

	public function test_process_single_updates_status_to_running(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		
		// Sprawdź że update jest wywoływany - pierwszy z statusem 'running', drugi z 'completed'
		$call_count = 0;
		$this->wpdb->method( 'update' )->willReturnCallback( function ( $table, $data ) use ( &$call_count ) {
			$call_count++;
			if ( $call_count === 1 ) {
				// Pierwszy update - status 'running'
				$this->assertSame( 'running', $data['status'] ?? '' );
			} elseif ( $call_count === 2 ) {
				// Drugi update - status 'completed'
				$this->assertSame( 'completed', $data['status'] ?? '' );
			}
			return 1;
		});
		
		$this->post_generator->method( 'generate' )->willReturn( 123 );

		$result = $this->service->run_now( 1 );

		$this->assertTrue( $result );
		$this->assertGreaterThanOrEqual( 2, $call_count );
	}

	public function test_process_single_calls_post_generator(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->post_generator->expects( $this->once() )->method( 'generate' )->willReturn( 123 );

		$this->service->run_now( 1 );
	}

	public function test_process_single_sets_completed_on_success(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->post_generator->method( 'generate' )->willReturn( 123 );

		$result = $this->service->run_now( 1 );

		$this->assertTrue( $result );
	}

	public function test_process_single_sets_failed_on_error(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );
		$this->wpdb->method( 'update' )->willReturn( 1 );
		$this->post_generator->method( 'generate' )->willReturn( null );

		$result = $this->service->run_now( 1 );

		$this->assertFalse( $result );
	}

	public function test_process_single_skips_invalid_status(): void {
		$this->wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM wp_kag_schedules WHERE id = 1' );
		$this->wpdb->method( 'get_row' )->willReturn( array(
			'id' => 1,
			'status' => 'running',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 1,
			'system_prompt' => '',
			'user_prompt' => 'Test prompt',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		) );

		$result = $this->service->run_now( 1 );

		$this->assertFalse( $result );
	}

	public function test_prepare_payload_sanitizes_status(): void {
		$result = $this->invoke_prepare_payload( array( 'status' => 'SCHEDULED' ), false );

		$this->assertSame( 'scheduled', $result['status'] );
	}

	public function test_prepare_payload_sanitizes_post_type(): void {
		$result = $this->invoke_prepare_payload( array( 'post_type' => 'PAGE' ), false );

		$this->assertSame( 'page', $result['post_type'] );
	}

	public function test_prepare_payload_converts_publish_at_to_gmt(): void {
		$result = $this->invoke_prepare_payload( array( 'publish_at' => '2024-01-01T10:00:00+01:00' ), false );

		$this->assertNotNull( $result['publish_at'] );
		$this->assertIsString( $result['publish_at'] );
	}

	public function test_prepare_payload_handles_null_publish_at(): void {
		$result = $this->invoke_prepare_payload( array( 'publish_at' => null ), false );

		$this->assertNull( $result['publish_at'] ?? null );
	}

	public function test_prepare_payload_encodes_meta_json(): void {
		$meta = array( 'key' => 'value' );
		$result = $this->invoke_prepare_payload( array( 'meta' => $meta ), false );

		$this->assertArrayHasKey( 'meta_json', $result );
		$this->assertJson( $result['meta_json'] );
	}

	public function test_normalize_row_converts_snake_to_camel(): void {
		$row = array(
			'id' => 1,
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Test',
			'author_id' => 5,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{}',
		);

		$method = new ReflectionMethod( ScheduleService::class, 'normalize_row' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->service, $row );

		$this->assertArrayHasKey( 'postType', $result );
		$this->assertArrayHasKey( 'postStatus', $result );
		$this->assertArrayHasKey( 'authorId', $result );
	}

	public function test_normalize_row_decodes_meta_json(): void {
		$row = array(
			'id' => 1,
			'status' => 'draft',
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => '',
			'author_id' => 0,
			'system_prompt' => '',
			'user_prompt' => '',
			'model' => '',
			'template_slug' => '',
			'meta_json' => '{"key":"value"}',
		);

		$method = new ReflectionMethod( ScheduleService::class, 'normalize_row' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->service, $row );

		$this->assertIsArray( $result['meta'] );
		$this->assertSame( 'value', $result['meta']['key'] );
	}

	public function test_to_gmt_handles_invalid_date(): void {
		$result = $this->invoke_to_gmt( 'invalid-date' );

		$this->assertNull( $result );
	}

	public function test_to_gmt_handles_timezone(): void {
		$result = $this->invoke_to_gmt( '2024-01-01T10:00:00+01:00' );

		$this->assertNotNull( $result );
		$this->assertIsString( $result );
	}

	public function test_from_gmt_converts_to_local(): void {
		$gmt = '2024-01-01 10:00:00';
		$result = $this->invoke_from_gmt( $gmt );

		$this->assertNotNull( $result );
		$this->assertIsString( $result );
	}

	public function test_from_gmt_handles_null(): void {
		$result = $this->invoke_from_gmt( null );

		$this->assertNull( $result );
	}

	public function test_decode_meta_handles_invalid_json(): void {
		$method = new ReflectionMethod( ScheduleService::class, 'decode_meta' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->service, 'invalid json' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_allowed_statuses(): void {
		$method = new ReflectionMethod( ScheduleService::class, 'allowed_statuses' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->service );

		$this->assertContains( 'draft', $result );
		$this->assertContains( 'scheduled', $result );
		$this->assertContains( 'running', $result );
		$this->assertContains( 'completed', $result );
		$this->assertContains( 'failed', $result );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function invoke_prepare_payload( array $payload, bool $partial ): array {
		$method = new ReflectionMethod( ScheduleService::class, 'prepare_payload' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $payload, $partial );
	}

	private function invoke_to_gmt( string $date ): ?string {
		$method = new ReflectionMethod( ScheduleService::class, 'to_gmt' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $date );
	}

	private function invoke_from_gmt( ?string $date ): ?string {
		$method = new ReflectionMethod( ScheduleService::class, 'from_gmt' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $date );
	}
}

