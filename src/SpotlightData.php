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
 * Owns the spotlight (highlights carousel) data (shared by the home page HTML, the Discord embed animation and the link preview card (og:image)).
 *
 * Picks both groups of pages (the 'primary' picks shown on the home page/embed animation + the 'secondary' picks that enhance other things like the link preview card collage of thumbnails).
 */
class SpotlightData {
	private const CACHE_VERSION = 'v2';
	private const CARGO_TABLE = 'Obbies';
	private const API_BATCH = 50;
	private const CARGO_PAGE = 500; // cargoquery returns at most 500 rows per call
	private const CARGO_MAX_ROWS = 5000;

	private const DEFAULTS = [
		'count' => 7,
		'wideCount' => 15,
		'poolSize' => 250,
		'category' => 'Category:Obby',
		'excludeCategories' => [ 'Category:Stubs' ],
		'requireImage' => true,
		'requireDescription' => false,
		'requirePublic' => true,
		'excludePlayability' => [ 'verified_broken', 'verified_does_not_load' ],
		'minVisits' => 0,
		'minLength' => 0,
		'maxPerSubgenre' => 2,
		'rotation' => 86400,
		'temperature' => 0.05,
		'recencyHalfLife' => 365,
		'weights' => [
			'visits' => 1.0,
			'rating' => 0.5,
			'favorites' => 0.5,
			'length' => 0.5,
			'description' => 0.25,
			'recency' => 0.25,
		],
	];

	/** @var array|null */
	private static $picks = null;

	/**
	 * Main pick: the carousel slides and the Discord animation frames.
	 *
	 * @return list<array{title:string,url:string,thumbnail:?string,description:?string,pageLength:int,pageId:int,imageSha1:?string}>
	 */
	public static function getItems(): array {
		return self::getPicks()['main'];
	}

	/**
	 * Wider pick: the main pick followed by more pages, for layouts with more room such as the link preview card collage.
	 *
	 * @return list<array{title:string,url:string,thumbnail:?string,description:?string,pageLength:int,pageId:int,imageSha1:?string}>
	 */
	public static function getWideItems(): array {
		return self::getPicks()['wide'];
	}

	/**
	 * Merged spotlight settings ($wgObbyWikiHomePageSpotlight over the defaults).
	 */
	public static function getSettings(): array {
		global $wgObbyWikiHomePageSpotlight;

		$config = is_array( $wgObbyWikiHomePageSpotlight ?? null ) ? $wgObbyWikiHomePageSpotlight : [];
		$settings = $config + self::DEFAULTS;
		$settings['weights'] = ( is_array( $config['weights'] ?? null ) ? $config['weights'] : [] ) + self::DEFAULTS['weights'];

		$settings['count'] = max( 1, (int)$settings['count'] );
		$settings['wideCount'] = max( $settings['count'], (int)$settings['wideCount'] );
		$settings['poolSize'] = max( $settings['wideCount'], (int)$settings['poolSize'] );
		$settings['maxPerSubgenre'] = max( 0, (int)$settings['maxPerSubgenre'] );
		$settings['rotation'] = max( 0, (int)$settings['rotation'] );
		$settings['temperature'] = max( 0.0, (float)$settings['temperature'] );
		$settings['recencyHalfLife'] = max( 1, (int)$settings['recencyHalfLife'] );
		$settings['excludeCategories'] = array_values( (array)$settings['excludeCategories'] );
		$settings['excludePlayability'] = array_values( (array)$settings['excludePlayability'] );
		foreach ( $settings['weights'] as $name => $weight ) {
			$settings['weights'][$name] = max( 0.0, (float)$weight );
		}

		return $settings;
	}

	/**
	 * Seed of the current rotation period (0 when rotation is off).
	 */
	public static function getRotationSeed( array $settings ): int {
		return $settings['rotation'] > 0 ? intdiv( (int)\MWTimestamp::time(), $settings['rotation'] ) : 0;
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

	/**
	 * Plain text title from a displaytitle page prop, falling back to the page name when it is empty or does not name the page.
	 */
	public static function displayTitle( ?string $displayTitle, Title $title ): string {
		$plain = $displayTitle !== null ? trim( Sanitizer::stripAllTags( $displayTitle ) ) : '';
		if ( $plain === '' ) {
			return $title->getText();
		}

		// displaytitle can be any wikitext when $wgRestrictDisplayTitle is off; only keep one that still reads as the page name
		$normalise = static fn ( string $s ): string => mb_strtolower( preg_replace( '/[\s_]+/u', ' ', trim( $s ) ) );
		return $normalise( $plain ) === $normalise( $title->getText() ) ? $plain : $title->getText();
	}

	/**
	 * Every scored candidate, best first, plus the picks, for tuning (see maintenance/previewSpotlight.php). Bypasses the cache.
	 *
	 * @return array{settings:array,seed:int,source:string,candidates:array[],main:array[],wide:array[]}
	 */
	public static function explain(): array {
		$settings = self::getSettings();
		$seed = self::getRotationSeed( $settings );
		[ $source, $candidates ] = self::getCandidates( $settings );
		self::scoreCandidates( $candidates, $settings, $seed );
		usort( $candidates, static fn ( $a, $b ) => $b['score'] <=> $a['score'] );

		return [ 'settings' => $settings, 'seed' => $seed, 'source' => $source, 'candidates' => $candidates ] + self::fetchPicks();
	}

	/**
	 * @return array{main:array[],wide:array[]}
	 */
	private static function getPicks(): array {
		if ( self::$picks !== null ) {
			return self::$picks;
		}

		global $wgObbyWikiHomePageCacheTTL, $wgObbyWikiHomePageFeaturedPages;
		$ttl = (int)( $wgObbyWikiHomePageCacheTTL ?? 900 );

		if ( $ttl <= 0 ) {
			self::$picks = self::fetchPicks();
			return self::$picks;
		}

		$settings = self::getSettings();
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		// config and rotation period are part of the key so a change shows up immediately
		$key = $cache->makeKey(
			'obbywikihomepage',
			'spotlight-items',
			self::CACHE_VERSION,
			md5( json_encode( [ $wgObbyWikiHomePageFeaturedPages ?? [], $settings ] ) ),
			self::getRotationSeed( $settings )
		);

		$picks = $cache->getWithSetCallback( $key, $ttl,
			static function () {
				return self::fetchPicks();
			},
			[ 'pcTTL' => WANObjectCache::TTL_PROC_SHORT ]
		);

		self::$picks = is_array( $picks ) && isset( $picks['main'] ) && isset( $picks['wide'] ) ? $picks : [ 'main' => [], 'wide' => [] ];
		return self::$picks;
	}

	/**
	 * @return array{main:array[],wide:array[]}
	 */
	private static function fetchPicks(): array {
		global $wgObbyWikiHomePageFeaturedPages;

		$settings = self::getSettings();
		$featured = is_array( $wgObbyWikiHomePageFeaturedPages ?? null ) ? array_values( $wgObbyWikiHomePageFeaturedPages ) : [];

		if ( $featured ) {
			$main = self::getConfiguredObbyPages( $featured );
			$wide = $main;

			// top the wide pick up with automatic pages that aren't featured already
			if ( count( $wide ) < $settings['wideCount'] ) {
				$taken = array_column( $main, 'pageId' );
				foreach ( self::pickAutomatic( $settings, $taken )['wide'] as $item ) {
					if ( count( $wide ) >= $settings['wideCount'] ) {
						break;
					}

					$wide[] = $item;
				}
			}
		} else {
			[ 'main' => $main, 'wide' => $wide ] = self::pickAutomatic( $settings, [] );
		}

		// (source image SHA1 feeds the render hashes)
		$sha1s = [];
		foreach ( [ &$main, &$wide ] as &$list ) {
			foreach ( $list as &$item ) {
				$id = (int)$item['pageId'];
				if ( !array_key_exists( $id, $sha1s ) ) {
					$file = self::getSourceFile( $item );
					$sha1s[$id] = $file ? $file->getSha1() : null;
				}
				$item['imageSha1'] = $sha1s[$id];
			}
			unset( $item );
		}
		unset( $list );

		return [ 'main' => $main, 'wide' => $wide ];
	}

	/**
	 * @param array $settings
	 * @param int[] $excludeIds page ids that are already shown
	 * @return array{main:array[],wide:array[]}
	 */
	private static function pickAutomatic( array $settings, array $excludeIds ): array {
		[ , $candidates ] = self::getCandidates( $settings );
		if ( $excludeIds ) {
			$excluded = array_flip( $excludeIds );
			$candidates = array_values( array_filter( $candidates, static fn ( $c ) => !isset( $excluded[$c['item']['pageId']] ) ) );
		}

		$seed = self::getRotationSeed( $settings );
		self::scoreCandidates( $candidates, $settings, $seed );
		// Gumbel top-k: sorting by score / T + Gumbel noise samples without replacement in proportion to exp(score / T)
		usort( $candidates, static fn ( $a, $b ) => $b['key'] <=> $a['key'] );

		$main = [];
		$used = [];
		$perSubgenre = [];
		foreach ( $candidates as $i => $candidate ) {
			if ( count( $main ) >= $settings['count'] ) {
				break;
			}
			$genre = $candidate['subgenre'];
			if ( $genre !== null && $settings['maxPerSubgenre'] > 0 && ( $perSubgenre[$genre] ?? 0 ) >= $settings['maxPerSubgenre'] ) {
				continue;
			}
			$perSubgenre[$genre ?? ''] = ( $perSubgenre[$genre ?? ''] ?? 0 ) + 1;
			$main[] = $candidate['item'];
			$used[$i] = true;
		}

		// the cap can leave the main pick short when the pool is one genre; fill from what's left rather than show fewer
		foreach ( $candidates as $i => $candidate ) {
			if ( count( $main ) >= $settings['count'] ) {
				break;
			}
			if ( !isset( $used[$i] ) ) {
				$main[] = $candidate['item'];
				$used[$i] = true;
			}
		}

		$wide = $main;
		foreach ( $candidates as $i => $candidate ) {
			if ( count( $wide ) >= $settings['wideCount'] ) {
				break;
			}
			if ( !isset( $used[$i] ) ) {
				$wide[] = $candidate['item'];
			}
		}

		return [ 'main' => $main, 'wide' => $wide ];
	}

	/**
	 * Adds 'score' (0..1), 'signals' and the seeded sort 'key' to each candidate.
	 */
	private static function scoreCandidates( array &$candidates, array $settings, int $seed ): void {
		if ( !$candidates ) {
			return;
		}

		$maxLog = static function ( string $field ) use ( $candidates ): float {
			$max = 0.0;
			foreach ( $candidates as $c ) {
				$max = max( $max, log10( 1 + max( 0, (int)( $c[$field] ?? 0 ) ) ) );
			}
			return $max;
		};
		$maxVisits = $maxLog( 'visits' );
		$maxFavorites = $maxLog( 'favorites' );
		$maxLength = 0.0;
		foreach ( $candidates as $c ) {
			$maxLength = max( $maxLength, log10( 1 + $c['item']['pageLength'] ) );
		}

		$weights = $settings['weights'];
		$now = (int)\MWTimestamp::time();
		$halfLife = $settings['recencyHalfLife'] * 86400;
		// a like ratio with few votes is mostly noise; pull it toward 0.5 as if this many votes were split evenly
		$ratingPrior = 50;

		foreach ( $candidates as &$c ) {
			$signals = [
				'visits' => $c['visits'] !== null && $maxVisits > 0 ? log10( 1 + $c['visits'] ) / $maxVisits : null,
				'favorites' => $c['favorites'] !== null && $maxFavorites > 0 ? log10( 1 + $c['favorites'] ) / $maxFavorites : null,
				'rating' => $c['likes'] !== null && $c['dislikes'] !== null
					? ( $c['likes'] + $ratingPrior / 2 ) / ( $c['likes'] + $c['dislikes'] + $ratingPrior )
					: null,
				'length' => $maxLength > 0 ? log10( 1 + $c['item']['pageLength'] ) / $maxLength : 0.0,
				'description' => $c['item']['description'] ? 1.0 : 0.0,
				'recency' => $c['released'] !== null ? 0.5 ** ( max( 0, $now - $c['released'] ) / $halfLife ) : null,
			];

			// signals the source can't provide (no Cargo) drop out instead of counting as zero
			$sum = 0.0;
			$total = 0.0;
			foreach ( $signals as $name => $value ) {
				$weight = $weights[$name] ?? 0.0;
				if ( $value === null || $weight <= 0 ) {
					continue;
				}
				$sum += $weight * $value;
				$total += $weight;
			}
			$c['signals'] = $signals;
			$c['score'] = $total > 0 ? $sum / $total : 0.0;

			// uniform in (0, 1) that is stable for a page within one rotation period
			$u = ( hexdec( substr( md5( $seed . ':' . $c['item']['pageId'] ), 0, 12 ) ) + 0.5 ) / 2 ** 48;
			$gumbel = -log( -log( $u ) );
			$c['key'] = $settings['temperature'] > 0 ? $c['score'] / $settings['temperature'] + $gumbel : $c['score'];
		}
		unset( $c );
	}

	/**
	 * Eligible candidates with their ranking inputs, at most poolSize. With Cargo, rows are read by visits, highest first, a page of rows at a time until enough of them pass the filters.
	 *
	 * @return array{0:string,1:array[]} source name and candidates
	 */
	private static function getCandidates( array $settings ): array {
		$candidates = [];
		$seen = [];
		$offset = 0;
		$source = 'cargo';

		while ( count( $candidates ) < $settings['poolSize'] && $offset < self::CARGO_MAX_ROWS ) {
			$page = self::getCargoRows( $settings, $offset );
			if ( $page === null ) {
				if ( $offset === 0 ) {
					$source = 'category';
				}
				break;
			}

			[ $rows, $rowCount ] = $page;
			$rows = array_diff_key( $rows, $seen );
			$seen += $rows;
			array_push( $candidates, ...self::filterCandidates( $rows, $settings ) );

			if ( $rowCount < self::CARGO_PAGE ) {
				break;
			}
			$offset += self::CARGO_PAGE;
		}

		if ( $source === 'category' ) {
			$candidates = self::filterCandidates( self::getCategoryRows( $settings ), $settings );
		}

		// pages come in batches, so the last batch can overshoot; keep the highest visits (cargo) or newest (category)
		return [ $source, array_slice( $candidates, 0, $settings['poolSize'] ) ];
	}

	/**
	 * Drops rows whose page fails the page filters (category, image, description, length) and attaches the page data.
	 *
	 * @param array<int,array> $rows ranking inputs keyed by page id, in pool order
	 * @return array[]
	 */
	private static function filterCandidates( array $rows, array $settings ): array {
		$required = self::normalizeCategory( (string)( $settings['category'] ?? '' ) );
		$excluded = array_flip( array_filter( array_map( [ self::class, 'normalizeCategory' ], $settings['excludeCategories'] ) ) );
		$check = array_values( array_unique( array_filter( array_merge( [ $required ], array_keys( $excluded ) ) ) ) );

		$pages = [];
		foreach ( array_chunk( array_keys( $rows ), self::API_BATCH ) as $ids ) {
			foreach ( self::queryPages( [ 'pageids' => implode( '|', $ids ) ], $check ) as $page ) {
				$pages[(int)( $page['pageid'] ?? 0 )] = $page;
			}
		}

		$candidates = [];
		// walk $rows, not the API result, to keep the pool order
		foreach ( $rows as $id => $row ) {
			$page = $pages[$id] ?? null;
			$title = isset( $page['title'] ) ? Title::newFromText( $page['title'] ) : null;
			if ( !$title ) {
				continue;
			}

			// clcategories limits the list to the required and excluded categories
			$categories = array_map( static fn ( $c ) => self::normalizeCategory( (string)( $c['title'] ?? '' ) ), $page['categories'] ?? [] );
			if ( ( $required !== '' && !in_array( $required, $categories, true ) )
				|| array_intersect_key( array_flip( $categories ), $excluded )
			) {
				continue;
			}

			$item = self::buildItem( $page, $title );
			if ( ( $settings['requireImage'] && !$item['thumbnail'] )
				|| ( $settings['requireDescription'] && !$item['description'] )
				|| $item['pageLength'] < (int)$settings['minLength']
			) {
				continue;
			}

			$candidates[] = [ 'item' => $item ] + $row;
		}

		return $candidates;
	}

	/**
	 * "Tier_Obby", "category:tier obby" and "Category:Tier Obby" all become "Category:Tier Obby"; '' when empty or invalid.
	 */
	private static function normalizeCategory( string $name ): string {
		$name = trim( $name );

		if ( $name === '' ) {
			return '';
		}

		$title = Title::newFromText( $name, NS_CATEGORY );

		return $title && $title->getNamespace() === NS_CATEGORY ? $title->getPrefixedText() : '';
	}

	/**
	 * One page of Cargo rows keyed by page id, plus the raw row count (for paging), or null when Cargo or the table is unavailable.
	 *
	 * @return array{0:array<int,array>,1:int}|null
	 */
	private static function getCargoRows( array $settings, int $offset ): ?array {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Cargo' ) ) {
			return null;
		}

		$where = [ '_pageNamespace=0' ];
		if ( (int)$settings['minVisits'] > 0 ) {
			$where[] = 'visits>=' . (int)$settings['minVisits'];
		}
		if ( $settings['requirePublic'] ) {
			$where[] = 'is_public=1';
		}

		$data = self::runApi( [
			'action' => 'cargoquery',
			'tables' => self::CARGO_TABLE,
			'fields' => '_pageID=id,visits,likes,dislikes,favorites,subgenre,playability,year,month,day',
			'where' => implode( ' AND ', $where ),
			// _ID breaks ties so paging is stable
			'order_by' => 'visits DESC,_ID',
			'limit' => self::CARGO_PAGE,
			'offset' => $offset,
		] );
		if ( $data === null || !isset( $data['cargoquery'] ) ) {
			return null;
		}

		$excludedPlayability = array_flip( $settings['excludePlayability'] );
		$intOrNull = static fn ( $v ): ?int => is_numeric( $v ) ? (int)$v : null;

		$rows = [];
		foreach ( $data['cargoquery'] as $row ) {
			$row = $row['title'] ?? [];
			$id = (int)( $row['id'] ?? 0 );
			// pages that call the infobox twice store duplicate rows
			if ( $id <= 0 || isset( $rows[$id] ) || isset( $excludedPlayability[(string)( $row['playability'] ?? '' )] ) ) {
				continue;
			}

			$year = $intOrNull( $row['year'] ?? null );
			$released = null;
			if ( $year ) {
				$month = min( 12, max( 1, $intOrNull( $row['month'] ?? null ) ?? 1 ) );
				$day = min( 28, max( 1, $intOrNull( $row['day'] ?? null ) ?? 1 ) );
				$released = gmmktime( 0, 0, 0, $month, $day, $year ) ?: null;
			}

			$subgenre = trim( (string)( $row['subgenre'] ?? '' ) );
			$rows[$id] = [
				'visits' => $intOrNull( $row['visits'] ?? null ),
				'likes' => $intOrNull( $row['likes'] ?? null ),
				'dislikes' => $intOrNull( $row['dislikes'] ?? null ),
				'favorites' => $intOrNull( $row['favorites'] ?? null ),
				'subgenre' => $subgenre !== '' ? $subgenre : null,
				'released' => $released,
			];
		}

		return [ $rows, count( $data['cargoquery'] ) ];
	}

	/**
	 * Fallback without Cargo: the most recently categorised members of the configured category, with no stats.
	 *
	 * @return array<int,array>
	 */
	private static function getCategoryRows( array $settings ): array {
		$category = self::normalizeCategory( (string)( $settings['category'] ?? '' ) );
		if ( $category === '' ) {
			return [];
		}

		$rows = [];
		$continue = [];
		// read extra members since the page filters drop some
		while ( count( $rows ) < $settings['poolSize'] * 2 ) {
			$data = self::runApi( [
				'action' => 'query',
				'list' => 'categorymembers',
				'cmtitle' => $category,
				'cmnamespace' => '0',
				'cmtype' => 'page',
				'cmsort' => 'timestamp',
				'cmdir' => 'desc',
				'cmlimit' => '500',
			] + $continue );

			foreach ( $data['query']['categorymembers'] ?? [] as $member ) {
				$rows[(int)$member['pageid']] = [
					'visits' => null, 'likes' => null, 'dislikes' => null, 'favorites' => null, 'subgenre' => null, 'released' => null,
				];
			}

			if ( empty( $data['continue']['cmcontinue'] ) ) {
				break;
			}
			$continue = [ 'cmcontinue' => $data['continue']['cmcontinue'] ];
		}

		return $rows;
	}

	private static function getConfiguredObbyPages( array $pageTitles ): array {
		$pages = [];
		foreach ( array_chunk( $pageTitles, self::API_BATCH ) as $chunk ) {
			foreach ( self::queryPages( [ 'titles' => implode( '|', $chunk ) ], [] ) as $page ) {
				$title = isset( $page['title'] ) && isset( $page['pageid'] ) ? Title::newFromText( $page['title'] ) : null;
				if ( $title ) {
					$pages[$title->getPrefixedText()] = self::buildItem( $page, $title );
				}
			}
		}

		$ordered = [];
		foreach ( $pageTitles as $titleText ) {
			$wanted = Title::newFromText( (string)$titleText );
			if ( $wanted && isset( $pages[$wanted->getPrefixedText()] ) ) {
				$ordered[] = $pages[$wanted->getPrefixedText()];
			}
		}

		return $ordered;
	}

	/**
	 * Image, short description, display title, length and membership of the given categories for up to 50 pages.
	 *
	 * @param array $selector 'pageids' or 'titles'
	 * @param string[] $categories only these are listed under 'categories'
	 * @return array[]
	 */
	private static function queryPages( array $selector, array $categories ): array {
		$params = $selector + [
			'action' => 'query',
			'prop' => 'pageimages|pageprops|info',
			'piprop' => 'thumbnail',
			'pithumbsize' => '400',
			'pilimit' => (string)self::API_BATCH,
			'ppprop' => 'shortdesc|displaytitle',
		];
		if ( $categories ) {
			$params['prop'] .= '|categories';
			$params['clcategories'] = implode( '|', $categories );
			$params['cllimit'] = 'max';
		}

		$data = self::runApi( $params );
		return $data['query']['pages'] ?? [];
	}

	private static function runApi( array $params ): ?array {
		$api = new ApiMain( new FauxRequest( $params ), false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return null;
		}

		return $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] );
	}

	private static function buildItem( array $page, Title $title ): array {
		return [
			'title' => self::displayTitle( $page['pageprops']['displaytitle'] ?? null, $title ),
			'url' => $title->getLocalURL(),
			'thumbnail' => $page['thumbnail']['source'] ?? null,
			'description' => $page['pageprops']['shortdesc'] ?? null,
			'pageLength' => isset( $page['length'] ) ? (int)$page['length'] : 0,
			'pageId' => isset( $page['pageid'] ) ? (int)$page['pageid'] : 0,
		];
	}
}
