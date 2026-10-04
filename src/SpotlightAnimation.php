<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Imagick;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\Utils\ExecutableFinder;
use Psr\Log\LoggerInterface;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Handles hashing, compat, storage, states, and pruning for the animated spotlight image used in the Discord component embed. Rendering doen by RenderSpotlightJob or the maintenance script.
 */
class SpotlightAnimation {
	public const RENDER_VERSION = 3; // bump whenever SpotlightRenderer's drawing changes

	private const DIR = 'obbywikihomepage';
	private const KEEP_FILES = 3;
	private const READY_TTL = WANObjectCache::TTL_WEEK;
	private const FAILED_TTL = 300;
	private const QUEUED_TTL = 60; // throttles the file-exists check and job push while not ready

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
			$wgObbyWikiHomePageSpotlightAnimationMaxBytes,
			$wgObbyWikiHomePageSpotlightAnimationFonts;

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
		$fonts = is_array( $wgObbyWikiHomePageSpotlightAnimationFonts ?? null ) ? $wgObbyWikiHomePageSpotlightAnimationFonts : [];
		$fontDir = dirname( __DIR__ ) . '/resources/fonts';

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
			'fonts' => [
				'bold' => $fonts['bold'] ?? $fontDir . '/Inter-Bold.ttf',
				'regular' => $fonts['regular'] ?? $fontDir . '/Inter-Regular.ttf',
			],
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
				$avifenc = self::getAvifencPath();
				$supported[$format] = class_exists( Imagick::class ) && Imagick::queryFormats( 'PNG' ) && $avifenc !== null
					&& str_contains( Shell::command( $avifenc, '--version' )->includeStderr()->execute()->getStdout(), 'svt [enc]' );
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
	 * Animation URL when ready; otherwise queues a render and returns null.
	 */
	public static function getState( array $items ): ?string {
		if ( !self::isEnabled() || !$items ) {
			return null;
		}

		$settings = self::getSettings();
		$hash = self::computeHash( $items, $settings );
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		$url = $cache->get( self::readyKey( $cache, $hash ) );
		if ( is_string( $url ) && $url !== '' ) {
			return $url;
		}

		if ( $cache->get( self::failedKey( $cache, $hash ) ) ) {
			return null;
		}

		$queuedKey = $cache->makeKey( 'obbywikihomepage', 'spotlight-queued', $hash );
		if ( $cache->get( $queuedKey ) ) {
			return null;
		}
		$cache->set( $queuedKey, 1, self::QUEUED_TTL );

		// the job may have run with a cache this request cannot see (e.g. APCu vs. CLI)
		$path = self::getStoragePath( $hash, $settings['format'] );
		if ( self::getBackend()->fileExists( [ 'src' => $path ] ) ) {
			return self::markReady( $hash, $settings['format'] );
		}

		if ( !self::isSupported( $settings['format'] ) ) {
			if ( !self::$loggedUnsupported ) {
				self::$loggedUnsupported = true;
				self::getLogger()->warning(
					'Spotlight animation is enabled but Imagick or the {format} format is unavailable; using the static thumbnail',
					[ 'format' => $settings['format'] ]
				);
			}
			return null;
		}

		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(
			new RenderSpotlightJob( [ 'hash' => $hash ] )
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
		$logger = self::getLogger();
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

		$backend = self::getBackend();
		$path = self::getStoragePath( $hash, $format );

		if ( $outPath === null && !$force && $backend->fileExists( [ 'src' => $path ] ) ) {
			// cache eviction: the file is already there, only reset the key
			return [ 'status' => 'exists', 'hash' => $hash, 'url' => self::markReady( $hash, $format ) ];
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

			$status = $backend->prepare( [ 'dir' => self::getStorageDir() ] );
			if ( $status->isOK() ) {
				$status = $backend->quickStore( [
					'src' => $tmp->getPath(),
					'dst' => $path,
					'overwrite' => true,
					// the hash in the filename is the cache buster
					'headers' => [ 'Cache-Control' => 'public, max-age=31536000, immutable' ],
				] );
			}
			unset( $tmp );

			if ( !$status->isOK() ) {
				$logger->error( 'Spotlight animation: could not store {path}: {status}', [
					'path' => $path,
					'status' => (string)$status,
				] );

				self::markFailed( $hash );
				return [ 'status' => 'failed', 'hash' => $hash ];
			}

			$url = self::markReady( $hash, $format );
			self::prune( $path );

			return [ 'status' => 'rendered', 'hash' => $hash, 'path' => $path, 'url' => $url,
				'seconds' => microtime( true ) - $start ] + $stats;
		} catch ( \Throwable $e ) {
			// mostly ImagickException, anything else would also just repeat on retry
			$logger->error( 'Spotlight animation render failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			if ( $outPath === null ) {
				self::markFailed( $hash );
			}
			return [ 'status' => 'failed', 'hash' => $hash, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Absolute URL of the stored animation for $hash.
	 */
	public static function getUrl( string $hash, string $format ): string {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$url = $repo->getZoneUrl( 'public' ) . '/' . self::DIR . '/' . self::getFileName( $hash, $format );
		return MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_CANONICAL ) ?? $url;
	}

	private static function buildSlides( array $items ): array {
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

	private static function markReady( string $hash, string $format ): string {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$url = self::getUrl( $hash, $format );
		$cache->set( self::readyKey( $cache, $hash ), $url, self::READY_TTL );

		return $url;
	}

	private static function markFailed( string $hash ): void {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		// so a broken render is not re-queued on every request
		$cache->set( self::failedKey( $cache, $hash ), 1, self::FAILED_TTL );
	}

	/**
	 * Keep the newest KEEP_FILES animations; older embeds may still point at the previous file.
	 */
	private static function prune( string $keepPath ): void {
		$backend = self::getBackend();
		$dir = self::getStorageDir();
		$list = $backend->getFileList( [ 'dir' => $dir, 'topOnly' => true ] );
		if ( $list === null ) {
			return;
		}

		$files = [];
		foreach ( $list as $name ) {
			if ( !preg_match( '/^spotlight-[0-9a-f]{16}\.(webp|gif|avif)$/', $name ) ) {
				continue;
			}

			$path = $dir . '/' . $name;
			if ( $path === $keepPath ) {
				continue;
			}

			$files[$path] = (string)$backend->getFileTimestamp( [ 'src' => $path ] );
		}

		arsort( $files );
		// the file just stored counts towards the kept ones
		foreach ( array_slice( array_keys( $files ), self::KEEP_FILES - 1 ) as $path ) {
			$status = $backend->quickDelete( [ 'src' => $path ] );
			if ( !$status->isOK() ) {
				self::getLogger()->warning( 'Spotlight animation: could not prune {path}', [ 'path' => $path ] );
			}
		}
	}

	private static function readyKey( WANObjectCache $cache, string $hash ): string {
		return $cache->makeKey( 'obbywikihomepage', 'spotlight-ready', $hash );
	}

	private static function failedKey( WANObjectCache $cache, string $hash ): string {
		return $cache->makeKey( 'obbywikihomepage', 'spotlight-failed', $hash );
	}

	private static function getBackend(): FileBackend {
		return MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->getBackend();
	}

	private static function getStorageDir(): string {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		return $repo->getZonePath( 'public' ) . '/' . self::DIR;
	}

	private static function getStoragePath( string $hash, string $format ): string {
		return self::getStorageDir() . '/' . self::getFileName( $hash, $format );
	}

	private static function getFileName( string $hash, string $format ): string {
		return 'spotlight-' . substr( $hash, 0, 16 ) . '.' . $format;
	}

	private static function getLogger(): LoggerInterface {
		return LoggerFactory::getInstance( 'ObbyWikiHomePage' );
	}
}
