<?php
/**
 * Generic website course source.
 *
 * Scrapes course listings from a generic HTML page. This is a basic
 * implementation that can be extended for specific websites.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Website_Source extends Conexao_Course_Source_Base {

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return isset( $this->config['id'] ) ? $this->config['id'] : 'website';
	}

	/**
	 * Fetch raw course listings from the source website.
	 *
	 * This generic implementation looks for common course listing patterns.
	 * For specific websites, extend this class and override fetch_courses().
	 *
	 * @return array[]
	 */
	public function fetch_courses() {
		$url = isset( $this->config['url'] ) ? $this->config['url'] : '';
		if ( empty( $url ) ) {
			return array();
		}

		$html = $this->fetch_html( $url );
		if ( empty( $html ) ) {
			return array();
		}

		$dom = $this->parse_html( $html );
		if ( ! $dom ) {
			return array();
		}

		$courses = array();
		$xpath   = new DOMXPath( $dom );

		// Try common course listing selectors.
		$selectors = array(
			'//article[contains(@class, "course")]',
			'//div[contains(@class, "course-card")]',
			'//div[contains(@class, "course-item")]',
			'//div[contains(@class, "training")]',
			'//li[contains(@class, "course")]',
			'//div[contains(@class, "event-card")]',
		);

		$nodes = array();
		foreach ( $selectors as $selector ) {
			$found = $xpath->query( $selector );
			if ( $found && $found->length > 0 ) {
				foreach ( $found as $node ) {
					$nodes[] = $node;
				}
				break;
			}
		}

		// Fallback: look for links that look like course pages.
		if ( empty( $nodes ) ) {
			$links = $xpath->query( '//a[contains(@href, "course") or contains(@href, "training") or contains(@href, "event")]' );
			if ( $links && $links->length > 0 ) {
				$seen_urls = array();
				foreach ( $links as $link ) {
					$href = $link->getAttribute( 'href' );
					$href = $this->resolve_url( $href, $url );
					if ( empty( $href ) || isset( $seen_urls[ $href ] ) ) {
						continue;
					}
					$seen_urls[ $href ] = true;

					$title = trim( $link->textContent );
					if ( strlen( $title ) < 5 ) {
						continue;
					}

					$courses[] = array(
						'title'       => $title,
						'url'         => $href,
						'description' => '',
						'source_id'   => md5( $href ),
					);
				}
			}
			return $courses;
		}

		foreach ( $nodes as $node ) {
			$course = $this->parse_course_node( $node, $url, $xpath );
			if ( ! empty( $course['title'] ) && ! empty( $course['url'] ) ) {
				$courses[] = $course;
			}
		}

		return $courses;
	}

	/**
	 * Parse a single course node from the DOM.
	 *
	 * @param DOMElement $node  The course node.
	 * @param string     $base  Base URL for resolving relative URLs.
	 * @param DOMXPath   $xpath XPath instance.
	 * @return array
	 */
	protected function parse_course_node( $node, $base, $xpath ) {
		$course = array(
			'title'       => '',
			'url'         => '',
			'description' => '',
			'image'       => '',
			'location'    => '',
			'start_date'  => '',
			'source_id'   => '',
			'price'       => '',
			'organizer'   => '',
			'category'    => '',
		);

		// Title: look for heading elements or links.
		$headings = $xpath->query( './/h2|.//h3|.//h4', $node );
		if ( $headings && $headings->length > 0 ) {
			$course['title'] = trim( $headings->item( 0 )->textContent );
		}

		// URL: look for the first link.
		$links = $xpath->query( './/a[@href]', $node );
		if ( $links && $links->length > 0 ) {
			$href = $links->item( 0 )->getAttribute( 'href' );
			$course['url'] = $this->resolve_url( $href, $base );

			// If no title found yet, use the link text.
			if ( empty( $course['title'] ) ) {
				$course['title'] = trim( $links->item( 0 )->textContent );
			}
		}

		// Description: look for paragraph or description elements.
		$desc_nodes = $xpath->query( './/p[contains(@class, "desc")]|.//p[contains(@class, "summary")]|.//div[contains(@class, "description")]', $node );
		if ( $desc_nodes && $desc_nodes->length > 0 ) {
			$course['description'] = trim( $desc_nodes->item( 0 )->textContent );
		}

		// Image: look for img elements.
		$images = $xpath->query( './/img[@src]', $node );
		if ( $images && $images->length > 0 ) {
			$src = $images->item( 0 )->getAttribute( 'src' );
			$course['image'] = $this->resolve_url( $src, $base );
		}

		// Generate a source ID from the URL.
		if ( ! empty( $course['url'] ) ) {
			$course['source_id'] = md5( $course['url'] );
		}

		// Apply source-level defaults from config.
		if ( ! empty( $this->config['category'] ) ) {
			$course['category'] = $this->config['category'];
		}
		if ( ! empty( $this->config['county'] ) ) {
			$course['location'] = $this->config['county'];
		}

		return $course;
	}
}