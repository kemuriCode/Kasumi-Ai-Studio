<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Cron;

use DateTimeImmutable;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Service\ScheduleService;
use Kasumi\AIGenerator\Status\StatusStore;

use function add_action;
use function array_rand;
use function ceil;
use function max;
use function min;
use function time;
use function wp_next_scheduled;
use function wp_rand;
use function wp_schedule_event;
use function wp_schedule_single_event;
use function wp_timezone;

use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;

/**
 * Konfiguruje zadania WP-Cron modułu AI.
 */
class Scheduler {
	public const POST_HOOK    = 'kasumi_ai_generate_post_event';
	public const COMMENT_HOOK = 'kasumi_ai_generate_comment_event';
	public const MANUAL_HOOK  = 'kasumi_ai_run_schedules';

	public function __construct(
		private PostGenerator $post_generator,
		private CommentGenerator $comment_generator,
		private Logger $logger,
		private ScheduleService $schedule_service
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_schedules' ) );
		add_action( self::POST_HOOK, array( $this, 'handle_post_generation' ) );
		add_action( self::COMMENT_HOOK, array( $this, 'handle_comment_generation' ) );
		add_action( self::MANUAL_HOOK, array( $this, 'handle_manual_schedules' ) );
	}

	public function ensure_schedules(): void {
		if ( ! wp_next_scheduled( self::POST_HOOK ) ) {
			$this->schedule_next_post();
		}

		if ( ! wp_next_scheduled( self::COMMENT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::COMMENT_HOOK );
		}

		if ( ! wp_next_scheduled( self::MANUAL_HOOK ) ) {
			$this->schedule_manual_runner();
		}
	}

	public function handle_post_generation(): void {
		$this->post_generator->generate();
		$this->schedule_next_post();
	}

	public function handle_comment_generation(): void {
		$this->comment_generator->process_queue();
	}

	public function handle_manual_schedules(): void {
		$processed = $this->schedule_service->run_due();

		if ( $processed > 0 ) {
			$this->logger->info(
				'Wykonano zadania z harmonogramu Kasumi.',
				array( 'count' => $processed )
			);
		}

		$this->schedule_manual_runner();
	}

	private function schedule_next_post(): void {
		$time = $this->calculate_next_timestamp();

		wp_schedule_single_event( $time, self::POST_HOOK );
		StatusStore::set( 'next_post_run', $time );
	}

	private function calculate_next_timestamp(): int {
		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$days     = $this->random_days_offset();
		$slot     = $this->pick_publication_slot();

		$target = $now->modify( '+' . $days . ' days' )
			->setTime( $slot['hour'], $slot['minute'] );

		if ( $target->getTimestamp() <= time() ) {
			$target = $target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}

	private function schedule_manual_runner(): void {
		wp_schedule_single_event( time() + ( 15 * MINUTE_IN_SECONDS ), self::MANUAL_HOOK );
	}

	private function random_days_offset(): int {
		$base_hours = max( 72, (int) Options::get( 'schedule_interval_hours', 84 ) );
		$base_days  = max( 3, (int) ceil( $base_hours / 24 ) );
		$min_days   = max( 3, $base_days - 1 );
		$max_days   = min( 7, max( $min_days + 1, $base_days + 2 ) );

		return wp_rand( $min_days, $max_days );
	}

	/**
	 * @return array{hour: int, minute: int}
	 */
	private function pick_publication_slot(): array {
		$hours   = array( 9, 11, 14, 17, 20 );
		$minutes = array( 0, 15, 30, 45 );

		return array(
			'hour'   => $hours[ array_rand( $hours ) ],
			'minute' => $minutes[ array_rand( $minutes ) ],
		);
	}
}
