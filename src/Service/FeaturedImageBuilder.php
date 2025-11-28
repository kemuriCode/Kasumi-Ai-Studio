<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Kasumi\AIGenerator\Log\Logger;
use Kasumi\AIGenerator\Options;

use const ABSPATH;
use function __;
use function array_map;
use function array_rand;
use function ceil;
use function base64_encode;
use function class_exists;
use function dirname;
use function lcfirst;
use function explode;
use function extension_loaded;
use function file_exists;
use function function_exists;
use function imagecolorallocate;
use function imagecolorallocatealpha;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagealphablending;
use function imagecopyresampled;
use function imagedestroy;
use function imagefilledrectangle;
use function imagefontheight;
use function imagefontwidth;
use function imagejpeg;
use function imageline;
use function imagettfbbox;
use function imagettftext;
use function imagestring;
use function imagesx;
use function imagesy;
use function imagewebp;
use function implode;
use function is_array;
use function is_readable;
use function is_wp_error;
use function json_decode;
use function preg_replace;
use function mb_strlen;
use function mb_strtoupper;
use function ob_get_clean;
use function ob_start;
use function preg_split;
use function strlen;
use function set_post_thumbnail;
use function sprintf;
use function strip_tags;
use function time;
use function trim;
use function update_post_meta;
use function wordwrap;
use function wp_normalize_path;
use function wp_generate_attachment_metadata;
use function wp_insert_attachment;
use function wp_strip_all_tags;
use function wp_trim_words;
use function wp_update_attachment_metadata;
use function wp_upload_bits;
use function trailingslashit;

/**
 * Buduje grafiki wyróżniające.
 */
class FeaturedImageBuilder {
	private const STYLE_PRESETS = array(
		'modern'   => array(
			'font_candidates' => array(
				'Inter-SemiBold.ttf',
				'Inter-Bold.ttf',
			),
			'system_fonts'    => array(
				'/usr/share/fonts/truetype/inter/Inter-SemiBold.ttf',
				'/usr/share/fonts/truetype/google-fonts/Inter-SemiBold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			),
			'size_ratio'  => 18,
			'line_height' => 1.32,
			'kerning'     => 0.8,
			'uppercase'   => false,
			'weight'      => 600,
		),
		'classic'  => array(
			'font_candidates' => array(
				'Merriweather-Bold.ttf',
				'PlayfairDisplay-SemiBold.ttf',
			),
			'system_fonts'    => array(
				'/usr/share/fonts/truetype/merriweather/Merriweather-Bold.ttf',
				'/usr/share/fonts/truetype/freefont/FreeSerifBold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
			),
			'size_ratio'  => 20,
			'line_height' => 1.4,
			'kerning'     => 0.4,
			'uppercase'   => false,
			'weight'      => 600,
		),
		'oldschool'=> array(
			'font_candidates' => array(
				'SpaceMono-Bold.ttf',
				'RubikMonoOne-Regular.ttf',
			),
			'system_fonts'    => array(
				'/usr/share/fonts/truetype/space-mono/SpaceMono-Bold.ttf',
				'/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
			),
			'size_ratio'  => 22,
			'line_height' => 1.2,
			'kerning'     => 1.1,
			'uppercase'   => true,
			'weight'      => 700,
		),
	);

	private const DEFAULT_STYLE = 'modern';
	private const TEXT_MARGIN = 56;

	private Client $http_client;

	public function __construct(
		private Logger $logger,
		private AiClient $ai_client,
		?Client $http_client = null
	) {
		$this->http_client = $http_client ?: new Client(
			array(
				'timeout' => 15,
			)
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	public function build( int $post_id, array $article ): ?int {
		$blob = $this->generate_image_blob( $article, true );

		if ( ! $blob ) {
			return null;
		}

		return $this->persist_attachment( $post_id, $blob, $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	public function preview( array $article ): ?string {
		$blob = $this->generate_image_blob( $article, false );

		if ( ! $blob ) {
			return null;
		}

		return 'data:image/webp;base64,' . base64_encode( $blob );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_image_blob( array $article, bool $respect_toggle = true ): ?string {
		if ( $respect_toggle && ! Options::get( 'enable_featured_images' ) ) {
			return null;
		}

		$mode = (string) Options::get( 'image_generation_mode', 'server' );

		return 'remote' === $mode
			? $this->generate_remote_image( $article )
			: $this->generate_server_image( $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_remote_image( array $article ): ?string {
		$binary = $this->ai_client->generate_remote_image( $article );

		if ( empty( $binary ) ) {
			$this->logger->warning( 'OpenAI Images API nie zwróciło grafiki.' );

			return null;
		}

		return $binary;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function generate_server_image( array $article ): ?string {
		$engine = (string) Options::get( 'image_server_engine', 'imagick' );

		if ( 'imagick' === $engine && ! class_exists( Imagick::class ) ) {
			$this->logger->warning( 'Imagick nie jest dostępny – użyję biblioteki GD.' );
			$engine = 'gd';
		}

		if ( 'gd' === $engine && ! extension_loaded( 'gd' ) ) {
			$this->logger->warning( 'Biblioteka GD nie jest dostępna na serwerze.' );

			return null;
		}

		$image_url = $this->fetch_pixabay_url();

		// Fallback: jeśli brak Pixabay, generuj prostszą grafikę
		if ( ! $image_url ) {
			$this->logger->info( 'Brak klucza Pixabay – generowanie uproszczonej grafiki z tłem.' );
			return $this->generate_simple_fallback_image( $article, $engine );
		}

		$binary = $this->download_image( $image_url );

		if ( ! $binary ) {
			$this->logger->info( 'Nie udało się pobrać obrazu z Pixabay – generowanie uproszczonej grafiki.' );
			return $this->generate_simple_fallback_image( $article, $engine );
		}

		return 'gd' === $engine
			? $this->process_with_gd( $binary, $article )
			: $this->process_with_imagick( $binary, $article );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function process_with_imagick( string $binary, array $article ): ?string {
		try {
			$imagick = new Imagick();
			$imagick->readImageBlob( $binary );
			$imagick->setImageColorspace( Imagick::COLORSPACE_SRGB );
			$this->normalize_imagick_canvas( $imagick );
			$this->apply_overlay( $imagick, $this->get_overlay_color(), $this->get_overlay_opacity() );

			if ( $this->should_render_caption() ) {
				$this->annotate_caption_imagick( $imagick, $article );
			}

			$imagick->setImageFormat( 'webp' );

			return $imagick->getImageBlob();
		} catch ( \Throwable $throwable ) {
			$this->logger->error(
				'Nie udało się przetworzyć grafiki AI (Imagick).',
				array( 'exception' => $throwable->getMessage() )
			);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function process_with_gd( string $binary, array $article ): ?string {
		$source = imagecreatefromstring( $binary );

		if ( false === $source ) {
			return null;
		}

		$dimensions = $this->get_canvas_dimensions();
		$canvas     = imagecreatetruecolor( $dimensions['width'], $dimensions['height'] );

		if ( false === $canvas ) {
			imagedestroy( $source );
			return null;
		}

		imagealphablending( $canvas, true );
		imagecopyresampled(
			$canvas,
			$source,
			0,
			0,
			0,
			0,
			$dimensions['width'],
			$dimensions['height'],
			imagesx( $source ),
			imagesy( $source )
		);
		imagedestroy( $source );

		$this->apply_overlay_gd( $canvas, $this->get_overlay_color(), $this->get_overlay_opacity() );

		if ( $this->should_render_caption() ) {
			$this->annotate_caption_gd( $canvas, $article );
		}

		return $this->export_gd_image( $canvas );
	}

	private function fetch_pixabay_url(): ?string {
		$api_key = Options::get( 'pixabay_api_key', '' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$query       = Options::get( 'pixabay_query', 'qr code' );
		$orientation = Options::get( 'pixabay_orientation', 'horizontal' );

		try {
			$response = $this->http_client->get(
				'https://pixabay.com/api/',
				array(
					'query' => array(
						'key'         => $api_key,
						'q'           => $query,
						'image_type'  => 'photo',
						'orientation' => $orientation,
						'safesearch'  => 'true',
						'per_page'    => 20,
					),
				)
			);
		} catch ( GuzzleException $exception ) {
			$this->logger->warning(
				'Pixabay API jest nieosiągalne.',
				array( 'exception' => $exception->getMessage() )
			);

			return null;
		}

		$data = json_decode( (string) $response->getBody(), true );

		if ( empty( $data['hits'] ) || ! is_array( $data['hits'] ) ) {
			return null;
		}

		$hit = $data['hits'][ array_rand( $data['hits'] ) ];

		return $hit['largeImageURL'] ?? $hit['webformatURL'] ?? null;
	}

	private function download_image( string $url ): ?string {
		try {
			$response = $this->http_client->get( $url );
		} catch ( GuzzleException $exception ) {
			$this->logger->warning(
				'Nie udało się pobrać pliku Pixabay.',
				array( 'exception' => $exception->getMessage() )
			);

			return null;
		}

		return (string) $response->getBody();
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function persist_attachment( int $post_id, string $blob, array $article ): ?int {
		$filename = sprintf( 'kasumi-ai-%d-%d.webp', $post_id, time() );
		$upload   = wp_upload_bits( $filename, null, $blob );

		if ( ! empty( $upload['error'] ) ) {
			$this->logger->error(
				'Błąd zapisu grafiki AI w katalogu upload.',
				array( 'error' => $upload['error'] )
			);

			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/webp',
				'post_title'     => wp_strip_all_tags( ( $article['title'] ?? '' ) . ' – grafika Kasumi AI' ),
				'post_status'    => 'inherit',
				'guid'           => $upload['url'],
			),
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->error(
				'Nie można utworzyć załącznika grafiki AI.',
				array( 'error' => $attachment_id->get_error_message() )
			);

			return null;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $this->build_alt_text( $article ) );
		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}

	private function normalize_imagick_canvas( Imagick $imagick ): void {
		$dimensions = $this->get_canvas_dimensions();
		$imagick->cropThumbnailImage( $dimensions['width'], $dimensions['height'] );
	}

	private function apply_overlay( Imagick $imagick, string $color, float $opacity ): void {
		$overlay = new Imagick();
		$overlay->newImage( $imagick->getImageWidth(), $imagick->getImageHeight(), new ImagickPixel( '#' . $color ) );
		$overlay->setImageAlpha( max( 0.0, min( 1.0, $opacity ) ) );
		$imagick->compositeImage( $overlay, Imagick::COMPOSITE_OVER, 0, 0 );
	}

	private function apply_overlay_gd( \GdImage $canvas, string $color, float $opacity ): void {
		$rgb    = $this->hex_to_rgb( $color );
		$alpha  = $this->opacity_to_alpha( $opacity );
		$width  = imagesx( $canvas );
		$height = imagesy( $canvas );
		$brush  = imagecolorallocatealpha( $canvas, $rgb['r'], $rgb['g'], $rgb['b'], $alpha );

		imagefilledrectangle( $canvas, 0, 0, $width, $height, $brush );
	}

	private function export_gd_image( \GdImage $canvas ): ?string {
		ob_start();

		if ( function_exists( 'imagewebp' ) ) {
			imagewebp( $canvas, null, 90 );
		} else {
			imagejpeg( $canvas, null, 90 );
		}

		$blob = ob_get_clean();
		imagedestroy( $canvas );

		return $blob ?: null;
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function annotate_caption_imagick( Imagick $imagick, array $article ): void {
		$style    = $this->get_style_settings();
		$caption  = $this->prepare_caption_text( $article, $style );
		$fontSize = $this->calculate_font_size( $imagick->getImageWidth(), $style );
		$draw     = new ImagickDraw();
		$fontPath = $style['font_path'] ?? null;

		if ( $fontPath ) {
			$draw->setFont( $fontPath );
		}

		$draw->setFillColor( new ImagickPixel( '#ffffff' ) );
		$draw->setFontSize( $fontSize );
		$draw->setFontWeight( (int) ( $style['weight'] ?? 600 ) );
		$draw->setTextKerning( (float) ( $style['kerning'] ?? 0.8 ) );

		$maxWidth = $imagick->getImageWidth() - ( self::TEXT_MARGIN * 2 );
		$lines    = $this->wrap_text_lines( $caption, $fontSize, $fontPath, $maxWidth );
		$lineHeight = (int) ceil( $fontSize * ( $style['line_height'] ?? 1.3 ) );
		$startY   = $this->resolve_vertical_start(
			$this->get_text_vertical_position(),
			$imagick->getImageHeight(),
			$lineHeight,
			count( $lines )
		);

		foreach ( $lines as $line ) {
			$lineWidth = 0;

			if ( $fontPath ) {
				$metrics   = $imagick->queryFontMetrics( $draw, $line );
				$lineWidth = (int) ceil( $metrics['textWidth'] ?? 0 );
			}

			$x = $this->resolve_horizontal_start_for_imagick(
				$this->get_text_alignment(),
				$imagick->getImageWidth(),
				$lineWidth
			);

			$imagick->annotateImage( $draw, $x, $startY, 0, $line );
			$startY += $lineHeight;
		}
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function annotate_caption_gd( \GdImage $canvas, array $article ): void {
		$style       = $this->get_style_settings();
		$caption     = $this->prepare_caption_text( $article, $style );
		$fontPath    = $style['font_path'] ?? null;
		$fontSize    = $this->calculate_font_size( imagesx( $canvas ), $style );
		$lineHeight  = (int) ceil( $fontSize * ( $style['line_height'] ?? 1.3 ) );
		$textColor   = imagecolorallocate( $canvas, 255, 255, 255 );
		$maxWidth    = imagesx( $canvas ) - ( self::TEXT_MARGIN * 2 );
		$lines       = $this->wrap_text_lines( $caption, $fontSize, $fontPath, $maxWidth );
		$startY      = $this->resolve_vertical_start(
			$this->get_text_vertical_position(),
			imagesy( $canvas ),
			$lineHeight,
			count( $lines )
		);

		if ( $fontPath && function_exists( 'imagettftext' ) ) {
			foreach ( $lines as $line ) {
				$x = $this->resolve_horizontal_start_for_gd(
					$this->get_text_alignment(),
					imagesx( $canvas ),
					$line,
					$fontPath,
					$fontSize
				);

				imagettftext( $canvas, $fontSize, 0, $x, $startY, $textColor, $fontPath, $line );
				$startY += $lineHeight;
			}

			return;
		}

		// Fallback bez bibliotek TrueType
		$font      = 5;
		$lineHeight = imagefontheight( $font ) + 8;
		$startY    = $this->resolve_vertical_start(
			$this->get_text_vertical_position(),
			imagesy( $canvas ),
			$lineHeight,
			count( $lines )
		);

		foreach ( $lines as $line ) {
			$lineWidth = imagefontwidth( $font ) * mb_strlen( $line );
			$x         = $this->resolve_horizontal_start_from_width(
				$this->get_text_alignment(),
				imagesx( $canvas ),
				$lineWidth
			);

			imagestring( $canvas, $font, $x, $startY - imagefontheight( $font ), $line, $textColor );
			$startY += $lineHeight;
		}
	}

	private function prepare_caption_text( array $article, array $style ): string {
		$caption = trim( preg_replace( '/\s+/u', ' ', strip_tags( $this->build_caption( $article ) ) ) ?? '' );

		if ( ! empty( $style['uppercase'] ) ) {
			return mb_strtoupper( $caption );
		}

		return $caption;
	}

	private function wrap_text_lines( string $text, int $fontSize, ?string $fontPath, int $maxWidth ): array {
		$text    = trim( $text );
		$maxWidth = max( 200, $maxWidth );

		if ( '' === $text ) {
			return array();
		}

		$words = preg_split( '/\s+/u', $text ) ?: array( $text );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			$test = trim( $line . ' ' . $word );

			if ( '' === $line ) {
				$line = $word;
				continue;
			}

			if ( $fontPath && function_exists( 'imagettfbbox' ) ) {
				$box   = imagettfbbox( $fontSize, 0, $fontPath, $test );
				$width = (int) abs( $box[2] - $box[0] );

				if ( $width > $maxWidth ) {
					$lines[] = $line;
					$line    = $word;
					continue;
				}
			} elseif ( mb_strlen( $test ) * ( $fontSize / 1.8 ) > $maxWidth ) {
				$lines[] = $line;
				$line    = $word;
				continue;
			}

			$line = $test;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	private function calculate_font_size( int $canvasWidth, array $style ): int {
		$ratio = (float) ( $style['size_ratio'] ?? 18 );
		$size  = (int) max( 28, $canvasWidth / $ratio );

		return min( 120, $size );
	}

	private function resolve_vertical_start( string $position, int $canvasHeight, int $lineHeight, int $lineCount ): int {
		$total = $lineHeight * max( 1, $lineCount );

		switch ( $position ) {
			case 'top':
				return self::TEXT_MARGIN + $lineHeight;
			case 'middle':
				return (int) max(
					self::TEXT_MARGIN + $lineHeight,
					( ( $canvasHeight - $total ) / 2 ) + $lineHeight
				);
			case 'bottom':
			default:
				return max(
					self::TEXT_MARGIN + $lineHeight,
					$canvasHeight - self::TEXT_MARGIN - $total + $lineHeight
				);
		}
	}

	private function resolve_horizontal_start_for_imagick( string $alignment, int $canvasWidth, int $lineWidth ): int {
		return $this->resolve_horizontal_start_from_width( $alignment, $canvasWidth, $lineWidth );
	}

	private function resolve_horizontal_start_for_gd( string $alignment, int $canvasWidth, string $line, string $fontPath, int $fontSize ): int {
		if ( function_exists( 'imagettfbbox' ) ) {
			$box       = imagettfbbox( $fontSize, 0, $fontPath, $line );
			$lineWidth = (int) abs( $box[2] - $box[0] );

			return $this->resolve_horizontal_start_from_width( $alignment, $canvasWidth, $lineWidth );
		}

		$approxWidth = (int) ( mb_strlen( $line ) * ( $fontSize / 1.8 ) );

		return $this->resolve_horizontal_start_from_width( $alignment, $canvasWidth, $approxWidth );
	}

	private function resolve_horizontal_start_from_width( string $alignment, int $canvasWidth, int $lineWidth ): int {
		switch ( $alignment ) {
			case 'right':
				return max( self::TEXT_MARGIN, $canvasWidth - self::TEXT_MARGIN - $lineWidth );
			case 'center':
				return (int) max(
					self::TEXT_MARGIN,
					( $canvasWidth - $lineWidth ) / 2
				);
			case 'left':
			default:
				return self::TEXT_MARGIN;
		}
	}

	/**
	 * @return array{width:int,height:int}
	 */
	private function get_canvas_dimensions(): array {
		$width  = (int) Options::get( 'image_canvas_width', 1200 );
		$height = (int) Options::get( 'image_canvas_height', 675 );

		return array(
			'width'  => max( 640, min( 4000, $width ) ),
			'height' => max( 360, min( 4000, $height ) ),
		);
	}

	private function get_overlay_color(): string {
		$color = (string) Options::get( 'image_overlay_color', '1b1f3b' );

		return '' === $color ? '1b1f3b' : $color;
	}

	private function get_overlay_opacity(): float {
		$value = (int) Options::get( 'image_overlay_opacity', 75 );

		return max( 0.0, min( 1.0, $value / 100 ) );
	}

	private function opacity_to_alpha( float $opacity ): int {
		return (int) max( 0, min( 127, 127 - round( $opacity * 127 ) ) );
	}

	private function should_render_caption(): bool {
		return (bool) Options::get( 'image_text_enabled', true );
	}

	private function get_text_alignment(): string {
		$alignment = (string) Options::get( 'image_text_alignment', 'center' );

		return in_array( $alignment, array( 'left', 'center', 'right' ), true ) ? $alignment : 'center';
	}

	private function get_text_vertical_position(): string {
		$position = (string) Options::get( 'image_text_vertical', 'middle' );

		return in_array( $position, array( 'top', 'middle', 'bottom' ), true ) ? $position : 'middle';
	}

	private function get_style_settings(): array {
		$key    = (string) Options::get( 'image_style', self::DEFAULT_STYLE );
		$preset = self::STYLE_PRESETS[ $key ] ?? self::STYLE_PRESETS[ self::DEFAULT_STYLE ];

		$preset['font_path'] = $this->resolve_font_path( $preset );

		return $preset;
	}

	private function resolve_font_path( array $preset ): ?string {
		$candidates = array();
		$base_dir   = $this->font_base_directory();

		foreach ( (array) ( $preset['font_candidates'] ?? array() ) as $font ) {
			$candidates[] = $base_dir . $font;
		}

		foreach ( (array) ( $preset['system_fonts'] ?? array() ) as $font ) {
			$candidates[] = $font;
		}

		$candidates[] = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

		foreach ( $candidates as $path ) {
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {
				return wp_normalize_path( $path );
			}
		}

		return null;
	}

	private function font_base_directory(): string {
		$base = defined( 'KASUMI_AI_PATH' )
			? KASUMI_AI_PATH
			: dirname( __DIR__, 2 ) . '/';

		return wp_normalize_path( trailingslashit( $base ) . 'assets/fonts/' );
	}

	private function paint_gradient_background( \GdImage $canvas ): void {
		$width  = imagesx( $canvas );
		$height = imagesy( $canvas );
		$base   = $this->hex_to_rgb( $this->get_overlay_color() );
		$start  = $base;
		$end    = $this->hex_to_rgb( $this->adjust_hex_brightness( $this->get_overlay_color(), 18 ) );

		for ( $y = 0; $y < $height; $y++ ) {
			$ratio = $height > 0 ? $y / $height : 0;
			$color = imagecolorallocate(
				$canvas,
				(int) ( $start['r'] + ( $end['r'] - $start['r'] ) * $ratio ),
				(int) ( $start['g'] + ( $end['g'] - $start['g'] ) * $ratio ),
				(int) ( $start['b'] + ( $end['b'] - $start['b'] ) * $ratio )
			);
			imageline( $canvas, 0, $y, $width, $y, $color );
		}
	}

	private function adjust_hex_brightness( string $hex, int $percent ): string {
		$rgb     = $this->hex_to_rgb( $hex );
		$percent = max( -100, min( 100, $percent ) );

		$adjusted = array_map(
			static function( int $component ) use ( $percent ): int {
				$result = $component + ( $component * $percent / 100 );

				return (int) max( 0, min( 255, round( $result ) ) );
			},
			$rgb
		);

		return sprintf( '%02x%02x%02x', $adjusted['r'], $adjusted['g'], $adjusted['b'] );
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function build_alt_text( array $article ): string {
		$title   = wp_strip_all_tags( (string) ( $article['title'] ?? '' ) );
		$summary = wp_trim_words(
			wp_strip_all_tags( (string) ( $article['summary'] ?? $article['excerpt'] ?? '' ) ),
			16
		);

		if ( '' === $title ) {
			return __( 'Grafika wyróżniająca Kasumi AI', 'kasumi-full-ai-content-generator' );
		}

		if ( '' === $summary ) {
			return sprintf(
				/* translators: %s is the post title. */
				__( '%s – grafika wyróżniająca Kasumi AI', 'kasumi-full-ai-content-generator' ),
				$title
			);
		}

		return sprintf(
			/* translators: 1: post title, 2: article summary. */
			__( '%1$s – grafika do artykułu o %2$s', 'kasumi-full-ai-content-generator' ),
			$title,
			lcfirst( $summary )
		);
	}

	/**
	 * @param array<string, mixed> $article
	 */
	private function build_caption( array $article ): string {
		$template = (string) Options::get( 'image_template', 'Kasumi AI – {{title}}' );

		return strtr(
			$template,
			array(
				'{{title}}'   => (string) ( $article['title'] ?? '' ),
				'{{summary}}' => (string) ( $article['summary'] ?? $article['excerpt'] ?? '' ),
			)
		);
	}

	/**
	 * @return array{r:int,g:int,b:int}
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$int = hexdec( $hex );

		return array(
			'r' => ( $int >> 16 ) & 255,
			'g' => ( $int >> 8 ) & 255,
			'b' => $int & 255,
		);
	}

	/**
	 * Generuje prostszą grafikę fallback gdy brak Pixabay.
	 * 
	 * @param array<string, mixed> $article
	 * @param string $engine 'imagick' lub 'gd'
	 */
	private function generate_simple_fallback_image( array $article, string $engine ): ?string {
		$dimensions = $this->get_canvas_dimensions();
		$width      = $dimensions['width'];
		$height     = $dimensions['height'];

		return 'gd' === $engine
			? $this->generate_simple_gd_image( $article, $width, $height )
			: $this->generate_simple_imagick_image( $article, $width, $height );
	}

	/**
	 * Generuje prostszą grafikę używając GD.
	 * 
	 * @param array<string, mixed> $article
	 */
	private function generate_simple_gd_image( array $article, int $width, int $height ): ?string {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return null;
		}

		imagealphablending( $canvas, true );
		$this->paint_gradient_background( $canvas );
		$this->apply_overlay_gd( $canvas, $this->get_overlay_color(), $this->get_overlay_opacity() );

		if ( $this->should_render_caption() ) {
			$this->annotate_caption_gd( $canvas, $article );
		}

		return $this->export_gd_image( $canvas );
	}

	/**
	 * Generuje prostszą grafikę używając Imagick.
	 * 
	 * @param array<string, mixed> $article
	 */
	private function generate_simple_imagick_image( array $article, int $width, int $height ): ?string {
		try {
			$imagick = new Imagick();
			$imagick->newImage( $width, $height, new ImagickPixel( '#1b1f3b' ) );
			$imagick->setImageFormat( 'webp' );

			$primary   = '#' . $this->get_overlay_color();
			$secondary = '#' . $this->adjust_hex_brightness( $this->get_overlay_color(), 15 );
			$gradient  = new Imagick();
			$gradient->newPseudoImage( $width, $height, "gradient:{$primary}-{$secondary}" );
			$imagick->compositeImage( $gradient, Imagick::COMPOSITE_OVER, 0, 0 );

			$this->apply_overlay( $imagick, $this->get_overlay_color(), $this->get_overlay_opacity() );

			if ( $this->should_render_caption() ) {
				$this->annotate_caption_imagick( $imagick, $article );
			}

			return $imagick->getImageBlob();
		} catch ( \Throwable $throwable ) {
			$this->logger->warning(
				'Nie udało się wygenerować uproszczonej grafiki (Imagick), próba z GD.',
				array( 'exception' => $throwable->getMessage() )
			);
			
			// Fallback na GD jeśli Imagick nie działa
			if ( extension_loaded( 'gd' ) ) {
				return $this->generate_simple_gd_image( $article, $width, $height );
			}
		}

		return null;
	}
}
