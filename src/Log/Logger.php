<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Log;

use Kasumi\AIGenerator\Options;

use const FILE_APPEND;
use const JSON_PRETTY_PRINT;
use function __;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function get_bloginfo;
use function gmdate;
use function is_dir;
use function preg_match;
use function sprintf;
use function strtoupper;
use function trailingslashit;
use function wp_json_encode;
use function wp_mail;
use function wp_mkdir_p;
use function wp_upload_dir;

/**
 * Bardzo prosty logger zapisujący pliki w katalogu uploadów.
 */
class Logger {
	private const DIRECTORY = 'kasumi-ai/logs';
	private const FILE_NAME = 'ai-content.log';

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( ! Options::get( 'enable_logging' ) ) {
			return;
		}

		$uploads = wp_upload_dir();
		$directory = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$line = sprintf(
			"[%s][%s] %s",
			gmdate( 'c' ),
			strtoupper( $level ),
			$message
		);

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		file_put_contents(
			$directory . '/' . self::FILE_NAME,
			$line . PHP_EOL,
			FILE_APPEND
		);

		if ( 'error' === $level ) {
			$this->maybe_notify( $message, $context );
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function maybe_notify( string $message, array $context ): void {
		$email = Options::get( 'debug_email', '' );

		if ( empty( $email ) ) {
			return;
		}

		/* translators: %s – nazwa strony */
		$subject = sprintf( __( 'Błąd modułu AI na %s', 'kasumi-ai-generator' ), get_bloginfo( 'name' ) );
		$body    = $message;

		if ( ! empty( $context ) ) {
			$body .= "\n\n" . wp_json_encode( $context, JSON_PRETTY_PRINT );
		}

		wp_mail( $email, $subject, $body );
	}

	/**
	 * Pobiera ostatnie wpisy z logów.
	 *
	 * @param int $limit Maksymalna liczba wpisów.
	 * @return array<int, array{date: string, level: string, message: string, context: array}>
	 */
	public function get_recent_logs( int $limit = 50 ): array {
		$uploads = wp_upload_dir();
		$file_path = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY . '/' . self::FILE_NAME;

		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			return array();
		}

		$lines = explode( PHP_EOL, $content );
		$lines = array_filter( $lines );
		$lines = array_reverse( $lines ); // Najnowsze na początku
		$lines = array_slice( $lines, 0, $limit );

		$logs = array();

		foreach ( $lines as $line ) {
			// Format: [2024-01-01T12:00:00+00:00][INFO] message {"context": "data"}
			if ( preg_match( '/^\[([^\]]+)\]\[([^\]]+)\]\s+(.+?)(?:\s+(\{.*\}))?$/', $line, $matches ) ) {
				$date = $matches[1] ?? '';
				$level = strtolower( $matches[2] ?? '' );
				$message = $matches[3] ?? '';
				$context_json = $matches[4] ?? '{}';

				$context = array();
				if ( ! empty( $context_json ) ) {
					$decoded = json_decode( $context_json, true );
					if ( is_array( $decoded ) ) {
						$context = $decoded;
					}
				}

				$logs[] = array(
					'date'    => $date,
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		}

		return $logs;
	}
}
