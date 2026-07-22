<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Article;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Skin;
use Wikimedia\ObjectCache\WANObjectCache;

class Hooks {
	private const DISCOURSE_FEATURED_EXCERPT_MAX_CHARS = 360;
	private const BLOG_FEATURED_EXCERPT_MAX_CHARS = 360;
	private const BLOG_PROP_DATE = 'modernblog-date';
	private const BLOG_PROP_TITLE = 'modernblog-title';
	private const BLOG_PROP_AUTHOR = 'modernblog-author';
	private const BLOG_PROP_SUBTITLE = 'modernblog-subtitle';
	private const HOME_PAGE_CACHE_VERSION = 'v14'; // only reset for large changes
	private const TRENDING_THUMB_SIZE = 368;

	/** @var array<string,array{label:string,hue:int}> Category title => genre chip (priority order). */
	private const TRENDING_GENRE_CATEGORIES = [
		'Category:Tower Stage Obby' => [ 'label' => 'Tower Stage', 'hue' => 320 ],
		'Category:Difficulty Chart Obby' => [ 'label' => 'Difficulty Chart', 'hue' => 28 ],
		'Category:Classic Obby' => [ 'label' => 'Classic', 'hue' => 210 ],
		'Category:Tower Obby' => [ 'label' => 'Tower', 'hue' => 275 ],
		'Category:Gimmick Obby' => [ 'label' => 'Gimmick', 'hue' => 160 ],
		'Category:Tier Obby' => [ 'label' => 'Tiered', 'hue' => 45 ],
		'Category:Troll Obby' => [ 'label' => 'Troll', 'hue' => 350 ],
		'Category:Co-Op Obby' => [ 'label' => 'Co-Op', 'hue' => 195 ],
	];
	private const HOME_PAGE_CACHE_LOCK_TSE = 120;
	private const HOME_PAGE_CACHE_STALE_TTL = 3600;

	private static function isTargetPage( Title $title ): bool {
		global $wgObbyWikiHomePageTitle;
		$target = $wgObbyWikiHomePageTitle ?? 'Home';

		return $title->getNamespace() === NS_MAIN
			&& $title->getDBkey() === str_replace( ' ', '_', $target );
	}

	public static function onArticleViewHeader( Article $article, &$outputDone, &$pcache ) {
		$title = $article->getTitle();
		if ( !$title || !self::isTargetPage( $title ) ) {
			return;
		}

		$out = $article->getContext()->getOutput();
		$outputDone = true;
		$pcache = false;

		$out->setPageTitle( '' );
		$out->setSubtitle( '' );

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		global $wgObbyWikiHomePageCacheTTL;
		$ttl = (int)( $wgObbyWikiHomePageCacheTTL ?? 900 );

		if ( $ttl > 0 ) {
			$cacheKey = $cache->makeKey(
				'obbywikihomepage',
				'html',
				self::HOME_PAGE_CACHE_VERSION
			);
			$backupKey = $cache->makeKey(
				'obbywikihomepage',
				'html',
				self::HOME_PAGE_CACHE_VERSION,
				'backup'
			);
			$html = $cache->getWithSetCallback(
				$cacheKey,
				$ttl,
				static function ( $oldValue, &$callbackTtl, array &$setOpts, $oldAsOf ) use ( $cache, $backupKey ) {
					unset( $oldValue, $callbackTtl, $setOpts, $oldAsOf );
					$html = self::buildHomePage();

					if ( $html !== '' ) {
						$cache->set( $backupKey, $html, WANObjectCache::TTL_DAY );
					}

					return $html;
				},
				[
					'lockTSE' => self::HOME_PAGE_CACHE_LOCK_TSE,
					'staleTTL' => self::HOME_PAGE_CACHE_STALE_TTL,
					'busyValue' => static function () use ( $cache, $backupKey ) {
						$backup = $cache->get( $backupKey );

						if ( is_string( $backup ) && $backup !== '' ) {
							return $backup;
						}

						return self::buildHomePageBusyFallback();
					},
					'pcTTL' => WANObjectCache::TTL_PROC_SHORT,
				]
			);
		} else {
			$html = self::buildHomePage();
		}

		$out->addHTML( $html );
	}

	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		$title = $out->getTitle();
		if ( !$title || !self::isTargetPage( $title ) ) {
			return;
		}

		// add modules, styles, and classes
		$out->addModuleStyles( [ 'ext.ObbyWikiHomePage.styles' ] );
		$out->addModules( [ 'ext.ObbyWikiHomePage.scripts' ] );
		$out->addBodyClasses( [ 'obbywiki-homepage' ] );

		$description = 'Welcome to the Obby Wiki! The leading community-run and independent wiki for information and archives on Roblox obbies that anyone can contribute to.'; // TODO convert to config

		$out->addMeta( 'description', $description );
		$out->addHeadItem(
			'og-description',
			'<meta property="og:description" content="' . htmlspecialchars( $description ) . '"/>'
		);
	}

	private static function buildHomePageBusyFallback(): string {
		return '<div class="obbywiki-home obbywiki-home--loading">'
			. '<p>The home page is currently being refreshed. Please refresh the page in 6-12 seconds.</p></div>';
	}

	private static function buildHomePage(): string {
		$logoSVG = self::logoSVG();
		$carouselItems = self::getObbyPages();
		$siteStats = self::getSiteStatistics();
		$thisMonthPages = self::getThisMonthPages();
		$archiveMonths = self::getArchiveMonths();
		$recentChanges = self::getRecentChanges();
		$blogPosts = self::getBlogPosts();
		$trendingPages = self::getTrendingPages();
		return self::buildHomePageHTML(
			$logoSVG,
			$carouselItems,
			$siteStats,
			$thisMonthPages,
			$archiveMonths,
			$recentChanges,
			$blogPosts,
			$trendingPages
		);
	}

	private static function getObbyPages(): array {
		global $wgObbyWikiHomePageFeaturedPages;

		if ( isset( $wgObbyWikiHomePageFeaturedPages ) && is_array( $wgObbyWikiHomePageFeaturedPages ) && count( $wgObbyWikiHomePageFeaturedPages ) > 0 ) {
			return self::getConfiguredObbyPages( $wgObbyWikiHomePageFeaturedPages );
		}

		// use 'Above 1,000,000 visits' as the source, then filter by 'Category:Obby' membership and exclude 'Category:Stubs'
		// we want high-enough quality pages to be highlighted, preferrably
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
			'clcategories' => 'Category:Obby|Category:Stubs',
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

				$thumb = isset( $page['thumbnail']['source'] )
					? $page['thumbnail']['source']
					: null;
				$desc = isset( $page['pageprops']['shortdesc'] )
					? $page['pageprops']['shortdesc']
					: null;

				$pageLength = isset( $page['length'] ) ? (int)$page['length'] : 0;
				// $editCount = self::getRevisionCount( $page['title'] );

				// little finicky, TODO FIXME
				$displayTitle = isset( $page['pageprops']['displaytitle'] )
					? $page['pageprops']['displaytitle']
					: ucwords( $title->getText() );

				$pages[] = [
					'title' => $displayTitle,
					'url' => $title->getLocalURL(),
					'thumbnail' => $thumb,
					'description' => $desc,
					// 'editCount' => $editCount,
					'pageLength' => $pageLength,
				];

				// 7 features only
				if ( count( $pages ) >= 7 ) {
					break;
				}
			}
		}

		return $pages;
	}

	// private static function getRevisionCount( string $pageTitle ): int {
	// 	$request = new FauxRequest( [
	// 		'action' => 'query',
	// 		'titles' => $pageTitle,
	// 		'prop' => 'revisions',
	// 		'rvprop' => 'ids',
	// 		'rvlimit' => 'max',
	// 	] );

	// 	$api = new ApiMain( $request, false );

	// 	try {
	// 		$api->execute();
	// 	} catch ( \Throwable $e ) {
	// 		return 0;
	// 	}

	// 	$data = $api->getResult()->getResultData( null, [
	// 		'Strip' => 'all',
	// 	] );

	// 	if ( isset( $data['query']['pages'] ) ) {
	// 		foreach ( $data['query']['pages'] as $p ) {
	// 			if ( isset( $p['revisions'] ) ) {
	// 				return count( $p['revisions'] );
	// 			}
	// 		}
	// 	}

	// 	return 0;
	// }

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

				$thumb = isset( $page['thumbnail']['source'] )
					? $page['thumbnail']['source']
					: null;
				$desc = isset( $page['pageprops']['shortdesc'] )
					? $page['pageprops']['shortdesc']
					: null;

				$pageLength = isset( $page['length'] ) ? (int)$page['length'] : 0;

				$displayTitle = isset( $page['pageprops']['displaytitle'] )
					? $page['pageprops']['displaytitle']
					: ucwords( $title->getText() );

				$pages[$page['title']] = [
					'title' => $displayTitle,
					'url' => $title->getLocalURL(),
					'thumbnail' => $thumb,
					'description' => $desc,
					// 'editCount' => 0,
					'pageLength' => $pageLength,
				];
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

	private static function logoSVG(): string {
		return <<<'SVG'
<svg width="1080" height="1080" viewBox="0 0 1080 1080" fill="none" xmlns="http://www.w3.org/2000/svg">
<g clip-path="url(#clip0_1192_2)">
<mask id="mask0_1192_2" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="1080" height="1080">
<path fill-rule="evenodd" clip-rule="evenodd" d="M228.268 0L140 329.369V560.399V889.253L241 916.322V3.41217L228.268 0ZM790 1063.46L291 929.722V16.8124L790 150.546V1063.46ZM840 1076.86L851.729 1080L940 750.559V190.747L840 163.947V1076.86ZM1020 212.187V452.044L1080 228.268L1020 212.187ZM0 851.732L60 627.875V867.813L0 851.732ZM452.848 388.936L690.948 452.746L627.15 690.951L386.5 626.459L452.848 388.936Z" fill="#FA015A"/>
</mask>
<g mask="url(#mask0_1192_2)">
<rect x="114" width="136" height="1080" fill="#009FFF"/>
<rect width="114" height="1080" fill="#0061F3"/>
<rect x="962" width="114" height="1080" fill="#0061F3"/>
<rect x="250" width="576" height="1080" fill="#0061F3"/>
<rect x="826" width="136" height="1080" fill="#009FFF"/>
</g>
</g>
<defs>
<clipPath id="clip0_1192_2">
<rect width="1080" height="1080" fill="white"/>
</clipPath>
</defs>
</svg>
SVG;
	}

	private static function getSiteStatistics(): array {
		$request = new FauxRequest( [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'statistics',
		] );

		$api = new ApiMain( $request, false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return [ 'articles' => 0, 'pages' => 0, 'edits' => 0, 'images' => 0, 'users' => 0 ];
		}

		$data = $api->getResult()->getResultData( null, [
			'Strip' => 'all',
		] );

		$stats = $data['query']['statistics'] ?? [];

		return [
			'articles' => (int)( $stats['articles'] ?? 0 ),
			'pages' => (int)( $stats['pages'] ?? 0 ),
			'edits' => (int)( $stats['edits'] ?? 0 ),
			'images' => (int)( $stats['images'] ?? 0 ),
			'users' => (int)( $stats['users'] ?? 0 ),
		];
	}

	private static function getMonthAnchorInUTC(): \DateTimeImmutable {
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $now->modify( 'first day of this month' )->setTime( 0, 0 );
	}

	private static function formatMonthCategory( \DateTimeImmutable $month ): string {
		return $month->format( 'F Y' );
	}

	private static function formatMonthLabel( \DateTimeImmutable $month ): string {
		return $month->format( 'F' );
	}

	private static function getThisMonthPages(): array {
		$groups = [];
		$thisMonth = self::getMonthAnchorInUTC();
		$thisMonthStr = self::formatMonthCategory( $thisMonth );
		$pages = self::fetchCategoryPages( $thisMonthStr, 8 );

		if ( count( $pages ) > 0 ) {
			$groups[] = [
				'month' => self::formatMonthLabel( $thisMonth ),
				'pages' => $pages,
			];
		}

		if ( count( $pages ) < 5 ) {
			$lastMonth = $thisMonth->modify( '-1 month' );
			$lastMonthStr = self::formatMonthCategory( $lastMonth );
			if ( $lastMonthStr !== $thisMonthStr ) {
				$lastMonthPages = self::fetchCategoryPages( $lastMonthStr, 8 - count( $pages ) );
				if ( count( $lastMonthPages ) > 0 ) {
					$groups[] = [
						'month' => self::formatMonthLabel( $lastMonth ),
						'pages' => $lastMonthPages,
					];
				}
			}
		}

		return $groups;
	}

	private static function fetchCategoryPages( string $monthName, int $limit ): array {
		$catTitle = 'Category:' . $monthName;

		$request = new FauxRequest( [
			'action' => 'query',
			'generator' => 'categorymembers',
			'gcmtitle' => $catTitle,
			'gcmlimit' => '10',
			'gcmnamespace' => '0',
			'gcmsort' => 'timestamp',
			'gcmdir' => 'desc',
			'prop' => 'pageimages|pageprops',
			'piprop' => 'thumbnail',
			'pithumbsize' => '80',
			'ppprop' => 'displaytitle',
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
				if ( !isset( $page['title'] ) ) {
					continue;
				}

				$title = Title::newFromText( $page['title'] );
				if ( !$title ) {
					continue;
				}

				$displayTitle = isset( $page['pageprops']['displaytitle'] )
					? $page['pageprops']['displaytitle']
					: ucwords( $title->getText() );

				$thumb = isset( $page['thumbnail']['source'] )
					? $page['thumbnail']['source']
					: null;

				$pages[] = [
					'title' => $displayTitle,
					'url' => $title->getLocalURL(),
					'thumbnail' => $thumb,
				];

				if ( count( $pages ) >= $limit ) {
					break;
				}
			}
		}

		return $pages;
	}

	private static function fetchMonthArchiveCard( \DateTimeImmutable $month, string $label ): ?array {
		$monthName = self::formatMonthCategory( $month );
		$catTitle = 'Category:' . $monthName;

		$request = new FauxRequest( [
			'action' => 'query',
			'titles' => $catTitle,
			'prop' => 'categoryinfo',
		] );

		$api = new ApiMain( $request, false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return null;
		}

		$data = $api->getResult()->getResultData( null, [
			'Strip' => 'all',
		] );

		$count = 0;
		if ( isset( $data['query']['pages'] ) ) {
			foreach ( $data['query']['pages'] as $page ) {
				if ( isset( $page['categoryinfo']['pages'] ) ) {
					$count = (int)$page['categoryinfo']['pages'];
				}
			}
		}

		if ( $count <= 0 ) {
			return null;
		}

		$title = Title::newFromText( $catTitle );
		if ( !$title ) {
			return null;
		}

		return [
			'label' => $label,
			'url' => $title->getLocalURL(),
			'count' => $count,
		];
	}

	private static function getArchiveMonths(): array {
		$months = [];
		$seen = [];
		$thisMonth = self::getMonthAnchorInUTC();

		$thisMonthCard = self::fetchMonthArchiveCard( $thisMonth, 'This Month' );
		if ( $thisMonthCard ) {
			$months[] = $thisMonthCard;
			$seen[self::formatMonthCategory( $thisMonth )] = true;
		}

		// start from last month and go back up to 12 months (UTC month boundaries)
		for ( $i = 1; $i <= 12; $i++ ) {
			$archiveMonth = $thisMonth->modify( "-{$i} months" );
			$monthName = self::formatMonthCategory( $archiveMonth );
			if ( isset( $seen[$monthName] ) ) {
				continue;
			}
			$seen[$monthName] = true;

			$archiveCard = self::fetchMonthArchiveCard( $archiveMonth, $monthName );
			if ( $archiveCard ) {
				$months[] = $archiveCard;
			}
		}

		return $months;
	}

	private static function getRecentChanges(): array {
		$request = new FauxRequest( [
			'action' => 'query',
			'list' => 'recentchanges',
			'rcnamespace' => 0,
			'rcshow' => '!bot',
			'rcprop' => 'title|timestamp|user',
			'rclimit' => 50,
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

		$changes = [];
		$seen_titles = [];
		if ( isset( $data['query']['recentchanges'] ) ) {
			foreach ( $data['query']['recentchanges'] as $rc ) {
				$page_title = $rc['title'] ?? '';
				if ( $page_title === '' || isset( $seen_titles[$page_title] ) ) {
					continue;
				}

				$title = Title::newFromText( $page_title );
				if ( !$title ) {
					continue;
				}

				$seen_titles[$page_title] = true;
				$changes[] = [
					'title' => $title->getPrefixedText(),
					'url' => $title->getLocalURL(),
					'user' => $rc['user'] ?? '',
					'timestamp' => $rc['timestamp'] ?? '',
				];

				if ( count( $changes ) >= 8 ) {
					break;
				}
			}
		}

		return $changes;
	}

	 // soft-dep on TrendingArticles; top pages in a category by recent/all-time views
	// @return list<array{title:string,url:string,thumbnail:?string,description:?string,genre:?string,genre_hue:?int,views:int,views_period:string}>
	private static function getTrendingPages(): array {
		global $wgObbyWikiHomePageTrendingLimit, $wgObbyWikiHomePageTrendingCategory;

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'TrendingArticles' ) ) {
			return [];
		}

		$trending_query = \MediaWiki\Extension\Trending\TrendingQuery::class;
		if ( !class_exists( $trending_query ) ) {
			return [];
		}

		$limit = (int)( $wgObbyWikiHomePageTrendingLimit ?? 6 );
		if ( $limit <= 0 ) {
			return [];
		}

		$category_text = trim( (string)( $wgObbyWikiHomePageTrendingCategory ?? 'Category:Obby' ) );
		$category = Title::newFromText( $category_text );
		if ( !$category || !$category->inNamespace( NS_CATEGORY ) ) {
			return [];
		}

		$views_period = 'week';
		$pages = $trending_query::getTopPagesInCategory(
			$category,
			$limit,
			$trending_query::PERIOD_WEEK
		);
		if ( $pages === [] ) {
			$views_period = 'all';
			$pages = $trending_query::getTopPagesInCategory(
				$category,
				$limit,
				$trending_query::PERIOD_ALL
			);
		}
		if ( $pages === [] ) {
			return [];
		}

		return self::enrichTrendingPages( $pages, $views_period );
	}

	/**
	 * @param list<array{title:Title,count:int}> $pages
	 * @return list<array{title:string,url:string,thumbnail:?string,description:?string,genre:?string,genre_hue:?int,views:int,views_period:string}>
	 */
	private static function enrichTrendingPages( array $pages, string $views_period = 'week' ): array {
		$title_texts = [];
		foreach ( $pages as $entry ) {
			$title_texts[] = $entry['title']->getPrefixedText();
		}

		$genre_filter = implode( '|', array_keys( self::TRENDING_GENRE_CATEGORIES ) );

		$request = new FauxRequest( [
			'action' => 'query',
			'titles' => implode( '|', $title_texts ),
			'prop' => 'pageimages|pageprops|categories',
			'piprop' => 'thumbnail',
			'pithumbsize' => (string)self::TRENDING_THUMB_SIZE,
			'ppprop' => 'shortdesc|displaytitle',
			'clcategories' => $genre_filter,
			'cllimit' => 'max',
		] );

		$api = new ApiMain( $request, false );
		$by_prefixed = [];

		try {
			$api->execute();
			$data = $api->getResult()->getResultData( null, [
				'Strip' => 'all',
			] );

			if ( isset( $data['query']['pages'] ) ) {
				foreach ( $data['query']['pages'] as $page ) {
					if ( !isset( $page['title'] ) ) {
						continue;
					}
					$page_title = Title::newFromText( $page['title'] );
					if ( !$page_title ) {
						continue;
					}

					$display_title = $page_title->getText();
					if ( isset( $page['pageprops']['displaytitle'] ) ) {
						$stripped = Sanitizer::stripAllTags( (string)$page['pageprops']['displaytitle'] );
						if ( $stripped !== '' ) {
							$display_title = $stripped;
						}
					}

					$genre = self::resolveTrendingGenre( $page['categories'] ?? [] );
					$by_prefixed[$page_title->getPrefixedText()] = [
						'title' => $display_title,
						'url' => $page_title->getLocalURL(),
						'thumbnail' => $page['thumbnail']['source'] ?? null,
						'description' => $page['pageprops']['shortdesc'] ?? null,
						'genre' => $genre['label'] ?? null,
						'genre_hue' => $genre['hue'] ?? null,
					];
				}
			}
		} catch ( \Throwable $e ) {
			// fall through to title-only rows
		}

		// Prefer PageImages via TrendingPageMedia when available (same stack as category grids)
		$trending_media = \MediaWiki\Extension\Trending\TrendingPageMedia::class;
		$media = class_exists( $trending_media )
			? $trending_media::getForPages( $pages, self::TRENDING_THUMB_SIZE )
			: [];

		$result = [];
		foreach ( $pages as $entry ) {
			/** @var Title $title */
			$title = $entry['title'];
			$key = $title->getPrefixedText();
			$row = $by_prefixed[$key] ?? [
				'title' => $title->getText(),
				'url' => $title->getLocalURL(),
				'thumbnail' => null,
				'description' => null,
				'genre' => null,
				'genre_hue' => null,
			];

			$page_id = $title->getArticleID();
			$page_media = $media[$page_id] ?? [];
			if ( isset( $page_media['thumbnail']['source'] ) && is_string( $page_media['thumbnail']['source'] ) ) {
				$row['thumbnail'] = $page_media['thumbnail']['source'];
			}
			if ( empty( $row['description'] ) ) {
				$shortdesc = $page_media['shortdesc'] ?? '';
				if ( is_string( $shortdesc ) && $shortdesc !== '' ) {
					$row['description'] = $shortdesc;
				}
			}
			if ( isset( $page_media['display_title'] ) && is_string( $page_media['display_title'] ) ) {
				$stripped = Sanitizer::stripAllTags( $page_media['display_title'] );
				if ( $stripped !== '' ) {
					$row['title'] = $stripped;
				}
			}

			$row['views'] = (int)( $entry['count'] ?? 0 );
			$row['views_period'] = $views_period;
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * @param list<array{title?:string}> $categories
	 * @return array{label:string,hue:int}|null
	 */
	private static function resolveTrendingGenre( array $categories ): ?array {
		$present = [];
		foreach ( $categories as $cat ) {
			$cat_title = $cat['title'] ?? '';
			if ( $cat_title !== '' ) {
				$present[$cat_title] = true;
			}
		}

		foreach ( self::TRENDING_GENRE_CATEGORIES as $cat_title => $genre ) {
			if ( isset( $present[$cat_title] ) ) {
				return $genre;
			}
		}

		return null;
	}

	// private static function normalizeDiscourseExcerpt( string $excerpt ): string {
	// 	$t = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	// 	$t = preg_replace( '/\[\/?(quote|img|video|audio)[^\]]*\]/i', '', $t );
	// 	$t = preg_replace( '/\s+/u', ' ', $t );
	// 	return trim( $t );
	// }

	private static function truncateAnnouncementPlaintext( string $text, int $max_chars = 8000 ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}
		$chunk = mb_substr( $text, 0, $max_chars );
		$pos = mb_strrpos( $chunk, ' ' );
		if ( $pos !== false && $pos > (int)( $max_chars * 0.85 ) ) {
			$chunk = mb_substr( $chunk, 0, $pos );
		}
		return rtrim( $chunk ) . '…';
	}
	
	// private static function discourseFetchPrimaryTopicEnrichment( string $forum_base, int $topic_id ): ?array {
	// 	if ( $topic_id <= 0 ) {
	// 		return null;
	// 	}

	// 	$url = $forum_base . '/t/' . $topic_id . '.json';
	// 	$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
	// 	$req = $http->create(
	// 		$url,
	// 		[
	// 			'method' => 'GET',
	// 			'timeout' => 6,
	// 			'userAgent' => 'ObbyWikiHomePage/0.1 (MediaWiki; +https://obbywiki.com)',
	// 		],
	// 		__METHOD__
	// 	);

	// 	$status = $req->execute();
	// 	if ( !$status->isOK() ) {
	// 		return null;
	// 	}

	// 	$body = $req->getContent();
	// 	if ( $body === '' ) {
	// 		return null;
	// 	}

	// 	$data = json_decode( $body, true );
	// 	if ( !is_array( $data ) ) {
	// 		return null;
	// 	}

	// 	$plain = '';
	// 	$posts = $data['post_stream']['posts'] ?? null;
	// 	if ( is_array( $posts ) && $posts !== [] && is_array( $posts[0] ) ) {
	// 		$cooked = $posts[0]['cooked'] ?? '';
	// 		if ( is_string( $cooked ) && $cooked !== '' ) {
	// 			$plain = self::normalizeDiscourseExcerpt(
	// 				html_entity_decode( strip_tags( $cooked ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
	// 			);
	// 		}
	// 	}

	// 	$display_name = '';
	// 	$username = '';
	// 	$avatar_template = '';
	// 	$created_by = $data['details']['created_by'] ?? null;
	// 	if ( is_array( $created_by ) ) {
	// 		$username = isset( $created_by['username'] ) && is_string( $created_by['username'] )
	// 			? $created_by['username']
	// 			: '';
	// 		$display_name = isset( $created_by['name'] ) && is_string( $created_by['name'] )
	// 			? $created_by['name']
	// 			: '';
	// 		if ( $display_name === '' ) {
	// 			$display_name = $username;
	// 		}
	// 		$avatar_template = isset( $created_by['avatar_template'] ) && is_string( $created_by['avatar_template'] )
	// 			? $created_by['avatar_template']
	// 			: '';
	// 	}

	// 	return [
	// 		'plain' => $plain,
	// 		'display_name' => $display_name,
	// 		'username' => $username,
	// 		'avatar_template' => $avatar_template,
	// 	];
	// }

	// private static function discourseAvatarSrc( string $forum_base, string $avatar_template, int $size = 48 ): string {
	// 	if ( $avatar_template === '' ) {
	// 		return '';
	// 	}
	// 	$path = str_replace( '{size}', (string)$size, $avatar_template );
	// 	if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
	// 		return $path;
	// 	}
	// 	return $forum_base . $path;
	// }

	// private static function discourseTopicOriginalPoster( array $topic, array $user_by_id ): array {
	// 	$posters = $topic['posters'] ?? [];
	// 	$op_id = 0;
	// 	if ( is_array( $posters ) ) {
	// 		foreach ( $posters as $poster ) {
	// 			if ( !is_array( $poster ) ) {
	// 				continue;
	// 			}
	// 			$desc = $poster['description'] ?? '';
	// 			if ( is_string( $desc ) && str_contains( $desc, 'Original Poster' ) ) {
	// 				$op_id = (int)( $poster['user_id'] ?? 0 );
	// 				break;
	// 			}
	// 		}
	// 		if ( !$op_id && isset( $posters[0]['user_id'] ) ) {
	// 			$op_id = (int)$posters[0]['user_id'];
	// 		}
	// 	}

	// 	$u = $user_by_id[$op_id] ?? [];
	// 	$username = is_array( $u ) && isset( $u['username'] ) && is_string( $u['username'] )
	// 		? $u['username']
	// 		: '';
	// 	$display = '';
	// 	if ( is_array( $u ) && isset( $u['name'] ) && is_string( $u['name'] ) && $u['name'] !== '' ) {
	// 		$display = $u['name'];
	// 	} else {
	// 		$display = $username;
	// 	}

	// 	$tpl = is_array( $u ) && isset( $u['avatar_template'] ) && is_string( $u['avatar_template'] )
	// 		? $u['avatar_template']
	// 		: '';

	// 	return [
	// 		'display_name' => $display,
	// 		'username' => $username,
	// 		'avatar_template' => $tpl,
	// 	];
	// }

	// private static function getDiscourseAnnouncements(): array {
	// 	global $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl;
	// 	global $wgObbyWikiHomePageDiscourseFetchPrimaryTopic;
	// 	$url = trim( (string)( $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl ?? '' ) );
	// 	if ( $url === '' ) {
	// 		return [];
	// 	}

	// 	$parsed = wfParseUrl( $url ); // TODO replace deprecated
	// 	if ( !$parsed || ( $parsed['scheme'] ?? '' ) !== 'https' || empty( $parsed['host'] ) ) {
	// 		return [];
	// 	}

	// 	$forum_base = 'https://' . $parsed['host'];

	// 	$fetch_url = wfAppendQuery( $url, [ 'per_page' => 30 ] );

	// 	$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
	// 	$req = $http->create(
	// 		$fetch_url,
	// 		[
	// 			'method' => 'GET',
	// 			'timeout' => 8,
	// 			'userAgent' => 'ObbyWikiHomePage/0.1 (MediaWiki; +https://obbywiki.com)',
	// 		],
	// 		__METHOD__
	// 	);

	// 	$status = $req->execute();
	// 	if ( !$status->isOK() ) {
	// 		return [];
	// 	}

	// 	$body = $req->getContent();
	// 	if ( $body === '' ) {
	// 		return [];
	// 	}

	// 	$data = json_decode( $body, true );
	// 	if ( !is_array( $data ) || !isset( $data['topic_list']['topics'] ) || !is_array( $data['topic_list']['topics'] ) ) {
	// 		return [];
	// 	}

	// 	$user_by_id = [];
	// 	if ( isset( $data['users'] ) && is_array( $data['users'] ) ) {
	// 		foreach ( $data['users'] as $u ) {
	// 			if ( is_array( $u ) && isset( $u['id'] ) ) {
	// 				$user_by_id[(int)$u['id']] = $u;
	// 			}
	// 		}
	// 	}

	// 	$topics = [];
	// 	foreach ( $data['topic_list']['topics'] as $t ) {
	// 		if ( !is_array( $t ) ) {
	// 			continue;
	// 		}

	// 		$id = $t['id'] ?? null;
	// 		$slug = $t['slug'] ?? '';
	// 		if ( $id === null || $slug === '' || !is_string( $slug ) ) {
	// 			continue;
	// 		}

	// 		$title = $t['fancy_title'] ?? null;
	// 		if ( !is_string( $title ) || $title === '' ) {
	// 			$title = $t['title'] ?? '';
	// 		}
	// 		if ( !is_string( $title ) || $title === '' ) {
	// 			continue;
	// 		}

	// 		$created_at = $t['created_at'] ?? '';
	// 		if ( !is_string( $created_at ) ) {
	// 			$created_at = '';
	// 		}

	// 		$excerpt = $t['excerpt'] ?? '';
	// 		if ( !is_string( $excerpt ) ) {
	// 			$excerpt = '';
	// 		}

	// 		$excerpt_plain = self::normalizeDiscourseExcerpt( $excerpt );

	// 		$poster = self::discourseTopicOriginalPoster( $t, $user_by_id );
	// 		$avatar_url = self::discourseAvatarSrc( $forum_base, $poster['avatar_template'], 48 );

	// 		$topics[] = [
	// 			'topic_id' => (int)$id,
	// 			'title' => $title,
	// 			'url' => $forum_base . '/t/' . $slug . '/' . (int)$id,
	// 			'excerpt_plain' => $excerpt_plain,
	// 			'created_at' => $created_at,
	// 			'poster_display_name' => $poster['display_name'],
	// 			'poster_username' => $poster['username'],
	// 			'poster_avatar_url' => $avatar_url,
	// 		];
	// 	}

	// 	usort(
	// 		$topics,
	// 		static function ( array $a, array $b ): int {
	// 			return strcmp( $b['created_at'], $a['created_at'] );
	// 		}
	// 	);

	// 	$topics = array_slice( $topics, 0, 6 );

	// 	$fetch_primary = (bool)( $wgObbyWikiHomePageDiscourseFetchPrimaryTopic ?? true );
	// 	if ( $fetch_primary && $topics !== [] ) {
	// 		$primary_id = (int)$topics[0]['topic_id'];
	// 		$enriched = self::discourseFetchPrimaryTopicEnrichment( $forum_base, $primary_id );
	// 		if ( $enriched !== null ) {
	// 			if ( $enriched['plain'] !== '' ) {
	// 				$topics[0]['excerpt_plain'] = self::truncateAnnouncementPlaintext(
	// 					$enriched['plain'],
	// 					self::DISCOURSE_FEATURED_EXCERPT_MAX_CHARS
	// 				);
	// 			}
	// 			if ( $enriched['username'] !== '' ) {
	// 				$topics[0]['poster_username'] = $enriched['username'];
	// 				$topics[0]['poster_display_name'] = $enriched['display_name'] !== ''
	// 					? $enriched['display_name']
	// 					: $enriched['username'];
	// 			}
	// 			if ( $enriched['avatar_template'] !== '' ) {
	// 				$topics[0]['poster_avatar_url'] = self::discourseAvatarSrc(
	// 					$forum_base,
	// 					$enriched['avatar_template'],
	// 					48
	// 				);
	// 			}
	// 		}
	// 	}

	// 	if ( $topics !== [] && $topics[0]['excerpt_plain'] === '' ) {
	// 		$topics[0]['excerpt_plain'] = 'Read the full announcement on the forum.';
	// 	}

	// 	$topic_count = count( $topics );
	// 	for ( $i = 1; $i < $topic_count; $i++ ) {
	// 		if ( $topics[$i]['excerpt_plain'] === '' ) {
	// 			$topics[$i]['excerpt_plain'] = 'Read more on the forum.';
	// 		}
	// 	}

	// 	foreach ( $topics as $i => $_ ) {
	// 		unset( $topics[$i]['topic_id'] );
	// 	}

	// 	return $topics;
	// }

	// private static function formatAnnouncementDateHtml( string $created_at ): string {
	// 	if ( $created_at === '' ) {
	// 		return '';
	// 	}
	// 	$unix = wfTimestamp( TS_UNIX, $created_at );
	// 	if ( !is_numeric( $unix ) ) {
	// 		return '';
	// 	}
	// 	if ( (int)$unix <= 0 ) {
	// 		return '';
	// 	}
	// 	$label = self::getRelativeTime( $created_at );
	// 	return '<time class="obbywiki-announce__date" datetime="'
	// 		. htmlspecialchars( $created_at, ENT_QUOTES ) . '">'
	// 		. htmlspecialchars( $label, ENT_QUOTES ) . '</time>';
	// }

	private static function getRelativeTime( string $timestamp ): string {
		$ts = wfTimestamp( TS_UNIX, $timestamp );
		$now = time();
		$diff = max( 0, $now - $ts );

		if ( $diff < 60 ) {
			return 'Just now';
		} elseif ( $diff < 3600 ) {
			$mins = (int)floor( $diff / 60 );
			return $mins . ' min' . ( $mins > 1 ? 's' : '' ) . ' ago';
		} elseif ( $diff < 86400 ) {
			$hours = (int)floor( $diff / 3600 );
			return $hours . ' hr' . ( $hours > 1 ? 's' : '' ) . ' ago';
		} else {
			$days = (int)floor( $diff / 86400 );
			return $days . ' day' . ( $days > 1 ? 's' : '' ) . ' ago';
		}
	}

	private static function getBlogNamespaceId(): ?int {
		global $wgObbyWikiHomePageBlogNamespaceName;
		$expectedName = trim( (string)( $wgObbyWikiHomePageBlogNamespaceName ?? 'Blog' ) );
		if ( $expectedName === '' ) {
			return null;
		}

		if ( class_exists( \MediaWiki\Extension\ModernBlog\BlogNamespace::class ) ) {
			return \MediaWiki\Extension\ModernBlog\BlogNamespace::getSubjectNamespaceId();
		}

		$namespaces = MediaWikiServices::getInstance()->getNamespaceInfo()->getCanonicalNamespaces();
		foreach ( $namespaces as $id => $name ) {
			if ( $id === NS_MAIN ) {
				continue;
			}

			if ( strcasecmp( $name, $expectedName ) === 0 ) {
				return $id;
			}
		}

		if ( defined( 'NS_MODERNBLOG' ) && NS_MODERNBLOG !== NS_MAIN ) {
			$name = $namespaces[NS_MODERNBLOG] ?? null;

			if ( is_string( $name ) && strcasecmp( $name, $expectedName ) === 0 ) {
				return NS_MODERNBLOG;
			}
		}

		return null;
	}

	private static function getBlogTimelineDbKey(): string {
		global $wgObbyWikiHomePageBlogTimelinePage;
		global $wgModernBlogTimelinePage;
		$name = trim( (string)( $wgObbyWikiHomePageBlogTimelinePage ?? $wgModernBlogTimelinePage ?? 'Timeline' ) );

		return str_replace( ' ', '_', $name !== '' ? $name : 'Timeline' );
	}

	private static function getBlogTimelineTitle(): ?Title {
		$nsId = self::getBlogNamespaceId();

		if ( $nsId === null ) {
			return null;
		}

		return Title::makeTitle( $nsId, self::getBlogTimelineDbKey() );
	}

	private static function isUsableBlogMetadata( string $value ): bool {
		$value = trim( $value );

		if ( $value === '' || preg_match( '/^frame(\{\})?$/i', $value ) ) {
			return false;
		}

		return !preg_match( '/[{}|<>]/', $value );
	}

	private static function normalizeBlogTimestamp( string $dateRaw ): ?string {
		$dateRaw = trim( $dateRaw );

		if ( $dateRaw === '' ) {
			return null;
		}

		if ( wfTimestamp( TS_MW, $dateRaw ) !== false ) {
			return wfTimestamp( TS_MW, $dateRaw );
		}

		$parsed = strtotime( $dateRaw );
		if ( $parsed !== false ) {
			return wfTimestamp( TS_MW, $parsed );
		}

		return null;
	}

	private static function normalizeBlogPlaintext( string $text ): string {
		$t = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( '/\[\[(?:[^\]|]*\|)?([^\]]*)\]\]/u', '$1', $t );
		$t = preg_replace( '/{{[^}]*}}/u', '', $t );
		$t = preg_replace( "/'{2,5}/u", '', $t );
		$t = strip_tags( $t );
		$t = preg_replace( '/\s+/u', ' ', $t );

		return trim( $t );
	}
	
	private static function loadBlogPageProps( $dbr, array $pageIds ): array {
		if ( $pageIds === [] ) {
			return [];
		}

		$propNames = [
			self::BLOG_PROP_DATE,
			self::BLOG_PROP_TITLE,
			self::BLOG_PROP_AUTHOR,
			self::BLOG_PROP_SUBTITLE,
			'displaytitle',
		];

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'pp_page', 'pp_propname', 'pp_value' ] )
			->from( 'page_props' )
			->where( [
				'pp_page' => $pageIds,
				'pp_propname' => $propNames,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$propsByPage = [];
		foreach ( $res as $row ) {
			$pageId = (int)$row->pp_page;
			$propsByPage[$pageId][$row->pp_propname] = $row->pp_value;
		}

		return $propsByPage;
	}

	private static function resolveBlogPostTimestamp( ?string $dateProp, Title $title ): ?string {
		if ( is_string( $dateProp ) && $dateProp !== '' ) {
			$normalized = self::normalizeBlogTimestamp( $dateProp );
			if ( $normalized !== null ) {
				return $normalized;
			}
		}

		$firstRevision = MediaWikiServices::getInstance()->getRevisionLookup()->getFirstRevision( $title );
		if ( $firstRevision !== null ) {
			return $firstRevision->getTimestamp();
		}

		return null;
	}

	private static function resolveBlogPostDisplayTitle(
		Title $title,
		?string $blogTitleProp,
		?string $displayTitleProp
	): string {
		if ( is_string( $blogTitleProp ) && self::isUsableBlogMetadata( $blogTitleProp ) ) {
			return trim( $blogTitleProp );
		}

		if ( is_string( $displayTitleProp ) && $displayTitleProp !== '' ) {
			return str_replace( '_', ' ', $displayTitleProp );
		}

		return str_replace( '_', ' ', $title->getText() );
	}

	private static function getBlogPostRevisionPlaintext( Title $title ): string {
		$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		$rev = $revisionLookup->getRevisionByTitle( $title );
		if ( $rev === null ) {
			return '';
		}

		$content = $rev->getContent( SlotRecord::MAIN );
		if ( $content === null ) {
			return '';
		}

		$text = $content->getText();
		$parts = preg_split( '/^---\s*$/m', $text );
		$lead = is_array( $parts ) ? (string)( $parts[0] ?? $text ) : $text;

		return self::normalizeBlogPlaintext( $lead );
	}

	private static function resolveBlogPostExcerpt(
		Title $title,
		array $pageProps,
		bool $allowRevisionFallback
	): string {
		$subtitle = $pageProps[self::BLOG_PROP_SUBTITLE] ?? null;
		if ( is_string( $subtitle ) && self::isUsableBlogMetadata( $subtitle ) ) {
			return trim( $subtitle );
		}

		if ( !$allowRevisionFallback ) {
			return '';
		}

		return self::getBlogPostRevisionPlaintext( $title );
	}

	private static function getBlogPosts(): array {
		global $wgObbyWikiHomePageBlogPostLimit;

		$limit = max( 1, (int)( $wgObbyWikiHomePageBlogPostLimit ?? 4 ) );
		$nsId = self::getBlogNamespaceId();
		if ( $nsId === null ) {
			return [];
		}

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		$timelineDbKey = self::getBlogTimelineDbKey();

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => $nsId,
				'page_is_redirect' => 0,
				$dbr->expr( 'page_title', '!=', $timelineDbKey ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$pageIds = [];
		$titlesById = [];
		foreach ( $res as $row ) {
			$pageId = (int)$row->page_id;
			$pageIds[] = $pageId;
			$titlesById[$pageId] = Title::makeTitle( $nsId, $row->page_title );
		}

		if ( $pageIds === [] ) {
			return [];
		}

		$propsByPage = self::loadBlogPageProps( $dbr, $pageIds );
		$posts = [];

		foreach ( $pageIds as $pageId ) {
			$title = $titlesById[$pageId];
			$pageProps = $propsByPage[$pageId] ?? [];
			$timestamp = self::resolveBlogPostTimestamp( $pageProps[self::BLOG_PROP_DATE] ?? null, $title );
			if ( $timestamp === null ) {
				continue;
			}

			$author = '';
			$authorProp = $pageProps[self::BLOG_PROP_AUTHOR] ?? null;
			if ( is_string( $authorProp ) && self::isUsableBlogMetadata( $authorProp ) ) {
				$author = trim( $authorProp );
			}

			$posts[] = [
				'title' => self::resolveBlogPostDisplayTitle(
					$title,
					$pageProps[self::BLOG_PROP_TITLE] ?? null,
					$pageProps['displaytitle'] ?? null
				),
				'url' => $title->getLocalURL(),
				'excerpt_plain' => '',
				'created_at' => $timestamp,
				'poster_display_name' => $author,
				'poster_username' => '',
				'poster_avatar_url' => '',
				'_title' => $title,
				'_page_props' => $pageProps,
			];
		}

		usort(
			$posts,
			static function ( array $a, array $b ): int {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		$posts = array_slice( $posts, 0, $limit );

		$postCount = count( $posts );
		for ( $i = 0; $i < $postCount; $i++ ) {
			$title = $posts[$i]['_title'];
			$pageProps = $posts[$i]['_page_props'];
			$allowRevisionFallback = $i === 0;
			$excerpt = self::resolveBlogPostExcerpt( $title, $pageProps, $allowRevisionFallback );

			if ( $excerpt !== '' ) {
				$maxChars = $i === 0 ? self::BLOG_FEATURED_EXCERPT_MAX_CHARS : 180;
				$posts[$i]['excerpt_plain'] = self::truncateAnnouncementPlaintext( $excerpt, $maxChars );
			} elseif ( $i === 0 ) {
				$posts[$i]['excerpt_plain'] = 'Read the full post on the wiki.';
			} else {
				$posts[$i]['excerpt_plain'] = 'Read more on the wiki.';
			}

			unset( $posts[$i]['_title'], $posts[$i]['_page_props'] );
		}

		return $posts;
	}

	private static function formatBlogPostDateHTML( string $timestamp ): string {
		if ( $timestamp === '' ) {
			return '';
		}

		$unix = wfTimestamp( TS_UNIX, $timestamp );
		if ( !is_numeric( $unix ) || (int)$unix <= 0 ) {
			return '';
		}

		$label = self::getRelativeTime( $timestamp );

		return '<time class="obbywiki-blog-card__date" datetime="'
			. htmlspecialchars( $timestamp, ENT_QUOTES ) . '">'
			. htmlspecialchars( $label, ENT_QUOTES ) . '</time>';
	}

	private static function buildBlogPostLogoHTML( string $logoSVG ): string {
		return '<div class="obbywiki-blog-card__logo" aria-hidden="true">' . $logoSVG . '</div>';
	}

	private static function buildBlogPostsHTML( array $blogPosts, string $logoSVG ): string {
		if ( $blogPosts === [] ) {
			return '';
		}

		$timelineTitle = self::getBlogTimelineTitle();
		$view_all_url = $timelineTitle !== null ? $timelineTitle->getLocalURL() : Title::newFromText( 'Blog:Timeline' )->getLocalURL();
		$view_all_esc = htmlspecialchars( $view_all_url, ENT_QUOTES );

		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M720-440v-80h160v80H720Zm48 280-128-96 48-64 128 96-48 64Zm-80-480-48-64 128-96 48 64-128 96ZM200-200v-160h-40q-33 0-56.5-23.5T80-440v-80q0-33 23.5-56.5T160-600h160l200-120v480L320-360h-40v160h-80Zm360-146v-268q27 24 43.5 58.5T620-480q0 41-16.5 75.5T560-346Z"/></svg>';

		$cardsHTML = '';
		foreach ( $blogPosts as $post ) {
			$title_esc = htmlspecialchars( $post['title'], ENT_QUOTES );
			$url_esc = htmlspecialchars( $post['url'], ENT_QUOTES );
			$blurb_esc = htmlspecialchars( $post['excerpt_plain'], ENT_QUOTES );
			$date_html = self::formatBlogPostDateHTML( (string)( $post['created_at'] ?? '' ) );
			$author = trim( (string)( $post['poster_display_name'] ?? '' ) );
			$author_html = $author !== ''
				? '<span class="obbywiki-blog-card__author">' . htmlspecialchars( $author, ENT_QUOTES ) . '</span>'
				: '';

			$cardsHTML .= '<a href="' . $url_esc . '" class="obbywiki-blog-card">'
				. self::buildBlogPostLogoHTML( $logoSVG )
				. '<div class="obbywiki-blog-card__body">'
				. '<h3 class="obbywiki-blog-card__title">' . $title_esc . '</h3>'
				. '<p class="obbywiki-blog-card__excerpt">' . $blurb_esc . '</p>'
				. '<div class="obbywiki-blog-card__meta">'
				. $date_html
				. $author_html
				. '</div>'
				. '</div>'
				. '</a>';
		}

		return '<section class="obbywiki-blog" aria-label="Announcements">'
			. '<div class="obbywiki-blog__header">'
			. '<div class="obbywiki-blog__header-main">'
			. '<span class="obbywiki-blog__icon">' . $icon_svg . '</span>'
			. '<h2 class="obbywiki-blog__title">Announcements</h2>'
			. '</div>'
			. '<a href="' . $view_all_esc . '" class="obbywiki-blog__all">View all</a>'
			. '</div>'
			. '<div class="obbywiki-blog__cards">' . $cardsHTML . '</div>'
			. '</section>';
	}

	// private static function buildAnnouncementAvatarHtml( array $item, int $size ): string {
	// 	$avatar_url = (string)( $item['poster_avatar_url'] ?? '' );
	// 	$username = (string)( $item['poster_username'] ?? '' );
	// 	$display = (string)( $item['poster_display_name'] ?? '' );
	// 	$initial_src = $username !== '' ? $username : ( $display !== '' ? $display : '?' );
	// 	$initial = mb_substr( $initial_src, 0, 1 );
	// 	$hue = abs( crc32( $initial_src ) ) % 360;
	// 	$size_attr = (string)$size;

	// 	if ( $avatar_url !== '' ) {
	// 		return '<img class="obbywiki-announce__avatar" src="' . htmlspecialchars( $avatar_url, ENT_QUOTES )
	// 			. '" alt="" width="' . $size_attr . '" height="' . $size_attr . '" loading="lazy" decoding="async">';
	// 	}

	// 	return '<span class="obbywiki-announce__avatar obbywiki-announce__avatar--placeholder" style="--announce-hue: '
	// 		. $hue . '">' . htmlspecialchars( $initial, ENT_QUOTES ) . '</span>';
	// }

	// private static function buildAnnouncementPosterMetaHtml( array $item ): string {
	// 	$display = (string)( $item['poster_display_name'] ?? '' );
	// 	$username = (string)( $item['poster_username'] ?? '' );

	// 	$html = '<div class="obbywiki-announce__poster-meta">';
	// 	if ( $display !== '' && ( $username === '' || strcasecmp( $display, $username ) !== 0 ) ) {
	// 		$html .= '<span class="obbywiki-announce__poster-display">' . htmlspecialchars( $display, ENT_QUOTES ) . '</span>';
	// 	}
	// 	if ( $username !== '' ) {
	// 		$html .= '<span class="obbywiki-announce__poster-user">@' . htmlspecialchars( $username, ENT_QUOTES ) . '</span>';
	// 	} elseif ( $display !== '' ) {
	// 		$html .= '<span class="obbywiki-announce__poster-user">' . htmlspecialchars( $display, ENT_QUOTES ) . '</span>';
	// 	}
	// 	$html .= '</div>';

	// 	return $html;
	// }

	// private static function buildAnnouncementsHTML( array $announcements ): string {
	// 	if ( $announcements === [] ) {
	// 		return '';
	// 	}

	// 	global $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl;
	// 	$view_all_url = 'https://forum.wou.gg/c/obby-wiki/obby-wiki-meta/13';
	// 	$p = wfParseUrl( trim( (string)( $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl ?? '' ) ) ); // TODO replace deprecated
	// 	if ( $p && !empty( $p['host'] ) ) {
	// 		$scheme = ( $p['scheme'] ?? '' ) === 'http' ? 'http' : 'https';
	// 		$view_all_url = $scheme . '://' . $p['host'] . '/c/obby-wiki/obby-wiki-meta/13';
	// 	}

	// 	$featured = $announcements[0];
	// 	$rest = array_slice( $announcements, 1 );

	// 	$view_all_esc = htmlspecialchars( $view_all_url, ENT_QUOTES );
	// 	$featured_title = htmlspecialchars( $featured['title'], ENT_QUOTES );
	// 	$featured_url = htmlspecialchars( $featured['url'], ENT_QUOTES );
	// 	$featured_blurb = htmlspecialchars( $featured['excerpt_plain'], ENT_QUOTES );

	// 	$featured_date = self::formatAnnouncementDateHtml( (string)( $featured['created_at'] ?? '' ) );

	// 	$featured_top = '<div class="obbywiki-announce__featured-top">'
	// 		. self::buildAnnouncementAvatarHtml( $featured, 52 )
	// 		. '<div class="obbywiki-announce__featured-head">'
	// 		. '<h4 class="obbywiki-announce__featured-title">' . $featured_title . '</h4>'
	// 		. self::buildAnnouncementPosterMetaHtml( $featured )
	// 		. $featured_date
	// 		. '</div>'
	// 		. '</div>';

	// 	$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M720-440v-80h160v80H720Zm48 280-128-96 48-64 128 96-48 64Zm-80-480-48-64 128-96 48 64-128 96ZM200-200v-160h-40q-33 0-56.5-23.5T80-440v-80q0-33 23.5-56.5T160-600h160l200-120v480L320-360h-40v160h-80Zm360-146v-268q27 24 43.5 58.5T620-480q0 41-16.5 75.5T560-346Z"/></svg>';

	// 	$body_class = $rest !== [] ? ' obbywiki-announce__body--split' : '';


		
	// 	$community_links = [
	// 		[ 'label' => 'Discussions', 'url' => 'https://forum.wou.gg/c/obby-wiki/12' ],
	// 		[ 'label' => 'Page Feedback', 'url' => 'https://forum.wou.gg/c/obby-wiki/obby-wiki-page-feedback/14' ],
	// 	];

	// 	$html = '<section class="obbywiki-announce" aria-label="Announcements">'
	// 		. '<div class="obbywiki-announce__header">'
	// 		. '<div class="obbywiki-announce__header-main">'
	// 		. '<span class="obbywiki-announce__icon">' . $icon_svg . '</span>'
	// 		. '<h3 class="obbywiki-announce__title">Announcements</h3>'
	// 		. '</div>'
	// 		. '<a href="' . $view_all_esc . '" class="obbywiki-announce__all">View all</a>'
	// 		. '</div>'
	// 		. '<div class="obbywiki-announce__body' . $body_class . '">'
	// 		. '<a href="' . $featured_url . '" class="obbywiki-announce__featured">'
	// 		. $featured_top
	// 		. '<p class="obbywiki-announce__featured-blurb">' . $featured_blurb . '</p>'
	// 		. '</a>';

	// 	if ( $rest !== [] ) {
	// 		$html .= '<div class="obbywiki-announce__list">'
	// 			. '<div class="obbywiki-announce__community-nav">';
			
	// 		foreach ( $community_links as $link ) {
	// 			$html .= '<a href="' . htmlspecialchars( $link['url'], ENT_QUOTES ) . '" class="obbywiki-announce__community-link">'
	// 				. htmlspecialchars( $link['label'], ENT_QUOTES )
	// 				. '</a>';
	// 		}

	// 		$html .= '</div>'
	// 			. '<div class="obbywiki-announce__compact-list">';

	// 		foreach ( $rest as $item ) {
	// 			$title_esc = htmlspecialchars( $item['title'], ENT_QUOTES );
	// 			$url_esc = htmlspecialchars( $item['url'], ENT_QUOTES );
	// 			$blurb_esc = htmlspecialchars( $item['excerpt_plain'], ENT_QUOTES );
	// 			$avatar = self::buildAnnouncementAvatarHtml( $item, 36 );
	// 			$meta = self::buildAnnouncementPosterMetaHtml( $item );
	// 			$compact_date = self::formatAnnouncementDateHtml( (string)( $item['created_at'] ?? '' ) );
	// 			$html .= '<a href="' . $url_esc . '" class="obbywiki-announce__compact">'
	// 				. '<div class="obbywiki-announce__compact-inner">'
	// 				. '<div class="obbywiki-announce__compact-avatar">' . $avatar . '</div>'
	// 				. '<div class="obbywiki-announce__compact-main">'
	// 				. '<span class="obbywiki-announce__compact-title">' . $title_esc . '</span>'
	// 				. '<p class="obbywiki-announce__compact-blurb">' . $blurb_esc . '</p>'
	// 				. '<div class="obbywiki-announce__compact-meta">'
	// 				. $compact_date
	// 				. '</div>'
	// 				. '</div>'
	// 				. '</div>'
	// 				. '</a>';
	// 		}
	// 		$html .= '</div></div>';
	// 	}

	// 	$html .= '</div></section>';

	// 	return $html;
	// }

	// MAIN
	// builds the full html
	private static function buildHomePageHTML( string $logoSVG, array $carouselItems, array $siteStats, array $thisMonthPages, array $archiveMonths, array $recentChanges = [], array $blogPosts = [], array $trendingPages = [] ): string {
		global $wgExtensionAssetsPath;
		$scriptPath = wfScript();
		$blogPostsHTML = self::buildBlogPostsHTML( $blogPosts, $logoSVG );

		$clAssetBase = ( $wgExtensionAssetsPath ?? '/extensions' ) . '/ObbyWikiHomePage/resources/images/cl/';

		// mini nav links
		$navLinks = [
			[
				'url' => Title::newFromText( 'Special:RandomInCategory/Obby' )->getLocalURL(),
				'label' => 'Random Obby',
				'iconSVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor"><path d="M342.5-257.5Q360-275 360-300t-17.5-42.5Q325-360 300-360t-42.5 17.5Q240-325 240-300t17.5 42.5Q275-240 300-240t42.5-17.5Zm0-360Q360-635 360-660t-17.5-42.5Q325-720 300-720t-42.5 17.5Q240-685 240-660t17.5 42.5Q275-600 300-600t42.5-17.5Zm180 180Q540-455 540-480t-17.5-42.5Q505-540 480-540t-42.5 17.5Q420-505 420-480t17.5 42.5Q455-420 480-420t42.5-17.5Zm180 180Q720-275 720-300t-17.5-42.5Q685-360 660-360t-42.5 17.5Q600-325 600-300t17.5 42.5Q635-240 660-240t42.5-17.5Zm0-360Q720-635 720-660t-17.5-42.5Q685-720 660-720t-42.5 17.5Q600-685 600-660t17.5 42.5Q635-600 660-600t42.5-17.5ZM200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/></svg>',
			],
			[
				'url' => Title::newFromText( 'Help:Contributing' )->getLocalURL(),
				'label' => 'Contribute',
				'iconSVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20"><g fill="currentColor"><path d="m16.77 8 1.94-2a1 1 0 0 0 0-1.41l-3.34-3.3a1 1 0 0 0-1.41 0L12 3.23zM1 14.25V19h4.75l9.96-9.96-4.75-4.75z"/></g></svg>',
			],
			[
				'url' => Title::newFromText( 'Blog:Timeline' )->getLocalURL(),
				'label' => 'Blog',
				'iconSVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor"><path d="M280-240q-17 0-28.5-11.5T240-280v-80h520v-360h80q17 0 28.5 11.5T880-680v600L720-240H280ZM80-280v-560q0-17 11.5-28.5T120-880h520q17 0 28.5 11.5T680-840v360q0 17-11.5 28.5T640-440H240L80-280Z"/></svg>',
			],
			[
				'url' => Title::newFromText( 'Special:AllPages' )->getLocalURL(),
				'label' => 'All Pages',
				'iconSVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path d="M5 1a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm0 3h5v1H5zm0 2h5v1H5zm0 2h5v1H5zm10 7H5v-1h10zm0-2H5v-1h10zm0-2H5v-1h10zm0-2h-4V4h4z"/></svg>',
				'badge' => (string)$siteStats['articles'],
			],
			[
				'url' => Title::newFromText( 'Special:RecentChanges' )->getLocalURL(),
				'label' => 'Recent Changes',
				'iconSVG' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor"><path d="M480-80q-155 0-269-103T82-440h81q15 121 105.5 200.5T480-160q134 0 227-93t93-227q0-134-93-227t-227-93q-86 0-159.5 42.5T204-640h116v80H88q29-140 139-230t253-90q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm112-232L440-464v-216h80v184l128 128-56 56Z"/></svg>',
			],
		];

		$navHTML = '';
		foreach ( $navLinks as $link ) {
			$urlEsc = htmlspecialchars( $link['url'] );
			$labelEsc = htmlspecialchars( $link['label'] );

			if ( isset( $link['iconSVG'] ) ) {
				$iconHTML = $link['iconSVG'];
			} else {
				$iconHTML = '<span class="obbywiki-home__nav-icon">' . $link['icon'] . '</span>';
			}

			$badgeHTML = '';
			if ( isset( $link['badge'] ) ) {
				$badgeHTML = '<span class="obbywiki-home__nav-badge">' . htmlspecialchars( $link['badge'] ) . '</span>';
			}

			$navHTML .= '<a href="' . $urlEsc . '" class="obbywiki-home__nav-btn" title="' . $labelEsc . '">'
				. $iconHTML . $badgeHTML
				. '</a>';
		}

		// spotlight/featured
		$slidesHTML = '';
		$dotsHTML = '';
		$index = 0;
		foreach ( $carouselItems as $item ) {
			$titleEsc = htmlspecialchars( $item['title'] );
			$urlEsc = htmlspecialchars( $item['url'] );
			$activeClass = $index === 0 ? ' obbywiki-spotlight__slide--active' : '';

			$descHTML = '';
			if ( $item['description'] ) {
				$descEsc = htmlspecialchars( $item['description'] );
				$descHTML = '<p class="obbywiki-spotlight__slide-desc">' . $descEsc . '</p>';
			}

			if ( $item['thumbnail'] ) {
				$thumbEsc = htmlspecialchars( $item['thumbnail'] );
				// prioritize first slide (LCP); lazy-load the rest
				$imgLoadingMode = $index === 0
					? ' fetchpriority="high"'
					: ' loading="lazy"';
				$mediaHtml = '<img class="obbywiki-spotlight__slide-img" src="'
					. $thumbEsc . '" alt="' . $titleEsc . '"' . $imgLoadingMode . '>';
			} else {
				$hash = crc32( $item['title'] );
				$hue = abs( $hash ) % 360;
				$initial = mb_substr( $item['title'], 0, 1 );
				$initialEsc = htmlspecialchars( $initial );
				$mediaHtml = '<div class="obbywiki-spotlight__slide-placeholder" style="--card-hue: '
					. $hue . '"><span>' . $initialEsc . '</span></div>';
			}

			// $statsHtml = '<div class="obbywiki-spotlight__slide-stats">';
			// if ( $item['editCount'] > 0 ) {
			// 	$statsHtml .= '<span class="obbywiki-spotlight__slide-stat">'
			// 		. '<svg viewBox="0 0 20 20" width="12" height="12" fill="currentColor"><path d="M16.77 8l1.94-2a1 1 0 000-1.41l-3.34-3.3a1 1 0 00-1.41 0L12 3.23zM1 14.25V17.5a.5.5 0 00.5.5h3.25a.5.5 0 00.35-.15l9.4-9.4-4.05-4.05-9.4 9.4a.5.5 0 00-.15.35z"/></svg>'
			// 		. htmlspecialchars( (string)$item['editCount'] ) . ' edits</span>';
			// }
			// if ( $item['pageLength'] > 0 ) {
			// 	$sizeStr = self::formatPageSize( $item['pageLength'] );
			// 	$statsHtml .= '<span class="obbywiki-spotlight__slide-stat">'
			// 		. '<svg viewBox="0 0 20 20" width="12" height="12" fill="currentColor"><path d="M15.5 1h-11A1.5 1.5 0 003 2.5v15A1.5 1.5 0 004.5 19h11a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0015.5 1M5 12h5.5v1H5zm0 3h3v1H5zm0-12h10v1H5zm0 3h10v1H5zm0 3h10v1H5z"/></svg>'
			// 		. htmlspecialchars( $sizeStr ) . '</span>';
			// }
			// $statsHtml .= '</div>';

			$slidesHTML .= '<a href="' . $urlEsc . '" class="obbywiki-spotlight__slide' . $activeClass
				. '" data-index="' . $index . '">'
				. '<div class="obbywiki-spotlight__slide-info">'
				. '<h2 class="obbywiki-spotlight__slide-title">' . $titleEsc . '</h2>'
				// . $statsHtml
				. $descHTML
				. '</div>'
				. '<div class="obbywiki-spotlight__slide-media">' . $mediaHtml . '</div>'
				. '</a>';

			$dotActive = $index === 0 ? ' obbywiki-spotlight__bar--active' : '';
			$dotsHTML .= '<span class="obbywiki-spotlight__bar' . $dotActive
				. '" data-index="' . $index . '" aria-hidden="true">'
				. '<span class="obbywiki-spotlight__bar-fill"></span></span>';

			$index++;
		}

		$emptyState = empty( $carouselItems )
			? '<div class="obbywiki-spotlight__empty"><p>No obbies found yet. Add pages to <a href="'
				. htmlspecialchars( Title::newFromText( 'Category:Obby' )->getLocalURL() )
				. '">Category:Obby</a> to see them here!</p></div>'
			: '';

		// content links
		$contentLinks = [
			[
				'url' => Title::newFromText( 'Category:Obby' )->getLocalURL(),
				'label' => 'All Obbies',
				'image' => $clAssetBase . 'ow_cl_v2_1_1x.webp',
				'priority' => 1, // always shown
			],
			[
				'url' => Title::newFromText( 'New' )->getLocalURL(),
				'label' => 'New Releases',
				'image' => $clAssetBase . 'ow_cl_v2_2_1x.webp',
				'priority' => 2,
			],
			[
				'url' => Title::newFromText( 'Category:Studio' )->getLocalURL(),
				'label' => 'Studios',
				'image' => $clAssetBase . 'ow_cl_v2_3_1x.webp',
				'priority' => 3,
			],
			[
				'url' => Title::newFromText( 'Tiers' )->getLocalURL(),
				'label' => 'Tiers',
				'image' => $clAssetBase . 'ow_cl_v2_4_1x.webp',
				'priority' => 4,
			],
			[
				'url' => Title::newFromText( 'Category:Above_1,000,000_visits' )->getLocalURL(),
				'label' => 'Popular',
				'image' => $clAssetBase . 'ow_cl_v2_5_1x.webp',
				'priority' => 5,
			],
			[
				'url' => Title::newFromText( 'Category:Tower_Obby' )->getLocalURL(),
				'label' => 'Towers',
				'image' => $clAssetBase . 'ow_cl_v2_6_1x.webp',
				'priority' => 6,
			],
			[
				'url' => Title::newFromText( 'List_of_all_known_obby-related_wikis' )->getLocalURL(),
				'label' => 'Wikis',
				'image' => $clAssetBase . 'ow_cl_v2_7_1x.webp',
				'priority' => 7,
			],
			[
				'url' => Title::newFromText( 'Obby' )->getLocalURL(),
				'label' => 'About Obbies',
				'image' => $clAssetBase . 'ow_cl_v2_8_1x.webp',
				'priority' => 8,
			],
			// [
			// 	'url' => Title::newFromText( 'Category:Developers' )->getLocalURL(),
			// 	'label' => 'Developers',
			// 	'image' => 'https://dummyimage.com/400x200/dc2626/ffffff&text=Developers',
			// 	'priority' => 4,
			// ],
			[
				'url' => Title::newFromText( 'Development:Get Started' )->getLocalURL(),
				'label' => 'Get Started',
				'image' => $clAssetBase . 'ow_cl_v2_9_1x.webp',
				'priority' => 9,
			],
			// [
			// 	'url' => Title::newFromText( 'Difficulties' )->getLocalURL(),
			// 	'label' => 'Difficulties',
			// 	'image' => 'https://dummyimage.com/400x200/0891b2/ffffff&text=Difficulties',
			// 	'priority' => 6,
			// ],
		];

		$contentLinksHTML = '';
		foreach ( $contentLinks as $cl ) {
			$clUrl = htmlspecialchars( $cl['url'] );
			$clLabel = htmlspecialchars( $cl['label'] );
			$clImage = htmlspecialchars( $cl['image'] );
			$clPriority = (int)$cl['priority'];
			$clClass = 'obbywiki-content-link';
			if ( $clPriority > 5 ) {
				$clClass .= ' obbywiki-content-link--low-priority';
			}
			$contentLinksHTML .= '<a href="' . $clUrl
				. '" class="' . $clClass . '" data-priority="' . $clPriority
				. '" style="background-image: url(' . $clImage . ')"'
				. '>'
				. '<span class="obbywiki-content-link__label">' . $clLabel . '</span>'
				. '</a>';
		}

		// this month
		$thisMonthHTML = '';
		$monthName = date( 'F' );
		if ( empty( $thisMonthPages ) ) {
			$thisMonthHTML = '<p class="obbywiki-featured__aside-month-empty">No new releases recently.</p>';
		} else {
			foreach ( $thisMonthPages as $group ) {
				$monthLabel = htmlspecialchars( $group['month'] );
				$thisMonthHTML .= '<h3 class="obbywiki-month__label">' . $monthLabel . '</h3>';
				
				foreach ( $group['pages'] as $mp ) {
					$mpUrl = htmlspecialchars( $mp['url'] );
					$mpTitle = htmlspecialchars( $mp['title'] );

					if ( $mp['thumbnail'] ) {
						$mpThumb = htmlspecialchars( $mp['thumbnail'] );
						$thumbHtml = '<img class="obbywiki-featured__aside-month-thumb" src="'
							. $mpThumb . '" alt="' . $mpTitle . '" loading="lazy">';
					} else {
						$hash = crc32( $mp['title'] );
						$hue = abs( $hash ) % 360;
						$initial = mb_substr( $mp['title'], 0, 1 );
						$thumbHtml = '<span class="obbywiki-featured__aside-month-thumb obbywiki-featured__aside-month-thumb--placeholder" style="--thumb-hue: '
							. $hue . '">' . htmlspecialchars( $initial ) . '</span>';
					}

					$thisMonthHTML .= '<a href="' . $mpUrl . '" class="obbywiki-featured__aside-month-item">'
						. $thumbHtml
						. '<span class="obbywiki-featured__aside-month-name">' . $mpTitle . '</span>'
						. '</a>';
				}
			}
		}

		// trending (soft-dep TrendingArticles)
		$trendingHTML = '';
		if ( !empty( $trendingPages ) ) {
			$trendingListHTML = '';
			foreach ( $trendingPages as $tp ) {
				$tpUrl = htmlspecialchars( $tp['url'] );
				$tpTitle = htmlspecialchars( $tp['title'] );
				$tpGenre = !empty( $tp['genre'] ) ? htmlspecialchars( $tp['genre'] ) : '';
				$tpDesc = !empty( $tp['description'] ) ? htmlspecialchars( $tp['description'] ) : '';
				$tpViews = (int)( $tp['views'] ?? 0 );
				$tpHue = isset( $tp['genre_hue'] ) ? (int)$tp['genre_hue'] : 210;

				$genreHtml = $tpGenre !== ''
					? '<span class="obbywiki-trending__genre" style="--type-hue: ' . $tpHue . '">'
						. $tpGenre . '</span>'
					: '';

				$viewsHtml = $tpViews > 0
					? '<span class="obbywiki-trending__views" title="views last week">'
						. htmlspecialchars( number_format( $tpViews ) ) . '</span>'
					: '';

				$descHtml = $tpDesc !== ''
					? '<p class="obbywiki-trending__desc">' . $tpDesc . '</p>'
					: '';

				$infoHtml = '<span class="obbywiki-trending__info">'
					. '<span class="obbywiki-trending__name">' . $tpTitle . '</span>'
					. $descHtml
					. '</span>';

				if ( !empty( $tp['thumbnail'] ) ) {
					$tpThumb = htmlspecialchars( $tp['thumbnail'] );
					$mediaHtml = '<span class="obbywiki-trending__media">'
						. '<img class="obbywiki-trending__image" src="' . $tpThumb
						. '" alt="" loading="lazy" decoding="async">'
						. $infoHtml
						. '</span>';
				} else {
					$hash = crc32( $tp['title'] );
					$hue = abs( $hash ) % 360;
					$initial = mb_substr( $tp['title'], 0, 1 );
					$mediaHtml = '<span class="obbywiki-trending__media obbywiki-trending__media--placeholder" style="--thumb-hue: '
						. $hue . '">'
						. '<span class="obbywiki-trending__placeholder-initial" aria-hidden="true">' . htmlspecialchars( $initial ) . '</span>'
						. $infoHtml
						. '</span>';
				}

				$metaHtml = ( $genreHtml !== '' || $viewsHtml !== '' )
					? '<span class="obbywiki-trending__meta">' . $genreHtml . $viewsHtml . '</span>'
					: '';

				$trendingListHTML .= '<a href="' . $tpUrl . '" class="obbywiki-trending__card">'
					. $mediaHtml
					. $metaHtml
					. '</a>';
			}

			$trendingHTML = '<section class="obbywiki-trending" aria-label="Trending">' .
				'<div class="obbywiki-trending__header">' .
					'<span class="obbywiki-trending__icon"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="m136-240-56-56 296-298 160 160 208-206H640v-80h240v240h-80v-104L536-320 376-480 136-240Z"/></svg></span>' .
					'<h2 class="obbywiki-trending__title">Trending</h2>' .
				'</div>' .
				'<div class="obbywiki-trending__grid">' . $trendingListHTML . '</div>' .
			'</section>';
		}

		// build category URLs for the aside
		$categoryURLs = [
			'classic' => htmlspecialchars( Title::newFromText( 'Category:Classic Obby' )->getLocalURL() ),
			'tower' => htmlspecialchars( Title::newFromText( 'Category:Tower Obby' )->getLocalURL() ),
			'towerstage' => htmlspecialchars( Title::newFromText( 'Category:Tower Stage Obby' )->getLocalURL() ),
			'dco' => htmlspecialchars( Title::newFromText( 'Category:Difficulty Chart Obby' )->getLocalURL() ),
			'gimmick' => htmlspecialchars( Title::newFromText( 'Category:Gimmick Obby' )->getLocalURL() ),
			'tier' => htmlspecialchars( Title::newFromText( 'Category:Tier Obby' )->getLocalURL() ),
			'troll' => htmlspecialchars( Title::newFromText( 'Category:Troll Obby' )->getLocalURL() ),
			'coop' => htmlspecialchars( Title::newFromText( 'Category:Co-Op Obby' )->getLocalURL() ),
			'obby' => htmlspecialchars( Title::newFromText( 'Category:Obby' )->getLocalURL() ),
			'stubs' => htmlspecialchars( Title::newFromText( 'Category:Stubs' )->getLocalURL() ),
			'contributing' => htmlspecialchars( Title::newFromText( 'Help:Contributing' )->getLocalURL() ),
		];

		// archive section html
		$archiveHTML = '';
		if ( !empty( $archiveMonths ) ) {
			$archiveCardsHTML = '';
			foreach ( $archiveMonths as $am ) {
				$amUrl = htmlspecialchars( $am['url'] );
				$amLabel = htmlspecialchars( $am['label'] );
				$amCount = (int)$am['count'];
				if ( $am['label'] === 'This Month' ) {
					$amDesc = htmlspecialchars( "View all {$amCount} obbies released this month" );
				} else {
					$amDesc = htmlspecialchars( "View all {$amCount} obbies released in {$am['label']}" );
				}
				$archiveCardsHTML .= '<a href="' . $amUrl . '" class="obbywiki-archive__card">' .
					'<span class="obbywiki-archive__card-title">' . $amLabel . '</span>' .
					'<span class="obbywiki-archive__card-desc">' . $amDesc . '</span>' .
					'</a>';
			}
			$archiveHTML = '<section class="obbywiki-archive" aria-label="Monthly archive">' .
				// '<div class="obbywiki-archive__header">' .
				// 	'<svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>' .
				// 	'<h3 class="obbywiki-archive__title">Archive</h3>' .
				// '</div>' .
				'<div class="obbywiki-archive__grid">' . $archiveCardsHTML . '</div>' .
			'</section>';
		}

		// recent changes html
		$recentChangesHTML = '';
		if ( !empty( $recentChanges ) ) {
			$rcListHTML = '';
			foreach ( $recentChanges as $rc ) {
				$rcArticleURL = htmlspecialchars( $rc['url'] );
				$rcTitle = htmlspecialchars( $rc['title'] );
				$rcUser = htmlspecialchars( $rc['user'] );
				$rcTime = htmlspecialchars( self::getRelativeTime( $rc['timestamp'] ) );

				$rcListHTML .= '<a href="' . $rcArticleURL . '" class="obbywiki-recent__item">' .
					'<span class="obbywiki-recent__item-title">' . $rcTitle . '</span>' .
					'<span class="obbywiki-recent__item-meta">' . $rcUser . ' · ' . $rcTime . '</span>' .
				'</a>';
			}

			$recentChangesHTML = '<section class="obbywiki-recent" aria-label="Recent Changes">' .
				'<div class="obbywiki-recent__header">' .
					'<span class="obbywiki-recent__icon"><svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M478-86q-152 0-264.5-101T87-440h127q15 99 89.5 163.5T478-212q112 0 190-78t78-190q0-112-78-190t-190-78q-57 0-109 23.5T279-657h82v97H94v-265h95v79q56-62 130.5-95T478-874q81 0 153 31t125.5 84.5Q810-705 841-633t31 153q0 81-31 153t-84.5 125.5Q703-148 631-117T478-86Zm107-218L433-456v-224h95v184l125 124-68 68Z"/></svg></span>' .
					'<h2 class="obbywiki-recent__title">Recently Changed</h2>' .
				'</div>' .
				'<div class="obbywiki-recent__list">' . $rcListHTML . '</div>' .
			'</section>';
		}

		// about section html
		$aboutURL = htmlspecialchars( Title::newFromText( 'OW:About' )->getLocalURL() );
		$aboutProjectsURL = htmlspecialchars( Title::newFromText( 'OW:About/Projects' )->getLocalURL() );
		$aboutWhyURL = htmlspecialchars( Title::newFromText( 'OW:About/Why' )->getLocalURL() );
		$obbyURL = htmlspecialchars( Title::newFromText( 'Obby' )->getLocalURL() );
		$allObbiesURL = htmlspecialchars( Title::newFromText( 'Category:Obby' )->getLocalURL() );
		$contributingURL = htmlspecialchars( Title::newFromText( 'Help:Contributing' )->getLocalURL() );
		$rulesURL = htmlspecialchars( Title::newFromText( 'OW:Rules' )->getLocalURL() );
		$styleGuideURL = htmlspecialchars( Title::newFromText( 'OW:Style guide' )->getLocalURL() );
		$helpURL = htmlspecialchars( Title::newFromText( 'Help:Contents' )->getLocalURL() );
		$classicURL = htmlspecialchars( Title::newFromText( 'Category:Classic Obby' )->getLocalURL() );
		$towerURL = htmlspecialchars( Title::newFromText( 'Category:Tower Obby' )->getLocalURL() );
		$dcoURL = htmlspecialchars( Title::newFromText( 'Category:Difficulty Chart Obby' )->getLocalURL() );
		$gimmickURL = htmlspecialchars( Title::newFromText( 'Category:Gimmick Obby' )->getLocalURL() );
		$articlesCount = number_format( $siteStats['articles'] );
		$userCount = number_format( $siteStats['users'] );
		$editsCount = number_format( $siteStats['edits'] );
		$aboutHTML = '<section class="obbywiki-about" aria-label="About the Wiki">' .
			'<div class="obbywiki-about__header">' .
				'<div class="obbywiki-about__header-main">' .
					'<span class="obbywiki-about__icon">' .
						'<svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="M425-265h110v-255H425v255Zm97.5-332.25Q540-614.5 540-640t-17.25-42.75Q505.5-700 480-700t-42.75 17.25Q420-665.5 420-640t17.5 42.75Q455-580 480-580t42.5-17.25ZM480-46q-91 0-169.99-34.08-78.98-34.09-137.41-92.52-58.43-58.43-92.52-137.41Q46-389 46-480q0-91 34.08-169.99 34.09-78.98 92.52-137.41 58.43-58.43 137.41-92.52Q389-914 480-914q91 0 169.99 34.08 78.98 34.09 137.41 92.52 58.43 58.43 92.52 137.41Q914-571 914-480q0 91-34.08 169.99-34.09 78.98-92.52 137.41-58.43 58.43-137.41 92.52Q571-46 480-46Z"/></svg>' .
					'</span>' .
					'<h2 class="obbywiki-about__title">About The Obby Wiki</h2>' .
				'</div>' .
			'</div>' .
			'<div class="obbywiki-about__content">' .
				'<div class="obbywiki-about__stats">' .
					'<div class="obbywiki-about__stat">' .
						'<span class="obbywiki-about__stat-value">' . $articlesCount . '</span>' .
						'<span class="obbywiki-about__stat-label">Articles</span>' .
					'</div>' .
					'<div class="obbywiki-about__stat">' .
						'<span class="obbywiki-about__stat-value">' . $editsCount . '</span>' .
						'<span class="obbywiki-about__stat-label">Edits</span>' .
					'</div>' .
					'<div class="obbywiki-about__stat">' .
						'<span class="obbywiki-about__stat-value">' . $userCount . '</span>' .
						'<span class="obbywiki-about__stat-label">Total Users</span>' .
					'</div>' .
					'<div class="obbywiki-about__since">Since April 2025</div>' .
				'</div>' .
				'<p class="obbywiki-about__text">An <a href="' . $obbyURL . '">obby</a> is a genre of game on Roblox that is essentially an obstacle course or 3D platformer. Players complete levels that gradually ascend in difficulty until the end of the game, with countless variations from <a href="' . $classicURL . '">classic platformers</a> to <a href="' . $towerURL . '">towers</a>, <a href="' . $dcoURL . '">difficulty chart obbies</a>, and <a href="' . $gimmickURL . '">unique spins on the genre</a>. It has been one of the platform\'s most popular genres since the mid-2010s, spanning hundreds of thousands of unique games.</p>' .
				'<p class="obbywiki-about__text">The Obby Wiki (also referred to as the Roblox Obby Wiki) is an independent, community-run encyclopedia dedicated to documenting Roblox obbies and everything surrounding them. From individual games, their creators, studios, mechanics, glitches, terminology, their communities, and more. Our goal is to provide the most comprehensive, accurate, and complete information about as many obbies as possible. The genre is consistently undocumented, with many games being forgotten entirely. This is <a href="' . $aboutWhyURL . '">why the Obby Wiki</a> exists.</p>' .
				'<p class="obbywiki-about__text">Help contribute to the largest database and collection of Roblox obbies ever created, with over <a href="' . $allObbiesURL . '">' . $articlesCount . '</a> articles and counting.</p>' .
				'<div class="obbywiki-about__footer">' .
					'<div class="obbywiki-about__footer-group obbywiki-about__footer-group--contribute">' .
						'<a href="' . $rulesURL . '" class="obbywiki-about__link">Wiki Rules & Guidelines</a>' .
						'<a href="' . $styleGuideURL . '" class="obbywiki-about__link">Style Guide</a>' .
						'<a href="' . $helpURL . '" class="obbywiki-about__link">Help</a>' .
						'<a href="' . $contributingURL . '" class="obbywiki-about__link">Contributing</a>' .
					'</div>' .
					'<div class="obbywiki-about__footer-end">' .
						'<span class="obbywiki-about__footer-divider" aria-hidden="true">|</span>' .
						'<div class="obbywiki-about__footer-group">' .
							'<a href="' . $aboutURL . '" class="obbywiki-about__link">About</a>' .
							'<a href="' . $aboutProjectsURL . '" class="obbywiki-about__link">Projects</a>' .
							'<a href="' . $aboutWhyURL . '" class="obbywiki-about__link">Why the Obby Wiki?</a>' .
						'</div>' .
					'</div>' .
				'</div>' .
			'</div>' .
		'</section>';

		// RAW CONSTRUCT

		return <<<HTML
<div class="obbywiki-home">
	<header class="obbywiki-home__header">
		<div class="obbywiki-home__brand">
			<div class="obbywiki-home__brand-row">
				<div class="obbywiki-home__logo">{$logoSVG}</div>
				<h1 class="obbywiki-home__title">Obby Wiki</h1>
			</div>
			<p class="obbywiki-home__tagline">The leading community-run and independent wiki for information and archives on Roblox obbies that anyone can contribute to.</p>
		</div>
		<div class="obbywiki-home__actions">
			<nav class="obbywiki-home__nav">{$navHTML}</nav>
			<div class="obbywiki-home__search">
				<div class="obbywiki-home__search-form" role="search" aria-label="Search the wiki">
					<div class="obbywiki-home__search-input citizen-search-trigger">Search the wiki...</div>
					<button class="obbywiki-home__search-btn citizen-search-trigger" aria-label="Search">
						<img src="/load.php?modules=skins.citizen.icons&amp;image=search&amp;format=original&amp;lang=en&amp;skin=citizen" alt="" width="18" height="18">
					</button>
				</form>
			</div>
		</div>
	</header>

	<nav class="obbywiki-content-links" aria-label="Content categories">
		{$contentLinksHTML}
	</nav>

	<section class="obbywiki-featured">
		<div class="obbywiki-spotlight">
			<div class="obbywiki-spotlight__viewport">
				<!-- <span class="obbywiki-spotlight__chip">OBBY WIKI HIGHLIGHTS</span> -->
				<div class="obbywiki-spotlight__track">
					{$slidesHTML}
				</div>
				{$emptyState}
				<nav class="obbywiki-spotlight__nav">
					<button class="obbywiki-spotlight__arrow obbywiki-spotlight__arrow--prev" aria-label="Previous">
						<svg xmlns="http://www.w3.org/2000/svg" height="18" width="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M560-240 320-480l240-240 56 56-184 184 184 184-56 56Z"/></svg>
					</button>
					<div class="obbywiki-spotlight__bars">{$dotsHTML}</div>
					<button class="obbywiki-spotlight__arrow obbywiki-spotlight__arrow--next" aria-label="Next">
						<svg xmlns="http://www.w3.org/2000/svg" height="18" width="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
					</button>
				</nav>
			</div>
		</div>
		<div class="obbywiki-month">
			<div class="obbywiki-month__header">
				<span class="obbywiki-month__icon">
					<svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="m612-292 56-56-148-148v-184h-80v216l172 172ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
				</span>
				<h2 class="obbywiki-month__title">Recent Releases</h2>
			</div>
			<div class="obbywiki-month__list">
				{$thisMonthHTML}
			</div>
		</div>
	</section>

	<aside class="obbywiki-aside">
		<div class="obbywiki-aside__card">
			<div class="obbywiki-aside__header">
				<div class="obbywiki-aside__header-main">
					<span class="obbywiki-aside__icon"><svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="m240-160 40-160H120l20-80h160l40-160H180l20-80h160l40-160h80l-40 160h160l40-160h80l-40 160h160l-20 80H660l-40 160h160l-20 80H600l-40 160h-80l40-160H360l-40 160h-80Zm140-240h160l40-160H420l-40 160Z"/></svg></span>
					<h2 class="obbywiki-aside__title">Obby Sub-genres</h2>
				</div>
				<a href="{$categoryURLs['obby']}" class="obbywiki-aside__all">View all</a>
			</div>
			<div class="obbywiki-aside__type-grid">
				<a href="{$categoryURLs['classic']}" class="obbywiki-aside__type-card" style="--type-hue: 210">Classic Obby</a>
				<a href="{$categoryURLs['tower']}" class="obbywiki-aside__type-card" style="--type-hue: 275">Tower Obby</a>
				<a href="{$categoryURLs['towerstage']}" class="obbywiki-aside__type-card" style="--type-hue: 320">Tower Stage Obby</a>
				<a href="{$categoryURLs['dco']}" class="obbywiki-aside__type-card" style="--type-hue: 28">Difficulty Chart Obby</a>
				<a href="{$categoryURLs['gimmick']}" class="obbywiki-aside__type-card" style="--type-hue: 160">Gimmick Obby</a>
				<a href="{$categoryURLs['tier']}" class="obbywiki-aside__type-card" style="--type-hue: 45">Tiered Obby</a>
				<a href="{$categoryURLs['troll']}" class="obbywiki-aside__type-card" style="--type-hue: 350">Troll Obby</a>
				<a href="{$categoryURLs['coop']}" class="obbywiki-aside__type-card" style="--type-hue: 195">Co-Op Obby</a>
			</div>
		</div>
		<div class="obbywiki-aside__card">
			<div class="obbywiki-aside__header">
				<div class="obbywiki-aside__header-main">
					<span class="obbywiki-aside__icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20"><title>edit</title><g fill="currentColor"><path d="m16.77 8 1.94-2a1 1 0 0 0 0-1.41l-3.34-3.3a1 1 0 0 0-1.41 0L12 3.23zM1 14.25V19h4.75l9.96-9.96-4.75-4.75z"/></g></svg></span>
					<h2 class="obbywiki-aside__title">Start Contributing</h2>
				</div>
			</div>
			<p class="obbywiki-aside__text">Whether you're a casual obby player, a content creator, or a developer, there's a place for you here. Learn more below.</p>
			<div class="obbywiki-featured__aside-cta-links">
				<a href="{$categoryURLs['contributing']}" class="obbywiki-featured__aside-cta-link">
					<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M10 1a9 9 0 109 9 9 9 0 00-9-9m1 14H9v-2h2zm0-4H9V5h2z"/></svg>
					How to Contribute
				</a>
				<a class="obbywiki-featured__aside-cta-link owaf-new-article-trigger" style="cursor:pointer;">
					<svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="M444-288h72v-156h156v-72H516v-156h-72v156H288v72h156v156Zm36.28 192Q401-96 331-126t-122.5-82.5Q156-261 126-330.96t-30-149.5Q96-560 126-629.5q30-69.5 82.5-122T330.96-834q69.96-30 149.5-30t149.04 30q69.5 30 122 82.5T834-629.28q30 69.73 30 149Q864-401 834-331t-82.5 122.5Q699-156 629.28-126q-69.73 30-149 30Z"/></svg>
					Create a new article
				</a>
				<a href="{$categoryURLs['stubs']}" class="obbywiki-featured__aside-cta-link">
					<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M15.5 1h-11A1.5 1.5 0 003 2.5v15A1.5 1.5 0 004.5 19h11a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0015.5 1M5 12h5.5v1H5zm0 3h3v1H5zm0-12h10v1H5zm0 3h10v1H5zm0 3h10v1H5z"/></svg>
					View Stubs
				</a>
				<a href="/wiki/Special:WantedPages" class="obbywiki-featured__aside-cta-link" rel="nofollow"> <!-- engines cant crawl special pages -->
					<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M15.5 1h-11A1.5 1.5 0 003 2.5v15A1.5 1.5 0 004.5 19h11a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0015.5 1M5 12h5.5v1H5zm0 3h3v1H5zm0-12h10v1H5zm0 3h10v1H5zm0 3h10v1H5z"/></svg>
					View Wanted Pages
				</a>
			</div>
		</div>
	</aside>

	{$archiveHTML}

	{$trendingHTML}

	{$blogPostsHTML}

	{$recentChangesHTML}

	{$aboutHTML}
</div>
HTML;
	}

	// format page bytes size to a readable string
	private static function formatPageSize( int $bytes ): string {
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}
}
