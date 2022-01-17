<?php

use MediaWiki\Extension\PagedTiffHandler\PagedTiffHandler;

/**
 * @covers MediaWiki\Extension\PagedTiffHandler\PagedTiffHandler
 */
class PagedTiffHandlerTest extends MediaWikiMediaTestCase {

	/** @var PagedTiffHandler */
	private $handler;

	/** @var string Paths to various test files. */
	private $multipage_path, $truncated_path, $mhz_path, $test_path, $large_path;

	/** @var UnregisteredLocalFile File objects for various test files. */
	private $multipage_image, $truncated_image, $mhz_image, $test_image, $large_image;

	/**
	 * Inform parent class where test files are located
	 * @return string
	 */
	protected function getFilePath() {
		return __DIR__ . '/testImages';
	}

	/**
	 * Set up file repo for thumbnails
	 * @inheritDoc
	 */
	protected function createsThumbnails() {
		return true;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new PagedTiffHandler();

		$this->multipage_path = $this->filePath . '/multipage.tiff';
		$this->truncated_path = $this->filePath . '/truncated.tiff';
		$this->mhz_path = $this->filePath . '/380mhz.tiff';
		$this->test_path = $this->filePath . '/test.tif';
		$this->large_path = $this->filePath . '/large.tiff';

		$this->multipage_image = $this->dataFile( 'multipage.tiff', 'image/tiff' );
		$this->truncated_image = $this->dataFile( 'truncated.tiff', 'image/tiff' );
		$this->mhz_image = $this->dataFile( '380mhz.tiff', 'image/tiff' );
		$this->test_image = $this->dataFile( 'test.tiff', 'image/tiff' );
		$this->large_image = $this->dataFile( 'large.tiff', 'image/tiff' );

		// Max 50 Megapixels like on WMF
		$this->setMwGlobals( 'wgMaxImageArea', 5e7 );
		$this->setMwGlobals( 'wgTiffIntermediaryScaleStep', 2048 );
	}

	public function testGetMetadataArray() {
		/** @var PagedTiffHandler $handler */
		$metaArray = $this->mhz_image->getMetadataArray();
		if ( !empty( $metaArray['errors'] ) ) {
			$this->fail( implode( '; ', $metaArray['error'] ) );
		}
		$this->assertSame( 1, $metaArray['page_count'] );

		$this->assertEquals( 'true', strtolower( $metaArray['page_data'][1]['alpha'] ) );

		$metaArray = $this->multipage_image->getMetadataArray();
		if ( !empty( $metaArray['errors'] ) ) {
			$this->fail( implode( '; ', $metaArray['error'] ) );
		}
		$this->assertEquals( 7, $metaArray['page_count'] );

		$this->assertEquals( 'false', strtolower( $metaArray['page_data'][1]['alpha'] ) );
		$this->assertEquals( 'true', strtolower( $metaArray['page_data'][2]['alpha'] ) );

		$interp = $metaArray['exif']['PhotometricInterpretation'];
		$this->assertTrue( $interp == 2 || $interp == 'RGB' ); // RGB
	}

	public function testValidateParam() {
		// ---- Parameter handling and lossy parameter
		$this->assertTrue( $this->handler->validateParam( 'lossy', '0' ) );
		$this->assertTrue( $this->handler->validateParam( 'lossy', '1' ) );
		$this->assertTrue( $this->handler->validateParam( 'lossy', 'false' ) );
		$this->assertTrue( $this->handler->validateParam( 'lossy', 'true' ) );
		$this->assertTrue( $this->handler->validateParam( 'lossy', 'lossy' ) );
		$this->assertTrue( $this->handler->validateParam( 'lossy', 'lossless' ) );

		$this->assertTrue( $this->handler->validateParam( 'width', '60000' ) );
		$this->assertTrue( $this->handler->validateParam( 'height', '60000' ) );
		$this->assertTrue( $this->handler->validateParam( 'page', '60000' ) );

		$this->assertFalse( $this->handler->validateParam( 'lossy', '' ) );
		$this->assertFalse( $this->handler->validateParam( 'lossy', 'quark' ) );

		$this->assertFalse( $this->handler->validateParam( 'width', '160000' ) );
		$this->assertFalse( $this->handler->validateParam( 'height', '160000' ) );
		$this->assertFalse( $this->handler->validateParam( 'page', '160000' ) );

		$this->assertFalse( $this->handler->validateParam( 'width', '0' ) );
		$this->assertFalse( $this->handler->validateParam( 'height', '0' ) );
		$this->assertFalse( $this->handler->validateParam( 'page', '0' ) );

		$this->assertFalse( $this->handler->validateParam( 'width', '-1' ) );
		$this->assertFalse( $this->handler->validateParam( 'height', '-1' ) );
		$this->assertFalse( $this->handler->validateParam( 'page', '-1' ) );
	}

	public function testNormaliseParams() {
		// here, boxfit behavior is tested
		$params = [ 'width' => '100', 'height' => '100', 'page' => '4' ];
		$this->assertTrue( $this->handler->normaliseParams( $this->multipage_image, $params ) );
		$this->assertEquals( 75, $params['height'] );

		// lossy and lossless
		$params = [ 'width' => '100', 'height' => '100', 'page' => '1' ];
		$this->handler->normaliseParams( $this->multipage_image, $params );
		$this->assertEquals( 'lossy', $params['lossy'] );

		$params = [ 'width' => '100', 'height' => '100', 'page' => '2' ];
		$this->handler->normaliseParams( $this->multipage_image, $params );
		$this->assertEquals( 'lossless', $params['lossy'] );

		// single page
		$params = [ 'width' => '100', 'height' => '100', 'page' => '1' ];
		$this->handler->normaliseParams( $this->mhz_image, $params );
		$this->assertEquals( 'lossless', $params['lossy'] );
	}

	public function testMakeParamString() {
		$this->assertEquals(
			'lossless-page4-100px',
			$this->handler->makeParamString(
				[
					'width' => '100',
					'page' => '4',
					'lossy' => 'lossless'
				]
			)
		);
	}

	public function testVerifyUpload() {
		// ---- File upload checks.
		// check
		// TODO: check other images
		$status = $this->handler->verifyUpload( $this->multipage_path );
		$this->assertTrue( $status->isGood() );

		$status = $this->handler->verifyUpload( $this->truncated_path );
		$this->assertFalse( $status->isGood() );
		$this->assertTrue( $status->hasMessage( 'tiff_bad_file' ) );
	}

	public function testDoTransform() {
		$multipageThumbFile = $this->getNewTempFile();
		$result = $this->handler->doTransform(
			$this->multipage_image,
			$multipageThumbFile,
			'Test.tif',
			[ 'width' => 100, 'height' => 100 ]
		);
		$this->assertFalse( $result->isError() );
		$this->assertFileExists( $multipageThumbFile );
		$this->assertGreaterThan( 0, filesize( $multipageThumbFile ) );

		$truncatedThumbFile = $this->getNewTempFile();
		$error = $this->handler->doTransform(
			$this->truncated_image,
			$truncatedThumbFile,
			'Truncated.tiff',
			[ 'width' => 100, 'height' => 100 ]
		);
		$this->assertTrue( $error->isError() );
	}

	public function testDoTransformLarge() {
		// Artificially make this small. Jenkins kept OOMing on
		// big images.
		$this->setMwGlobals( 'wgTiffIntermediaryScaleStep', 480 );

		$params = [ 'width' => 120, 'lossy' => 'lossy' ];
		$thumb = $this->large_image->transform( $params, File::RENDER_FORCE );
		$this->assertFalse(
			$thumb->isError(),
			"Error rendering thumbnail: " . ( $thumb->isError() ? $thumb->toText() : '' )
		);
		$this->assertFileExists( $thumb->getLocalCopyPath() );
		$this->assertGreaterThan( 0, filesize( $thumb->getLocalCopyPath() ) );

		// Verify that an intermediate thumb was actually made
		$intermediateParams = [ 'width' => 500, 'lossy' => 'lossless' ];
		$this->large_image->getHandler()->normaliseParams(
			$this->large_image, $intermediateParams
		);

		$thumbName = $this->large_image->thumbName( $intermediateParams );
		$thumbPath = $this->large_image->getThumbPath( $thumbName );

		$this->assertTrue( $this->repo->fileExists( $thumbPath ), "Intermediate thumb missing" );
	}

	public function testGetThumbType() {
		// ---- Image information
		$type = $this->handler->getThumbType( '.tiff', 'image/tiff', [ 'lossy' => 'lossy' ] );
		$this->assertEquals( 'jpg', $type[0] );
		$this->assertEquals( 'image/jpeg', $type[1] );

		$type = $this->handler->getThumbType( '.tiff', 'image/tiff', [ 'lossy' => 'lossless' ] );
		$this->assertEquals( 'png', $type[0] );
		$this->assertEquals( 'image/png', $type[1] );
	}

	public function testPageCount() {
		$this->assertEquals( 7, $this->handler->pageCount( $this->multipage_image ) );
		$this->assertSame( 1, $this->handler->pageCount( $this->mhz_image ) );
	}

	public function testGetPageDimensions() {
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 768 ],
			$this->handler->getPageDimensions( $this->multipage_image, 0 ),
			"multipage, page 0"
		);
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 768 ],
			$this->handler->getPageDimensions( $this->multipage_image, 1 ),
			"multipage, page 1"
		);
		$this->assertEquals(
			[ 'width' => 640, 'height' => 564 ],
			$this->handler->getPageDimensions( $this->multipage_image, 2 ),
			"multipage, page 2"
		);
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 563 ],
			$this->handler->getPageDimensions( $this->multipage_image, 3 ),
			"multipage, page 3"
		);
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 768 ],
			$this->handler->getPageDimensions( $this->multipage_image, 4 ),
			"multipage, page 4"
		);
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 768 ],
			$this->handler->getPageDimensions( $this->multipage_image, 5 ),
			"multipage, page 5"
		);
		$this->assertEquals(
			[ 'width' => 1024, 'height' => 768 ],
			$this->handler->getPageDimensions( $this->multipage_image, 6 ),
			"multipage, page 6" );
		$this->assertEquals(
			$this->handler->getPageDimensions( $this->multipage_image, 7 ),
			[ 'width' => 768, 'height' => 1024 ],
			"multipage, page 7"
		);

		$this->assertEquals(
			[ 'width' => 643, 'height' => 452 ],
			$this->handler->getPageDimensions( $this->mhz_image, 0 ),
			"multipage, page 8"
		);

		// return dimensions of last page if page number is too high
		$this->assertEquals(
			[ 'width' => 768, 'height' => 1024 ],
			$this->handler->getPageDimensions( $this->multipage_image, 8 ),
			"multipage out of range"
		);
		$this->assertEquals(
			[ 'width' => 643, 'height' => 452 ],
			$this->handler->getPageDimensions( $this->mhz_image, 1 ),
			"mhz"
		);
	}

	public function testIsMultiPage() {
		$this->assertTrue( $this->handler->isMultiPage( $this->multipage_image ) );
		$this->assertTrue( $this->handler->isMultiPage( $this->mhz_image ) );
	}

	public function testFormatMetadata() {
		$formattedMetadata = $this->handler->formatMetadata( $this->multipage_image );

		foreach ( $formattedMetadata['collapsed'] as $k => $e ) {
			if ( $e['id'] == 'exif-photometricinterpretation' ) {
				$this->assertEquals( 'RGB', $e['value'] );
			}
		}
	}

	/**
	 * This is only testing the boolean output. There's some other
	 * tests above for the other behaviours of normaliseParams.
	 */
	public function testNormaliseParamsBoolean() {
		// Make max image area bigger than one test file, smaller than the other
		$this->setMwGlobals( 'wgMaxImageArea', 500000 );

		$params = [];
		$this->assertFalse(
			$this->handler->normaliseParams( $this->mhz_image, $params ),
			"no width"
		);

		$params = [ 'width' => '50' ];
		$this->assertTrue(
			$this->handler->normaliseParams( $this->mhz_image, $params ),
			"normal scale"
		);

		// Should normalise but still return true
		$params = [ 'width' => '100000000' ];
		$this->assertTrue(
			$this->handler->normaliseParams( $this->mhz_image, $params ),
			"normal scale"
		);

		/* FIXME: broken
		// Why should this return false?
		$params = [ 'width' => '50' ];
		$this->assertFalse(
			$this->handler->normaliseParams( $this->multipage_image, $params ),
			"Image > max area"
		);
		*/

		/* FIXME: broken
		// This should normalise the page, but still return true
		$params = [ 'page' => '50' ];
		$this->assertTrue(
			$this->handler->normaliseParams( $this->mhz_image, $params ),
			"page out of range"
		);
		*/
	}

	/**
	 * We want to make sure that if a file is too big, it errors before downloading
	 * the file, not after.
	 *
	 * The main assertion of this test is that no exception was thrown.
	 */
	public function testTransformTooBig() {
		$file = $this->getMockTiffFile( 'multipage.tiff', [ 8000, 8000 ] );
		$file->expects( $this->never() )->method( 'getLocalRefPath' );
		$tempFile = $this->getNewTempFile();
		$result = $this->handler->doTransform(
			$file,
			$tempFile,
			'out.tif',
			[ 'width' => 100, 'height' => 100 ]
		);
		$this->assertTrue( $result->isError() );
	}

	/**
	 * The file is small enough so it should get transformed
	 */
	public function testTransformNotTooBig() {
		$tempFile = $this->getNewTempFile();
		$file = $this->getMockTiffFile( 'multipage.tiff', [ 1000, 1000 ] );
		// We however don't want to actually transform it, as that is unnessary so return false.
		$file->expects( $this->once() )
			->method( 'getLocalRefPath' )
			->willReturn( false );
		$result = $this->handler->doTransform(
			$file,
			$tempFile,
			'out.tif',
			[ 'width' => 100, 'height' => 100 ]
		);
		$this->assertFalse( !$result->isError() );
	}

	/**
	 * Get a file that will throw an exception when fetched
	 * @param string $name
	 * @param array $dim
	 * @return File
	 */
	private function getMockTiffFile( $name, $dim ) {
		$file = $this->getMockBuilder( UnregisteredLocalFile::class )
			->onlyMethods( [ 'getWidth', 'getHeight', 'getMetadataArray', 'getLocalRefPath' ] )
			->setConstructorArgs( [
				false,
				$this->repo,
				"mwstore://localtesting/data/$name",
				'image/tiff'
			] )
			->getMock();
		$file->expects( $this->any() )->method( 'getWidth' )->will( $this->returnValue( $dim[0] ) );
		$file->expects( $this->any() )->method( 'getHeight' )->will( $this->returnValue( $dim[1] ) );
		$metadata = [
			'page_data' => [
				1 => [
					'width' => 1024,
					'height' => 768,
					'page' => 1,
					'alpha' => 'false',
					'pixels' => 786432,
				],
				2 => [
					'width' => 640,
					'height' => 564,
					'alpha' => 'true',
					'page' => 2,
					'pixels' => 360960,
				],
				3 => [
					'width' => 1024,
					'height' => 563,
					'page' => 3,
					'alpha' => 'false',
					'pixels' => 576512,
				],
				4 => [
					'width' => 1024,
					'height' => 768,
					'page' => 4,
					'alpha' => 'false',
					'pixels' => 786432,
				],
				5 => [
					'width' => 1024,
					'height' => 768,
					'page' => 5,
					'alpha' => 'false',
					'pixels' => 786432,
				],
				6 => [
					'width' => 1024,
					'height' => 768,
					'page' => 6,
					'alpha' => 'false',
					'pixels' => 786432,
				],
				7 => [
					'width' => 768,
					'height' => 1024,
					'page' => 7,
					'alpha' => 'false',
					'pixels' => 786432,
				],
			],
			'page_count' => 7,
			'first_page' => 1,
			'last_page' => 7,
			'exif' => [
				'ImageWidth' => 1024,
				'ImageLength' => 768,
				'BitsPerSample' => [ 8, 8, 8 ],
				'Compression' => 7,
				'PhotometricInterpretation' => 2,
				'Orientation' => 1,
				'SamplesPerPixel' => 3,
				'RowsPerStrip' => 16,
				'XResolution' => '1207959552/16777216',
				'YResolution' => '1207959552/16777216',
				'PlanarConfiguration' => 1,
				'ResolutionUnit' => 2,
				'Software' => 'ImageMagick 6.5.0-0 2009-03-09 Q16 OpenMP http://www.imagemagick.org',
				'YCbCrSubSampling' => [ 1, 1 ],
				'MEDIAWIKI_EXIF_VERSION' => 2,
			],
			'TIFF_METADATA_VERSION' => '1.4',
		];
		$file->expects( $this->any() )->method( 'getMetadataArray' )->will( $this->returnValue( $metadata ) );
		return $file;
	}
}
