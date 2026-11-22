<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Imagick;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\Utils\ExecutableFinder;

/**
 * Handles hashing, compat, and settings for the animated spotlight image used in the Discord component embed; storage and render state live in RenderStore. Rendering done by RenderSpotlightJob or the maintenance script.
 */
class SpotlightAnimation {
	public const RENDER_VERSION = 3; // bump whenever SpotlightRenderer's drawing changes

	private const KIND = 'spotlight';

	/** @var bool */
	private static $loggedUnsupported = false;

	public static function isEnabled(): bool {
		global $wgObbyWikiHomePageSpotlightAnimation;
		return (bool)( $wgObbyWikiHomePageSpotlightAnimation ?? true );
	}

	public static function getSettings(): array {
		global $wgObbyWikiHomePageSpotlightAnimationFormat,
			$wgObbyWikiHomePageSpotlightAnimationWidth,
			$wgObbyWikiHomePageSpotlightAnimationQuality,
			$wgObbyWikiHomePageSpotlightAnimationMaxBytes;

		$format = strtolower( (string)( $wgObbyWikiHomePageSpotlightAnimationFormat ?? 'webp' ) );
		if ( !in_array( $format, [ 'webp', 'gif', 'avif' ], true ) ) {
			$format = 'webp';
		}
		$width = max( 320, (int)( $wgObbyWikiHomePageSpotlightAnimationWidth ?? 960 ) );
		if ( $format === 'gif' ) {
			// GIF transitions are expensive!
			$width = min( $width, 800 );
		} elseif ( $format === 'avif' ) {
			// SVT-AV1 rejects odd dimensions; multiples of 32 keep the 16:9 height even
			$width = intdiv( $width, 32 ) * 32;
		}
		return [
			'format' => $format,
			'width' => $width,
			'height' => SpotlightRenderer::heightFor( $width ),
			// avifenc's scale runs much higher than WebP's: q50 still beats WebP q80 on PSNR
			'quality' => min( 100, max( 1, (int)( $wgObbyWikiHomePageSpotlightAnimationQuality ?? ( $format === 'avif' ? 50 : 80 ) ) ) ),
			'maxBytes' => max( 1, (int)( $wgObbyWikiHomePageSpotlightAnimationMaxBytes ?? 6291456 ) ),
			// 16 puts the fast part of the ease-out on the 20 ms floor (50 fps)
			'transitionFrames' => $format === 'gif' ? 4 : 16,
			// 0 = one frame per pixel of bar fill (these frames only change the bar region)
			'holdFrames' => 0,
			'fonts' => self::getFonts(),
		];
	}

	/**
	 * @return array{bold:string,regular:string} TTF paths, $wgObbyWikiHomePageSpotlightAnimationFonts over the bundled Inter
	 */
	public static function getFonts(): array {
		global $wgObbyWikiHomePageSpotlightAnimationFonts;
		$fonts = is_array( $wgObbyWikiHomePageSpotlightAnimationFonts ?? null ) ? $wgObbyWikiHomePageSpotlightAnimationFonts : [];
		$fontDir = dirname( __DIR__ ) . '/resources/fonts';

		return [
			'bold' => $fonts['bold'] ?? $fontDir . '/Inter-Bold.ttf',
			'regular' => $fonts['regular'] ?? $fontDir . '/Inter-Regular.ttf',
		];
	}

	public static function computeHash( array $items, array $settings ): string {
		$slides = [];
		foreach ( $items as $item ) {
			$slides[] = [
				(string)$item['title'],
				(string)( $item['description'] ?? '' ),
				$item['imageSha1'] ?? 'placeholder',
			];
		}

		return sha1( json_encode( [
			'version' => self::RENDER_VERSION,
			'format' => $settings['format'],
			'width' => $settings['width'],
			'height' => $settings['height'],
			'quality' => $settings['quality'],
			'maxBytes' => $settings['maxBytes'],
			'transitionFrames' => $settings['transitionFrames'],
			'holdFrames' => $settings['holdFrames'],
			'fonts' => $settings['fonts'],
			'slides' => $slides,
		] ) );
	}

	public static function isSupported( string $format ): bool {
		static $supported = [];
		if ( !isset( $supported[$format] ) ) {
			if ( $format === 'avif' ) {
				// Imagick only draws the PNG frames and avifenc (libavif 1.x with SVT-AV1) encodes them
				$supported[$format] = class_exists( Imagick::class ) && Imagick::queryFormats( 'PNG' )
					&& isset( self::getAvifencVersions()['svt'] );
			} else {
				$supported[$format] = class_exists( Imagick::class ) && (bool)Imagick::queryFormats( strtoupper( $format ) );
			}
		}
		return $supported[$format];
	}

	/**
	 * Configured avifenc, else the first one on the usual paths and $PATH.
	 */
	private static function getAvifencPath(): ?string {
		global $wgObbyWikiHomePageSpotlightAnimationAvifenc;
		if ( $wgObbyWikiHomePageSpotlightAnimationAvifenc ) {
			return (string)$wgObbyWikiHomePageSpotlightAnimationAvifenc;
		}
		return ExecutableFinder::findInDefaultPaths( 'avifenc' ) ?: null;
	}

	/**
	 * @return array{libavif: string, svt: ?string}|null
	 */
	public static function getAvifencVersions(): ?array {
		$avifenc = self::getAvifencPath();
		if ( $avifenc === null || Shell::isDisabled() ) {
			return null;
		}

		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		// the mtime picks up a reinstalled avifenc without yielding for the TTL
		$key = $cache->makeKey( 'obbywikihomepage', 'avifenc-version', md5( $avifenc ), (int)@filemtime( $avifenc ) );
		$versions = $cache->getWithSetCallback( $key, $cache::TTL_HOUR, static function () use ( $avifenc ) {
			$output = Shell::command( $avifenc, '--version' )->includeStderr()->execute()->getStdout();
			if ( !preg_match( '/^Version:\s*(\S+)/m', $output, $m ) ) {
				return [];
			}

			return [
				'libavif' => $m[1],
				'svt' => preg_match( '/svt \[enc\]:\s*v?([^,)\s]+)/', $output, $svt ) ? $svt[1] : null
			];
		} );
		return $versions ?: null;
	}

	/**
	 * Animation URL when ready; otherwise queues a render and returns null.
	 */
	public static function getState( array $items ): ?string {
		if ( !self::isEnabled() || !$items ) {
			return null;
		}

		$settings = self::getSettings();
		$hash = self::computeHash( $items, $settings );

		$state = RenderStore::getCachedState( self::KIND, $hash );
		if ( $state !== null ) {
			return $state ?: null;
		}

		// the job may have run with a cache this request cannot see (e.g. APCu vs. CLI)
		$fileName = RenderStore::getFileName( self::KIND, $hash, $settings['format'] );
		if ( RenderStore::fileExists( $fileName ) ) {
			return RenderStore::markReady( self::KIND, $hash, $fileName );
		}

		if ( !self::isSupported( $settings['format'] ) ) {
			if ( !self::$loggedUnsupported ) {
				self::$loggedUnsupported = true;
				RenderStore::getLogger()->warning(
					'Spotlight animation is enabled but Imagick or the {format} format is unavailable; using the static thumbnail',
					[ 'format' => $settings['format'] ]
				);
			}
			return null;
		}

		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(
			new RenderSpotlightJob( [ 'kind' => self::KIND, 'hash' => $hash ] )
		);

		return null;
	}

	/**
	 * Render and store the animation for the current spotlight items.
	 *
	 * @param string|null $expectedHash skip when the current hash differs (job parameter)
	 * @param bool $force re-render even when the file already exists
	 * @param string|null $outPath write here instead of storing (development)
	 * @param string|null $dumpDir also write the frames as PNGs here (development)
	 * @return array status plus renderer stats when rendered
	 */
	public static function renderAndStore(
		?string $expectedHash = null, bool $force = false, ?string $outPath = null, ?string $dumpDir = null
	): array {
		$logger = RenderStore::getLogger();
		$items = SpotlightData::getItems();
		$settings = self::getSettings();
		$hash = self::computeHash( $items, $settings );
		$settings['dumpDir'] = $dumpDir;
		$format = $settings['format'];

		if ( $expectedHash !== null && $expectedHash !== $hash ) {
			// a newer job exists or will be queued by the next page view
			return [ 'status' => 'stale', 'hash' => $hash ];
		}
		if ( !$items ) {
			return [ 'status' => 'empty', 'hash' => $hash ];
		}

		$fileName = RenderStore::getFileName( self::KIND, $hash, $format );

		if ( $outPath === null && !$force && RenderStore::fileExists( $fileName ) ) {
			// cache eviction: the file is already there, only reset the key
			return [ 'status' => 'exists', 'hash' => $hash, 'url' => RenderStore::markReady( self::KIND, $hash, $fileName ) ];
		}

		if ( !self::isSupported( $format ) ) {
			$logger->warning( 'Spotlight animation: Imagick or the {format} format is unavailable', [ 'format' => $format ] );
			return [ 'status' => 'unsupported', 'hash' => $hash ];
		}

		if ( $format === 'avif' ) {
			$settings['avifenc'] = self::getAvifencPath();
		}
		$renderer = new SpotlightRenderer( $settings, $logger );
		$slides = self::buildSlides( $items );
		$start = microtime( true );

		try {
			if ( $outPath !== null ) {
				$stats = $renderer->render( $slides, $outPath );
				return [ 'status' => 'rendered', 'hash' => $hash, 'path' => $outPath, 'seconds' => microtime( true ) - $start ] + $stats;
			}

			$tmp = MediaWikiServices::getInstance()->getTempFSFileFactory()
				->newTempFSFile( 'obbywikihomepage-spotlight', $format );
			if ( !$tmp ) {
				throw new \RuntimeException( 'Could not create a temporary file' );
			}
			$stats = $renderer->render( $slides, $tmp->getPath() );

			$url = RenderStore::store( self::KIND, $hash, $tmp->getPath(), $fileName );
			unset( $tmp );

			if ( $url === null ) {
				return [ 'status' => 'failed', 'hash' => $hash ];
			}

			return [ 'status' => 'rendered', 'hash' => $hash, 'path' => $fileName, 'url' => $url,
				'seconds' => microtime( true ) - $start ] + $stats;
		} catch ( \Throwable $e ) {
			// mostly ImagickException, anything else would also just repeat on retry
			$logger->error( 'Spotlight animation render failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			if ( $outPath === null ) {
				RenderStore::markFailed( self::KIND, $hash );
			}
			return [ 'status' => 'failed', 'hash' => $hash, 'error' => $e->getMessage() ];
		}
	}

	public static function buildSlides( array $items ): array {
		$slides = [];
		foreach ( $items as $item ) {
			$file = SpotlightData::getSourceFile( $item );
			$path = $file ? $file->getLocalRefPath() : false;
			$description = $item['description'] ?? null;

			$slides[] = [
				'title' => SpotlightData::getPlainTitle( $item ),
				'description' => $description !== null ? trim( (string)$description ) : null,
				'imagePath' => is_string( $path ) && $path !== '' ? $path : null,
				'hue' => abs( crc32( (string)$item['title'] ) ) % 360,
			];
		}
		return $slides;
	}
}
