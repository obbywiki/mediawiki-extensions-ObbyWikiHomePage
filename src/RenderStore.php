<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Storage and render state shared by the generated images (spotlight animation, social card). Files live under
 * obbywikihomepage/ in the public zone and are named by content hash, so they can be cached forever.
 *
 * A $kind ('spotlight', 'card') namespaces the cache keys and the file names.
 */
class RenderStore {
	private const DIR = 'obbywikihomepage';
	private const KEEP_FILES = 3;
	private const READY_TTL = WANObjectCache::TTL_WEEK;
	private const FAILED_TTL = 300;
	private const QUEUED_TTL = 60; // throttles the file-exists check and job push while not ready

	public static function getFileName( string $kind, string $hash, string $extension ): string {
		return $kind . '-' . substr( $hash, 0, 16 ) . '.' . $extension;
	}

	/**
	 * URL when ready, false when it failed recently or was just queued, null when the caller should check the file and maybe queue a render.
	 *
	 * @return string|false|null
	 */
	public static function getCachedState( string $kind, string $hash ) {
		$cache = self::getCache();

		// the file name, not the URL: the job may run under another host (CLI, job runner) than this request
		$fileName = $cache->get( self::key( $cache, $kind, 'ready', $hash ) );
		if ( is_string( $fileName ) && preg_match( '/^[a-z]+-[0-9a-f]{16}\.[a-z]+$/', $fileName ) ) {
			return self::getUrl( $fileName );
		}
		if ( $cache->get( self::key( $cache, $kind, 'failed', $hash ) ) ) {
			return false;
		}

		$queuedKey = self::key( $cache, $kind, 'queued', $hash );
		if ( $cache->get( $queuedKey ) ) {
			return false;
		}
		$cache->set( $queuedKey, 1, self::QUEUED_TTL );

		return null;
	}

	public static function fileExists( string $fileName ): bool {
		return (bool)self::getBackend()->fileExists( [ 'src' => self::getStoragePath( $fileName ) ] );
	}

	/**
	 * Store $srcPath and prune older files of the same kind.
	 *
	 * @return string|null URL, or null when the backend refused (logged and negatively cached)
	 */
	public static function store( string $kind, string $hash, string $srcPath, string $fileName ): ?string {
		$backend = self::getBackend();
		$path = self::getStoragePath( $fileName );

		$status = $backend->prepare( [ 'dir' => self::getStorageDir() ] );
		if ( $status->isOK() ) {
			$status = $backend->quickStore( [
				'src' => $srcPath,
				'dst' => $path,
				'overwrite' => true,
				// the hash in the filename is the cache buster
				'headers' => [ 'Cache-Control' => 'public, max-age=31536000, immutable' ],
			] );
		}

		if ( !$status->isOK() ) {
			self::getLogger()->error( 'Could not store {path}: {status}', [
				'path' => $path,
				'status' => (string)$status,
			] );
			self::markFailed( $kind, $hash );
			return null;
		}

		$url = self::markReady( $kind, $hash, $fileName );
		self::prune( $kind, $fileName );
		return $url;
	}

	public static function markReady( string $kind, string $hash, string $fileName ): string {
		$cache = self::getCache();
		$cache->set( self::key( $cache, $kind, 'ready', $hash ), $fileName, self::READY_TTL );

		return self::getUrl( $fileName );
	}

	public static function markFailed( string $kind, string $hash ): void {
		$cache = self::getCache();
		// so a broken render is not re-queued on every request
		$cache->set( self::key( $cache, $kind, 'failed', $hash ), 1, self::FAILED_TTL );
	}

	/**
	 * Absolute URL of a stored file, for the current request's server.
	 */
	public static function getUrl( string $fileName ): string {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$url = $repo->getZoneUrl( 'public' ) . '/' . self::DIR . '/' . $fileName;
		return MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_CANONICAL ) ?? $url;
	}

	public static function getLogger(): LoggerInterface {
		return LoggerFactory::getInstance( 'ObbyWikiHomePage' );
	}

	/**
	 * Keep the newest KEEP_FILES of this kind; older embeds and link previews may still point at the previous file.
	 */
	private static function prune( string $kind, string $keepName ): void {
		$backend = self::getBackend();
		$dir = self::getStorageDir();
		$list = $backend->getFileList( [ 'dir' => $dir, 'topOnly' => true ] );
		if ( $list === null ) {
			return;
		}

		$pattern = '/^' . preg_quote( $kind, '/' ) . '-[0-9a-f]{16}\.(webp|gif|avif|jpg|png)$/';
		$files = [];
		foreach ( $list as $name ) {
			if ( $name === $keepName || !preg_match( $pattern, $name ) ) {
				continue;
			}

			$path = $dir . '/' . $name;
			$files[$path] = (string)$backend->getFileTimestamp( [ 'src' => $path ] );
		}

		arsort( $files );
		// the file just stored counts towards the kept ones
		foreach ( array_slice( array_keys( $files ), self::KEEP_FILES - 1 ) as $path ) {
			$status = $backend->quickDelete( [ 'src' => $path ] );
			if ( !$status->isOK() ) {
				self::getLogger()->warning( 'Could not prune {path}', [ 'path' => $path ] );
			}
		}
	}

	private static function key( WANObjectCache $cache, string $kind, string $state, string $hash ): string {
		return $cache->makeKey( 'obbywikihomepage', $kind . '-' . $state, $hash );
	}

	private static function getCache(): WANObjectCache {
		return MediaWikiServices::getInstance()->getMainWANObjectCache();
	}

	private static function getBackend(): FileBackend {
		return MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->getBackend();
	}

	private static function getStorageDir(): string {
		$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		return $repo->getZonePath( 'public' ) . '/' . self::DIR;
	}

	private static function getStoragePath( string $fileName ): string {
		return self::getStorageDir() . '/' . $fileName;
	}
}
