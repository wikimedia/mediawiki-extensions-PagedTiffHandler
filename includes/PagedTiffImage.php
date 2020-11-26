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

use MediaWiki\Shell\Shell;

/**
 * inspired by djvuimage from Brion Vibber
 * modified and written by xarax
 * adapted to tiff by Hallo Welt! - Medienwerkstatt GmbH
 */

class PagedTiffImage {
	/** @var array|null */
	protected $metadata = null;
	/** @var string */
	protected $mFilename;

	/**
	 * @param string $filename
	 */
	public function __construct( $filename ) {
		$this->mFilename = $filename;
	}

	/**
	 * Called by MimeMagick functions.
	 * @return int
	 */
	public function isValid() {
		return count( $this->retrieveMetaData() );
	}

	/**
	 * Returns an array that corresponds to the native PHP function getimagesize().
	 * @return array|false
	 */
	public function getImageSize() {
		$data = $this->retrieveMetaData();
		$size = $this->getPageSize( $data, 1 );

		if ( $size ) {
			$width = $size['width'];
			$height = $size['height'];
			return [ $width, $height, 'Tiff',
			"width=\"$width\" height=\"$height\"" ];
		}
		return false;
	}

	/**
	 * Returns an array with width and height of the tiff page.
	 * @param array $data
	 * @param int $page
	 * @return array|false
	 */
	public static function getPageSize( $data, $page ) {
		if ( isset( $data['page_data'][$page] ) ) {
			return [
				'width'  => intval( $data['page_data'][$page]['width'] ),
				'height' => intval( $data['page_data'][$page]['height'] )
			];
		}
		return false;
	}

	public function resetMetaData() {
		$this->metadata = null;
	}

	/**
	 * Reads metadata of the tiff file via shell command and returns an associative array.
	 * @return array Associative array. Layout:
	 * meta['page_count'] = number of pages
	 * meta['first_page'] = number of first page
	 * meta['last_page'] = number of last page
	 * meta['page_data'] = metadata per page
	 * meta['exif']  = Exif, XMP and IPTC
	 * meta['errors'] = identify-errors
	 * meta['warnings'] = identify-warnings
	 */
	public function retrieveMetaData() {
		global $wgImageMagickIdentifyCommand, $wgExiv2Command, $wgTiffUseExiv;
		global $wgTiffUseTiffinfo, $wgTiffTiffinfoCommand;
		global $wgShowEXIF;

		if ( $this->metadata === null ) {
			// fetch base info: number of pages, size and alpha for each page.
			// run hooks first, then optionally tiffinfo or, per default,
			// ImageMagic's identify command
			if (
				!Hooks::run( 'PagedTiffHandlerTiffData', [ $this->mFilename, &$this->metadata ] )
			) {
				wfDebug(
					__METHOD__ . ": hook PagedTiffHandlerTiffData overrides TIFF data extraction\n"
				);
			} elseif ( $wgTiffUseTiffinfo ) {
				// read TIFF directories using libtiff's tiffinfo, see
				// http://www.libtiff.org/man/tiffinfo.1.html
				$cmd = Shell::escape( $wgTiffTiffinfoCommand ) .
					' ' . Shell::escape( $this->mFilename ) . ' 2>&1';

				wfDebug( __METHOD__ . ": $cmd\n" );
				$retval = '';
				$dump = wfShellExec( $cmd, $retval );

				if ( $retval ) {
					$data = [ 'errors' => [ "tiffinfo command failed: $cmd" ] ];
					wfDebug( __METHOD__ . ": tiffinfo command failed: $cmd\n" );
					return $data; // fail. we *need* that info
				}

				$this->metadata = $this->parseTiffinfoOutput( $dump );
			} else {
				$cmd = Shell::escape( $wgImageMagickIdentifyCommand ) .
					' -format ' .
					'"[BEGIN]page=%p\nalpha=%A\nalpha2=%r\nheight=%h\nwidth=%w\ndepth=%z[END]" ' .
					Shell::escape( $this->mFilename ) . ' 2>&1';

				wfDebug( __METHOD__ . ": $cmd\n" );
				$retval = '';
				$dump = wfShellExec( $cmd, $retval );

				if ( $retval ) {
					$data = [ 'errors' => [ "identify command failed: $cmd" ] ];
					wfDebug( __METHOD__ . ": identify command failed: $cmd\n" );
					return $data; // fail. we *need* that info
				}

				$this->metadata = $this->parseIdentifyOutput( $dump );
			}

			$this->metadata['exif'] = [];

			// fetch extended info: EXIF/IPTC/XMP
			// run hooks first, then optionally Exiv2 or, per default, the internal EXIF class
			if ( !empty( $this->metadata['errors'] ) ) {
				wfDebug( __METHOD__ . ": found errors, skipping EXIF extraction\n" );
			} elseif (
				!Hooks::run( 'PagedTiffHandlerExifData',
					[ $this->mFilename, &$this->metadata['exif'] ] )
			) {
				wfDebug(
					__METHOD__ . ": hook PagedTiffHandlerExifData overrides EXIF extraction\n"
				);
			} elseif ( $wgTiffUseExiv ) {
				// read EXIF, XMP, IPTC as name-tag => interpreted data
				// -ignore unknown fields
				// see exiv2-doc @link http://www.exiv2.org/sample.html
				// NOTE: the linux version of exiv2 has a bug: it can only
				// read one type of meta-data at a time, not all at once.
				$cmd = Shell::escape( $wgExiv2Command ) .
					' -u -psix -Pnt ' . Shell::escape( $this->mFilename ) . ' 2>&1';

				wfDebug( __METHOD__ . ": $cmd\n" );
				$retval = '';
				$dump = wfShellExec( $cmd, $retval );

				if ( $retval ) {
					$data = [ 'errors' => [ "exiv command failed: $cmd" ] ];
					wfDebug( __METHOD__ . ": exiv command failed: $cmd\n" );
					// don't fail - we are missing info, just report
				}

				$data = $this->parseExiv2Output( $dump );

				$this->metadata['exif'] = $data;
			} elseif ( $wgShowEXIF ) {
				wfDebug( __METHOD__ . ": using internal Exif( {$this->mFilename} )\n" );
				if ( method_exists( 'BitmapMetadataHandler', 'Tiff' ) ) {
					$data = BitmapMetadataHandler::Tiff( $this->mFilename );
				} else {
					// old method for back compat.
					$exif = new Exif( $this->mFilename );
					$data = $exif->getFilteredData();
				}

				if ( $data ) {
					$data['MEDIAWIKI_EXIF_VERSION'] = Exif::version();
					$this->metadata['exif'] = $data;
				}
			}

			unset( $this->metadata['exif']['Image'] );
			unset( $this->metadata['exif']['filename'] );
			unset( $this->metadata['exif']['Base filename'] );
			unset( $this->metadata['exif']['XMLPacket'] );
			unset( $this->metadata['exif']['ImageResources'] );

			$this->metadata['TIFF_METADATA_VERSION'] = PagedTiffHandler::TIFF_METADATA_VERSION;
		}

		return $this->metadata;
	}

	/**
	 * helper function of retrieveMetaData().
	 * parses shell return from tiffinfo-command into an array.
	 * @param string $dump
	 * @return array
	 */
	protected function parseTiffinfoOutput( $dump ) {
		global $wgTiffTiffinfoRejectMessages, $wgTiffTiffinfoBypassMessages;

		# HACK: width and length are given on a single line...
		$dump = preg_replace( '/ Image Length:/', "\n  Image Length:", $dump );
		$rows = preg_split( '/[\r\n]+\s*/', $dump );

		$state = new PagedTiffInfoParserState();

		$ignoreIFDs = [];
		$ignore = false;

		foreach ( $rows as $row ) {
			$row = trim( $row );

			# ignore XML rows
			if ( preg_match( '/^<|^$/', $row ) ) {
				continue;
			}

			$error = false;

			# handle fatal errors
			foreach ( $wgTiffTiffinfoRejectMessages as $pattern ) {
				if ( preg_match( $pattern, trim( $row ) ) ) {
					$state->addError( $row );
					$error = true;
					break;
				}
			}

			if ( $error ) {
				continue;
			}

			$m = [];

			if ( preg_match( '/^TIFF Directory at offset 0x[a-f0-9]+ \((\d+)\)/', $row, $m ) ) {
				# new IFD starting, flush previous page

				if ( $ignore ) {
					$state->resetPage();
				} else {
					$ok = $state->finishPage();

					if ( !$ok ) {
						$error = true;
						continue;
					}
				}

				# check if the next IFD is to be ignored
				$offset = (int)$m[1];
				$ignore = !empty( $ignoreIFDs[ $offset ] );
			} elseif ( preg_match( '#^(TIFF.*?Directory): (.*?/.*?): (.*)#i', $row, $m ) ) {
				# handle warnings

				$bypass = false;
				$msg = $m[3];

				foreach ( $wgTiffTiffinfoBypassMessages as $pattern ) {
					if ( preg_match( $pattern, trim( $row ) ) ) {
						$bypass = true;
						break;
					}
				}

				if ( !$bypass ) {
					$state->addWarning( $msg );
				}
			} elseif ( preg_match( '/^\s*(.*?)\s*:\s*(.*?)\s*$/', $row, $m ) ) {
				# handle key/value pair

				list( , $key, $value ) = $m;

				if ( $key == 'Page Number' && preg_match( '/(\d+)-(\d+)/', $value, $m ) ) {
					$state->setPageProperty( 'page', (int)$m[1] + 1 );
				} elseif ( $key == 'Samples/Pixel' ) {
					if ( $value == '4' ) {
						$state->setPageProperty( 'alpha', 'true' );
					}
				} elseif ( $key == 'Extra samples' ) {
					if ( preg_match( '/.*alpha.*/', $value ) ) {
						$state->setPageProperty( 'alpha', 'true' );
					}
				} elseif ( $key == 'Image Width' || $key == 'PixelXDimension' ) {
					$state->setPageProperty( 'width', (int)$value );
				} elseif ( $key == 'Image Length' || $key == 'PixelYDimension' ) {
					$state->setPageProperty( 'height', (int)$value );
				} elseif ( preg_match( '/.*IFDOffset/', $key ) ) {
					# ignore extra IFDs,
					# see <http://www.awaresystems.be/imaging/tiff/tifftags/exififd.html>
					# Note: we assume that we will always see the reference before the actual IFD,
					# so we know which IFDs to ignore
					// Offset is usually in hex
					if ( preg_match( '/^0x[0-9A-Fa-f]+$/', $value ) ) {
						$value = hexdec( substr( $value, 2 ) );
					}
					$offset = (int)$value;
					$ignoreIFDs[$offset] = true;
				}
			} else {
				// strange line
			}

		}

		$state->finish( !$ignore );

		$data = $state->getMetadata();
		return $data;
	}

	/**
	 * helper function of retrieveMetaData().
	 * parses shell return from exiv2-command into an array.
	 * @param string $dump
	 * @return array
	 */
	protected function parseExiv2Output( $dump ) {
		$result = [];
		preg_match_all( '/^(\w+)\s+(.+)$/m', $dump, $result, PREG_SET_ORDER );

		$data = [];

		foreach ( $result as $row ) {
			$data[$row[1]] = $row[2];
		}

		return $data;
	}

	/**
	 * helper function of retrieveMetaData().
	 * parses shell return from identify-command into an array.
	 * @param string $dump
	 * @return array
	 */
	protected function parseIdentifyOutput( $dump ) {
		global $wgTiffIdentifyRejectMessages, $wgTiffIdentifyBypassMessages;

		$state = new PagedTiffInfoParserState();

		if ( strval( $dump ) == '' ) {
			$state->addError( "no metadata" );
			return $state->getMetadata();
		}

		$infos = null;
		preg_match_all( '/\[BEGIN\](.+?)\[END\]/si', $dump, $infos, PREG_SET_ORDER );
		// ImageMagick < 6.6.8-10 starts page numbering at 1; >= 6.6.8-10 starts at zero.
		// Handle both and map to one-based page numbers (which are assumed in various other parts
		// of the support for displaying multi-page files).
		$pageSeen = false;
		$pageOffset = 0;
		foreach ( $infos as $info ) {
			$state->resetPage();
			$lines = explode( "\n", $info[1] );
			foreach ( $lines as $line ) {
				if ( trim( $line ) == '' ) {
					continue;
				}
				list( $key, $value ) = explode( '=', $line );
				$key = trim( $key );
				$value = trim( $value );
				if ( $key === 'alpha' && $value === '%A' ) {
					continue;
				}
				if ( $key === 'alpha2' && !$state->hasPageProperty( 'alpha' ) ) {
					switch ( $value ) {
						case 'DirectClassRGBMatte':
						case 'DirectClassRGBA':
							$state->setPageProperty( 'alpha', 'true' );
							break;
						default:
							$state->setPageProperty( 'alpha', 'false' );
							break;
					}
					continue;
				}
				if ( $key === 'page' ) {
					if ( !$pageSeen ) {
						$pageSeen = true;
						$pageOffset = 1 - intval( $value );
					}
					if ( $pageOffset !== 0 ) {
						$value = intval( $value ) + $pageOffset;
					}
				}
				$state->setPageProperty( $key, $value );
			}
			$state->finishPage();
		}

		$dump = preg_replace( '/\[BEGIN\](.+?)\[END\]/si', '', $dump );
		if ( strlen( $dump ) ) {
			$errors = explode( "\n", $dump );
			foreach ( $errors as $error ) {
				$error = trim( $error );
				if ( $error === '' ) {
					continue;
				}

				$knownError = false;
				foreach ( $wgTiffIdentifyRejectMessages as $msg ) {
					if ( preg_match( $msg, trim( $error ) ) ) {
						$state->addError( $error );
						$knownError = true;
						break;
					}
				}
				if ( !$knownError ) {
					// ignore messages that match $wgTiffIdentifyBypassMessages
					foreach ( $wgTiffIdentifyBypassMessages as $msg ) {
						if ( preg_match( $msg, trim( $error ) ) ) {
							$knownError = true;
							break;
						}
					}
				}
				if ( !$knownError ) {
					$state->addWarning( $error );
				}
			}
		}

		$state->finish();

		$data = $state->getMetadata();
		return $data;
	}
}
