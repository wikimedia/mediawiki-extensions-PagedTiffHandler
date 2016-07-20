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

class PagedTiffHandler extends TransformationalImageHandler {
	const EXPENSIVE_SIZE_LIMIT = 10485760; // TIFF files over 10M are considered expensive to thumbnail

	/**
	 * @return bool
	 */
	function isEnabled() {
		return true;
	}

	/**
	 * @return bool
	 */
	function mustRender( $img ) {
		return true;
	}

	/**
	 * Does the file format support multi-page documents?
	 */
	function isMultiPage( $img ) {
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
	 */
	function verifyUpload( $fileName ) {
		global $wgTiffUseTiffReader, $wgTiffReaderCheckEofForJS;

		$status = Status::newGood();
		if ( $wgTiffUseTiffReader ) {
			$tr = new TiffReader( $fileName );
			$tr->check();
			if ( !$tr->isValidTiff() ) {
				wfDebug( __METHOD__ . ": bad file\n" );
				$status->fatal( 'tiff_invalid_file' );
			} else {
				if ( $tr->checkScriptAtEnd( $wgTiffReaderCheckEofForJS ) ) {
					wfDebug( __METHOD__ . ": script detected\n" );
					$status->fatal( 'tiff_script_detected' );
				}
				if ( !$tr->checkSize() ) {
					wfDebug( __METHOD__ . ": size error\n" );
					$status->fatal( 'tiff_size_error' );
				}
			}
		}
		$meta = self::getTiffImage( false, $fileName )->retrieveMetaData();
		if ( !$meta ) {
			wfDebug( __METHOD__ . ": unable to retreive metadata\n" );
			$status->fatal( 'tiff_out_of_service' );
		} else {
			$error = false;
			$ok = self::verifyMetaData( $meta, $error );

			if ( !$ok ) {
				call_user_func_array( array( $status, 'fatal' ), $error );
			}
		}

		return $status;
	}

	/**
	 * @param $meta array
	 * @param $error array
	 * @return bool
	 */
	static function verifyMetaData( $meta, &$error ) {
		global $wgTiffMaxEmbedFiles, $wgTiffMaxMetaSize;

		$errors = PagedTiffHandler::getMetadataErrors( $meta );
		if ( $errors ) {
			$error = array( 'tiff_bad_file', PagedTiffHandler::joinMessages( $errors ) );

			wfDebug( __METHOD__ . ": {$error[0]} " . PagedTiffHandler::joinMessages( $errors, false ) . "\n" );
			return false;
		}

		if ( $meta['page_count'] <= 0 || empty( $meta['page_data'] ) ) {
			$error = array( 'tiff_page_error', $meta['page_count'] );
			wfDebug( __METHOD__ . ": {$error[0]}\n" );
			return false;
		}
		if ( $wgTiffMaxEmbedFiles && $meta['page_count'] > $wgTiffMaxEmbedFiles ) {
			$error = array( 'tiff_too_much_embed_files', $meta['page_count'], $wgTiffMaxEmbedFiles );
			wfDebug( __METHOD__ . ": {$error[0]}\n" );
			return false;
		}
		$len = strlen( serialize( $meta ) );
		if ( ( $len + 1 ) > $wgTiffMaxMetaSize ) {
			$error = array( 'tiff_too_much_meta', $len, $wgTiffMaxMetaSize );
			wfDebug( __METHOD__ . ": {$error[0]}\n" );
			return false;
		}

		wfDebug( __METHOD__ . ": metadata is ok\n" );
		return true;
	}

	/**
	 * Maps MagicWord-IDs to parameters.
	 * In this case, width, page, and lossy.
	 */
	function getParamMap() {
		return array(
			'img_width' => 'width',
			'img_page' => 'page',
			'img_lossy' => 'lossy',
		);
	}


	/**
	 * Checks whether parameters are valid and have valid values.
	 * Check for lossy was added.
	 */
	function validateParam( $name, $value ) {
		if ( in_array( $name, array( 'width', 'height', 'page', 'lossy' ) ) ) {
			if ( $name === 'page' && trim( $value ) !== (string) intval( $value ) ) {
				// Extra junk on the end of page, probably actually a caption
				// e.g. [[File:Foo.tiff|thumb|Page 3 of the document shows foo]]
				return false;
			}

			if ( $name == 'lossy' ) {
				# NOTE: make sure to use === for comparison. in PHP, '' == 0 and 'foo' == 1.

				if ( $value === 1 || $value === 0 || $value === '1' || $value === '0' ) {
					return true;
				}

				if ( $value === 'true' || $value === 'false' || $value === 'lossy' || $value === 'lossless' ) {
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
	 */
	function makeParamString( $params ) {
		if ( !isset( $params['width'] ) || !isset( $params['lossy'] ) || !isset( $params['page'] )) {
			return false;
		}

		return "{$params['lossy']}-page{$params['page']}-{$params['width']}px";
	}

	/**
	 * Parses parameter string into an array.
	 */
	function parseParamString( $str ) {
		$m = false;
		if ( preg_match( '/^(\w+)-page(\d+)-(\d+)px$/', $str, $m ) ) {
			return array( 'width' => $m[3], 'page' => $m[2], 'lossy' => $m[1] );
		} else {
			return false;
		}
	}

	/**
	* The function is used to specify which parameters to File::transform() should be
	* passed through to thumb.php, in the case where the configuration specifies
	* thumb.php is to be used (e.g. $wgThumbnailScriptPath !== false). You should
	* pass through the same parameters as in makeParamString().
	*/
	function getScriptParams( $params ) {
		return array(
			'width' => $params['width'],
			'page' => $params['page'],
			'lossy' => $params['lossy'],
		);
	}

	/**
	 * Prepares param array and sets standard values.
	 * Adds normalisation for parameter "lossy".
	 */
	function normaliseParams( $image, &$params ) {
		if ( isset( $params['page'] ) ) {
			$params['page'] = $this->adjustPage( $image, $params['page'] );
		} else {
			$params['page'] = $this->firstPage( $image );
		}

		if ( isset( $params['lossy'] ) ) {
			if ( in_array( $params['lossy'], array( 1, '1', 'true', 'lossy' ) ) ) {
				$params['lossy'] = 'lossy';
			} else {
				$params['lossy'] = 'lossless';
			}
		} else {
			$page = $params['page'];
			$data = $this->getMetaArray( $image );

			if ( !$this->isMetadataError( $data )
				&& strtolower( $data['page_data'][$page]['alpha'] ) == 'true'
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
	 * @param $metadata array
	 * @return bool|string[] a list of errors or an error flag (true = error)
	 */
	static function getMetadataErrors( $metadata ) {
		if ( is_string( $metadata ) ) {
			$metadata = unserialize( $metadata );
		}

		if ( !$metadata ) {
			return true;
		} elseif ( isset( $metadata[ 'errors' ] ) ) {
			return $metadata[ 'errors' ];
		} else {
			return false;
		}
	}

	/**
	 * Is metadata an error condition?
	 * @param Array|string|boolean Metadata to test
	 * @return boolean True if metadata is an error, false if it has normal info
	 */
	private function isMetadataError( $metadata ) {
		$errors = self::getMetadataErrors( $metadata );
		if ( is_array( $errors ) ) {
			return count( $errors ) > 0;
		} else {
			return $errors;
		}
	}

	/**
	 * @param $errors_raw array
	 * @param $to_html bool
	 * @return bool|string
	 */
	static function joinMessages( $errors_raw, $to_html = true ) {
		if ( is_array( $errors_raw ) ) {
			if ( !$errors_raw ) {
				return false;
			}

			$errors = array();
			foreach ( $errors_raw as $error ) {
				if ( $error === false || $error === null || $error === 0 || $error === '' )
					continue;

				$error = trim( $error );

				if ( $error === '' )
					continue;

				if ( $to_html )
					$error = htmlspecialchars( $error );

				$errors[] = $error;
			}

			if ( $to_html ) {
				return trim( join( '<br />', $errors ) );
			} else {
				return trim( join( ";\n", $errors ) );
			}
		}

		return $errors_raw;
	}

	/**
	 * What method to use to scale this file
	 *
	 * @see TransformationalImageHandler::getScalerType
	 * @param $dstPath string Path to store thumbnail
	 * @param $checkDstPath boolean Whether to verify destination path exists
	 * @return Callable Transform function to call.
	 */
	protected function getScalerType( $dstPath, $checkDstPath = true ) {
		if ( !$dstPath && $checkDstPath ) {
			// We don't have the option of doing client side scaling for this filetype.
			throw new Exception( "Cannot create thumbnail, no destination path" );
		}

		return array( $this, 'transformIM' );
	}

	/**
	 * Actually scale the file (using ImageMagick).
	 *
	 * @param File $file File object
	 * @param Array $scalerParams Scaling options (see TransformationalImageHandler::doTransform)
	 * @return Boolean|MediaTransformError False on success, an instance of MediaTransformError otherwise.
	 * @note Success is noted by $scalerParams['dstPath'] no longer being a 0 byte file.
	 */
	protected function transformIM( $file, $scalerParams ) {
		global $wgImageMagickConvertCommand, $wgMaxImageArea;

		$meta = $this->getMetaArray( $file );

		$errors = PagedTiffHandler::getMetadataErrors( $meta );

		if ( $errors ) {
			$errors = PagedTiffHandler::joinMessages( $errors );
			if ( is_string( $errors ) ) {
				// TODO: original error as param // TESTME
				return $this->doThumbError( $scalerParams, 'tiff_bad_file' );
			} else {
				return $this->doThumbError( $scalerParams, 'tiff_no_metadata' );
			}
		}

		if ( !self::verifyMetaData( $meta, $error, $scalerParams['dstPath'] ) ) {
			return $this->doThumbError( $scalerParams, $error );
		}
		if ( !wfMkdirParents( dirname( $scalerParams['dstPath'] ), null, __METHOD__ ) ) {
			return $this->doThumbError( $scalerParams, 'thumbnail_dest_directory' );
		}

		// Get params and force width, height and page to be integers
		$width = intval( $scalerParams['physicalWidth'] );
		$height = intval( $scalerParams['physicalHeight'] );
		$page = intval( $scalerParams['page'] );
		$srcPath = $this->escapeMagickInput( $scalerParams['srcPath'], $page - 1 );
		$dstPath = $this->escapeMagickOutput( $scalerParams['dstPath'] );

		if ( isset( $meta['page_data'][$page]['pixels'] )
			&& $meta['page_data'][$page]['pixels'] > $wgMaxImageArea
		) {
			return $this->doThumbError( $scalerParams, 'tiff_sourcefile_too_large' );
		}

		$cmd = wfEscapeShellArg( $wgImageMagickConvertCommand );
		$cmd .= " " . wfEscapeShellArg( $srcPath );
		$cmd .= " -depth 8 -resize {$width} ";
		$cmd .= wfEscapeShellArg( $dstPath );

		Hooks::run( 'PagedTiffHandlerRenderCommand',
			array( &$cmd, $srcPath, $dstPath, $page, $width, $height, $scalerParams )
		);

		wfDebug( __METHOD__ . ": $cmd\n" );
		$retval = null;
		wfProfileIn( 'PagedTiffHandler' );
		$err = wfShellExecWithStderr( $cmd, $retval );
		wfProfileOut( 'PagedTiffHandler' );

		if ( $retval !== 0 ) {
			wfDebugLog( 'thumbnail', "thumbnail failed on " . wfHostname() .
				"; error $retval \"$err\" from \"$cmd\"" );
			return $this->getMediaTransformError( $scalerParams, $err );
		} else {
			return false; /* no error */
		}
	}

	/**
	 * Get the thumbnail extension and MIME type for a given source MIME type
	 * @param $ext
	 * @param $mime
	 * @param $params array
	 * @return array thumbnail extension and MIME type
	 */
	function getThumbType( $ext, $mime, $params = null ) {
		// Make sure the file is actually a tiff image
		$tiffImageThumbType = parent::getThumbType( $ext, $mime, $params );
		if ( $tiffImageThumbType[1] !== 'image/tiff' ) {
			// We have some other file pretending to be a tiff image.
			return $tiffImageThumbType;
		}

		if ( isset( $params['lossy'] ) && $params['lossy'] == 'lossy' ) {
			return array( 'jpg', 'image/jpeg' );
		} else {
			return array( 'png', 'image/png' );
		}
	}

	/**
	 * Returns the number of available pages/embedded files
	 */
	function pageCount( File $image ) {
		$data = $this->getMetaArray( $image );
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}

		return intval( $data['page_count'] );
	}

	/**
	 * Returns the number of the first page in the file
	 */
	function firstPage( $image ) {
		$data = $this->getMetaArray( $image );
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}
		return intval( $data['first_page'] );
	}

	/**
	 * Returns the number of the last page in the file
	 */
	function lastPage( $image ) {
		$data = $this->getMetaArray( $image );
		if ( $this->isMetadataError( $data ) ) {
			return 1;
		}
		return intval( $data['last_page'] );
	}

	/**
	 * Returns a page number within range.
	 */
	function adjustPage( $image, $page ) {
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
	 */
	protected function doThumbError( $params, $msg ) {
		global $wgUser, $wgThumbLimits;

		$errorParams = array();
		if ( empty( $params['width'] ) ) {
			// no usable width/height in the parameter array
			// only happens if we don't have image meta-data, and no
			// size was specified by the user.
			// we need to pick *some* size, and the preferred
			// thumbnail size seems sane.
			$sz = $wgUser->getOption( 'thumbsize' );
			$errorParams['clientWidth'] = $wgThumbLimits[ $sz ];
			$errorParams['clientHeight'] = $wgThumbLimits[ $sz ]; // we don't have a height or aspect ratio. make it square.
		} else {
			$errorParams['clientWidth'] = intval( $params['width'] );

			if ( !empty( $params['height'] ) ) {
				$errorParams['clientHeight'] = intval( $params['height'] );
			} else {
				$errorParams['clientWidth'] = $errorParams['clientHeight']; // we don't have a height or aspect ratio. make it square.
			}
		}

		return $this->getMediaTransformError( $errorParams, wfMessage( $msg )->text() );
	}

	/**
	 * Get handler-specific metadata which will be saved in the img_metadata field.
	 *
	 * @param $image File|bool: the image object, or false if there isn't one
	 * @param $path String: path to the image?
	 * @return string
	 */
	function getMetadata( $image, $path ) {
		if ( !$image ) {
			return serialize( $this->getTiffImage( $image, $path )->retrieveMetaData() );
		} else {
			if ( !isset( $image->tiffMetaArray ) ) {
				$image->tiffMetaArray = $this->getTiffImage( $image, $path )->retrieveMetaData();
			}

			return serialize( $image->tiffMetaArray );
		}
	}

	/**
	 * Creates detail information that is being displayed on image page.
	 */
	function getLongDesc( $image ) {
		global $wgLang, $wgRequest;

		$page = $wgRequest->getText( 'page', 1 );
		$page = $this->adjustPage( $image, $page );
		$metadata = $this->getMetaArray( $image );

		if ( $this->isMetadataError( $metadata ) ) {
			$params = array(
				'width' => intval( $metadata['page_data'][$page]['width'] ),
				'height' => intval( $metadata['page_data'][$page]['height'] ),
				'size' => $wgLang->formatSize( $image->getSize() ),
				'mime' => $image->getMimeType(),
				'page_count' => intval( $metadata['page_count'] )
			);

			// $1 × $2 pixels, file size: $3, MIME type: $4, $5 pages
			return wfMessage( 'tiff-file-info-size' )
				->numParams( $params['width'], $params['height'] )
				->params( $params['size'], $params['mime'] )
				->numParams( $params['page_count'] )
				->parse();
		} else {
			// If we have no metadata, we have no idea how many pages we
			// have, so just fallback to the parent class.
			return parent::getLongDesc( $image );
		}
	}

	/**
	 * Check if the metadata string is valid for this handler.
	 * If it returns false, Image will reload the metadata from the file and update the database
	 */
	function isMetadataValid( $image, $metadata ) {
		if ( is_string( $metadata ) ) {
			$metadata = unserialize( $metadata );
		}

		if ( isset( $metadata['errors'] ) ) {
			// In the case of a broken file, we do not want to reload the
			// metadata on every request.
			return true;
		}

		if ( !isset( $metadata['TIFF_METADATA_VERSION'] ) ) {
			return false;
		}

		if ( $metadata['TIFF_METADATA_VERSION'] != TIFF_METADATA_VERSION ) {
			return false;
		}

		return true;
	}

	/**
	 * Get an array structure that looks like this:
	 *
	 * array(
	 *	'visible' => array(
	 *	   'Human-readable name' => 'Human readable value',
	 *	   ...
	 *	),
	 *	'collapsed' => array(
	 *	   'Human-readable name' => 'Human readable value',
	 *	   ...
	 *	)
	 * )
	 * The UI will format this into a table where the visible fields are always
	 * visible, and the collapsed fields are optionally visible.
	 *
	 * The function should return false if there is no metadata to display.
	 *
	 * @param $image File
	 * @param bool|IContextSource $context Context to use (optional)
	 * @return array|bool
	 */
	function formatMetadata( $image, $context = false ) {
		$result = array(
			'visible' => array(),
			'collapsed' => array()
		);
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
			self::addMeta( $result,
				in_array( $tag, $visibleFields ) ? 'visible' : 'collapsed',
				'exif',
				$tag,
				$value
			);
		}
		$meta = unserialize( $metadata );
		$errors_raw = PagedTiffHandler::getMetadataErrors( $meta );
		if ( $errors_raw ) {
			$errors = PagedTiffHandler::joinMessages( $errors_raw );
			self::addMeta( $result,
				'collapsed',
				'metadata',
				'error',
				$errors
			);
			// XXX: need translation for <metadata-error>
		}
		if ( !empty( $meta['warnings'] ) ) {
			$warnings = PagedTiffHandler::joinMessages( $meta['warnings'] );
			self::addMeta( $result,
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
	 * @param $image File|bool: The image object, or false if there isn't one
	 * @param $path String: path to the image?
	 * @return PagedTiffImage
	 */
	static function getTiffImage( $image, $path ) {
		// If no Image object is passed, a TiffImage is created based on $path .
		// If there is an Image object, we check whether there's already a TiffImage
		// instance in there; if not, a new instance is created and stored in the Image object
		if ( !$image ) {
			$tiffimg = new PagedTiffImage( $path );
		} elseif ( !isset( $image->tiffImage ) ) {
			$tiffimg = $image->tiffImage = new PagedTiffImage( $path );
		} else {
			$tiffimg = $image->tiffImage;
		}

		return $tiffimg;
	}

	/**
	 * Returns an Array with the Image metadata.
	 *
	 * @param $image File
	 * @return bool|null|array
	 */
	function getMetaArray( $image ) {
		if ( isset( $image->tiffMetaArray ) ) {
			return $image->tiffMetaArray;
		}

		$metadata = $image->getMetadata();

		if ( !$this->isMetadataValid( $image, $metadata ) || PagedTiffHandler::getMetadataErrors( $metadata ) ) {
			wfDebug( "Tiff metadata is invalid, missing or has errors.\n" );
			return false;
		}

		wfProfileIn( __METHOD__ );
		wfSuppressWarnings();
		$image->tiffMetaArray = unserialize( $metadata );
		wfRestoreWarnings();
		wfProfileOut( __METHOD__ );

		return $image->tiffMetaArray;
	}

	/**
	 * Get an associative array of page dimensions
	 * Currently "width" and "height" are understood, but this might be
	 * expanded in the future.
	 * Returns false if unknown or if the document is not multi-page.
	 */
	function getPageDimensions( File $image, $page ) {
		// makeImageLink (Linker.php) sets $page to false if no page parameter
		// is set in wiki code
		$page = $this->adjustPage( $image, $page );
		$data = $this->getMetaArray( $image );
		return PagedTiffImage::getPageSize( $data, $page );
	}

	/**
	 * Handler for the ExtractThumbParameters hook
	 *
	 * @param $thumbname string URL-decoded basename of URI
	 * @param &$params Array Currently parsed thumbnail params
	 * @return bool
	 */
	public static function onExtractThumbParameters( $thumbname, array &$params ) {
		if ( !preg_match( '/\.(?:tiff|tif)$/i', $params['f'] ) ) {
			return true; // not an tiff file
		}
		// Check if the parameters can be extracted from the thumbnail name...
		if ( preg_match( '!^(lossy|lossless)-page(\d+)-(\d+)px-[^/]*$!', $thumbname, $m ) ) {
			list( /* all */, $lossy, $pagenum, $size ) = $m;
			$params['lossy'] = $lossy;
			$params['width'] = $size;
			$params['page'] = $pagenum;
			return false; // valid thumbnail URL
		}
		return true; // pass through to next handler
	}

	public function isExpensiveToThumbnail( $file ) {
		return $file->getSize() > static::EXPENSIVE_SIZE_LIMIT;
	}

	/**
	 * What source thumbnail to use.
	 *
	 * This does not use MW's builtin bucket system, as it tries to take
	 * advantage of the fact that VIPS can scale integer shrink factors
	 * much more efficiently than non-integral scaling factors.
	 *
	 * @param $file File
	 * @param $params Array Parameters to transform file with.
	 * @return Array Array with keys path, width and height
	 */
	protected function getThumbnailSource( $file, $params ) {
		/** @var MediaTransformOutput */
		$mto = $this->getIntermediaryStep( $file, $params );
		if ( $mto && !$mto->isError() ) {
			return array(
				'path' => $mto->getLocalCopyPath(),
				'width' => $mto->getWidth(),
				'height' => $mto->getHeight(),
				// The path to the temporary file may be deleted when last
				// instance of the MediaTransformObject is garbage collected,
				// so keep a reference around.
				'mto' => $mto
			);
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
	 * @param $file File
	 * @param $params Array Scaling parameters for original thumbnail
	 * @return MediaTransformObject|bool false if no in between step needed,
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
			if ( $intermediaryHeight == File::scaleHeight( $srcWidth, $srcHeight, $intermediaryWidth ) ) {
				break;
			}
		}

		if ( $intermediaryWidth <= $widthOfFinalThumb + $wgThumbnailMinimumBucketDistance || $rx < 2 ) {
			// Need to scale the original full sized thumb
			return false;
		}

		static $isInThisFunction;

		if ( $isInThisFunction ) {
			// Sanity check, should never be reached
			throw new Exception( "Loop detected in " . __METHOD__ );
		}
		$isInThisFunction = true;

		$newParams = array(
			'width' => $intermediaryWidth,
			'page' => $page,
			// Render a png, to avoid loss of quality when doing multi-step
			'lossy' => 'lossless'
		);

		// RENDER_NOW causes rendering in this process if
		// thumb doesn't exist, but unlike RENDER_FORCE, will return
		// a cached thumb if available.
		$mto = $file->transform( $newParams, File::RENDER_NOW );

		$isInThisFunction = false;
		return $mto;
	}
}
