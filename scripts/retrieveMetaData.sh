#!/bin/sh

# Get parameters from environment

export TIFF_USETIFFINFO="${TIFF_USETIFFINFO:-yes}"
export TIFF_TIFFINFO="${TIFF_TIFFINFO:-tiffinfo}"
export TIFF_IDENTIFY="${TIFF_IDENTIFY:-identify}"
export TIFF_USEEXIV="${TIFF_USEEXIV:-yes}"
export TIFF_EXIV2="${TIFF_EXIV2:-exiv2}"

runTiffInfo() {
	# read TIFF directories using libtiff's tiffinfo, see
	# http://www.libtiff.org/man/tiffinfo.1.html
	"$TIFF_TIFFINFO" file.tiff > info
	if [ $? -ne 0 ]; then
		# fail. we *need* that info
		exit 10
	fi
}

runIdentify() {
	# read TIFF directories using libtiff's tiffinfo, see
	# http://www.libtiff.org/man/tiffinfo.1.html
	"$TIFF_IDENTIFY" \
		-format \
		"[BEGIN]page=%p\nalpha=%A\nalpha2=%r\nheight=%h\nwidth=%w\ndepth=%z[END]" \
		file.tiff > identified
	if [ $? -ne 0 ]; then
		# fail. we *need* that info
		exit 11
	fi
}

runExiv() {
	# read EXIF, XMP, IPTC as name-tag => interpreted data
	# -ignore unknown fields
	# see exiv2-doc @link http://www.exiv2.org/sample.html
	# NOTE: the linux version of exiv2 has a bug: it can only
	# read one type of meta-data at a time, not all at once.
	"$TIFF_EXIV2" \
		-u \
		-psix \
		-Pnt \
		file.tiff > extended
	echo $? > exiv_exit_code
}

# Fetch base info: number of pages, size and alpha for each page.
# Run optionally tiffinfo or, per default, ImageMagick's identify
# command.
if [ "$TIFF_USETIFFINFO" = yes ] && [ -x "$TIFF_TIFFINFO" ]; then
	runTiffInfo
else
	runIdentify
fi



# Fetch extended info: EXIF/IPTC/XMP.
# Run optionally Exiv2 or, per default, the internal EXIF class.
# Note: we are not checking tiffinfo/identify output for additional
# errors first, so this final output might get thrown away
if [ "$TIFF_USEEXIV" = yes ] && [ -x "$TIFF_EXIV2" ]; then
	runExiv
fi
