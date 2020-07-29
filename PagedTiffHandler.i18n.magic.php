<?php
/**
 * Internationalization file for extension.
 *
 * Add the "lossy"-parameter to image link.
 * Usage:
 *  lossy=true|false
 *  lossy=1|0
 *  lossy=lossy|lossless
 * E.g. [[Image:Test.tif|lossy=1]]
 *
 * @file
 * @ingroup Extensions
 */

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'img_lossy' => [ 1, "lossy=$1" ],
];
