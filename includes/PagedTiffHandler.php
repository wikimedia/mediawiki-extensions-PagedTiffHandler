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

use Exception;
use File;
use FormatMetadata;
use IContextSource;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MapCacheLRU;
use MediaHandlerState;
use MediaTransformError;
use MediaTransformOutput;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\User\UserOptionsLookup;
use Message;
use RequestContext;
use Status;
use TransformationalImageHandler;

class PagedTiffHandler extends TransformationalImageHandler {
	// TIFF files over 10M are considered expensive to thumbnail
	private const EXPENSIVE_SIZE_LIMIT = 10485760;

	/**
	 * 1.0: Initial
	 * 1.1: Fixed bugs in imageinfo parser
	 * 1.2: Photoshop quirks (reverted)
	 * 1.3: Handing extra IFDs reported by tiffinfo
	 * 1.4: Allowed page numbering to start from numbers other than 1
	 */
	public const TIFF_METADATA_VERSION = '1.4';

	/**
	 * Known images cache
	 *
	 * @var MapCacheLRU
	 */
	private $knownImages;

	/** @var CommandFactory */
	private $commandFactory;

	/** @var HookContainer */
	private $hookContainer;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var StatsdDataFactoryInterface */
	private $statsdFactory;

	/**
	 * Number of images to keep in $knownImages
	 */
	private const CACHE_SIZE = 5;

	public function __construct() {
		$this->knownImages = new MapCacheLRU( self::CACHE_SIZE );

		$services = MediaWikiServices::getInstance();
		$this->commandFactory = $services->getShellCommandFactory();
		$this->hookContainer = $services->getHookContainer();
		$this->userOptionsLookup = $services->getUserOptionsLookup();
		$this->statsdFactory = $services->getStatsdDataFactory();
	}

	/**
	 * @return bool
	 */
	public function isEnabled() {
		return true;
	}

	/**
	 * @param File $img
	 * @return bool
	 */
	public function mustRender( $img ) {
		return true;
	}

	/**
	 * Does the file format support multi-page documents?
	 * @param File $img
	 * @return bool
	 */
	public function isMultiPage( $img ) {
		return true;
	}

	/**
	 * Various checks against the uploaded file
	 * - maximum upload size
	 * - maximum number of embedded files
	 * - maximum size of metadata
	 * - identify-errors
	 * - identify-warnings
	 * - check for running-identify-service
	 * @param string $fileName
	 * @return Status
	 */
	public function verifyUpload( $fileName ) {
		$status = Status::newGood();
		$meta = $this->getTiffImage( false, $fileName )->retrieveMetaData();
		if ( !$meta ) {
			wfDebug( __METHOD__ . ": unable to retrieve metadata" );
			$status->fatal( 'tiff_out_of_service' );
		} else {
			$ok = $this->verifyMetaData( $meta, $error );

			if ( !$ok ) {
				$this->getCachedTiffImage( $fileName )->resetMetaData();
				call_user_func_array( [ $status, 'fatal' ], $error );
			}
		}

		return $status;
	}

	/**
	 * @param array $meta
	 * @param array &$error
	 * @return bool
	 */
	private function verifyMetaData( $meta, &$error ) {
		global $wgTiffMaxEmbedFiles, $wgTiffMaxMetaSize;

		$errors = $this->getMetadataErrors( $meta );
		if ( $errors ) {
			$error = [ 'tiff_bad_file', $this->joinMessages( $errors ) ];

			wfDebug( __METHOD__ . ": {$error[0]} " .
				$this->joinMessages( $errors, false ) );
			return false;
		}

		if ( $meta['page_count'] <= 0 || empty( $meta['page_data'] ) ) {
			$error = [ 'tiff_page_error', $meta['page_count'] ];
			wfDebug( __METHOD__ . ": {$error[0]}" );
			return false;
		}
		if ( $wgTiffMaxEmbedFiles && $meta['page_count'] > $wgTiffMaxEmbedFiles ) {
			$error = [ 'tiff_too_much_embed_files', $meta['page_count'], $wgTiffMaxEmbedFiles ];
			wfDebug( __METHOD__ . ": {$error[0]}" );
			return false;
		}
		$len = strlen( serialize( $meta ) );
		if ( ( $len + 1 ) > $wgTiffMaxMetaSize ) {
			$error = [ 'tiff_too_much_meta', $len, $wgTiffMaxMetaSize ];
			wfDebug( __METHOD__ . ": {$error[0]}" );
			return false;
		}

		wfDebug( __METHOD__ . ": metadata is ok" );
		return true;
	}

	/**
	 * Maps MagicWord-IDs to parameters.
	 * In this case, width, page, and lossy.
	 * @return array
	 */
	public function getParamMap() {
		return [
			'img_width' => 'width',
			'img_page' => 'page',
			'img_lossy' => 'lossy',
		];
	}

	/**
	 * Checks whether parameters are valid and have valid values.
	 * Check for lossy was added.
	 * @param string $name
	 * @param string $value
	 * @return bool
	 */
	public function validateParam( $name, $value ) {
		if ( in_array( $name, [ 'width', 'height', 'page', 'lossy' ] ) ) {
			if ( $name === 'page' && trim( $value ) !== (string)intval( $value ) ) {
				// Extra junk on the end of page, probably actually a caption
				// e.g. [[File:Foo.tiff|thumb|Page 3 of the document shows foo]]
				return false;
			}

			if ( $name == 'lossy' ) {
				# NOTE: make sure to use === for comparison. in PHP, '' == 0 and 'foo' == 1.

				if ( $value === 1 || $value === 0 || $value === '1' || $value === '0' ) {
					return true;
				}

				if ( $value === 'true' || $value === 'false'
					|| $value === 'lossy' || $value === 'lossless'
				) {
					return true;
				}

				return false;
			} elseif ( $value <= 0 || $value > 65535 ) { // ImageMagick overflows for values > 65536
				return false;
			} else {
				return true;
			}
		} else {
			return false;
		}
	}

	/**
	 * Creates parameter string for file name.
	 * Page number was added.
	 * @param array $params
	 * @return string|false
	 */
	public function makeParamString( $params ) {
		if (
			!isset( $params['width'] ) || !isset( $params['lossy'] ) || !isset( $params['page'] )
		) {
			return false;
		}

		return "{$params['lossy']}-page{$params['page']}-{$params['width']}px";
	}

	/**
	 * Parses parameter string into an array.
	 * @param string $str
	 * @return array|false
	 */
	public function parseParamString( $str ) {
		if ( preg_match( '/^(\w+)-page(\d+)-(\d+)px$/', $str, $matches ) ) {
			return [ 'width' => $matches[3], 'page' => $matches[2], 'lossy' => $matches[1] ];
		}

		return false;
	}

	/**
	 * The function is used to specify which parameters to File::transform() should be
	 * passed through to thumb.php, in the case where the configuration specifies
	 * thumb.php is to be used (e.g. $wgThumbnailScriptPath !== false). You should
	 * pass through the same parameters as in makeParamString().
	 * @param array $params
	 * @return array
	 */
	protected function getScriptParams( $params ) {
		return [
			'width' => $params['width'],
			'page' => $params['page'],
			'lossy' => $params['lossy'],
		];
	}

	/**
	 * Prepares param array and sets standard values.
	 * Adds normalisation for parameter "lossy".
	 * @param File $image
	 * @param array &$params
	 * @return bool
	 */
	public function normaliseParams( $image, &$params ) {
		if ( isset( $params['page'] ) ) {
			$params['page'] = $this->adjustPage( $image, $params['page'] );
		} else {
			$params['page'] = $this->firstPage( $image );
		}

		if ( isset( $params['lossy'] ) ) {
			if ( in_array( $params['lossy'], [ 1, '1', 'true', 'lossy' ] ) ) {
				$params['lossy'] = 'lossy';
			} else {
				$params['lossy'] = 'lossless';
			}
		} else {
			$page = $params['page'];
			$data = $image->getMetadataArray();

			if ( !$this->isMetadataError( $data )
				&& strtolower( $data['page_data'][$page]['alpha'] ?? '' ) == 'true'
			) {
				// If there is an alpha channel, use png.
				$params['lossy'] = 'lossless';
			} else {
				$params['lossy'] = 'lossy';
			}
		}

		return parent::normaliseParams( $image, $params );
	}

	/**
	 * @param array|false $metadata
	 * @return bool|string[] a list of errors or an error flag (true = error)
	 */
	private function getMetadataErrors( $metadata ) {
		if ( !$metadata ) {
			return true;
		} elseif ( !isset( $metadata['errors'] ) ) {
			return false;
		}

		return $metadata['errors'];
	}

	/**
	 * Is metadata an error condition?
	 * @param array|false $metadata Metadata to test
	 * @return bool True if metadata is an error, false if it has normal info
	 */
	private function isMetadataError( $metadata ) {
		$errors = $this->getMetadataErrors( $metadata );
		if ( is_array( $errors ) ) {
			return count( $errors ) > 0;
		} else {
			return $errors;
		}
	}

	/**
	 * @param array|string $errors_raw
	 * @param bool $to_html
	 * @return bool|string
	 */
	private function joinMessages( $errors_raw, $to_html = true ) {
		if ( is_array( $errors_raw ) ) {
			if ( !$errors_raw ) {
				return false;
			}

			$errors = [];
			foreach ( $errors_raw as $error ) {
				if ( $error === false || $error === null || $error === 0 || $error === '' ) {
					continue;
				}

				$error = trim( $error );

				if ( $error === '' ) {
					continue;
				}

				if ( $to_html ) {
					$error = htmlspecialchars( $error );
				}

				$errors[] = $error;
			}

			if ( $to_html ) {
				return trim( implode( '<br />', $errors ) );
			} else {
				return trim( implode( ";\n", $errors ) );
			}
		}

		return $errors_raw;
	}

	/**
	 * What method to use to scale this file
	 *
	 * @see TransformationalImageHandler::getScalerType
	 * @param string $dstPath Path to store thumbnail
	 * @param bool $checkDstPath Whether to verify destination path exists
	 * @return callable Transform function to call.
	 */
	protected function getScalerType( $dstPath, $checkDstPath = true ) {
		if ( !$dstPath && $checkDstPath ) {
			// We don't have the option of doing client side scaling for this filetype.
			throw new Exception( "Cannot create thumbnail, no destination path" );
		}

		return [ $this, 'transformIM' ];
	}

	/**
	 * Actually scale the file (using ImageMagick).
	 *
	 * @param File $file File object
	 * @param array $scalerParams Scaling options (see TransformationalImageHandler::doTransform)
	 * @return bool|MediaTransformError False on success, an instance of MediaTransformError
	 *   otherwise.
	 * @note Success is noted by $scalerParams['dstPath'] no longer being a 0 byte file.
	 */
	protected function transformIM( $file, $scalerParams ) {
		global $wgImageMagickConvertCommand, $wgMaxImageArea;

		$meta = $file->getMetadataArray();

		$errors = $this->getMetadataErrors( $meta );

		if ( $errors ) {
			$errors = $this->joinMessages( $errors );
			if ( is_string( $errors ) ) {
				// TODO: original error as param // TESTME
				return $this->doThumbError( $scalerParams, 'tiff_bad_file' );
			} else {
				return $this->doThumbError( $scalerParams, 'tiff_no_metadata' );
			}
		}

		if ( !$this->verifyMetaData( $meta, $error ) ) {
			return $this->doThumbError( $scalerParams, $error );
		}
		if ( !wfMkdirParents( dirname( $scalerParams['dstPath'] ), null, __METHOD__ ) ) {
			return $this->doThumbError( $scalerParams, 'thumbnail_dest_directory' );
		}

		// Get params and force width, height and page to be integers
		$width = intval( $scalerParams['physicalWidth'] );
		$height = intval( $scalerParams['physicalHeight'] );
		$page = intval( $scalerParams['page'] );
		$srcPath = $this->escapeMagickInput( $scalerParams['srcPath'], (string)( $page - 1 ) );
		$dstPath = $this->escapeMagickOutput( $scalerParams['dstPath'] );

		if ( $wgMaxImageArea
			&& isset( $meta['page_data'][$page]['pixels'] )
			&& $meta['page_data'][$page]['pixels'] > $wgMaxImageArea
		) {
			return $this->doThumbError( $scalerParams, 'tiff_sourcefile_too_large' );
		}

		$command = $this->commandFactory->create()
			->params(
				$wgImageMagickConvertCommand,
				$srcPath,
				'-depth', '8',
				'-resize', $width,
				$dstPath
			)
			->includeStderr();

		$result = $command->execute();
		$exitCode = $result->getExitCode();
		if ( $exitCode !== 0 ) {
			$err = $result->getStdout();
			$cmd = $command->getCommandString();
			wfDebugLog(
				'thumbnail',
				"thumbnail failed on " . wfHostname() . "; error $exitCode \"$err\" from \"$cmd\""
			);
			return $this->getMediaTransformError( $scalerParams, $err );
		} else {
			return false; /* no error */
		}
	}

	/**
	 * Get the thumbnail extension and MIME type for a given source MIME type
	 * @param string $ext
	 * @param string $mime
	 * @param array|null $params
	 * @return array thumbnail extension and MIME type
	 */
	public function getThumbType( $ext, $mime, $params = null ) {
		// Make sure the file is actually a tiff image
		$tiffImageThumbType = parent::getThumbType( $ext, $mime, $params );
		if ( $tiffImageThumbType[1] !== 'image/tiff' ) {
			// We have some other file pretending to be a tiff image.
			return $tiffImageThumbType;
		}

		if ( isset( $params['lossy'] ) && $params['lossy'] == 'lossy' ) {
			return [ 'jpg', 'image/jpeg' ];
		} else {
			return [ 'png', 'image/png' ];
		}
	}

	/**
	 * Returns the number of available pages/embedded files
	 * @param File $image
	 * @return int
	 */
	public function pageCount( File $image ) {
		$data = $image->getMetadataArray();
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}

		return intval( $data['page_count'] );
	}

	/**
	 * Returns the number of the first page in the file
	 * @param File $image
	 * @return int
	 */
	private function firstPage( $image ) {
		$data = $image->getMetadataArray();
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}
		return intval( $data['first_page'] );
	}

	/**
	 * Returns the number of the last page in the file
	 * @param File $image
	 * @return int
	 */
	private function lastPage( $image ) {
		$data = $image->getMetadataArray();
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}
		return intval( $data['last_page'] );
	}

	/**
	 * Returns a page number within range.
	 * @param File $image
	 * @param int|string $page
	 * @return int
	 */
	private function adjustPage( $image, $page ) {
		$page = intval( $page );

		if ( !$page || $page < $this->firstPage( $image ) ) {
			$page = $this->firstPage( $image );
		}

		if ( $page > $this->lastPage( $image ) ) {
			$page = $this->lastPage( $image );
		}

		return $page;
	}

	/**
	 * Returns a new error message.
	 * @param array $params
	 * @param string|array $msg
	 * @return MediaTransformError
	 */
	protected function doThumbError( $params, $msg ) {
		global $wgThumbLimits;

		$errorParams = [];
		if ( empty( $params['width'] ) ) {
			$user = RequestContext::getMain()->getUser();
			// no usable width/height in the parameter array
			// only happens if we don't have image meta-data, and no
			// size was specified by the user.
			// we need to pick *some* size, and the preferred
			// thumbnail size seems sane.
			$sz = $this->userOptionsLookup->getOption( $user, 'thumbsize' );
			$errorParams['clientWidth'] = $wgThumbLimits[ $sz ];
			// we don't have a height or aspect ratio. make it square.
			$errorParams['clientHeight'] = $wgThumbLimits[ $sz ];
		} else {
			$errorParams['clientWidth'] = intval( $params['width'] );

			if ( !empty( $params['height'] ) ) {
				$errorParams['clientHeight'] = intval( $params['height'] );
			} else {
				// we don't have a height or aspect ratio. make it square.
				$errorParams['clientHeight'] = $errorParams['clientWidth'];
			}
		}

		return $this->getMediaTransformError( $errorParams, Message::newFromSpecifier( $msg )->text() );
	}

	/**
	 * @param MediaHandlerState $state
	 * @param string $path
	 * @return array
	 */
	public function getSizeAndMetadata( $state, $path ) {
		$metadata = $state->getHandlerState( 'TiffMetaArray' );
		if ( !$metadata ) {
			$metadata = $this->getTiffImage( $state, $path )->retrieveMetaData();
			$state->setHandlerState( 'TiffMetaArray', $metadata );
		}

		$gis = getimagesize( $path );
		if ( $gis ) {
			[ $width, $height ] = $gis;
		} else {
			$width = 0;
			$height = 0;
		}

		return [
			'width' => $width,
			'height' => $height,
			'metadata' => $metadata
		];
	}

	/**
	 * Check if the metadata string is valid for this handler.
	 * If it returns false, Image will reload the metadata from the file and update the database
	 * @param File $image
	 * @return bool
	 */
	public function isFileMetadataValid( $image ) {
		$metadata = $image->getMetadataArray();
		if ( isset( $metadata['errors'] ) ) {
			// In the case of a broken file, we do not want to reload the
			// metadata on every request.
			return self::METADATA_GOOD;
		}

		if ( !isset( $metadata['TIFF_METADATA_VERSION'] )
			|| $metadata['TIFF_METADATA_VERSION'] != self::TIFF_METADATA_VERSION
		) {
			return self::METADATA_BAD;
		}

		return self::METADATA_GOOD;
	}

	/**
	 * Get an array structure that looks like this:
	 *
	 * [
	 *  'visible' => [
	 *    'Human-readable name' => 'Human readable value',
	 *    ...
	 *  ],
	 *  'collapsed' => [
	 *    'Human-readable name' => 'Human readable value',
	 *    ...
	 *  ]
	 * ]
	 * The UI will format this into a table where the visible fields are always
	 * visible, and the collapsed fields are optionally visible.
	 *
	 * The function should return false if there is no metadata to display.
	 *
	 * @param File $image
	 * @param bool|IContextSource $context Context to use (optional)
	 * @return array|bool
	 */
	public function formatMetadata( $image, $context = false ) {
		$result = [
			'visible' => [],
			'collapsed' => []
		];
		$metadata = $image->getMetadata();
		if ( !$metadata ) {
			return false;
		}
		$exif = unserialize( $metadata );
		if ( !isset( $exif['exif'] ) || !$exif['exif'] ) {
			return false;
		}
		$exif = $exif['exif'];
		unset( $exif['MEDIAWIKI_EXIF_VERSION'] );
		$formatted = FormatMetadata::getFormattedData( $exif, $context );

		// Sort fields into visible and collapsed
		$visibleFields = $this->visibleMetadataFields();
		foreach ( $formatted as $name => $value ) {
			$tag = strtolower( $name );
			$this->addMeta( $result,
				in_array( $tag, $visibleFields ) ? 'visible' : 'collapsed',
				'exif',
				$tag,
				$value
			);
		}
		$meta = unserialize( $metadata );
		$errors_raw = $this->getMetadataErrors( $meta );
		if ( $errors_raw ) {
			$errors = $this->joinMessages( $errors_raw );
			$this->addMeta( $result,
				'collapsed',
				'metadata',
				'error',
				$errors
			);
			// XXX: need translation for <metadata-error>
		}
		if ( !empty( $meta['warnings'] ) ) {
			$warnings = $this->joinMessages( $meta['warnings'] );
			$this->addMeta( $result,
				'collapsed',
				'metadata',
				'warning',
				$warnings
			);
			// XXX: need translation for <metadata-warning>
		}
		return $result;
	}

	/**
	 * Returns a PagedTiffImage or creates a new one if it doesn't exist.
	 * @param MediaHandlerState|false $state The image object, or false if there isn't one
	 * @param string $path path to the image?
	 * @return PagedTiffImage
	 */
	private function getTiffImage( $state, $path ) {
		if ( !$state ) {
			return $this->getCachedTiffImage( $path );
		}

		// If there is an Image object, we check whether there's already a TiffImage
		// instance in there; if not, a new instance is created and stored in the Image object
		$tiffImage = $state->getHandlerState( 'TiffImage' );
		if ( !$tiffImage ) {
			$tiffImage = $this->getCachedTiffImage( $path );
			$state->setHandlerState( 'TiffImage', $tiffImage );
		}

		return $tiffImage;
	}

	/**
	 * Gets a PagedTiffImage from the cache, or creates one
	 * @param string $path path to the image
	 * @return PagedTiffImage
	 */
	private function getCachedTiffImage( $path ) {
		$image = $this->knownImages->get( $path );
		if ( $image === null ) {
			$image = new PagedTiffImage( $this->commandFactory, $this->statsdFactory, $path );
			$this->knownImages->set( $path, $image );
		}
		return $image;
	}

	/**
	 * Get an associative array of page dimensions
	 * Currently "width" and "height" are understood, but this might be
	 * expanded in the future.
	 * @param File $image
	 * @param int $page
	 * @return int|false Returns false if unknown or if the document is not multi-page.
	 */
	public function getPageDimensions( File $image, $page ) {
		// makeImageLink (Linker.php) sets $page to false if no page parameter
		// is set in wiki code
		$page = $this->adjustPage( $image, $page );
		$data = $image->getMetadataArray();
		return PagedTiffImage::getPageSize( $data, $page );
	}

	public function isExpensiveToThumbnail( $file ) {
		return $file->getSize() > self::EXPENSIVE_SIZE_LIMIT;
	}

	/**
	 * What source thumbnail to use.
	 *
	 * This does not use MW's builtin bucket system, as it tries to take
	 * advantage of the fact that VIPS can scale integer shrink factors
	 * much more efficiently than non-integral scaling factors.
	 *
	 * @param File $file
	 * @param array $params Parameters to transform file with.
	 * @return array Array with keys path, width and height
	 */
	protected function getThumbnailSource( $file, $params ) {
		/** @var MediaTransformOutput */
		$mto = $this->getIntermediaryStep( $file, $params );
		if ( $mto && !$mto->isError() ) {
			return [
				'path' => $mto->getLocalCopyPath(),
				'width' => $mto->getWidth(),
				'height' => $mto->getHeight(),
				// The path to the temporary file may be deleted when last
				// instance of the MediaTransformOutput is garbage collected,
				// so keep a reference around.
				'mto' => $mto
			];
		} else {
			return parent::getThumbnailSource( $file, $params );
		}
	}

	/**
	 * Get an intermediary sized thumb to do further rendering on
	 *
	 * Tiff files can be huge. This method gets a large thumbnail
	 * to further scale things down. Size is chosen to be
	 * efficient to scale in vips for those who use VipsScaler
	 *
	 * @param File $file
	 * @param array $params Scaling parameters for original thumbnail
	 * @return MediaTransformOutput|MediaTransformError|bool false if no in between step needed,
	 *   MediaTransformError on error. False if the doTransform method returns false
	 *   MediaTransformOutput on success.
	 */
	private function getIntermediaryStep( $file, $params ) {
		global $wgTiffIntermediaryScaleStep, $wgThumbnailMinimumBucketDistance;

		$page = intval( $params['page'] );
		$page = $this->adjustPage( $file, $page );
		$srcWidth = $file->getWidth( $page );
		$srcHeight = $file->getHeight( $page );

		if ( $srcWidth <= $wgTiffIntermediaryScaleStep ) {
			// Image is already smaller than intermediary step or at that step
			return false;
		}

		$widthOfFinalThumb = $params['physicalWidth'];

		// Try and get a width that's easy for VipsScaler to work with
		// i.e. Is an integer shrink factor.
		$rx = floor( $srcWidth / ( $wgTiffIntermediaryScaleStep + 0.125 ) );
		$intermediaryWidth = intval( floor( $srcWidth / $rx ) );
		$intermediaryHeight = intval( floor( $srcHeight / $rx ) );

		// We need both the vertical and horizontal shrink factors to be
		// integers, and at the same time make sure that both vips and mediawiki
		// have the same height for a given width (MediaWiki makes the assumption
		// that the height of an image functionally depends on its width)
		for ( ; $rx >= 2; $rx-- ) {
			$intermediaryWidth = intval( floor( $srcWidth / $rx ) );
			$intermediaryHeight = intval( floor( $srcHeight / $rx ) );
			if ( $intermediaryHeight ==
				File::scaleHeight( $srcWidth, $srcHeight, $intermediaryWidth )
			) {
				break;
			}
		}

		if (
			$intermediaryWidth <= $widthOfFinalThumb + $wgThumbnailMinimumBucketDistance || $rx < 2
		) {
			// Need to scale the original full sized thumb
			return false;
		}

		static $isInThisFunction;

		if ( $isInThisFunction ) {
			// Sanity check, should never be reached
			throw new Exception( "Loop detected in " . __METHOD__ );
		}
		$isInThisFunction = true;

		$newParams = [
			'width' => $intermediaryWidth,
			'page' => $page,
			// Render a png, to avoid loss of quality when doing multi-step
			'lossy' => 'lossless'
		];

		// RENDER_NOW causes rendering in this process if
		// thumb doesn't exist, but unlike RENDER_FORCE, will return
		// a cached thumb if available.
		$mto = $file->transform( $newParams, File::RENDER_NOW );

		$isInThisFunction = false;
		return $mto;
	}
}
