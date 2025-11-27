<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use DateTimeImmutable;
use DateTimeZone;
use Kasumi\AIGenerator\Log\Logger;
use wpdb;

use function absint;
use function current_time;
use function get_date_from_gmt;
use function in_array;
use function is_array;
use function json_decode;
use function max;
use function sanitize_key;
use function sanitize_text_field;
use function wp_json_encode;
use function wp_parse_args;
use function wp_timezone;
use function wp_unslash;

/**
 * Obsługuje CRUD harmonogramu oraz uruchamianie zadań.
 */
class ScheduleService {
	private string $table;

	public function __construct(
		private wpdb $wpdb,
		private Logger $logger,
		private PostGenerator $post_generator
	) {
		$this->table = $this->wpdb->prefix . 'kag_schedules';
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function list( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'author'   => 0,
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$where  = array();
		$params = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], $this->allowed_statuses(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['author'] ) ) {
			$where[]  = 'author_id = %d';
			$params[] = absint( $args['author'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(post_title LIKE %s OR user_prompt LIKE %s)';
			$like     = '%' . $this->wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$items_query = "SELECT * FROM {$this->table} {$where_sql} ORDER BY publish_at IS NULL, publish_at ASC, id DESC LIMIT %d OFFSET %d";
		$count_query = "SELECT COUNT(id) FROM {$this->table} {$where_sql}";

		$items_params = array_merge( $params, array( $per_page, $offset ) );

		$items_sql = $this->wpdb->prepare( $items_query, $items_params );
		$rows      = $this->wpdb->get_results( $items_sql, ARRAY_A ) ?: array();

		if ( ! empty( $params ) ) {
			$count_sql = $this->wpdb->prepare( $count_query, $params );
		} else {
			$count_sql = $count_query;
		}

		$total = (int) $this->wpdb->get_var( $count_sql );

		return array(
			'items' => array_map( fn( $row ) => $this->normalize_row( $row ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? $this->normalize_row( $row ) : null;
	}

	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return (bool) $deleted;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function create( array $payload ): array {
		$data = $this->prepare_payload( $payload );
		$data['created_at'] = $this->now();
		$data['updated_at'] = $this->now();

		$this->wpdb->insert(
			$this->table,
			$data,
			$this->formats_for_data( $data )
		);

		$id = (int) $this->wpdb->insert_id;

		return $this->find( $id ) ?? array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function update( int $id, array $payload ): array {
		$data = $this->prepare_payload( $payload, true );

		if ( empty( $data ) ) {
			return $this->find( $id ) ?? array();
		}

		$data['updated_at'] = $this->now();

		$this->wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			$this->formats_for_data( $data ),
			array( '%d' )
		);

		return $this->find( $id ) ?? array();
	}

	/**
	 * Uruchamia zaległe zadania (status scheduled + publish_at <= now).
	 */
	public function run_due( int $limit = 3 ): int {
		$limit = max( 1, $limit );
		$now   = $this->now();

		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table}
				WHERE status = %s AND publish_at IS NOT NULL AND publish_at <= %s
				ORDER BY publish_at ASC LIMIT %d",
				'scheduled',
				$now,
				$limit
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$processed = 0;

		foreach ( $ids as $id ) {
			if ( $this->process_single( (int) $id ) ) {
				$processed++;
			}
		}

		return $processed;
	}

	/**
	 * Uruchamia wskazane zadanie natychmiast.
	 */
	public function run_now( int $id ): bool {
		return $this->process_single( $id );
	}

	/**
	 * Wspiera walidację danych wejściowych.
	 */
	private function prepare_payload( array $payload, bool $partial = false ): array {
		$clean = array();

		if ( isset( $payload['status'] ) ) {
			$status = sanitize_key( (string) $payload['status'] );
			if ( in_array( $status, $this->allowed_statuses(), true ) ) {
				$clean['status'] = $status;
			}
		} elseif ( ! $partial ) {
			$clean['status'] = 'draft';
		}

		if ( isset( $payload['post_type'] ) ) {
			$clean['post_type'] = sanitize_key( (string) $payload['post_type'] );
		} elseif ( ! $partial ) {
			$clean['post_type'] = 'post';
		}

		if ( isset( $payload['post_status'] ) ) {
			$clean['post_status'] = sanitize_key( (string) $payload['post_status'] );
		} elseif ( ! $partial ) {
			$clean['post_status'] = 'draft';
		}

		if ( isset( $payload['post_title'] ) ) {
			$clean['post_title'] = sanitize_text_field( wp_unslash( $payload['post_title'] ) );
		} elseif ( ! $partial ) {
			$clean['post_title'] = '';
		}

		if ( array_key_exists( 'publish_at', $payload ) ) {
			$clean['publish_at'] = $this->to_gmt( $payload['publish_at'] );
		}

		if ( isset( $payload['author_id'] ) ) {
			$clean['author_id'] = absint( $payload['author_id'] );
		} elseif ( ! $partial ) {
			$clean['author_id'] = 0;
		}

		if ( array_key_exists( 'system_prompt', $payload ) ) {
			$clean['system_prompt'] = (string) $payload['system_prompt'];
		}

		if ( array_key_exists( 'user_prompt', $payload ) ) {
			$clean['user_prompt'] = (string) $payload['user_prompt'];
		}

		if ( array_key_exists( 'model', $payload ) ) {
			$clean['model'] = sanitize_text_field( (string) $payload['model'] );
		}

		if ( array_key_exists( 'template_slug', $payload ) ) {
			$clean['template_slug'] = sanitize_key( (string) $payload['template_slug'] );
		}

		if ( array_key_exists( 'meta', $payload ) ) {
			$meta = is_array( $payload['meta'] ) ? $payload['meta'] : array();
			$clean['meta_json'] = wp_json_encode( $meta );
		} elseif ( ! $partial ) {
			$clean['meta_json'] = wp_json_encode( array() );
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'status'        => (string) $row['status'],
			'postType'      => (string) $row['post_type'],
			'postStatus'    => (string) $row['post_status'],
			'postTitle'     => (string) $row['post_title'],
			'publishAt'     => $this->from_gmt( $row['publish_at'] ?? null ),
			'authorId'      => (int) $row['author_id'],
			'systemPrompt'  => (string) ( $row['system_prompt'] ?? '' ),
			'userPrompt'    => (string) ( $row['user_prompt'] ?? '' ),
			'model'         => (string) ( $row['model'] ?? '' ),
			'templateSlug'  => (string) ( $row['template_slug'] ?? '' ),
			'meta'          => $this->decode_meta( $row['meta_json'] ?? '' ),
			'runAt'         => $this->from_gmt( $row['run_at'] ?? null ),
			'resultPostId'  => isset( $row['result_post_id'] ) ? (int) $row['result_post_id'] : null,
			'lastError'     => (string) ( $row['last_error'] ?? '' ),
			'createdAt'     => $this->from_gmt( $row['created_at'] ?? null ),
			'updatedAt'     => $this->from_gmt( $row['updated_at'] ?? null ),
		);
	}

	/**
	 * @param string|null $datetime Local time (site tz) in ISO or mysql format.
	 */
	private function to_gmt( $datetime ): ?string {
		if ( empty( $datetime ) ) {
			return null;
		}

		try {
			$timezone = wp_timezone();
			$dt       = new DateTimeImmutable( (string) $datetime, $timezone );

			return $dt
				->setTimezone( new DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			$this->logger->warning(
				'Nie udało się sparsować daty harmonogramu.',
				array( 'value' => $datetime, 'error' => $exception->getMessage() )
			);
		}

		return null;
	}

	private function from_gmt( ?string $datetime ): ?string {
		if ( empty( $datetime ) ) {
			return null;
		}

		return get_date_from_gmt( $datetime, 'c' );
	}

	private function decode_meta( ?string $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : array();
	}

	private function now(): string {
		return current_time( 'mysql', true );
	}

	private function allowed_statuses(): array {
		return array( 'draft', 'scheduled', 'running', 'completed', 'failed' );
	}

	private function formats_for_data( array $data ): array {
		$map = array(
			'status'         => '%s',
			'post_type'      => '%s',
			'post_status'    => '%s',
			'post_title'     => '%s',
			'publish_at'     => '%s',
			'author_id'      => '%d',
			'system_prompt'  => '%s',
			'user_prompt'    => '%s',
			'model'          => '%s',
			'template_slug'  => '%s',
			'meta_json'      => '%s',
			'run_at'         => '%s',
			'result_post_id' => '%d',
			'last_error'     => '%s',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $key ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	private function process_single( int $id ): bool {
		$row = $this->find( $id );

		if ( ! $row ) {
			return false;
		}

		if ( ! in_array( $row['status'], array( 'draft', 'scheduled', 'failed' ), true ) ) {
			return false;
		}

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'     => 'running',
				'updated_at' => $this->now(),
			),
			array(
				'id'     => $id,
				'status' => $row['status'],
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			return false;
		}

		$post_id = $this->post_generator->generate(
			array(
				'user_prompt'  => $row['userPrompt'],
				'system_prompt'=> $row['systemPrompt'],
				'post_type'    => $row['postType'],
				'post_status'  => $row['postStatus'],
				'post_title'   => $row['postTitle'],
				'author_id'    => $row['authorId'],
				'publish_at'   => $row['publishAt'],
				'meta'         => $row['meta'],
				'model'        => $row['model'],
				'ignore_preview_mode' => true,
			)
		);

		if ( $post_id ) {
			$this->wpdb->update(
				$this->table,
				array(
					'status'        => 'completed',
					'run_at'        => $this->now(),
					'result_post_id'=> $post_id,
					'last_error'    => '',
					'updated_at'    => $this->now(),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			return true;
		}

		$this->wpdb->update(
			$this->table,
			array(
				'status'     => 'failed',
				'last_error' => 'Nie udało się wygenerować wpisu.',
				'updated_at' => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false;
	}
}

