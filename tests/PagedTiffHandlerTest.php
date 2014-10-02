<?php
class PagedTiffHandlerTest extends MediaWikiMediaTestCase {

	/** @var PagedTiffHandler */
	private $handler;

	/** @var string Paths to various test files. */
	private $multipage_path, $truncated_path, $mhz_path, $test_path;

	/** @var UnregisteredFile File objects for various test files. */
	private $multipage_image, $truncated_image, $mhz_image, $test_image;

	/**
	 * Inform parent class where test files are located
	 */
	function getFilePath() {
		return __DIR__ . '/testImages';
	}

	/**
	 * Set up file repo for thumbnails
	 */
	protected function createsThumbnails() {
		return true;
	}

	function setUp() {
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
	
	function testMetadata() {
		// ---- Metdata initialization
		$this->handler->getMetadata( $this->multipage_image, $this->multipage_path );
		$this->handler->getMetadata( $this->truncated_image, $this->truncated_path );


		// ---- Metadata handling
		// getMetadata
		$metadata =  $this->handler->getMetadata( false, $this->multipage_path );
		$this->assertTrue( strpos( $metadata, '"page_count";i:7' ) !== false );
		$this->assertTrue( $this->handler->isMetadataValid( $this->multipage_image, $metadata ) );

		$metadata =  $this->handler->getMetadata( false, $this->mhz_path );
		$this->assertTrue( strpos( $metadata, '"page_count";i:1' ) !== false );
		$this->assertTrue( $this->handler->isMetadataValid( $this->mhz_image, $metadata ) );
	}

	function testGetMetaArray() {

		// getMetaArray
		$metaArray = $this->handler->getMetaArray( $this->mhz_image );
		if ( !empty( $metaArray['errors'] ) ) $this->fail( join('; ', $metaArray['error']) );
		$this->assertEquals( $metaArray['page_count'], 1 );

		$this->assertEquals( strtolower( $metaArray['page_data'][1]['alpha'] ), 'true' );

		$metaArray = $this->handler->getMetaArray( $this->multipage_image );
		if ( !empty( $metaArray['errors'] ) ) $this->fail( join('; ', $metaArray['error']) );
		$this->assertEquals( $metaArray['page_count'], 7 );

		$this->assertEquals( strtolower( $metaArray['page_data'][1]['alpha'] ), 'false' );
		$this->assertEquals( strtolower( $metaArray['page_data'][2]['alpha'] ), 'true' );

		$interp = $metaArray['exif']['PhotometricInterpretation'];
		$this->assertTrue( $interp == 2 || $interp == 'RGB' ); //RGB

	}

	function testValidateParam() {
		// ---- Parameter handling and lossy parameter
		// validateParam
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
	function testNormaliseParams() {
		// normaliseParams
		// here, boxfit behavior is tested
		$params = array( 'width' => '100', 'height' => '100', 'page' => '4' );
		$this->assertTrue( $this->handler->normaliseParams( $this->multipage_image, $params ) );
		$this->assertEquals( $params['height'], 75 );

		// lossy and lossless
		$params = array('width'=>'100', 'height'=>'100', 'page'=>'1');
		$this->handler->normaliseParams($this->multipage_image, $params );
		$this->assertEquals($params['lossy'], 'lossy');

		$params = array('width'=>'100', 'height'=>'100', 'page'=>'2');
		$this->handler->normaliseParams($this->multipage_image, $params );
		$this->assertEquals($params['lossy'], 'lossless');

		// single page
		$params = array('width'=>'100', 'height'=>'100', 'page'=>'1');
		$this->handler->normaliseParams($this->mhz_image, $params );
		$this->assertEquals($params['lossy'], 'lossless');
	}
	function testMakeParamString() {

		// makeParamString
		$this->assertEquals(
			$this->handler->makeParamString(
				array(
					'width' => '100',
					'page' => '4',
					'lossy' => 'lossless'
				)
			),
			'lossless-page4-100px'
		);
	}

	function testVerifyUpload() {
		// ---- File upload checks.
		// check
		// TODO: check other images
		$status = $this->handler->verifyUpload( $this->multipage_path );
		$this->assertTrue( $status->isGood() );

		$status = $this->handler->verifyUpload( $this->truncated_path );
		$this->assertFalse( $status->isGood() );
		$this->assertTrue( $status->hasMessage( 'tiff_bad_file' ) );
	}

	function testDoTransform() {
		$multipageThumbFile = $this->getNewTempFile();
		// doTransform
		$result = $this->handler->doTransform( $this->multipage_image, $multipageThumbFile, 'Test.tif', array( 'width' => 100, 'height' => 100 ) );
		$this->assertFalse( $result->isError() );
		$this->assertFileExists( $multipageThumbFile );
		$this->assertGreaterThan( 0, filesize( $multipageThumbFile ) );

		$truncatedThumbFile = $this->getNewTempFile();
		$error = $this->handler->doTransform( $this->truncated_image, $truncatedThumbFile, 'Truncated.tiff', array( 'width' => 100, 'height' => 100 ) );
		$this->assertTrue( $error->isError() );
	}

	function testDoTransformLarge() {
		// Artificially make this small. Jenkins kept OOMing on
		// big images.
		$this->setMwGlobals( 'wgTiffIntermediaryScaleStep', 480 );

		$params = array( 'width' => 120, 'lossy' => 'lossy' );
		$thumb = $this->large_image->transform( $params, File::RENDER_FORCE );
		$this->assertFalse( $thumb->isError(), "Error rendering thumbnail: " . ( $thumb->isError() ? $thumb->toText() : '' ) );
		$this->assertFileExists( $thumb->getLocalCopyPath() );
		$this->assertGreaterThan( 0, filesize( $thumb->getLocalCopyPath() ) );

		// Verify that an intermediate thumb was actually made
		$intermediateParams = array( 'width' => 500, 'lossy' => 'lossless' );
		$this->large_image->getHandler()->normaliseParams( $this->large_image, $intermediateParams );

		$thumbName = $this->large_image->thumbName( $intermediateParams );
		$thumbPath = $this->large_image->getThumbPath( $thumbName );

		$this->assertTrue( $this->repo->fileExists( $thumbPath ), "Intermediate thumb missing" );

	}

	function testGetThumbType() {
		// ---- Image information
		// getThumbType
		$type = $this->handler->getThumbType( '.tiff', 'image/tiff', array( 'lossy' => 'lossy' ) );
		$this->assertEquals( $type[0], 'jpg' );
		$this->assertEquals( $type[1], 'image/jpeg' );

		$type = $this->handler->getThumbType( '.tiff', 'image/tiff', array( 'lossy' => 'lossless' ) );
		$this->assertEquals( $type[0], 'png' );
		$this->assertEquals( $type[1], 'image/png' );
	}

	function testGetLongDesc() {
		// English
		$this->assertEquals( $this->handler->getLongDesc( $this->multipage_image ), wfMessage( 'tiff-file-info-size', '1,024', '768', '2.64 MB', 'image/tiff', '7' )->text() );
	}

	function testPageCount() {
		// pageCount
		$this->assertEquals( $this->handler->pageCount( $this->multipage_image ), 7 );
		$this->assertEquals( $this->handler->pageCount( $this->mhz_image ), 1 );
	}

	function testGetPageDimensions() {

		// getPageDimensions
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 0 ), array( 'width' => 1024, 'height' => 768 ), "multipage, page 0" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 1 ), array( 'width' => 1024, 'height' => 768 ), "multipage, page 1" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 2 ), array( 'width' => 640, 'height' => 564 ), "multipage, page 2" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 3 ), array( 'width' => 1024, 'height' => 563 ), "multipage, page 3" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 4 ), array( 'width' => 1024, 'height' => 768 ), "multipage, page 4" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 5 ), array( 'width' => 1024, 'height' => 768 ), "multipage, page 5" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 6 ), array( 'width' => 1024, 'height' => 768 ), "multipage, page 6" );
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 7 ), array( 'width' => 768, 'height' => 1024 ), "multipage, page 7" );

		$this->assertEquals( $this->handler->getPageDimensions( $this->mhz_image, 0 ), array( 'width' => 643, 'height' => 452 ), "multipage, page 8" );

		// return dimensions of last page if page number is too high
		$this->assertEquals( $this->handler->getPageDimensions( $this->multipage_image, 8 ), array( 'width' => 768, 'height' => 1024 ), "multipage out of range");
		$this->assertEquals( $this->handler->getPageDimensions( $this->mhz_image, 1 ), array( 'width' => 643, 'height' => 452 ), "mhz" );
	}

	function testIsMultiPage() {
		// isMultiPage
		$this->assertTrue( $this->handler->isMultiPage( $this->multipage_image ) );
		$this->assertTrue( $this->handler->isMultiPage( $this->mhz_image ) );

	}

	function testFormatMetadata() {

		// formatMetadata
		$formattedMetadata = $this->handler->formatMetadata( $this->multipage_image );

		foreach (  $formattedMetadata['collapsed'] as $k => $e ) {
			if ( $e['id'] == 'exif-photometricinterpretation' ) {
				$this->assertEquals( $e['value'], 'RGB' ); 
			}
		}
	}

	/**
	 * This is only testing the boolean output. There's some other
	 * tests above for the other behaviours of normaliseParams.
	 * @dataProvider normaliseProvider
	 */
	function normaliseParamsBooleanTest( $file, $params, $expectedResult, $testName ) {
		global $wgMaxImageArea;

		// Make max image area bigger than one test file, smaller than the other
		$oldMaxArea = $wgMaxImageArea;
		$wgMaxImageArea = 5e5;
		$actualResult = $this->handler->normaliseParams( $file, $params );
		$wgMaxImageArea = $oldMaxArea;

		$this->assertEquals( $actualResult, $expectedResult, $testName );
	}

	function normaliseProvider() {
		return array(
			array( $this->mhz_image, array(), false, "no width" ),
			array( $this->mhz_image, array( 'width' => '50' ), true, "normal scale" ),
			// Should normalise but still return true
			array( $this->mhz_image, array( 'width' => '100000000' ), true, "normal scale" ),
			array( $this->multipage_image, array( 'width' => '50' ), false, "Image > max area" ),
			// This should normalise the page, but still return true
			array( $this->mhz_image, array( 'page' => '50' ), true, "page out of range" ),
		);
	}

	/**
	 * We want to make sure that if a file is too big, it errors before downloading
	 * the file, not after.
	 *
	 * The main assertion of this test is that no exception was thrown.
	 */
	function testTransformTooBig() {
		$file = $this->getMockTiffFile( 'multipage.tiff', array( 8000, 8000 ) );
		$file->expects( $this->never() )->method( 'getLocalRefPath' );
		$tempFile = $this->getNewTempFile();
		$result = $this->handler->doTransform( $file, $tempFile, 'out.tif', array( 'width' => 100, 'height' => 100 ) );
		$this->assertTrue( $result->isError() );
	}

	/**
	 * The file is small enough so it should get transformed
	 */
	function testTransformNotTooBig() {
		$tempFile = $this->getNewTempFile();
		$file = $this->getMockTiffFile( 'multipage.tiff', array( 1000, 1000 ) );
		// We however don't want to actually transform it, as that is unnessary so return false.
		$file->expects( $this->once() )->method( 'getLocalRefPath' )->will( $this->returnValue( false ) );
		$result = $this->handler->doTransform( $file, $tempFile, 'out.tif', array( 'width' => 100, 'height' => 100 ) );
	}

	/**
	 * Get a file that will throw an exception when fetched
	 */
	private function getMockTiffFile( $name, $dim ) {
		$file = $this->getMock( 'UnregisteredLocalFile',
			array( 'getWidth', 'getHeight', 'getMetadata', 'getLocalRefPath' ),
			array( false, $this->repo, "mwstore://localtesting/data/$name", 'image/tiff' )
		);
		$file->expects( $this->any() )->method( 'getWidth' )->will( $this->returnValue( $dim[0] ) );
		$file->expects( $this->any() )->method( 'getHeight' )->will( $this->returnValue( $dim[1] ) );
		$metadata = 'a:6:{s:9:"page_data";a:7:{i:1;a:5:{s:5:"width";i:1024;s:6:"height";i:768;s:4:"page";i:1;s:5:"alpha";s:5:"false";s:6:"pixels";i:786432;}i:2;a:5:{s:5:"width";i:640;s:6:"height";i:564;s:5:"alpha";s:4:"true";s:4:"page";i:2;s:6:"pixels";i:360960;}i:3;a:5:{s:5:"width";i:1024;s:6:"height";i:563;s:4:"page";i:3;s:5:"alpha";s:5:"false";s:6:"pixels";i:576512;}i:4;a:5:{s:5:"width";i:1024;s:6:"height";i:768;s:4:"page";i:4;s:5:"alpha";s:5:"false";s:6:"pixels";i:786432;}i:5;a:5:{s:5:"width";i:1024;s:6:"height";i:768;s:4:"page";i:5;s:5:"alpha";s:5:"false";s:6:"pixels";i:786432;}i:6;a:5:{s:5:"width";i:1024;s:6:"height";i:768;s:4:"page";i:6;s:5:"alpha";s:5:"false";s:6:"pixels";i:786432;}i:7;a:5:{s:5:"width";i:768;s:6:"height";i:1024;s:4:"page";i:7;s:5:"alpha";s:5:"false";s:6:"pixels";i:786432;}}s:10:"page_count";i:7;s:10:"first_page";i:1;s:9:"last_page";i:7;s:4:"exif";a:15:{s:10:"ImageWidth";i:1024;s:11:"ImageLength";i:768;s:13:"BitsPerSample";a:3:{i:0;i:8;i:1;i:8;i:2;i:8;}s:11:"Compression";i:7;s:25:"PhotometricInterpretation";i:2;s:11:"Orientation";i:1;s:15:"SamplesPerPixel";i:3;s:12:"RowsPerStrip";i:16;s:11:"XResolution";s:19:"1207959552/16777216";s:11:"YResolution";s:19:"1207959552/16777216";s:19:"PlanarConfiguration";i:1;s:14:"ResolutionUnit";i:2;s:8:"Software";s:68:"ImageMagick 6.5.0-0 2009-03-09 Q16 OpenMP http://www.imagemagick.org";s:16:"YCbCrSubSampling";a:2:{i:0;i:1;i:1;i:1;}s:22:"MEDIAWIKI_EXIF_VERSION";i:2;}s:21:"TIFF_METADATA_VERSION";s:3:"1.4";}';
		$file->expects( $this->any() )->method( 'getMetadata' )->will( $this->returnValue( $metadata ) );
		return $file;
	}
}

