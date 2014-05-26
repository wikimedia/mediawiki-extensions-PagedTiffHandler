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

	function setUp() {
		parent::setUp();
		$this->handler = new PagedTiffHandler();
		
		$this->multipage_path = $this->filePath . '/multipage.tiff';
		$this->truncated_path = $this->filePath . '/truncated.tiff';
		$this->mhz_path = $this->filePath . '/380mhz.tiff';
		$this->test_path = $this->filePath . '/test.tif';


		$this->multipage_image = $this->dataFile( 'multipage.tiff', 'image/tiff' );
		$this->truncated_image = $this->dataFile( 'truncated.tiff', 'image/tiff' );
		$this->mhz_image = $this->dataFile( '380mhz.tiff', 'image/tiff' );
		$this->test_image = $this->dataFile( 'test.tiff', 'image/tiff' );
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
		// Note: Originally this was testing for 'tiff_bad_file' with missing parameter,
		// but that's not the behaviour observed. 'tiff_no_metadata' was what it was actually
		// doing, which seems to make more sense than a message without all parameters replaced, so
		// I changed the test.
		$this->assertEquals(  wfMessage( 'thumbnail_error', wfMessage( 'tiff_no_metadata' )->text() )->text(), $error->toText() );
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

}
