<?php

namespace Lachesis\PHPImageWrapper;

/*
 * Abstraction layer for images in PHP
 * Provides information, analysis and editing utilities on an image
 * Author: Pierre-Henry Baudin
 */

class Image {
	/*
	 * Class constants
	 */
	const RESIZE_STANDARD = 0;
	const RESIZE_CIRCUMSCRIBED = 1;
	const RESIZE_CROP_PROPORTIONATE = 2;
	const RESIZE_INSCRIBED = 3;
	const RESIZE_PROPORTIONATE = 4;

	const TRANSPARENCY_DEFAULT_BACKGROUND_COLOUR = 'white';
	const TRANSPARENCY_DEFAULT_THRESHOLD = 1279;
	const JPEG_QUALITY_VALUE = 75;
	const RESAMPLING_FILTER = \Imagick::FILTER_LANCZOS;
	const BLUR_FACTOR = 1;

	/*
	 * Image information
	 */
	protected $file;

	protected $width;
	protected $height;
	protected $format;

	/*
	 * Image data
	 */
	protected $image;

	/*
	 * Getters
	 */
	public function __get($name) {
		if (in_array($name, array('file', 'width', 'height', 'format', 'image'))) {
			return $this->$name;
		}
	}

	/**
	 * Class constructor
	 * @param $arg can be a file name (string), an ImageMagick object or a GD resource
	 */
	public function __construct($arg) {
		if (is_string($arg)) { // Load image from file name
			$this->file = $arg;
			$this->image = new \Imagick($this->file);
		}
		else if (is_object($arg) && get_class($arg) == 'Imagick') { // Load image from ImageMagick object
			$this->image = $arg;
		}
		else if (is_resource($arg)) { // Load image from GD resource
			$this->image = new \Imagick();
			ob_start();
			imagepng($arg); // A GD resource has no MIME type any more, so we generate a PNG image because of its lossless compression
			$this->image->readImageBlob(ob_get_clean());
			$this->image->setImageFormat('JPEG'); // And then we tell ImageMagick to treat it as JPEG by default
		}
		else {
			throw new \Exception("Invalid argument for Image class");
		}

		$size = $this->image->getImageGeometry();
		$this->width = $size['width'];
		$this->height = $size['height'];

		$this->format = $this->image->getImageFormat();

		switch ($this->image->getImageOrientation()) { // Automatically rotate the image if required
			case \Imagick::ORIENTATION_BOTTOMRIGHT:
				$this->image->rotateimage("#000", 180);
				break;

			case \Imagick::ORIENTATION_RIGHTTOP: 
				$this->image->rotateimage("#000", 90);
				break;

			case \Imagick::ORIENTATION_LEFTBOTTOM:
				$this->image->rotateimage("#000", -90);
				break;
		}
	}

	/**
	 * Resizes an image
	 * @param $method int (optional) default 0
	 * @param $format string (optional)
	 * @param $maxNewWidth int (optional) default 510
	 * @param $maxNewHeight int (optional) default 580
	 * @param $offsetWidth int (optional) default 0
	 * @param $offsetHeight int (optional) default 0
	 * @param $croppedWidth (optional) default null
	 * @param $croppedHeight (optional) default null
	 */
	public function resize($method = self::RESIZE_STANDARD, $format = null, $maxNewWidth = 510, $maxNewHeight = 580, $offsetWidth = 0, $offsetHeight = 0, $croppedWidth = null, $croppedHeight = null) {
		switch ($method) {
			case self::RESIZE_STANDARD:
				$resizeDimensions = $this->getResizeStandardDimensions($maxNewWidth, $maxNewHeight);
				break;
			case self::RESIZE_CIRCUMSCRIBED:
				$resizeDimensions = $this->getResizeCircumscribedDimensions($maxNewWidth, $maxNewHeight);
				break;
			case self::RESIZE_CROP_PROPORTIONATE:
				$resizeDimensions = $this->getResizeCropProportionateDimensions($maxNewWidth, $maxNewHeight, $offsetWidth, $offsetHeight, $croppedWidth, $croppedHeight);
				break;
			case self::RESIZE_INSCRIBED:
				$resizeDimensions = $this->getResizeInscribedDimensions($maxNewWidth, $maxNewHeight);
				break;
			case self::RESIZE_PROPORTIONATE:
				$resizeDimensions = $this->getResizeProportionateDimensions($maxNewWidth, $maxNewHeight);
				break;
			default:
				throw new \Exception("Invalid resize method");
		}

		list($newWidth, $newHeight, $canvasWidth, $canvasHeight, $offsetWidth, $offsetHeight) = $resizeDimensions;

		$this->width = $canvasWidth;
		$this->height = $canvasHeight;

		if ($format) {
			$this->image->setImageFormat($format);
			$this->format = $format;
		}

		$newImage = new \Imagick;
		$newImage->newImage($canvasWidth, $canvasHeight, in_array($this->format, array('GIF', 'PNG')) ? new \ImagickPixel('transparent') : new \ImagickPixel('white'));

		$this->image->resizeImage($newWidth, $newHeight, self::RESAMPLING_FILTER, self::BLUR_FACTOR);

		$newImage->compositeImage($this->image, \Imagick::COMPOSITE_OVER, $offsetWidth, $offsetHeight);

		$this->image = $newImage;
	}

	/*
	 * Resizes an image to fit inside a defined rectangle, ignoring the proportions
	 */
	protected function getResizeStandardDimensions($maxNewWidth, $maxNewHeight) {
		return array($maxNewWidth, $maxNewHeight, $maxNewWidth, $maxNewHeight, 0, 0);
	}

	/*
	 * Resizes an image to fit inside a defined rectangle, cropping it so that there is no blank space (circumscribed)
	 */
	protected function getResizeCircumscribedDimensions($maxNewWidth, $maxNewHeight) {
		if ($this->width / $maxNewWidth >= $this->height / $maxNewHeight) {
			$newHeight = min($this->height, $maxNewHeight);
			$newWidth = round($this->width * $newHeight / $this->height);

			$canvasHeight = $newHeight;
			$canvasWidth = round($maxNewWidth * $canvasHeight / $maxNewHeight);
		}
		else {
			$newWidth = min($this->width, $maxNewWidth);
			$newHeight = round($this->height * $newWidth / $this->width);

			$canvasWidth = $newWidth;
			$canvasHeight = round($maxNewHeight * $canvasWidth / $maxNewWidth);
		}

		$offsetWidth = round(($canvasWidth - $newWidth) / 2);
		$offsetHeight = round(($canvasHeight - $newHeight) / 2);

		return array($newWidth, $newHeight, $canvasWidth, $canvasHeight, $offsetWidth, $offsetHeight);
	}

	/*
	 * Crops a defined section of an image and resizes it to fit inside a defined rectangle, adding no blank space to keep the proportions
	 */
	protected function getResizeCropProportionateDimensions($maxNewWidth, $maxNewHeight, $offsetWidth, $offsetHeight, $croppedWidth, $croppedHeight) {
		if (!$croppedWidth) {
			$croppedWidth = $this->width - $offsetWidth;
		}
		if (!$croppedHeight) {
			$croppedHeight = $this->height - $offsetHeight;
		}

		if ($maxNewWidth == null || $maxNewHeight != null && $croppedWidth / $maxNewWidth >= $croppedHeight / $maxNewHeight) {
			$canvasWidth = min($croppedWidth, $maxNewWidth);
			$canvasHeight = round($croppedHeight * $canvasWidth / $croppedWidth);
		}
		else {
			$canvasHeight = min($croppedHeight, $maxNewHeight);
			$canvasWidth = round($croppedWidth * $canvasHeight / $croppedHeight);
		}

		$newWidth = round($this->width * $canvasWidth / $croppedWidth);
		$newHeight = round($this->height * $canvasHeight / $croppedHeight);

		$offsetWidth = -round($offsetWidth * $canvasWidth / $croppedWidth);
		$offsetHeight = -round($offsetHeight * $canvasHeight / $croppedHeight);

		return array($newWidth, $newHeight, $canvasWidth, $canvasHeight, $offsetWidth, $offsetHeight);
	}

	/*
	 * Resizes an image to fit inside a defined rectangle, adding blank space if needed (inscribed)
	 */
	protected function getResizeInscribedDimensions($maxNewWidth, $maxNewHeight) {
		if ($this->width / $maxNewWidth >= $this->height / $maxNewHeight) {
			$newWidth = min($this->width, $maxNewWidth);
			$newHeight = round($this->height * $newWidth / $this->width);

			$canvasWidth = $newWidth;
			$canvasHeight = round($maxNewHeight * $canvasWidth / $maxNewWidth);
		}
		else {
			$newHeight = min($this->height, $maxNewHeight);
			$newWidth = round($this->width * $newHeight / $this->height);

			$canvasHeight = $newHeight;
			$canvasWidth = round($maxNewWidth * $canvasHeight / $maxNewHeight);
		}

		$offsetWidth = round(($canvasWidth - $newWidth) / 2);
		$offsetHeight = round(($canvasHeight - $newHeight) / 2);

		return array($newWidth, $newHeight, $canvasWidth, $canvasHeight, $offsetWidth, $offsetHeight);
	}

	/*
	 * Resizes an image to fit inside a defined rectangle, adding no blank space to keep the proportions
	 */
	protected function getResizeProportionateDimensions($maxNewWidth, $maxNewHeight) {
		if ($maxNewWidth == null || $maxNewHeight != null && $this->width / $maxNewWidth >= $this->height / $maxNewHeight) {
			$newWidth = min($this->width, $maxNewWidth);
			$newHeight = round($this->height * $newWidth / $this->width);
		}
		else {
			$newHeight = min($this->height, $maxNewHeight);
			$newWidth = round($this->width * $newHeight / $this->height);
		}

		$canvasWidth = $newWidth;
		$canvasHeight = $newHeight;

		$offsetWidth = 0;
		$offsetHeight = 0;

		return array($newWidth, $newHeight, $canvasWidth, $canvasHeight, $offsetWidth, $offsetHeight);
	}

	/*
	 * Makes an image transparent
	 */
	public function makeTransparent($backgroundColor = self::TRANSPARENCY_DEFAULT_BACKGROUND_COLOUR, $threshold = self::TRANSPARENCY_DEFAULT_THRESHOLD) {
		$this->image->paintTransparentImage($backgroundColor, 0, $threshold);

		$this->image->setImageFormat('PNG');
	}

	/*
	 * Rounds image corners
	 */
	public function roundCorners($color, $borderRadius = null) {
		if ($this->format == 'JPEG') {
			$this->image->setImageBackgroundColor(new \ImagickPixel($color));
		}

		$borderRadius = $borderRadius ?: ($this->width + $this->height) / 4;

		$this->image->roundCorners($borderRadius, $borderRadius);
	}

	/*
	 * Writes image
	 * Note: ImageMagick won't save a file without the extension corresponding to its format, so we add it to the file name and then move the saved file 
	 */
	public function write($newFile = null) {
		if ($newFile !== null) {
			$this->file = $newFile;
		}

		if (!$this->file) {
			throw new \Exception("Missing filename");
		}

		if ($this->format == 'JPEG') {
			$this->image->setImageCompression(\Imagick::COMPRESSION_JPEG);
			$this->image->setImageCompressionQuality(self::JPEG_QUALITY_VALUE);
		}

		$temporaryFileName = $this->file . '.' . strtolower($this->format);
		$this->image->writeImage($temporaryFileName);
		rename($temporaryFileName, $this->file);
	}

	/*
	 * Returns the corresponding GD resource
	 */
	public function getResource() {
		ob_start();
		echo $this->image;
		return imagecreatefromstring(ob_get_clean());
	}
}