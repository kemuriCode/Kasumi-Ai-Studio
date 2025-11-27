<?php

declare(strict_types=1);

namespace Kasumi\AIGenerator\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

use function esc_attr;
use function esc_html;
use function esc_url;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_replace;
use function sprintf;
use function str_replace;
use function trim;
use function wp_json_encode;
use function wp_kses_post;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_HTML_NODEFDTD;
use const XML_TEXT_NODE;

/**
 * Buduje serializowane bloki Gutenberg na podstawie Markdown.
 */
class BlockContentBuilder {
	public function __construct( private MarkdownConverter $markdown_converter ) {}

	/**
	 * Konwertuje Markdown na łańcuch bloków Gutenberg.
	 */
	public function build_blocks( string $markdown ): string {
		$html = $this->markdown_converter->to_block_ready_html( $markdown );

		if ( '' === trim( $html ) ) {
			return '';
		}

		$document = $this->create_dom_document( $html );

		if ( ! $document instanceof DOMDocument ) {
			return '';
		}

		$root = $document->documentElement;

		if ( ! $root instanceof DOMElement ) {
			return '';
		}

		$chunks = array();

		foreach ( $root->childNodes as $node ) {
			$block = $this->convert_node_to_block( $node );

			if ( null !== $block ) {
				$chunks[] = $block;
			}
		}

		return trim( implode( "\n", $chunks ) );
	}

	private function create_dom_document( string $html ): ?DOMDocument {
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $document->loadHTML(
			'<?xml encoding="utf-8" ?><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		return $loaded ? $document : null;
	}

	private function convert_node_to_block( DOMNode $node ): ?string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( (string) $node->textContent );

			return '' === $text ? null : $this->paragraph_block( '<p>' . esc_html( $text ) . '</p>' );
		}

		if ( ! $node instanceof DOMElement ) {
			return null;
		}

		$tag = strtolower( $node->tagName );

		return match ( $tag ) {
			'p'       => $this->paragraph_block( $this->node_html( $node ) ),
			'h1','h2','h3','h4','h5','h6' => $this->heading_block( $node ),
			'ul'      => $this->list_block( $node, false ),
			'ol'      => $this->list_block( $node, true ),
			'blockquote' => $this->quote_block( $node ),
			'img'     => $this->image_block( $node ),
			'figure'  => $this->figure_block( $node ),
			'pre','code' => $this->code_block( $node ),
			'hr'      => $this->separator_block(),
			default   => $this->html_block( $this->node_html( $node ) ),
		};
	}

	private function paragraph_block( string $html ): string {
		return $this->wrap_block( 'core/paragraph', null, $html );
	}

	private function heading_block( DOMElement $node ): string {
		$level = (int) preg_replace( '/\D/', '', $node->tagName );
		$level = max( 1, min( 6, $level ) );
		$attrs = array( 'level' => $level );

		return $this->wrap_block(
			'core/heading',
			$attrs,
			sprintf( '<h%d>%s</h%d>', $level, $this->inner_html( $node ), $level )
		);
	}

	private function list_block( DOMElement $node, bool $ordered ): string {
		$html  = $ordered ? '<ol>' : '<ul>';
		$html .= $this->inner_html( $node );
		$html .= $ordered ? '</ol>' : '</ul>';

		$attrs = $ordered ? array( 'ordered' => true ) : null;

		return $this->wrap_block( 'core/list', $attrs, $html );
	}

	private function quote_block( DOMElement $node ): string {
		$content = '<blockquote>' . $this->inner_html( $node ) . '</blockquote>';

		return $this->wrap_block( 'core/quote', null, $content );
	}

	private function image_block( DOMElement $node ): ?string {
		$src = trim( $node->getAttribute( 'src' ) );

		if ( '' === $src ) {
			return null;
		}

		$alt = $node->getAttribute( 'alt' );

		$figure = sprintf(
			'<figure class="wp-block-image"><img src="%s" alt="%s" /></figure>',
			esc_url( $src ),
			esc_attr( $alt )
		);

		return $this->wrap_block( 'core/image', array( 'url' => esc_url( $src ), 'alt' => $alt ), $figure );
	}

	private function figure_block( DOMElement $node ): ?string {
		$images = $node->getElementsByTagName( 'img' );

		if ( 0 === $images->length ) {
			return $this->html_block( $this->node_html( $node ) );
		}

		return $this->image_block( $images->item( 0 ) );
	}

	private function code_block( DOMElement $node ): string {
		$content = '<pre><code>' . esc_html( $node->textContent ?? '' ) . '</code></pre>';

		return $this->wrap_block( 'core/code', null, $content );
	}

	private function separator_block(): string {
		return $this->wrap_block( 'core/separator', null, '<hr />' );
	}

	private function html_block( string $html ): string {
		return $this->wrap_block( 'core/html', null, $html );
	}

	private function wrap_block( string $name, ?array $attrs, string $content ): string {
		$attr_string = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';

		return sprintf(
			"<!-- wp:%s%s -->\n%s\n<!-- /wp:%s -->",
			str_replace( 'core/', '', $name ),
			$attr_string,
			wp_kses_post( trim( $content ) ),
			str_replace( 'core/', '', $name )
		);
	}

	private function node_html( DOMNode $node ): string {
		$document = $node->ownerDocument;

		if ( ! $document instanceof DOMDocument ) {
			return '';
		}

		return trim( (string) $document->saveHTML( $node ) );
	}

	private function inner_html( DOMElement $element ): string {
		$html = '';

		foreach ( $element->childNodes as $child ) {
			$document = $child->ownerDocument;

			if ( $document instanceof DOMDocument ) {
				$html .= (string) $document->saveHTML( $child );
			}
		}

		return trim( $html );
	}
}


