<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use MediaWiki\Api\ApiMain;
use MediaWiki\FileRepo\File\File;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Owns the spotlight (highlights carousel) slide list, shared by the home page HTML and the Discord embed animation.
 */
class SpotlightData {
	private const CACHE_VERSION = 'v1';
	private const MAX_ITEMS = 7;

	/** @var array|null */
	private static $items = null;

	/**
	 * @return list<array{title:string,url:string,thumbnail:?string,description:?string,pageLength:int,pageId:int,imageSha1:?string}>
	 */
	public static function getItems(): array {
		if ( self::$items !== null ) {
			return self::$items;
		}

		global $wgObbyWikiHomePageCacheTTL, $wgObbyWikiHomePageFeaturedPages;
		$ttl = (int)( $wgObbyWikiHomePageCacheTTL ?? 900 );

		if ( $ttl <= 0 ) {
			self::$items = self::fetchItems();
			return self::$items;
		}

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		// config is part of the key so a featured pages change is picked up immediately
		$key = $cache->makeKey(
			'obbywikihomepage',
			'spotlight-items',
			self::CACHE_VERSION,
			md5( json_encode( $wgObbyWikiHomePageFeaturedPages ?? [] ) )
		);

		$items = $cache->getWithSetCallback( $key, $ttl,
			static function () {
				return self::fetchItems();
			},
			[ 'pcTTL' => WANObjectCache::TTL_PROC_SHORT ]
		);

		self::$items = is_array( $items ) ? $items : [];
		return self::$items;
	}

	public static function getPlainTitle( array $item ): string {
		return trim( Sanitizer::stripAllTags( (string)$item['title'] ) );
	}

	public static function getSourceFile( array $item ): ?File {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'PageImages' ) || empty( $item['pageId'] ) ) {
			return null;
		}

		$title = Title::newFromID( (int)$item['pageId'] );
		if ( !$title ) {
			return null;
		}

		/** @var \PageImages\PageImages $pageImages */
		$pageImages = MediaWikiServices::getInstance()->getService( 'PageImages.PageImages' );
		$file = $pageImages->getImage( $title );

		return ( $file && $file->exists() ) ? $file : null;
	}

	private static function fetchItems(): array {
		global $wgObbyWikiHomePageFeaturedPages;

		if ( isset( $wgObbyWikiHomePageFeaturedPages ) && is_array( $wgObbyWikiHomePageFeaturedPages ) && count( $wgObbyWikiHomePageFeaturedPages ) > 0 ) {
			$items = self::getConfiguredObbyPages( $wgObbyWikiHomePageFeaturedPages );
		} else {
			$items = self::getObbyPages();
		}

		// source image sha1 feeds the animation hash; resolved here so it is cached with the list
		foreach ( $items as &$item ) {
			$file = self::getSourceFile( $item );
			$item['imageSha1'] = $file ? $file->getSha1() : null;
		}
		unset( $item );

		return $items;
	}

	private static function getObbyPages(): array {
		// use 'Above 1,000,000 visits' as the source, then filter by 'Category:Obby' membership and exclude 'Category:Stubs'. we want high-enough quality pages to be highlighted, preferrably
		$request = new FauxRequest( [
			'action' => 'query',
			'generator' => 'categorymembers',
			'gcmtitle' => 'Category:Above 1,000,000 visits',
			'gcmlimit' => '50',
			'gcmnamespace' => '0',
			'gcmsort' => 'timestamp',
			'gcmdir' => 'desc',
			'prop' => 'pageimages|pageprops|info|categories',
			'piprop' => 'thumbnail',
			'pithumbsize' => '400',
			'ppprop' => 'shortdesc|displaytitle',
			'clcategories' => 'Category:Obby|Category:Stubs'
		] );

		$api = new ApiMain( $request, false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return [];
		}

		$data = $api->getResult()->getResultData( null, [
			'Strip' => 'all',
		] );

		$pages = [];
		if ( isset( $data['query']['pages'] ) ) {
			foreach ( $data['query']['pages'] as $page ) {
				if ( !isset( $page['title'] ) ) continue;

				$inObby = false;
				$inStubs = false;
				if ( isset( $page['categories'] ) ) {
					foreach ( $page['categories'] as $cat ) {
						$catTitle = $cat['title'] ?? '';
						if ( $catTitle === 'Category:Obby' ) {
							$inObby = true;
						}
						if ( $catTitle === 'Category:Stubs' ) {
							$inStubs = true;
						}
					}
				}

				if ( !$inObby || $inStubs ) {
					continue;
				}

				$title = Title::newFromText( $page['title'] );
				if ( !$title ) {
					continue;
				}

				$pages[] = self::buildItem( $page, $title );

				// 7 features only
				if ( count( $pages ) >= self::MAX_ITEMS ) {
					break;
				}
			}
		}

		return $pages;
	}

	private static function getConfiguredObbyPages( array $pageTitles ): array {
		if ( empty( $pageTitles ) ) {
			return [];
		}

		$request = new FauxRequest( [
			'action' => 'query',
			'titles' => implode( '|', $pageTitles ),
			'prop' => 'pageimages|pageprops|info',
			'piprop' => 'thumbnail',
			'pithumbsize' => '400',
			'ppprop' => 'shortdesc|displaytitle',
		] );

		$api = new ApiMain( $request, false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return [];
		}

		$data = $api->getResult()->getResultData( null, [
			'Strip' => 'all',
		] );

		$pages = [];
		if ( isset( $data['query']['pages'] ) ) {
			foreach ( $data['query']['pages'] as $page ) {
				if ( !isset( $page['title'] ) ) continue;

				$title = Title::newFromText( $page['title'] );
				if ( !$title ) {
					continue;
				}

				$pages[$page['title']] = self::buildItem( $page, $title );
			}
		}

		$orderedPages = [];
		foreach ( $pageTitles as $titleText ) {
			$wantedTitle = Title::newFromText( $titleText );
			if ( !$wantedTitle ) {
				continue;
			}
			$wantedPrefixedText = $wantedTitle->getPrefixedText();

			foreach ( $pages as $pTitle => $pData ) {
				$pTitleObj = Title::newFromText( $pTitle );

				if ( $pTitleObj && $pTitleObj->getPrefixedText() === $wantedPrefixedText ) {
					$orderedPages[] = $pData;
					break;
				}
			}
		}

		return $orderedPages;
	}

	private static function buildItem( array $page, Title $title ): array {
		// little finicky, TODO FIXME
		$displayTitle = isset( $page['pageprops']['displaytitle'] ) ? $page['pageprops']['displaytitle'] : ucwords( $title->getText() );

		return [
			'title' => $displayTitle,
			'url' => $title->getLocalURL(),
			'thumbnail' => $page['thumbnail']['source'] ?? null,
			'description' => $page['pageprops']['shortdesc'] ?? null,
			'pageLength' => isset( $page['length'] ) ? (int)$page['length'] : 0,
			'pageId' => isset( $page['pageid'] ) ? (int)$page['pageid'] : 0
		];
	}
}
