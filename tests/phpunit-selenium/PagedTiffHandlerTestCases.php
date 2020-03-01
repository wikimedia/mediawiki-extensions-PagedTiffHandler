<?php

class SeleniumCheckPrerequisites extends SeleniumTestCase {
	public $name = 'Check prerequisites';
	private $prerequisiteError = null;

	public function runTest() {
		// check whether Multipage.tiff is already uploaded
		$this->open( $this->getUrl() .
			'/index.php?title=Image:Multipage.tiff' );

		$source = $this->getAttribute( "//div[@id='bodyContent']//ul@id" );
		if ( $source != 'filetoc' ) {
			$this->prerequisiteError = 'Image:Multipage.tiff must exist.';
		}

		// Check for language
		$this->open( $this->getUrl() .
			'/api.php?action=query&meta=userinfo&uiprop=options&format=xml' );

		$lang = $this->getAttribute( "//options/@language" );
		if ( $lang != 'en' ) {
			$this->prerequisiteError =
				'interface language must be set to' .
				' English (en), but was ' . $lang . '.';
		}
	}

	public function tearDown() : void {
		if ( $this->prerequisiteError ) {
			// $this->selenium->stop();
			throw new Exception( 'failed: ' . $this->prerequisiteError . "\n" );
		}
	}
}

class SeleniumUploadTiffTest extends SeleniumTestCase {
	public function uploadFile( $filename ) {
		$this->open( $this->getUrl() .
			'/index.php?title=Special:Upload' );
		$this->type( 'wpUploadFile', __DIR__ .
			"\\testImages\\" . $filename );
		$this->check( 'wpIgnoreWarning' );
		$this->click( 'wpUpload' );
		$this->waitForPageToLoad( 30000 );
	}

	public function assertUploaded( $filename ) {
		$this->assertSeleniumHTMLContains(
			'//h1[@class="firstHeading"]', ucfirst( $filename ) );
	}

	public function assertErrorMsg( $msg ) {
		$this->assertSeleniumHTMLContains(
			'//div[@id="bodyContent"]//span[@class="error"]', $msg );
	}

}

class SeleniumUploadWorkingTiffTest extends SeleniumUploadTiffTest {
	public $name = 'Upload working Tiff: ';
	private $filename;

	public function __construct( $filename ) {
		parent::__construct();
		$this->filename = $filename;
		$this->name .= $filename;
	}

	public function runTest() {
		$this->uploadFile( $this->filename );
		$this->assertUploaded( str_replace( '_', ' ', $this->filename ) );
	}
}

class SeleniumUploadBrokenTiffTest extends SeleniumUploadTiffTest {
	public $name = 'Upload broken Tiff: ';
	private $filename;
	private $errorMsg;

	public function __construct( $filename, $errorMsg ) {
		parent::__construct();
		$this->filename = $filename;
		$this->name .= $filename;
		$this->errorMsg = $errorMsg;
	}

	public function runTest() {
		$this->uploadFile( $this->filename );
		$this->assertErrorMsg( $this->errorMsg );
	}
}

class SeleniumDeleteTiffTest extends SeleniumTestCase {
	public $name = 'Delete Tiff: ';
	private $filename;

	public function __construct( $filename ) {
		parent::__construct();
		$this->filename = $filename;
		$this->name .= $filename;
	}

	public function runTest() {
		$this->open( $this->getUrl() . '/index.php?title=Image:'
			. ucfirst( $this->filename ) . '&action=delete' );
		$this->type( 'wpReason', 'Remove test file' );
		$this->click( 'mw-filedelete-submit' );
		$this->waitForPageToLoad( 10000 );

		// Todo: This message is localized
		$this->assertSeleniumHTMLContains( '//div[@id="bodyContent"]/p',
			ucfirst( $this->filename ) . '.*has been deleted.' );
	}

}

class SeleniumEmbedTiffTest extends SeleniumTestCase {

	public function tearDown() : void {
		parent::tearDown();
		// Clear EmbedTiffTest page for future tests
		$this->open( $this->getUrl() .
			'/index.php?title=EmbedTiffTest&action=edit' );
		$this->type( 'wpTextbox1', '' );
		$this->click( 'wpSave' );
	}

	public function preparePage( $text ) {
		$this->open( $this->getUrl() .
			'/index.php?title=EmbedTiffTest&action=edit' );
		$this->type( 'wpTextbox1', $text );
		$this->click( 'wpSave' );
		$this->waitForPageToLoad( 10000 );
	}

}

class SeleniumTiffPageTest extends SeleniumTestCase {
	public function tearDown() : void {
		parent::tearDown();
		// Clear EmbedTiffTest page for future tests
		$this->open( $this->getUrl() . '/index.php?title=Image:'
			. $this->image . '&action=edit' );
		$this->type( 'wpTextbox1', '' );
		$this->click( 'wpSave' );
	}

	public function prepareImagePage( $image, $text ) {
		$this->image = $image;
		$this->open( $this->getUrl() . '/index.php?title=Image:'
				. $image . '&action=edit' );
		$this->type( 'wpTextbox1', $text );
		$this->click( 'wpSave' );
		$this->waitForPageToLoad( 10000 );
	}
}

class SeleniumDisplayInCategoryTest extends SeleniumTiffPageTest {
	public $name = 'Display in category';

	public function runTest() {
		$this->prepareImagePage( 'Multipage.tiff',
			"[[Category:Wiki]]\n" );

		$this->open( $this->getUrl() . '/index.php?title=Category:Wiki' );

		// Ergebnis chekcen
		$source = $this->getAttribute(
			"//li[@class='gallerybox']//a[@class='image']//img@src" );
		$correct = strstr( $source, "-page1-" );
		$this->assertTrue( $correct );
	}
}

class SeleniumDisplayInGalleryTest extends SeleniumEmbedTiffTest {
	public $name = 'Display in gallery';

	public function runTest() {
		$this->preparePage( "<gallery>\nImage:Multipage.tiff\n</gallery>\n" );

		// $this->open( Selenium::getBaseUrl() . '/index.php?title=GalleryTest' );

		// Ergebnis chekcen
		// $source = $this->getAttribute(
		// "//div[@class='gallerybox']//a[@title='Multipage.tiff']//img@src" );
		$source = $this->getAttribute(
			"//li[@class='gallerybox']//a[@class='image']//img@src" );
		$correct = strstr( $source, "-page1-" );
		$this->assertTrue( $correct );
	}
}

class SeleniumEmbedTiffInclusionTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff Images';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '768' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '1024' );
	}
}

class SeleniumEmbedTiffThumbRatioTest extends SeleniumEmbedTiffTest {
	public $name = "Include Tiff Thumbnail Aspect Ratio";

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|200px]]\n" );
		// $this->selenium->type( 'wpTextbox1',
		// "[[Image:Pc260001.tif|thumb]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '150' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '200' );
	}
}

class SeleniumEmbedTiffBoxFitTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff Box Fit';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|200x75px]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '75' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '100' );
	}
}

class SeleniumEmbedTiffPage2InclusionTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff Images: Page 2';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|page=2]]\n" );
		// $this->selenium->type( 'wpTextbox1', "[[Image:Pc260001.tif|thumb]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '564' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '640' );
	}
}

class SeleniumEmbedTiffPage2ThumbRatioTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff Thumbnail Aspect Ratio: Page 2';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|320px|page=2]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '282' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '320' );
	}
}

class SeleniumEmbedTiffPage2BoxFitTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff Box Fit: Page 2';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|200x108px|page=2]]\n" );

		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@height", '108' );
		$this->assertSeleniumAttributeEquals(
			"//div[@id='bodyContent']//img@width", '123' );
	}
}

class SeleniumEmbedTiffNegativePageParameterTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff: negative page parameter';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|page=-1]]\n" );

		$source = $this->getAttribute( "//div[@id='bodyContent']//img@src" );
		$correct = strstr( $source, "-page1-" );
		$this->assertTrue( $correct );
	}
}

class SeleniumEmbedTiffPageParameterTooHighTest extends SeleniumEmbedTiffTest {
	public $name = 'Include Tiff: too high page parameter';

	public function runTest() {
		$this->preparePage( "[[Image:Multipage.tiff|page=8]]\n" );

		$source = $this->getAttribute( "//div[@id='bodyContent']//img@src" );
		$correct = strstr( $source, "-page7-" );
		$this->assertTrue( $correct );
	}
}
