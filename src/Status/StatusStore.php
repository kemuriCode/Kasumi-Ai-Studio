<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Status;

use function get_option;
use function update_option;
use function wp_parse_args;

/**
 * Przechowuje metadane dot. ostatnich zadań AI.
 */
final class StatusStore {
	private const OPTION = 'kasumi_ai_status';

	public static function all(): array {
		$status = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $status ) ? $status : array(),
			array(
				'last_post_id'        => null,
				'last_post_time'      => null,
				'next_post_run'       => null,
				'last_error'          => '',
				'last_comment_time'   => null,
				'queued_comment_jobs' => 0,
				'automation_notice'   => '',
			)
		);
	}

	public static function set( string $key, $value ): void {
		$current        = self::all();
		$current[ $key ] = $value;

		update_option( self::OPTION, $current );
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function merge( array $values ): void {
		$current = self::all();

		update_option(
			self::OPTION,
			array_merge( $current, $values )
		);
	}
}
