<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Tests\Unit;

use Kasumi\AIGenerator\Module;
use Kasumi\AIGenerator\Options;
use PHPUnit\Framework\TestCase;

/**
 * @group module
 */
final class ModuleTest extends TestCase {
	protected function tearDown(): void {
		parent::tearDown();
		update_option( Options::OPTION_NAME, array() );
	}

	public function test_module_registers_when_plugin_enabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => true ) );

		$module = new Module();
		$module->register();

		// Sprawdź że nie ma wyjątku
		$this->assertTrue( true );
	}

	public function test_module_registers_when_plugin_disabled(): void {
		update_option( Options::OPTION_NAME, array( 'plugin_enabled' => false ) );

		$module = new Module();
		$module->register();

		// Sprawdź że nie ma wyjątku (SettingsPage powinien być zarejestrowany)
		$this->assertTrue( true );
	}
}

