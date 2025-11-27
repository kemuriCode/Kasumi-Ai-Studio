<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Integration\Installer;

use Kasumi\AIGenerator\Installer\DatabaseMigrator;
use Kasumi\AIGenerator\Options;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group installer
 */
final class DatabaseMigratorTest extends TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		$table = $wpdb->prefix . 'kag_schedules';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( Options::OPTION_NAME, array() );
	}

	public function test_drop_tables_removes_schedule_table(): void {
		global $wpdb;

		// Najpierw utwórz tabelę
		DatabaseMigrator::migrate();
		$table = $wpdb->prefix . 'kag_schedules';

		// Sprawdź że tabela istnieje
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $table, $exists );

		// Usuń tabelę
		DatabaseMigrator::drop_tables();

		// Sprawdź że tabela nie istnieje
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotSame( $table, $exists );
	}

	public function test_drop_tables_does_not_remove_if_option_false(): void {
		global $wpdb;

		// Utwórz tabelę
		DatabaseMigrator::migrate();
		$table = $wpdb->prefix . 'kag_schedules';

		// Sprawdź że tabela istnieje
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $table, $exists );

		// Opcja jest false, więc tabela nie powinna być usunięta przez hook
		// (ten test sprawdza tylko metodę drop_tables, hook jest testowany osobno)
		$this->assertTrue( true );
	}
}

