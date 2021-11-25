<?php
/**
 * Copyright © Wikimedia Deutschland, 2009
 * Authors Hallo Welt! Medienwerkstatt GmbH
 * Authors Sebastian Ulbricht, Daniel Lynge, Marc Reymann, Markus Glaser
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

namespace MediaWiki\Extension\PagedTiffHandler;

class PagedTiffInfoParserState {
	/** @var array All data */
	public $metadata;

	/** @var array Current page */
	public $page;

	/** @var int */
	public $prevPage;

	public function __construct() {
		$this->metadata = [];
		$this->page = [];
		$this->prevPage = 0;

		$this->metadata['page_data'] = [];
	}

	public function finish( $finishPage = true ) {
		if ( $finishPage ) {
			$this->finishPage();
		}

		if ( !$this->metadata['page_data'] ) {
			$this->metadata['errors'][] = 'no page data found in tiff directory!';
			return;
		}

		if ( !isset( $this->metadata['page_count'] ) ) {
			$this->metadata['page_count'] = count( $this->metadata['page_data'] );
		}

		if ( !isset( $this->metadata['first_page'] ) ) {
			$this->metadata['first_page'] = min( array_keys( $this->metadata['page_data'] ) );
		}

		if ( !isset( $this->metadata['last_page'] ) ) {
			$this->metadata['last_page'] = max( array_keys( $this->metadata['page_data'] ) );
		}
	}

	public function resetPage() {
		$this->page = [];
	}

	public function finishPage() {
		if ( !isset( $this->page['page'] ) ) {
			$this->page['page'] = $this->prevPage + 1;
		} else {
			if ( $this->prevPage >= $this->page['page'] ) {
				$this->metadata['errors'][] = "inconsistent page numbering in TIFF directory";
				return false;
			}
		}

		if ( isset( $this->page['width'] ) && isset( $this->page['height'] ) ) {
			$this->prevPage = max( $this->prevPage, $this->page['page'] );

			if ( !isset( $this->page['alpha'] ) ) {
				$this->page['alpha'] = 'false';
			}

			$this->page['pixels'] = $this->page['height'] * $this->page['width'];
			$this->metadata['page_data'][$this->page['page']] = $this->page;
		}

		$this->page = [];
		return true;
	}

	public function setPageProperty( $key, $value ) {
		$this->page[$key] = $value;
	}

	public function hasPageProperty( $key ) {
		return isset( $this->page[$key] ) && $this->page[$key] !== null;
	}

	public function setFileProperty( $key, $value ) {
		$this->metadata[$key] = $value;
	}

	public function hasFileProperty( $key, $value ) {
		return isset( $this->metadata[$key] ) && $this->metadata[$key] !== null;
	}

	public function addError( $message ) {
		$this->metadata['errors'][] = $message;
	}

	public function addWarning( $message ) {
		$this->metadata['warnings'][] = $message;
	}

	public function getMetadata() {
		return $this->metadata;
	}

	public function hasErrors() {
		return !empty( $this->metadata['errors'] );
	}
}
