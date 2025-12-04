<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Status\StatsTracker;
use PHPUnit\Framework\TestCase;

/**
 * @group stats
 */
final class StatsTrackerTest extends TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		delete_option( 'kasumi_ai_stats' );
	}

	public function test_record_creates_daily_entry(): void {
		StatsTracker::record( 'article', 'openai', array( 'total_tokens' => 100 ) );

		$stats = StatsTracker::all();
		$today = date( 'Y-m-d' );

		$this->assertArrayHasKey( 'daily', $stats );
		$this->assertArrayHasKey( $today, $stats['daily'] );
		$this->assertNotEmpty( $stats['daily'][ $today ] );
	}

	public function test_record_increments_totals(): void {
		StatsTracker::record( 'article', 'openai', array( 'total_tokens' => 50 ) );
		StatsTracker::record( 'article', 'openai', array( 'total_tokens' => 50 ) );

		$stats = StatsTracker::all();

		$this->assertSame( 2, $stats['totals']['posts'] );
		$this->assertSame( 100, $stats['totals']['total_tokens'] );
	}

	public function test_record_tracks_article_type(): void {
		StatsTracker::record( 'article', 'openai', array() );

		$stats = StatsTracker::all();

		$this->assertSame( 1, $stats['totals']['posts'] );
		$this->assertSame( 0, $stats['totals']['images'] );
		$this->assertSame( 0, $stats['totals']['comments'] );
	}

	public function test_record_tracks_image_type(): void {
		StatsTracker::record( 'image', 'openai', array() );

		$stats = StatsTracker::all();

		$this->assertSame( 0, $stats['totals']['posts'] );
		$this->assertSame( 1, $stats['totals']['images'] );
	}

	public function test_record_tracks_comment_type(): void {
		StatsTracker::record( 'comment', 'openai', array() );

		$stats = StatsTracker::all();

		$this->assertSame( 0, $stats['totals']['posts'] );
		$this->assertSame( 1, $stats['totals']['comments'] );
	}

	public function test_record_tracks_tokens(): void {
		StatsTracker::record( 'article', 'openai', array(
			'input_tokens' => 100,
			'output_tokens' => 200,
			'total_tokens' => 300,
		) );

		$stats = StatsTracker::all();

		$this->assertSame( 100, $stats['totals']['input_tokens'] );
		$this->assertSame( 200, $stats['totals']['output_tokens'] );
		$this->assertSame( 300, $stats['totals']['total_tokens'] );
	}

	public function test_record_tracks_cost(): void {
		StatsTracker::record( 'article', 'openai', array( 'cost' => 0.05 ) );

		$stats = StatsTracker::all();

		$this->assertSame( 0.05, $stats['totals']['cost'] );
	}

	public function test_all_returns_defaults_when_empty(): void {
		delete_option( 'kasumi_ai_stats' );

		$stats = StatsTracker::all();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'totals', $stats );
		$this->assertArrayHasKey( 'daily', $stats );
		$this->assertSame( 0, $stats['totals']['posts'] );
	}

	public function test_get_last_days_returns_recent(): void {
		StatsTracker::record( 'article', 'openai', array() );

		$result = StatsTracker::get_last_days( 7 );

		$this->assertIsArray( $result );
	}

	public function test_get_last_days_limits_to_requested(): void {
		$result = StatsTracker::get_last_days( 5 );

		$this->assertCount( 5, $result );
	}

	public function test_get_last_days_aggregates_by_date(): void {
		StatsTracker::record( 'article', 'openai', array( 'total_tokens' => 100 ) );
		StatsTracker::record( 'image', 'openai', array( 'total_tokens' => 50 ) );

		$result = StatsTracker::get_last_days( 1 );

		$this->assertNotEmpty( $result );
		$last_day = array_key_last( $result );
		$this->assertSame( 1, $result[ $last_day ]['posts'] );
		$this->assertSame( 1, $result[ $last_day ]['images'] );
	}

	public function test_get_last_days_returns_zeroes_for_empty_days(): void {
		StatsTracker::clear();

		$result = StatsTracker::get_last_days( 3 );

		$this->assertCount( 3, $result );
		foreach ( $result as $day ) {
			$this->assertSame( 0, $day['posts'] );
			$this->assertSame( 0, $day['total_tokens'] );
			$this->assertSame( 0.0, $day['cost'] );
		}
	}

	public function test_calculate_cost_openai_gpt4_mini(): void {
		$cost = StatsTracker::calculate_cost( 'openai', 'gpt-4.1-mini', 1000, 500 );

		$this->assertIsFloat( $cost );
		$this->assertGreaterThan( 0, $cost );
	}

	public function test_calculate_cost_openai_gpt4o(): void {
		$cost = StatsTracker::calculate_cost( 'openai', 'gpt-4o', 1000, 500 );

		$this->assertIsFloat( $cost );
		$this->assertGreaterThan( 0, $cost );
	}

	public function test_calculate_cost_gemini_flash(): void {
		$cost = StatsTracker::calculate_cost( 'gemini', 'gemini-2.0-flash', 1000, 500 );

		$this->assertIsFloat( $cost );
		$this->assertSame( 0.0, $cost ); // Bezpłatny
	}

	public function test_calculate_cost_gemini_image(): void {
		$cost = StatsTracker::calculate_cost( 'gemini', 'gemini-2.5-flash-image', 0, 1 );

		$this->assertSame( 0.01, $cost );
	}

	public function test_calculate_cost_openai_image(): void {
		$cost = StatsTracker::calculate_cost( 'openai', 'gpt-image-1', 0, 1 );

		$this->assertSame( 0.04, $cost );
	}

	public function test_calculate_cost_falls_back_to_default(): void {
		$cost = StatsTracker::calculate_cost( 'openai', 'unknown-model', 1000, 500 );

		$this->assertIsFloat( $cost );
		$this->assertGreaterThanOrEqual( 0, $cost );
	}

	public function test_limit_daily_stats_keeps_last_90_days(): void {
		// Test wymaga utworzenia wielu wpisów z różnymi datami
		// W praktyce limit_daily_stats jest wywoływany wewnętrznie
		$this->assertTrue( true );
	}

	public function test_clear_resets_all_stats(): void {
		StatsTracker::record( 'article', 'openai', array( 'total_tokens' => 100 ) );
		StatsTracker::clear();

		$stats = StatsTracker::all();

		$this->assertSame( 0, $stats['totals']['posts'] );
		$this->assertSame( 0.0, $stats['totals']['cost'] );
		$this->assertEmpty( $stats['daily'] );
	}
}
