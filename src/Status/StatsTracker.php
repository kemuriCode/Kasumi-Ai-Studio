<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Status;

use DateTimeImmutable;
use function array_slice;
use function gmdate;
use function get_option;
use function max;
use function str_contains;
use function time;
use function update_option;
use function wp_parse_args;
use function wp_timezone;

/**
 * Śledzi statystyki użycia API (tokeny, koszty).
 */
final class StatsTracker {
	private const OPTION = 'kasumi_ai_stats';

	/**
	 * @param string $type Typ operacji: 'article', 'image', 'comment'
	 * @param string $provider Dostawca: 'openai', 'gemini'
	 * @param array{input_tokens?: int, output_tokens?: int, total_tokens?: int, cost?: float, model?: string} $data
	 */
	public static function record( string $type, string $provider, array $data ): void {
		$stats = self::all();
		$date  = gmdate( 'Y-m-d' );

		if ( ! isset( $stats['daily'][ $date ] ) ) {
			$stats['daily'][ $date ] = array();
		}

		$entry = array(
			'timestamp'     => time(),
			'type'          => $type,
			'provider'      => $provider,
			'input_tokens'  => (int) ( $data['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['output_tokens'] ?? 0 ),
			'total_tokens'  => (int) ( $data['total_tokens'] ?? 0 ),
			'cost'          => (float) ( $data['cost'] ?? 0.0 ),
			'model'         => (string) ( $data['model'] ?? '' ),
		);

		$stats['daily'][ $date ][] = $entry;
		
		// Sumuj totaly
		$stats['totals']['posts']        = (int) ( $stats['totals']['posts'] ?? 0 );
		$stats['totals']['images']       = (int) ( $stats['totals']['images'] ?? 0 );
		$stats['totals']['comments']     = (int) ( $stats['totals']['comments'] ?? 0 );
		$stats['totals']['input_tokens'] = (int) ( $stats['totals']['input_tokens'] ?? 0 );
		$stats['totals']['output_tokens'] = (int) ( $stats['totals']['output_tokens'] ?? 0 );
		$stats['totals']['total_tokens'] = (int) ( $stats['totals']['total_tokens'] ?? 0 );
		$stats['totals']['cost']         = (float) ( $stats['totals']['cost'] ?? 0.0 );

		if ( 'article' === $type ) {
			$stats['totals']['posts']++;
		} elseif ( 'image' === $type ) {
			$stats['totals']['images']++;
		} elseif ( 'comment' === $type ) {
			$stats['totals']['comments']++;
		}

		$stats['totals']['input_tokens'] += $entry['input_tokens'];
		$stats['totals']['output_tokens'] += $entry['output_tokens'];
		$stats['totals']['total_tokens'] += $entry['total_tokens'];
		$stats['totals']['cost'] += $entry['cost'];

		// Ograniczenie do ostatnich 90 dni
		$stats['daily'] = self::limit_daily_stats( $stats['daily'] ?? array() );

		update_option( self::OPTION, $stats );
	}

	/**
	 * Zwraca wszystkie statystyki.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stats = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $stats ) ? $stats : array(),
			array(
				'totals' => array(
					'posts'        => 0,
					'images'       => 0,
					'comments'     => 0,
					'input_tokens' => 0,
					'output_tokens' => 0,
					'total_tokens' => 0,
					'cost'         => 0.0,
				),
				'daily'  => array(),
			)
		);
	}

	/**
	 * Zwraca statystyki dla ostatnich N dni.
	 *
	 * @param int $days
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_last_days( int $days = 30 ): array {
		$stats = self::all();
		$daily = $stats['daily'] ?? array();

		ksort( $daily );

		$days     = max( 1, $days );
		$result   = array();
		$timezone = wp_timezone();
		$start    = ( new DateTimeImmutable( 'now', $timezone ) )
			->setTime( 0, 0, 0 )
			->modify( '-' . ( $days - 1 ) . ' days' );

		for ( $i = 0; $i < $days; $i++ ) {
			$current = $start->modify( '+' . $i . ' days' );
			$date    = $current->format( 'Y-m-d' );
			$entries = $daily[ $date ] ?? array();

			$result[ $date ] = self::summarize_entries( $entries );
		}

		return $result;
	}

	private static function summarize_entries( array $entries ): array {
		$day_totals = array(
			'posts'        => 0,
			'images'       => 0,
			'comments'     => 0,
			'input_tokens' => 0,
			'output_tokens'=> 0,
			'total_tokens' => 0,
			'cost'         => 0.0,
		);

		foreach ( $entries as $entry ) {
			$type = $entry['type'] ?? '';

			if ( 'article' === $type ) {
				$day_totals['posts']++;
			} elseif ( 'image' === $type ) {
				$day_totals['images']++;
			} elseif ( 'comment' === $type ) {
				$day_totals['comments']++;
			}

			$day_totals['input_tokens']  += (int) ( $entry['input_tokens'] ?? 0 );
			$day_totals['output_tokens'] += (int) ( $entry['output_tokens'] ?? 0 );
			$day_totals['total_tokens']  += (int) ( $entry['total_tokens'] ?? 0 );
			$day_totals['cost']          += (float) ( $entry['cost'] ?? 0.0 );
		}

		return $day_totals;
	}

	/**
	 * Oblicza koszt na podstawie modelu i tokenów.
	 *
	 * @param string $provider
	 * @param string $model
	 * @param int $input_tokens
	 * @param int $output_tokens
	 * @return float
	 */
	public static function calculate_cost( string $provider, string $model, int $input_tokens, int $output_tokens ): float {
		// Cenniki na milion tokenów (USD)
		$pricing = array(
			'openai' => array(
				'gpt-4.1-mini'     => array( 'input' => 0.15, 'output' => 0.60 ),
				'gpt-4o-mini'      => array( 'input' => 0.15, 'output' => 0.60 ),
				'gpt-4o'           => array( 'input' => 2.50, 'output' => 10.00 ),
				'gpt-4-turbo'      => array( 'input' => 10.00, 'output' => 30.00 ),
				'o1-preview'       => array( 'input' => 15.00, 'output' => 60.00 ),
				'o1-mini'          => array( 'input' => 3.00, 'output' => 12.00 ),
				'default'          => array( 'input' => 0.15, 'output' => 0.60 ),
			),
			'gemini' => array(
				'gemini-2.0-flash'     => array( 'input' => 0.00, 'output' => 0.00 ), // Bezpłatny
				'gemini-2.5-flash'     => array( 'input' => 0.075, 'output' => 0.30 ),
				'gemini-pro'           => array( 'input' => 0.00, 'output' => 0.00 ),
				'gemini-2.5-flash-image' => array( 'input' => 0.00, 'output' => 0.00 ), // Obrazy: $0.01 za obraz 1024x1024
				'default'              => array( 'input' => 0.00, 'output' => 0.00 ),
			),
		);

		$provider_pricing = $pricing[ $provider ] ?? $pricing['openai'];
		$model_pricing = $provider_pricing[ $model ] ?? $provider_pricing['default'];
		
		$input_cost = ( $input_tokens / 1_000_000 ) * $model_pricing['input'];
		$output_cost = ( $output_tokens / 1_000_000 ) * $model_pricing['output'];
		
		// Dla obrazów Gemini - $0.01 za obraz
		if ( 'gemini' === $provider && str_contains( $model, 'image' ) && $output_tokens > 0 ) {
			return 0.01;
		}
		
		// Dla obrazów OpenAI - $0.04 za obraz 1024x1024
		if ( 'openai' === $provider && str_contains( $model, 'image' ) && $output_tokens > 0 ) {
			return 0.04;
		}
		
		return $input_cost + $output_cost;
	}

	/**
	 * Ogranicza statystyki do ostatnich 90 dni.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $daily
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function limit_daily_stats( array $daily ): array {
		ksort( $daily );
		
		// Zachowaj ostatnie 90 dni
		return array_slice( $daily, -90, 90, true );
	}

	/**
	 * Czyści wszystkie statystyki.
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(
			'totals' => array(
				'posts'        => 0,
				'images'       => 0,
				'comments'     => 0,
				'input_tokens' => 0,
				'output_tokens' => 0,
				'total_tokens' => 0,
				'cost'         => 0.0,
			),
			'daily'  => array(),
		) );
	}
}
