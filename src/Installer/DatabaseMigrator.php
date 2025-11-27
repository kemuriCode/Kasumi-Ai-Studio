<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Installer;

use wpdb;

use function dbDelta;
use function trailingslashit;

/**
 * Tworzy/aktualizuje tabele wymagane przez harmonogram.
 */
final class DatabaseMigrator {
	public static function migrate(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		require_once trailingslashit( ABSPATH ) . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'kag_schedules';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			post_type VARCHAR(40) NOT NULL DEFAULT 'post',
			post_status VARCHAR(20) NOT NULL DEFAULT 'draft',
			post_title VARCHAR(255) NOT NULL DEFAULT '',
			publish_at DATETIME NULL,
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			system_prompt MEDIUMTEXT NULL,
			user_prompt MEDIUMTEXT NULL,
			model VARCHAR(80) NULL,
			template_slug VARCHAR(120) NULL,
			meta_json LONGTEXT NULL,
			run_at DATETIME NULL,
			result_post_id BIGINT UNSIGNED NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_publish (status, publish_at),
			KEY author_id (author_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Usuwa tabele wtyczki.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$table = $wpdb->prefix . 'kag_schedules';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

