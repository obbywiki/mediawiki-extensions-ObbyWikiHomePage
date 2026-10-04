<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Draws the spotlight carousel as an animated image (WebP or GIF, WebP is preferred + more optimized) with Imagick 7+.
 *
 * [ 'title' => plain text, 'description' => ?string, 'imagePath' => ?string, 'hue' => int ]
 */
class SpotlightRenderer {
	public const SLIDE_MS = 4000; // keep in sync with INTERVAL_MS in resources/ext.ObbyWikiHomePage.js

	// slide transition. keep in sync with the .obbywiki-spotlight__track transition
	public const TRANSITION_MS = 640;
	public const EASING = [ 0.25, 1.0, 0.5, 1.0 ];
	public const MIN_DELAY_MS = 20; // Chromium (and so Discord) plays GIF/WebP frame delays of 10 ms or less as 100 ms
	private const TAIL_CUTOFF = 0.0005; // remaining transition progress below which further frames are not worth drawing

	private const CSS_WIDTH = 640;

	private const MEMORY_LIMIT = 256 * 1024 * 1024;
	private const MAP_LIMIT = 512 * 1024 * 1024;

	private const ELLIPSIS = "..."; // alternatively, \u{2026} works fine

	/** @var array */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var Imagick|null used only for font metrics */
	private $measurer = null;

	/**
	 * @param array $settings format, width, quality, maxBytes, transitionFrames, holdFrames (0 = one per bar pixel), fonts{bold,regular}
	 * @param LoggerInterface $logger
	 */
	public function __construct( array $settings, LoggerInterface $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * Render to $outPath, stepping down the size budget until the file fits.
	 *
	 * @param array[] $slides
	 * @param string $outPath
	 * @return array{frames:int,bytes:int,width:int,height:int,step:int,stepLabel:string,format:string}
	 */
	public function render( array $slides, string $outPath ): array {
		if ( !$slides ) {
			throw new InvalidArgumentException( 'No slides to render' );
		}

		$prevMemory = Imagick::getResourceLimit( Imagick::RESOURCETYPE_MEMORY );
		$prevMap = Imagick::getResourceLimit( Imagick::RESOURCETYPE_MAP );
		// spill the pixel cache to disk instead of exhausting memory
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, min( $prevMemory ?: PHP_INT_MAX, self::MEMORY_LIMIT ) );
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, min( $prevMap ?: PHP_INT_MAX, self::MAP_LIMIT ) );

		try {
			$plans = self::getAttemptPlans( $this->settings );
			$last = count( $plans ) - 1;
			foreach ( $plans as $i => $plan ) {
				$frames = $this->renderAttempt( $slides, $outPath, $plan );
				clearstatcache( true, $outPath );
				$bytes = (int)filesize( $outPath );

				if ( $bytes <= $this->settings['maxBytes'] || $i === $last ) {
					$context = [ 'step' => $plan['step'], 'label' => $plan['label'], 'bytes' => $bytes, 'frames' => $frames ];
					
					if ( $bytes > $this->settings['maxBytes'] ) {
						$this->logger->warning( 'Spotlight animation exceeds size budget after all steps ({bytes} bytes)', $context );
					} else {
						$this->logger->info( 'Spotlight animation rendered at budget step {step} ({label})', $context );
					}

					return [
						'frames' => $frames,
						'bytes' => $bytes,
						'width' => $plan['width'],
						'height' => self::heightFor( $plan['width'] ),
						'step' => $plan['step'],
						'stepLabel' => $plan['label'],
						'format' => $this->settings['format'],
					];
				}
			}
		} finally {
			if ( $this->measurer ) {
				$this->measurer->clear();
				$this->measurer = null;
			}
			try {
				Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, $prevMemory );
				Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, $prevMap );
			} catch ( \ImagickException $e ) {
				// some builds refuse to raise limits again (harmless for the rest of the job)
			}
		}

		throw new \LogicException( 'unreachable' );
	}

	/**
	 * Cumulative fallback plans for the size budget, base settings first.
	 *
	 * @param array $settings
	 * @return list<array{step:int,label:string,width:int,quality:int,transitionFrames:int,holdFrames:int}>
	 */
	public static function getAttemptPlans( array $settings ): array {
		$plan = [
			'step' => 0,
			'label' => 'base',
			'width' => (int)$settings['width'],
			'quality' => (int)$settings['quality'],
			'transitionFrames' => (int)$settings['transitionFrames'],
			'holdFrames' => (int)$settings['holdFrames']
		];
		$plans = [ $plan ];

		$steps = [
			1 => [ 'quality 65', 'quality', 65 ],
			2 => [ '4 transition frames', 'transitionFrames', 4 ],
			3 => [ '3 hold frames', 'holdFrames', 3 ],
			4 => [ 'no transition', 'transitionFrames', 0 ],
			5 => [ 'canvas 800', 'width', 800 ]
		];
		foreach ( $steps as $step => [ $label, $field, $value ] ) {
			if ( $field === 'quality' && $settings['format'] === 'gif' ) {
				continue;
			}

			// holdFrames 0 means one frame per bar pixel (always more than the fallback)
			$current = ( $field === 'holdFrames' && $plan[$field] === 0 ) ? PHP_INT_MAX : $plan[$field];
			if ( $current <= $value ) {
				continue;
			}

			$plan[$field] = $value;
			$plan['step'] = $step;
			$plan['label'] = $label;
			$plans[] = $plan;
		}

		return $plans;
	}

	/**
	 * Frame list for the loop. Starts on slide 0's hold phase (frame 0 is a complete / non-transition frame) and ends with the transition back into slide 0.
	 *
	 * @param int $slideCount
	 * @param int $transitionFrames
	 * @param int $holdFrames
	 * @return list<array{slide:int,prev:?int,progress:float,elapsed:int,delay:int}> delay in 1/100 s ticks
	 */
	public static function buildTimeline( int $slideCount, int $transitionFrames, int $holdFrames ): array {
		if ( $slideCount < 1 ) {
			return [];
		}
		if ( $slideCount === 1 ) {
			return [ [ 'slide' => 0, 'prev' => null, 'progress' => 1.0, 'elapsed' => 0, 'delay' => 0 ] ];
		}

		$transitionTimes = $transitionFrames > 0 ? self::transitionTimes( $transitionFrames ) : [];
		$holdStart = $transitionTimes ? self::TRANSITION_MS : 0;
		$holdMs = self::SLIDE_MS - $holdStart;
		$holdFrames = min( max( 1, $holdFrames ), intdiv( $holdMs, self::MIN_DELAY_MS ) );
		$holdTicks = self::distributeTicks( intdiv( $holdMs, 10 ), $holdFrames );

		$frames = [];
		$addHold = static function ( int $slide ) use ( &$frames, $holdTicks, $holdStart ) {
			$elapsed = $holdStart;
			foreach ( $holdTicks as $ticks ) {
				$frames[] = [ 'slide' => $slide, 'prev' => null, 'progress' => 1.0, 'elapsed' => $elapsed, 'delay' => $ticks ];
				$elapsed += $ticks * 10;
			}
		};
		$addTransition = static function ( int $slide, int $prev ) use ( &$frames, $transitionTimes ) {
			foreach ( $transitionTimes as $i => $elapsed ) {
				$next = $transitionTimes[$i + 1] ?? self::TRANSITION_MS;
				$frames[] = [
					'slide' => $slide,
					'prev' => $prev,
					'progress' => self::ease( $elapsed / self::TRANSITION_MS ),
					'elapsed' => $elapsed,
					'delay' => intdiv( $next - $elapsed, 10 )
				];
			}
		};

		$addHold( 0 );
		for ( $k = 1; $k < $slideCount; $k++ ) {
			$addTransition( $k, $k - 1 );
			$addHold( $k );
		}
		$addTransition( 0, $slideCount - 1 );

		return $frames;
	}

	/**
	 * Start times (ms, on 10 ms ticks) of the transition frames.
	 *
	 * Each step moves the track at most 1/$frames of the way, so the fast start of the ease-out (~40% of the distance in the first 75 ms) is not one big jump.
	 * 
	 * Each step also lasts at most TRANSITION_MS / $frames, so the slow tail does not stall and then snap into place.
	 * Delays never drop below MIN_DELAY_MS, and frames stop once the remaining movement is below TAIL_CUTOFF (about half a pixel at 960 wide).
	 * 
	 * Ergo, $frames is a density (the returned count is usually a little higher).
	 *
	 * @return int[] starting with 0
	 */
	public static function transitionTimes( int $frames ): array {
		$frames = max( 1, $frames );
		$maxDelay = max( self::MIN_DELAY_MS, (int)ceil( self::TRANSITION_MS / $frames / 10 ) * 10 );
		$times = [ 0 ];
		$t = 0;

		while ( true ) {
			$target = self::inverseEase( self::ease( $t / self::TRANSITION_MS ) + 1 / $frames );
			$next = (int)round( $target * self::TRANSITION_MS / 10 ) * 10;
			$next = min( max( $next, $t + self::MIN_DELAY_MS ), $t + $maxDelay );

			if ( $next > self::TRANSITION_MS - self::MIN_DELAY_MS
				|| 1 - self::ease( $next / self::TRANSITION_MS ) < self::TAIL_CUTOFF
			) {
				break;
			}

			$times[] = $next;
			$t = $next;
		}

		return $times;
	}

	/**
	 * Time fraction at which the easing reaches progress $y.
	 */
	public static function inverseEase( float $y ): float {
		if ( $y <= 0.0 ) {
			return 0.0;
		}
		if ( $y >= 1.0 ) {
			return 1.0;
		}

		[ $x1, $y1, $x2, $y2 ] = self::EASING;
		$lo = 0.0;
		$hi = 1.0;
		$t = $y;

		for ( $i = 0; $i < 50; $i++ ) {
			$t = ( $lo + $hi ) / 2;
			$value = self::bezierAxis( $t, $y1, $y2 );
			if ( abs( $value - $y ) < 1e-7 ) {
				break;
			}
			if ( $value < $y ) {
				$lo = $t;
			} else {
				$hi = $t;
			}
		}

		return self::bezierAxis( $t, $x1, $x2 );
	}

	/**
	 * Hold frames so the active bar's fill advances about one pixel per frame.
	 */
	public static function autoHoldFrames( int $slideCount, float $scale, bool $hasTransition ): int {
		$holdMs = self::SLIDE_MS - ( $hasTransition ? self::TRANSITION_MS : 0 );
		$barWidth = self::barGeometry( $slideCount, $scale )['barWidth'];

		return max( 1, (int)ceil( $barWidth * $holdMs / self::SLIDE_MS ) );
	}

	/**
	 * Split $total ticks over $count frames as evenly as possible.
	 *
	 * @return int[]
	 */
	public static function distributeTicks( int $total, int $count ): array {
		$base = intdiv( $total, $count );
		$remainder = $total - $base * $count;
		$ticks = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$ticks[] = $base + ( $i < $remainder ? 1 : 0 );
		}

		return $ticks;
	}

	/**
	 * CSS cubic-bezier(0.25, 1, 0.5, 1) at time fraction $x.
	 */
	public static function ease( float $x ): float {
		return self::cubicBezier( self::EASING[0], self::EASING[1], self::EASING[2], self::EASING[3], $x );
	}

	public static function cubicBezier( float $x1, float $y1, float $x2, float $y2, float $x ): float {
		if ( $x <= 0.0 ) {
			return 0.0;
		}
		if ( $x >= 1.0 ) {
			return 1.0;
		}

		// x(t) is monotonic for x1, x2 in [0, 1]; bisection is plenty fast here
		$lo = 0.0;
		$hi = 1.0;
		$t = $x;
		for ( $i = 0; $i < 50; $i++ ) {
			$t = ( $lo + $hi ) / 2;
			$value = self::bezierAxis( $t, $x1, $x2 );
			if ( abs( $value - $x ) < 1e-7 ) {
				break;
			}
			if ( $value < $x ) {
				$lo = $t;
			} else {
				$hi = $t;
			}
		}

		return self::bezierAxis( $t, $y1, $y2 );
	}

	private static function bezierAxis( float $t, float $p1, float $p2 ): float {
		$u = 1 - $t;
		return 3 * $u * $u * $t * $p1 + 3 * $u * $t * $t * $p2 + $t * $t * $t;
	}

	/**
	 * Greedy word wrap with a line clamp. Breaks on spaces, hard-breaks words wider
	 * than the line, and ellipsizes the last line when text is left over.
	 *
	 * @param string $text
	 * @param callable(string):float $measure
	 * @param float $maxWidth
	 * @param int $maxLines
	 * @return string[]
	 */
	public static function wrapText( string $text, callable $measure, float $maxWidth, int $maxLines ): array {
		$text = trim( (string)preg_replace( '/\s+/u', ' ', $text ) );
		if ( $text === '' || $maxLines < 1 ) {
			return [];
		}

		$words = explode( ' ', $text );
		$lines = [];
		$current = '';

		while ( $words ) {
			$word = array_shift( $words );
			$candidate = $current === '' ? $word : $current . ' ' . $word;

			if ( $measure( $candidate ) <= $maxWidth ) {
				$current = $candidate;
				continue;
			}

			if ( $current === '' ) {
				// single word wider than the line
				[ $head, $tail ] = self::splitToWidth( $word, $measure, $maxWidth );
				$lines[] = $head;
				if ( $tail !== '' ) {
					array_unshift( $words, $tail );
				}
			} else {
				$lines[] = $current;
				$current = '';
				array_unshift( $words, $word );
			}

			if ( count( $lines ) === $maxLines ) {
				if ( $words ) {
					$lines[$maxLines - 1] = self::ellipsize( $lines[$maxLines - 1] . ' ' . $words[0], $measure, $maxWidth );
				}

				return $lines;
			}
		}

		if ( $current !== '' ) {
			$lines[] = $current;
		}

		return $lines;
	}

	public static function ellipsize( string $text, callable $measure, float $maxWidth ): string {
		// trim $text from the end until it fits with a trailing '...'

		$chars = mb_str_split( $text );
		while ( $chars ) {
			$candidate = rtrim( implode( '', $chars ) ) . self::ELLIPSIS;
			if ( $measure( $candidate ) <= $maxWidth ) {
				return $candidate;
			}
			array_pop( $chars );
		}
		return self::ELLIPSIS;
	}

	/**
	 * @return array{0:string,1:string} longest fitting prefix (at least one character) and the rest
	 */
	private static function splitToWidth( string $word, callable $measure, float $maxWidth ): array {
		$chars = mb_str_split( $word );
		$head = array_shift( $chars );
		while ( $chars && $measure( $head . $chars[0] ) <= $maxWidth ) {
			$head .= array_shift( $chars );
		}
		return [ $head, implode( '', $chars ) ];
	}

	public static function heightFor( int $width ): int {
		return intdiv( $width * 9, 16 );
	}

	/**
	 * @return int frame count
	 */
	private function renderAttempt( array $slides, string $outPath, array $plan ): int {
		$format = $this->settings['format'];
		$width = $plan['width'];
		$height = self::heightFor( $width );
		$scale = $width / self::CSS_WIDTH;
		$count = count( $slides );

		$bases = [];
		foreach ( $slides as $slide ) {
			$bases[] = $this->renderSlideBase( $slide, $width, $height, $scale );
		}

		$holdFrames = $plan['holdFrames'] ?: self::autoHoldFrames( $count, $scale, $plan['transitionFrames'] > 0 );
		$timeline = self::buildTimeline( $count, $plan['transitionFrames'], $holdFrames );
		$palettes = [];
		$anim = new Imagick();

		foreach ( $timeline as $entry ) {
			$frame = $this->composeFrame( $bases, $entry, $count, $width, $height, $scale );

			if ( $format === 'gif' ) {
				$palettes[$entry['slide']] ??= $this->buildPalette( $bases, $entry['slide'], $count, $width, $height, $scale );
				$frame->remapImage( $palettes[$entry['slide']], Imagick::DITHERMETHOD_FLOYDSTEINBERG );
			} else {
				$frame->setImageCompressionQuality( $plan['quality'] );
			}

			$frame->setImageFormat( $format );
			$frame->setImageDelay( $entry['delay'] );
			$anim->addImage( $frame );
			$frame->clear();
		}

		foreach ( $bases as $base ) {
			$base->clear();
		}
		foreach ( $palettes as $palette ) {
			$palette->clear();
		}

		if ( $format === 'gif' && $count > 1 ) {
			// hold frames then only store the changed bar region
			$optimized = $anim->optimizeImageLayers();
			$anim->clear();
			$anim = $optimized;
		}

		$anim->setFirstIterator();
		$anim->setImageIterations( 0 );
		$anim->setFormat( $format );
		if ( $format === 'webp' ) {
			$anim->setOption( 'webp:lossless', 'false' );
			// slowest, smallest encode (rendering runs in a job)
			$anim->setOption( 'webp:method', '6' );
			$anim->setCompressionQuality( $plan['quality'] );
		}

		$frames = $anim->getNumberImages();
		$anim->writeImages( $format . ':' . $outPath, true );
		$anim->clear();

		return $frames;
	}

	private function renderSlideBase( array $slide, int $width, int $height, float $scale ): Imagick {
		$img = null;
		if ( !empty( $slide['imagePath'] ) ) {
			$img = $this->loadCover( $slide['imagePath'], $width, $height );
		}
		if ( !$img ) {
			$img = $this->renderPlaceholder( $slide, $width, $height, $scale );
		}

		$this->drawInfo( $img, $slide, $width, $scale );

		return $img;
	}

	private function loadCover( string $path, int $width, int $height ): ?Imagick {
		try {
			$img = new Imagick();
			// first frame only for animated sources
			$img->readImage( $path . '[0]' );
			if ( $img->getImageColorspace() === Imagick::COLORSPACE_CMYK ) {
				$img->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}
			$img->setImageBackgroundColor( new ImagickPixel( '#202122' ) );
			$img->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
			// object-fit: cover, centre crop
			$img->cropThumbnailImage( $width, $height );
			$img->setImagePage( 0, 0, 0, 0 );
			if ( $img->getImageWidth() !== $width || $img->getImageHeight() !== $height ) {
				$img->extentImage( $width, $height, 0, 0 );
			}
			$img->stripImage();
			return $img;
		} catch ( \ImagickException $e ) {
			$this->logger->warning( 'Spotlight: could not read slide image {path}: {message}', [ 'path' => $path, 'message' => $e->getMessage() ] );
			return null;
		}
	}

	private function renderPlaceholder( array $slide, int $width, int $height, float $scale ): Imagick {
		[ $r, $g, $b ] = self::hslToRgb( (int)( $slide['hue'] ?? 0 ), 0.35, 0.30 );
		$img = new Imagick();
		$img->newImage( $width, $height, new ImagickPixel( "rgb($r,$g,$b)" ) );

		$letter = mb_strtoupper( mb_substr( (string)$slide['title'], 0, 1 ) );
		if ( $letter !== '' ) {
			// 4rem, weight 800 in CSS (bold is the heaviest bundled weight)
			$draw = $this->makeDraw( 'bold', 64 * $scale, 'rgba(255,255,255,0.3)' );
			$metrics = $this->measurer()->queryFontMetrics( $draw, $letter );
			$x = ( $width - $metrics['textWidth'] ) / 2;
			$y = $height / 2 + ( $metrics['ascender'] + $metrics['descender'] ) / 2;
			$img->annotateImage( $draw, $x, $y, 0, $letter );
		}

		return $img;
	}

	private function drawInfo( Imagick $img, array $slide, int $width, float $scale ): void {
		$padX = 16 * $scale;
		$padY = 14 * $scale;
		$gap = 2 * $scale;
		$titleSize = 18 * $scale;
		$titleLineHeight = $titleSize * 1.3;
		$descSize = 12 * $scale;
		$descLineHeight = $descSize * 1.4;
		$maxTextWidth = $width - 2 * $padX;

		$titleDraw = $this->makeDraw( 'bold', $titleSize, '#ffffff' );
		$descDraw = $this->makeDraw( 'regular', $descSize, 'rgba(255,255,255,0.8)' );

		$titleLines = self::wrapText( (string)$slide['title'], $this->measureWith( $titleDraw ), $maxTextWidth, 1 );
		$descLines = self::wrapText( (string)( $slide['description'] ?? '' ), $this->measureWith( $descDraw ), $maxTextWidth, 2 );

		$infoHeight = 2 * $padY + $titleLineHeight;
		if ( $descLines ) {
			$infoHeight += $gap + count( $descLines ) * $descLineHeight;
		}

		$this->drawGradient( $img, $width, (int)ceil( $infoHeight ) );

		$top = $padY;
		if ( $titleLines ) {
			$baseline = $this->baseline( $titleDraw, $top, $titleLineHeight );
			// text-shadow: 0 1px 4px rgba(0,0,0,0.4)
			$shadowHeight = (int)ceil( $infoHeight + 8 * $scale );
			$shadow = new Imagick();
			$shadow->newImage( $width, $shadowHeight, new ImagickPixel( 'transparent' ) );
			$shadowDraw = $this->makeDraw( 'bold', $titleSize, 'rgba(0,0,0,0.4)' );
			$shadow->annotateImage( $shadowDraw, $padX, $baseline + 1 * $scale, 0, $titleLines[0] );
			$shadow->blurImage( 0, 2 * $scale );
			$img->compositeImage( $shadow, Imagick::COMPOSITE_OVER, 0, 0 );
			$shadow->clear();

			$img->annotateImage( $titleDraw, $padX, $baseline, 0, $titleLines[0] );
		}
		$top += $titleLineHeight + $gap;

		foreach ( $descLines as $line ) {
			$img->annotateImage( $descDraw, $padX, $this->baseline( $descDraw, $top, $descLineHeight ), 0, $line );
			$top += $descLineHeight;
		}
	}

	/**
	 * linear-gradient(to bottom, rgba(0,0,0,.65) 0%, rgba(0,0,0,.35) 70%, transparent 100%)
	 */
	private function drawGradient( Imagick $img, int $width, int $height ): void {
		$draw = new ImagickDraw();
		$draw->setStrokeOpacity( 0 );
		for ( $y = 0; $y < $height; $y++ ) {
			$pos = ( $y + 0.5 ) / $height;
			$alpha = $pos <= 0.7 ? 0.65 - 0.30 * ( $pos / 0.7 ) : 0.35 * ( 1 - ( $pos - 0.7 ) / 0.3 );
			$draw->setFillColor( new ImagickPixel( sprintf( 'rgba(0,0,0,%.4F)', $alpha ) ) );
			$draw->rectangle( 0, $y, $width - 1, $y );
		}

		$img->drawImage( $draw );
		$draw->clear();
	}

	/**
	 * Progress-bar pill, fixed on top of the moving track. Arrows are left out.
	 */
	private function drawPill( Imagick $frame, int $active, float $fill, int $count, int $width, int $height, float $scale ): void {
		[ 'barsWidth' => $barsWidth, 'barWidth' => $barWidth, 'barHeight' => $barHeight, 'barGap' => $barGap ] = self::barGeometry( $count, $scale );
		$padX = 8 * $scale;
		$padY = 6 * $scale;
		$inset = 12 * $scale;

		$pillWidth = $barsWidth + 2 * $padX;
		$pillHeight = $barHeight + 2 * $padY;
		$x0 = $width - $inset - $pillWidth;
		$y0 = $height - $inset - $pillHeight;

		$draw = new ImagickDraw();
		$draw->setStrokeOpacity( 0 );
		$draw->setFillColor( new ImagickPixel( 'rgba(0,0,0,0.4)' ) );
		$draw->roundRectangle( $x0, $y0, $x0 + $pillWidth - 1, $y0 + $pillHeight - 1, $pillHeight / 2, $pillHeight / 2 );

		$radius = $barHeight / 2;
		$by = $y0 + $padY;
		for ( $j = 0; $j < $count; $j++ ) {
			$bx = $x0 + $padX + $j * ( $barWidth + $barGap );
			$draw->setFillColor( new ImagickPixel( 'rgba(255,255,255,0.3)' ) );
			$draw->roundRectangle( $bx, $by, $bx + $barWidth - 1, $by + $barHeight - 1, $radius, $radius );

			$fraction = $j < $active ? 1.0 : ( $j === $active ? $fill : 0.0 );
			$fillWidth = $barWidth * min( 1.0, $fraction );
			if ( $fillWidth >= 1 ) {
				$draw->setFillColor( new ImagickPixel( '#ffffff' ) );
				$r = min( $radius, $fillWidth / 2 );
				$draw->roundRectangle( $bx, $by, $bx + $fillWidth - 1, $by + $barHeight - 1, $r, $r );
			}
		}

		$frame->drawImage( $draw );
		$draw->clear();
	}

	/**
	 * @return array{barsWidth:float,barWidth:float,barHeight:int,barGap:int}
	 */
	private static function barGeometry( int $count, float $scale ): array {
		$barsWidth = 96 * $scale;
		$barGap = (int)round( 5 * $scale, 0, PHP_ROUND_HALF_DOWN );
		return [
			'barsWidth' => $barsWidth,
			'barWidth' => ( $barsWidth - $barGap * ( $count - 1 ) ) / max( 1, $count ),
			'barHeight' => max( 2, (int)round( 3 * $scale, 0, PHP_ROUND_HALF_DOWN ) ),
			'barGap' => $barGap,
		];
	}

	/**
	 * @param Imagick[] $bases
	 */
	private function composeFrame( array $bases, array $entry, int $count, int $width, int $height, float $scale ): Imagick {
		if ( $entry['prev'] === null ) {
			$frame = clone $bases[$entry['slide']];
		} else {
			// track slides left: previous slide exits, current slide enters from the right
			$offset = (int)round( $entry['progress'] * $width );
			$frame = new Imagick();
			$frame->newImage( $width, $height, new ImagickPixel( '#000000' ) );
			$frame->compositeImage( $bases[$entry['prev']], Imagick::COMPOSITE_COPY, -$offset, 0 );
			$frame->compositeImage( $bases[$entry['slide']], Imagick::COMPOSITE_COPY, $width - $offset, 0 );
		}

		// a single slide is a still image; leave its bar empty
		$fill = $count > 1 ? $entry['elapsed'] / self::SLIDE_MS : 0.0;
		$this->drawPill( $frame, $entry['slide'], $fill, $count, $width, $height, $scale );

		return $frame;
	}

	/**
	 * @param Imagick[] $bases
	 */
	private function buildPalette( array $bases, int $slide, int $count, int $width, int $height, float $scale ): Imagick {
		$sample = clone $bases[$slide];
		$this->drawPill( $sample, $slide, 0.5, $count, $width, $height, $scale );

		$stack = new Imagick();
		$stack->addImage( $sample );
		if ( $count > 1 ) {
			$stack->addImage( $bases[( $slide - 1 + $count ) % $count] );
		}
		$palette = $stack->appendImages( true );
		$palette->quantizeImage( 256, Imagick::COLORSPACE_SRGB, 0, false, false );

		$stack->clear();
		$sample->clear();

		return $palette;
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

	/**
	 * Baseline y for a line box at $top, centering the font's content area.
	 */
	private function baseline( ImagickDraw $draw, float $top, float $lineHeight ): float {
		$metrics = $this->measurer()->queryFontMetrics( $draw, 'Hg' );
		$ascender = $metrics['ascender'];
		$descender = $metrics['descender'];
		return $top + ( $lineHeight - ( $ascender - $descender ) ) / 2 + $ascender;
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

	/**
	 * @return int[] r, g, b
	 */
	public static function hslToRgb( int $hue, float $saturation, float $lightness ): array {
		$c = ( 1 - abs( 2 * $lightness - 1 ) ) * $saturation;
		$h = ( $hue % 360 ) / 60;
		$x = $c * ( 1 - abs( fmod( $h, 2 ) - 1 ) );
		$m = $lightness - $c / 2;

		if ( $h < 1 ) {
			[ $r, $g, $b ] = [ $c, $x, 0 ];
		} elseif ( $h < 2 ) {
			[ $r, $g, $b ] = [ $x, $c, 0 ];
		} elseif ( $h < 3 ) {
			[ $r, $g, $b ] = [ 0, $c, $x ];
		} elseif ( $h < 4 ) {
			[ $r, $g, $b ] = [ 0, $x, $c ];
		} elseif ( $h < 5 ) {
			[ $r, $g, $b ] = [ $x, 0, $c ];
		} else {
			[ $r, $g, $b ] = [ $c, 0, $x ];
		}

		return [
			(int)round( ( $r + $m ) * 255 ),
			(int)round( ( $g + $m ) * 255 ),
			(int)round( ( $b + $m ) * 255 ),
		];
	}
}
