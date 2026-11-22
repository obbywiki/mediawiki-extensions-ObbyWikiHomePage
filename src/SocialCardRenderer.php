<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use Psr\Log\LoggerInterface;

/**
 * Draws the home page link preview card (og:image) with Imagick: a dimmed, tilted wall of the spotlight slides as the background,
 * with the logo and site name on one centred line and the tagline below.
 *
 * Slides use the same shape as SpotlightRenderer: [ 'title' => plain text, 'imagePath' => ?string, 'hue' => int ]
 */
class SocialCardRenderer {
	public const WIDTH = 1200;
	public const HEIGHT = 630;

	private const BG_TOP = '#0b1733';
	private const BG_BOTTOM = '#04070f';

	private const TEXT_WIDTH = 1000;
	private const SCRIM = '5,9,20'; // rgb of the centre dimming over the wall

	private const TILE_W = 384;
	private const TILE_H = 216;
	private const TILE_GAP = 22;
	private const TILE_RADIUS = 12;
	private const COLLAGE_COLUMNS = 5;
	private const COLLAGE_ROWS = 6;
	private const COLLAGE_ANGLE = -14;

	/** @var array */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var Imagick|null used only for font metrics */
	private $measurer = null;

	/**
	 * @param array $settings quality, fonts{bold,regular}
	 * @param LoggerInterface $logger
	 */
	public function __construct( array $settings, LoggerInterface $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * @param array[] $slides
	 * @param array{title:string,tagline:string,logoSvg:?string} $text
	 * @param string $outPath
	 * @return array{bytes:int,width:int,height:int}
	 */
	public function render( array $slides, array $text, string $outPath ): array {
		$card = new Imagick();
		$card->newPseudoImage( self::WIDTH, self::HEIGHT, 'gradient:' . self::BG_TOP . '-' . self::BG_BOTTOM );
		$card->setImageColorspace( Imagick::COLORSPACE_SRGB );

		if ( $slides ) {
			$collage = $this->renderCollage( $slides );
			$card->compositeImage( $collage, Imagick::COMPOSITE_OVER, 0, 0 );
			$collage->clear();
			$this->drawScrim( $card );
		}

		$this->drawText( $card, $text );

		$card->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
		$card->stripImage();
		$card->setImageFormat( 'jpeg' );
		$card->setImageCompressionQuality( (int)( $this->settings['quality'] ?? 85 ) );
		$card->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
		$card->setInterlaceScheme( Imagick::INTERLACE_PLANE );
		$card->writeImage( 'jpeg:' . $outPath );
		$card->clear();

		if ( $this->measurer ) {
			$this->measurer->clear();
			$this->measurer = null;
		}

		clearstatcache( true, $outPath );
		return [ 'bytes' => (int)filesize( $outPath ), 'width' => self::WIDTH, 'height' => self::HEIGHT ];
	}

	/**
	 * Slide index per collage cell (row-major). Spreads the slides evenly and never puts a slide next to itself, counting the
	 * diagonal neighbours that touch once odd columns drop half a tile.
	 *
	 * @return int[]
	 */
	public static function collageOrder( int $slideCount, int $columns, int $rows ): array {
		if ( $slideCount < 1 ) {
			return [];
		}

		$order = [];
		$uses = array_fill( 0, $slideCount, 0 );
		for ( $row = 0; $row < $rows; $row++ ) {
			for ( $col = 0; $col < $columns; $col++ ) {
				// odd columns sit half a tile lower, so an even column also touches the odd columns' cells one row up
				$neighbours = [];
				if ( $col > 0 ) {
					$neighbours[] = $order[$row * $columns + $col - 1];
				}
				if ( $row > 0 ) {
					$neighbours[] = $order[( $row - 1 ) * $columns + $col];
					if ( $col % 2 === 0 ) {
						if ( $col > 0 ) {
							$neighbours[] = $order[( $row - 1 ) * $columns + $col - 1];
						}
						if ( $col + 1 < $columns ) {
							$neighbours[] = $order[( $row - 1 ) * $columns + $col + 1];
						}
					}
				}

				// least used slide that no neighbour shows, lowest index first; fall back to least used overall
				$pick = null;
				foreach ( [ true, false ] as $avoid ) {
					for ( $i = 0; $i < $slideCount; $i++ ) {
						$candidate = ( $row * $columns + $col + $i ) % $slideCount;
						if ( $avoid && in_array( $candidate, $neighbours, true ) ) {
							continue;
						}
						if ( $pick === null || $uses[$candidate] < $uses[$pick] ) {
							$pick = $candidate;
						}
					}
					if ( $pick !== null ) {
						break;
					}
				}

				$order[] = $pick;
				$uses[$pick]++;
			}
		}
		return $order;
	}

	/**
	 * Dim the wall with the navy background gradient and a soft brand-blue glow, then a little more in the middle where the text sits.
	 */
	private function drawScrim( Imagick $card ): void {
		$tint = new Imagick();
		$tint->newPseudoImage( self::WIDTH, self::HEIGHT, 'gradient:rgba(11,23,51,0.62)-rgba(4,7,15,0.7)' );
		$card->compositeImage( $tint, Imagick::COMPOSITE_OVER, 0, 0 );
		$tint->clear();

		$size = 1000;
		$glow = new Imagick();
		$glow->newPseudoImage( $size, $size, 'radial-gradient:rgba(0,97,243,0.4)-rgba(0,97,243,0)' );
		$card->compositeImage( $glow, Imagick::COMPOSITE_SCREEN, -380, -300 );
		$card->compositeImage( $glow, Imagick::COMPOSITE_SCREEN, self::WIDTH - $size + 380, self::HEIGHT - $size + 300 );
		$glow->clear();

		$w = 1500;
		$h = 820;
		$spot = new Imagick();
		$spot->newPseudoImage( $w, $h, 'radial-gradient:rgba(' . self::SCRIM . ',0.6)-rgba(' . self::SCRIM . ',0)' );
		$card->compositeImage( $spot, Imagick::COMPOSITE_OVER, intdiv( self::WIDTH - $w, 2 ), intdiv( self::HEIGHT - $h, 2 ) );
		$spot->clear();
	}

	/**
	 * Full-card transparent layer with the tilted, masonry-offset tiles.
	 *
	 * @param array[] $slides
	 */
	private function renderCollage( array $slides ): Imagick {
		$tiles = [];
		foreach ( $slides as $i => $slide ) {
			$tiles[$i] = $this->renderTile( $slide );
		}

		$shadow = $this->renderTileShadow();
		$pitchX = self::TILE_W + self::TILE_GAP;
		$pitchY = self::TILE_H + self::TILE_GAP;
		$margin = 40;
		$gridW = self::COLLAGE_COLUMNS * $pitchX - self::TILE_GAP + 2 * $margin;
		$gridH = self::COLLAGE_ROWS * $pitchY - self::TILE_GAP + 2 * $margin + intdiv( $pitchY, 2 );

		$grid = new Imagick();
		$grid->newImage( $gridW, $gridH, new ImagickPixel( 'transparent' ) );
		$grid->setImageFormat( 'png' );

		$order = self::collageOrder( count( $slides ), self::COLLAGE_COLUMNS, self::COLLAGE_ROWS );
		foreach ( $order as $cell => $slideIndex ) {
			$col = $cell % self::COLLAGE_COLUMNS;
			$row = intdiv( $cell, self::COLLAGE_COLUMNS );
			$x = $margin + $col * $pitchX;
			// every other column drops half a tile, like a masonry wall
			$y = $margin + $row * $pitchY + ( $col % 2 === 1 ? intdiv( $pitchY, 2 ) : 0 );

			$grid->compositeImage( $shadow, Imagick::COMPOSITE_OVER, $x - 24, $y - 12 );
			$grid->compositeImage( $tiles[$slideIndex], Imagick::COMPOSITE_OVER, $x, $y );
		}

		$shadow->clear();
		foreach ( $tiles as $tile ) {
			$tile->clear();
		}

		$grid->rotateImage( new ImagickPixel( 'transparent' ), self::COLLAGE_ANGLE );

		// centre the rotated wall on the card; it is big enough to bleed off every edge
		$layer = new Imagick();
		$layer->newImage( self::WIDTH, self::HEIGHT, new ImagickPixel( 'transparent' ) );
		$layer->setImageFormat( 'png' );
		$cx = self::WIDTH / 2;
		$cy = self::HEIGHT / 2;
		$layer->compositeImage(
			$grid, Imagick::COMPOSITE_OVER,
			(int)round( $cx - $grid->getImageWidth() / 2 ),
			(int)round( $cy - $grid->getImageHeight() / 2 )
		);
		$grid->clear();

		return $layer;
	}

	private function renderTile( array $slide ): Imagick {
		$tile = null;
		if ( !empty( $slide['imagePath'] ) ) {
			$tile = $this->loadCover( $slide['imagePath'] );
		}
		if ( !$tile ) {
			$tile = $this->renderPlaceholder( $slide );
		}

		$this->roundCorners( $tile );

		return $tile;
	}

	private function loadCover( string $path ): ?Imagick {
		try {
			$img = new Imagick();
			$img->readImage( $path . '[0]' );
			if ( $img->getImageColorspace() === Imagick::COLORSPACE_CMYK ) {
				$img->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}
			$img->setImageBackgroundColor( new ImagickPixel( '#202122' ) );
			$img->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
			$img->cropThumbnailImage( self::TILE_W, self::TILE_H );
			$img->setImagePage( 0, 0, 0, 0 );
			if ( $img->getImageWidth() !== self::TILE_W || $img->getImageHeight() !== self::TILE_H ) {
				$img->extentImage( self::TILE_W, self::TILE_H, 0, 0 );
			}
			$img->stripImage();
			$img->setImageFormat( 'png' );
			return $img;
		} catch ( \ImagickException $e ) {
			$this->logger->warning( 'Social card: could not read slide image {path}: {message}', [ 'path' => $path, 'message' => $e->getMessage() ] );
			return null;
		}
	}

	private function renderPlaceholder( array $slide ): Imagick {
		[ $r, $g, $b ] = SpotlightRenderer::hslToRgb( (int)( $slide['hue'] ?? 0 ), 0.35, 0.30 );
		$img = new Imagick();
		$img->newImage( self::TILE_W, self::TILE_H, new ImagickPixel( "rgb($r,$g,$b)" ) );
		$img->setImageFormat( 'png' );

		$letter = mb_strtoupper( mb_substr( (string)( $slide['title'] ?? '' ), 0, 1 ) );
		if ( $letter !== '' ) {
			$draw = $this->makeDraw( 'bold', 96, 'rgba(255,255,255,0.3)' );
			$metrics = $this->measurer()->queryFontMetrics( $draw, $letter );
			$x = ( self::TILE_W - $metrics['textWidth'] ) / 2;
			$y = self::TILE_H / 2 + ( $metrics['ascender'] + $metrics['descender'] ) / 2;
			$img->annotateImage( $draw, $x, $y, 0, $letter );
		}

		return $img;
	}

	private function renderTileShadow(): Imagick {
		$shadow = new Imagick();
		$shadow->newImage( self::TILE_W + 48, self::TILE_H + 48, new ImagickPixel( 'transparent' ) );
		$shadow->setImageFormat( 'png' );
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( 'rgba(0,0,0,0.55)' ) );
		$draw->roundRectangle( 24, 24, 24 + self::TILE_W - 1, 24 + self::TILE_H - 1, self::TILE_RADIUS, self::TILE_RADIUS );
		$shadow->drawImage( $draw );
		$draw->clear();
		$shadow->blurImage( 0, 10 );

		return $shadow;
	}

	private function roundCorners( Imagick $img ): void {
		$mask = new Imagick();
		$mask->newImage( self::TILE_W, self::TILE_H, new ImagickPixel( 'transparent' ) );
		$mask->setImageFormat( 'png' );
		$draw = new ImagickDraw();
		$draw->setFillColor( new ImagickPixel( 'white' ) );
		$draw->roundRectangle( 0, 0, self::TILE_W - 1, self::TILE_H - 1, self::TILE_RADIUS, self::TILE_RADIUS );
		$mask->drawImage( $draw );
		$draw->clear();

		$img->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );
		$img->compositeImage( $mask, Imagick::COMPOSITE_DSTIN, 0, 0 );
		$mask->clear();
	}

	/**
	 * @param Imagick $card
	 * @param array{title:string,tagline:string,logoSvg:?string} $text
	 */
	private function drawText( Imagick $card, array $text ): void {
		$logoSize = 96;
		$logoGap = 14;
		$logo = $text['logoSvg'] ? $this->rasterizeLogo( $text['logoSvg'], $logoSize ) : null;
		$titleMax = self::TEXT_WIDTH - ( $logo ? $logoSize + $logoGap : 0 );

		// the title stays on one line: shrink it to fit, and only then ellipsize
		$titleSize = 80;
		$titleDraw = $this->makeDraw( 'bold', $titleSize, '#ffffff' );
		while ( $titleSize > 44 && $this->measureWith( $titleDraw )( $text['title'] ) > $titleMax ) {
			$titleSize -= 2;
			$titleDraw->setFontSize( $titleSize );
		}
		$measureTitle = $this->measureWith( $titleDraw );
		$title = $measureTitle( $text['title'] ) <= $titleMax
			? $text['title']
			: SpotlightRenderer::ellipsize( $text['title'], $measureTitle, $titleMax );

		$taglineDraw = $this->makeDraw( 'regular', 32, 'rgba(255,255,255,0.85)' );
		$measureTagline = $this->measureWith( $taglineDraw );
		$taglineLines = SpotlightRenderer::wrapText( $text['tagline'], $measureTagline, self::TEXT_WIDTH - 100, 2 );

		$rowHeight = max( $logoSize, (int)round( $titleSize * 1.15 ) );
		$taglineGap = 20;
		$taglineLineHeight = 44;

		// stack: [logo title] row, tagline; centred both ways
		$blockHeight = $rowHeight + ( $taglineLines ? $taglineGap + count( $taglineLines ) * $taglineLineHeight : 0 );
		$top = (int)round( ( self::HEIGHT - $blockHeight ) / 2 );

		$rowWidth = $measureTitle( $title ) + ( $logo ? $logoSize + $logoGap : 0 );
		$x = ( self::WIDTH - $rowWidth ) / 2;
		if ( $logo ) {
			$card->compositeImage( $logo, Imagick::COMPOSITE_OVER, (int)round( $x ), $top + intdiv( $rowHeight - $logoSize, 2 ) );
			$logo->clear();
			$x += $logoSize + $logoGap;
		}
		$this->drawShadowedText( $card, $titleDraw, $title, $x, $this->baseline( $titleDraw, $top, $rowHeight ) );
		$top += $rowHeight + $taglineGap;

		foreach ( $taglineLines as $line ) {
			$lineX = ( self::WIDTH - $measureTagline( $line ) ) / 2;
			$this->drawShadowedText( $card, $taglineDraw, $line, $lineX, $this->baseline( $taglineDraw, $top, $taglineLineHeight ) );
			$top += $taglineLineHeight;
		}
	}

	private function drawShadowedText( Imagick $card, ImagickDraw $draw, string $text, float $x, float $baseline ): void {
		$shadow = new Imagick();
		$shadow->newImage( self::WIDTH, self::HEIGHT, new ImagickPixel( 'transparent' ) );
		$shadow->setImageFormat( 'png' );
		$shadowDraw = clone $draw;
		$shadowDraw->setFillColor( new ImagickPixel( 'rgba(0,0,0,0.5)' ) );
		$shadow->annotateImage( $shadowDraw, $x, $baseline + 3, 0, $text );
		$shadow->blurImage( 0, 8 );
		$card->compositeImage( $shadow, Imagick::COMPOSITE_OVER, 0, 0 );
		$shadow->clear();
		$shadowDraw->clear();

		$card->annotateImage( $draw, $x, $baseline, 0, $text );
	}

	private function rasterizeLogo( string $svg, int $size ): ?Imagick {
		try {
			// ImageMagick's built-in SVG renderer ignores <mask>, so apply the logo's mask by hand: draw the masked content
			// unmasked, then keep it only where the mask shape is
			$shape = null;
			if ( preg_match( '/<mask\b[^>]*>(.*?)<\/mask>/s', $svg, $m )
				&& preg_match( '/<svg\b[^>]*>/', $svg, $open )
			) {
				$maskContent = preg_replace( '/\sfill="[^"]*"/', ' fill="#ffffff"', $m[1] );
				$shape = $this->readSvg( $open[0] . $maskContent . '</svg>', $size );
				$svg = preg_replace( [ '/<mask\b.*?<\/mask>/s', '/\smask="[^"]*"/' ], '', $svg );
			}

			$logo = $this->readSvg( $svg, $size );
			if ( $shape ) {
				$logo->compositeImage( $shape, Imagick::COMPOSITE_DSTIN, 0, 0 );
				$shape->clear();
			}
			return $logo;
		} catch ( \ImagickException $e ) {
			$this->logger->info( 'Social card: no SVG support, drawing without the logo: {message}', [ 'message' => $e->getMessage() ] );
			return null;
		}
	}

	private function readSvg( string $svg, int $size ): Imagick {
		$img = new Imagick();
		$img->setBackgroundColor( new ImagickPixel( 'transparent' ) );
		// rasterize large, then downsample for clean edges
		$img->setResolution( 288, 288 );
		$img->readImageBlob( $svg, 'logo.svg' );
		$img->setImageFormat( 'png' );
		$img->resizeImage( $size, $size, Imagick::FILTER_LANCZOS, 1, true );
		return $img;
	}

	/**
	 * @param string $weight 'bold' or 'regular'
	 */
	private function makeDraw( string $weight, float $size, string $color ): ImagickDraw {
		$draw = new ImagickDraw();
		$draw->setFont( $this->settings['fonts'][$weight] );
		$draw->setFontSize( $size );
		$draw->setFillColor( new ImagickPixel( $color ) );
		$draw->setTextAntialias( true );
		$draw->setTextEncoding( 'UTF-8' );
		return $draw;
	}

	private function baseline( ImagickDraw $draw, float $top, float $lineHeight ): float {
		$metrics = $this->measurer()->queryFontMetrics( $draw, 'Hg' );
		return $top + ( $lineHeight - ( $metrics['ascender'] - $metrics['descender'] ) ) / 2 + $metrics['ascender'];
	}

	/**
	 * @return callable(string):float
	 */
	private function measureWith( ImagickDraw $draw ): callable {
		$measurer = $this->measurer();
		return static function ( string $text ) use ( $measurer, $draw ): float {
			return (float)$measurer->queryFontMetrics( $draw, $text )['textWidth'];
		};
	}

	private function measurer(): Imagick {
		if ( !$this->measurer ) {
			$this->measurer = new Imagick();
			$this->measurer->newImage( 1, 1, new ImagickPixel( 'transparent' ) );
		}
		return $this->measurer;
	}
}
