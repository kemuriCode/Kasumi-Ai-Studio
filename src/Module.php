<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator;

use Kasumi\AIGenerator\Admin\ModelsController;
use Kasumi\AIGenerator\Admin\PreviewController;
use Kasumi\AIGenerator\Admin\SettingsPage;
use Kasumi\AIGenerator\Cron\Scheduler;
use Kasumi\AIGenerator\Installer\DatabaseMigrator;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Rest\SchedulesController;
use Kasumi\AIGenerator\Rest\SettingsController;
use Kasumi\AIGenerator\Service\AiClient;
use Kasumi\AIGenerator\Service\BlockContentBuilder;
use Kasumi\AIGenerator\Service\CommentGenerator;
use Kasumi\AIGenerator\Service\ContextResolver;
use Kasumi\AIGenerator\Service\FeaturedImageBuilder;
use Kasumi\AIGenerator\Service\LinkBuilder;
use Kasumi\AIGenerator\Service\MarkdownConverter;
use Kasumi\AIGenerator\Service\PostGenerator;
use Kasumi\AIGenerator\Service\ScheduleService;

use function get_option;
use function update_option;

/**
 * Bootstrap Kasumi AI generator.
 */
final class Module {
	private SettingsPage $settings_page;
	private Logger $logger;
	private Scheduler $scheduler;
	private PostGenerator $post_generator;
	private CommentGenerator $comment_generator;
	private PreviewController $preview_controller;
	private ModelsController $models_controller;
	private ContextResolver $context_resolver;
	private ScheduleService $schedule_service;
	private SchedulesController $schedules_controller;
	private SettingsController $settings_controller;

	public function __construct() {
		$this->settings_page = new SettingsPage();
		$this->logger        = new Logger();

		$this->maybe_run_migrations();

		$ai_client            = new AiClient( $this->logger );
		$link_builder         = new LinkBuilder();
		$image_builder        = new FeaturedImageBuilder( $this->logger, $ai_client );
		$markdown_converter   = new MarkdownConverter();
		$block_content_builder = new BlockContentBuilder( $markdown_converter );
		$this->context_resolver = new ContextResolver();
		$this->comment_generator = new CommentGenerator( $ai_client, $this->logger );
		$this->post_generator    = new PostGenerator(
			$ai_client,
			$image_builder,
			$link_builder,
			$this->comment_generator,
			$this->context_resolver,
			$this->logger,
			$markdown_converter,
			$block_content_builder
		);
		$this->preview_controller = new PreviewController(
			$ai_client,
			$image_builder,
			$this->context_resolver,
			$this->logger,
			$markdown_converter
		);
		$this->models_controller = new ModelsController( $ai_client );

		global $wpdb;

		$this->schedule_service = new ScheduleService( $wpdb, $this->logger, $this->post_generator );

		$this->scheduler = new Scheduler(
			$this->post_generator,
			$this->comment_generator,
			$this->logger,
			$this->schedule_service
		);
		$this->schedules_controller = new SchedulesController( $this->schedule_service );
		$this->settings_controller = new SettingsController();
	}

	public function register(): void {
		// Zawsze rejestruj stronę ustawień, aby można było włączyć wtyczkę z powrotem
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings_page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueue_assets' ) );

		// Rejestruj kontrolery, które nie wymagają aktywnej wtyczki
		$this->preview_controller->register();
		$this->models_controller->register();
		$this->settings_controller->register();

		// Rejestruj tylko jeśli wtyczka jest włączona
		if ( Options::get( 'plugin_enabled', true ) ) {
			$this->scheduler->register();
			$this->schedules_controller->register();
		}
	}

	private function maybe_run_migrations(): void {
		$current = (string) get_option( 'kasumi_ai_db_version', '' );

		if ( defined( 'KASUMI_AI_DB_VERSION' ) && KASUMI_AI_DB_VERSION !== $current ) {
			DatabaseMigrator::migrate();
			update_option( 'kasumi_ai_db_version', KASUMI_AI_DB_VERSION );
		}
	}
}
