<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Admin;

use Kasumi\AIGenerator\Cron\Scheduler;

use function __;
use function current_time;
use function date_i18n;
use function get_option;
use function human_time_diff;
use function number_format_i18n;
use function sprintf;

/**
 * Normalizes scheduler data for the admin UI and REST responses.
 */
final class AutomationStatus {
	/**
	 * Builds the UI payload for the current scheduler state.
	 *
	 * @param Scheduler|null $scheduler Scheduler instance.
	 * @return array<string, mixed>
	 */
	public static function snapshot( ?Scheduler $scheduler ): array {
		if ( null === $scheduler ) {
			return self::unavailable();
		}

		$raw = $scheduler->get_status_snapshot();

		return self::from_array( $raw );
	}

	/**
	 * @param array<string, mixed> $raw Snapshot returned by Scheduler::get_status_snapshot().
	 * @return array<string, mixed>
	 */
	public static function from_array( array $raw ): array {
		$available   = true;
		$paused      = (bool) ( $raw['paused'] ?? false );
		$block_reason = (string) ( $raw['block_reason'] ?? '' );
		$status_state = $paused ? 'paused' : 'active';

		if ( '' !== $block_reason ) {
			$status_state = 'blocked';
		}

		$status_label = __(
			'Automatyzacja aktywna',
			'kasumi-ai-generator'
		);

		if ( $paused ) {
			$status_label = __(
				'Automatyzacja zatrzymana',
				'kasumi-ai-generator'
			);
		}

		if ( '' !== $block_reason ) {
			$status_label = __(
				'Automatyzacja wymaga uwagi',
				'kasumi-ai-generator'
			);
		}

		$status        = $raw['status'] ?? array();
		$cron          = $raw['cron'] ?? array();
		$next_post_ts  = isset( $cron['post'] ) ? (int) $cron['post'] : null;
		$manual_ts     = isset( $cron['manual'] ) ? (int) $cron['manual'] : null;
		$comment_ts    = isset( $cron['comment'] ) ? (int) $cron['comment'] : null;
		$queue         = (int) ( $status['queued_comment_jobs'] ?? 0 );
		$last_post_id  = $status['last_post_id'] ?? '—';
		$last_post_ts  = isset( $status['last_post_time'] )
			? (int) $status['last_post_time']
			: null;
		$last_error    = (string) ( $status['last_error'] ?? '' );
		$automation_notice = (string) ( $status['automation_notice'] ?? '' );

		if ( '' !== $block_reason ) {
			$automation_notice = '';
		}

		return array(
			'available'     => $available,
			'paused'        => $paused,
			'state'         => $status_state,
			'status_label'  => $status_label,
			'block_reason'  => $block_reason,
			'notice'        => $automation_notice,
			'meta'          => array(
				'next_post' => self::format_timestamp( $next_post_ts ),
				'manual'    => self::format_timestamp( $manual_ts ),
				'comment'   => self::format_timestamp( $comment_ts ),
			),
			'queue'         => array(
				'value' => $queue,
				'label' => number_format_i18n( $queue ),
			),
			'last_post_id'  => '' !== (string) $last_post_id ? (string) $last_post_id : '—',
			'last_run'      => self::format_timestamp( $last_post_ts, false ),
			'last_error'    => '' !== $last_error
				? $last_error
				: __(
					'Brak błędów',
					'kasumi-ai-generator'
				),
			'fetched_at'    => current_time( 'timestamp' ),
		);
	}

	/**
	 * Returns an empty snapshot when the scheduler cannot be resolved.
	 *
	 * @return array<string, mixed>
	 */
	private static function unavailable(): array {
		return array(
			'available'     => false,
			'paused'        => true,
			'state'         => 'unavailable',
			'status_label'  => __(
				'Automatyzacja niedostępna',
				'kasumi-ai-generator'
			),
			'block_reason'  => '',
			'notice'        => '',
			'meta'          => array(
				'next_post' => self::format_timestamp( null ),
				'manual'    => self::format_timestamp( null ),
				'comment'   => self::format_timestamp( null ),
			),
			'queue'         => array(
				'value' => 0,
				'label' => number_format_i18n( 0 ),
			),
			'last_post_id'  => '—',
			'last_run'      => self::format_timestamp( null, false ),
			'last_error'    => __(
				'Brak błędów',
				'kasumi-ai-generator'
			),
			'fetched_at'    => current_time( 'timestamp' ),
		);
	}

	/**
	 * @return array{timestamp: int|null, label: string}
	 */
	private static function format_timestamp( ?int $timestamp, bool $include_relative = true ): array {
		if ( empty( $timestamp ) ) {
			return array(
				'timestamp' => null,
				'label'     => __(
					'Brak',
					'kasumi-ai-generator'
				),
			);
		}

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$label       = date_i18n( $date_format, $timestamp );
		$now         = current_time( 'timestamp' );
		$relative    = '';

		if ( $include_relative ) {
			if ( $timestamp > $now ) {
				$relative = sprintf(
					/* translators: %s human readable time diff. */
					__( '(za %s)', 'kasumi-ai-generator' ),
					human_time_diff( $now, $timestamp )
				);
			} elseif ( $timestamp < $now ) {
				$relative = sprintf(
					/* translators: %s human readable time diff. */
					__( '(%s temu)', 'kasumi-ai-generator' ),
					human_time_diff( $timestamp, $now )
				);
			}
		}

		return array(
			'timestamp' => $timestamp,
			'label'     => $relative
				? trim( $label . ' ' . $relative )
				: $label,
		);
	}
}
