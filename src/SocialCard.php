<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Imagick;
use MediaWiki\MediaWikiServices;

/**
 * The home page link preview image (og:image): logo, site name and tagline centred over a dimmed collage of the spotlight slides.
 * Rendered by RenderSpotlightJob or the maintenance script and stored through RenderStore.
 */
class SocialCard {
	public const RENDER_VERSION = 5; // bump whenever SocialCardRenderer's drawing changes

	public const TAGLINE = 'The community-run encyclopedia of Roblox obbies'; // TODO i18n

	private const KIND = 'card';
	private const EXTENSION = 'jpg';
	private const QUALITY = 85;

	public static function isEnabled(): bool {
		global $wgObbyWikiHomePageSocialCard;
		return (bool)( $wgObbyWikiHomePageSocialCard ?? true );
	}

	public static function isSupported(): bool {
		static $supported = null;
		$supported ??= class_exists( Imagick::class ) && (bool)Imagick::queryFormats( 'JPEG' );
		return $supported;
	}

	/**
	 * Card URL when ready; otherwise queues a render and returns null.
	 */
	public static function getState( array $items ): ?string {
		if ( !self::isEnabled() || !$items ) {
			return null;
		}

		$hash = self::computeHash( $items, self::getText() );
		$state = RenderStore::getCachedState( self::KIND, $hash );
		if ( $state !== null ) {
			return $state ?: null;
		}

		$fileName = RenderStore::getFileName( self::KIND, $hash, self::EXTENSION );
		if ( RenderStore::fileExists( $fileName ) ) {
			return RenderStore::markReady( self::KIND, $hash, $fileName );
		}

		if ( !self::isSupported() ) {
			return null;
		}

		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(
			new RenderSpotlightJob( [ 'kind' => self::KIND, 'hash' => $hash ] )
		);

		return null;
	}

	/**
	 * Render and store the card for the current spotlight items.
	 *
	 * @param string|null $expectedHash skip when the current hash differs (job parameter)
	 * @param bool $force re-render even when the file already exists
	 * @param string|null $outPath write here instead of storing (development)
	 * @return array status plus renderer stats when rendered
	 */
	public static function renderAndStore( ?string $expectedHash = null, bool $force = false, ?string $outPath = null ): array {
		$items = SpotlightData::getItems();
		$text = self::getText();
		$hash = self::computeHash( $items, $text );

		if ( $expectedHash !== null && $expectedHash !== $hash ) {
			return [ 'status' => 'stale', 'hash' => $hash ];
		}
		if ( !$items ) {
			return [ 'status' => 'empty', 'hash' => $hash ];
		}

		$fileName = RenderStore::getFileName( self::KIND, $hash, self::EXTENSION );
		if ( $outPath === null && !$force && RenderStore::fileExists( $fileName ) ) {
			return [ 'status' => 'exists', 'hash' => $hash, 'url' => RenderStore::markReady( self::KIND, $hash, $fileName ) ];
		}

		$logger = RenderStore::getLogger();
		if ( !self::isSupported() ) {
			$logger->warning( 'Social card: Imagick or JPEG support is unavailable' );
			return [ 'status' => 'unsupported', 'hash' => $hash ];
		}

		$renderer = new SocialCardRenderer( [ 'quality' => self::QUALITY, 'fonts' => SpotlightAnimation::getFonts() ], $logger );
		$slides = SpotlightAnimation::buildSlides( $items );
		$text['logoSvg'] = Hooks::logoSVG();
		$start = microtime( true );

		try {
			if ( $outPath !== null ) {
				$stats = $renderer->render( $slides, $text, $outPath );
				return [ 'status' => 'rendered', 'hash' => $hash, 'path' => $outPath, 'seconds' => microtime( true ) - $start ] + $stats;
			}

			$tmp = MediaWikiServices::getInstance()->getTempFSFileFactory()
				->newTempFSFile( 'obbywikihomepage-card', self::EXTENSION );
			if ( !$tmp ) {
				throw new \RuntimeException( 'Could not create a temporary file' );
			}
			$stats = $renderer->render( $slides, $text, $tmp->getPath() );
			$url = RenderStore::store( self::KIND, $hash, $tmp->getPath(), $fileName );
			unset( $tmp );

			if ( $url === null ) {
				return [ 'status' => 'failed', 'hash' => $hash ];
			}

			return [ 'status' => 'rendered', 'hash' => $hash, 'path' => $fileName, 'url' => $url,
				'seconds' => microtime( true ) - $start ] + $stats;
		} catch ( \Throwable $e ) {
			$logger->error( 'Social card render failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			if ( $outPath === null ) {
				RenderStore::markFailed( self::KIND, $hash );
			}
			return [ 'status' => 'failed', 'hash' => $hash, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Alt text for og:image:alt.
	 */
	public static function getAltText( array $items ): string {
		$text = self::getText();
		$titles = array_map( [ SpotlightData::class, 'getPlainTitle' ], $items );
		return $text['title'] . ': ' . $text['tagline'] . ( $titles ? '. Featuring ' . implode( ', ', $titles ) . '.' : '.' );
	}

	/**
	 * @return array{title:string,tagline:string}
	 */
	private static function getText(): array {
		global $wgSitename;

		return [
			'title' => (string)$wgSitename,
			'tagline' => self::TAGLINE,
		];
	}

	private static function computeHash( array $items, array $text ): string {
		$slides = [];
		foreach ( $items as $item ) {
			$slides[] = [ (string)$item['title'], $item['imageSha1'] ?? 'placeholder' ];
		}

		return sha1( json_encode( [
			'version' => self::RENDER_VERSION,
			'quality' => self::QUALITY,
			'fonts' => SpotlightAnimation::getFonts(),
			'text' => $text,
			'slides' => $slides,
		] ) );
	}
}
