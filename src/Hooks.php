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
use MediaWiki\Html\TemplateParser;

class Hooks {
	// modernblog (see https://github.com/obbywiki/mediawiki-extensions-ModernBlog)
	private const BLOG_FEATURED_EXCERPT_MAX_CHARS = 360;
	private const BLOG_PROP_DATE = 'modernblog-date';
	private const BLOG_PROP_TITLE = 'modernblog-title';
	private const BLOG_PROP_AUTHOR = 'modernblog-author';
	private const BLOG_PROP_SUBTITLE = 'modernblog-subtitle';
	// cache
	private const HOME_PAGE_CACHE_VERSION = 'v20'; // only reset for large changes
	private const HOME_PAGE_CACHE_LOCK_TSE = 120;
	private const HOME_PAGE_CACHE_STALE_TTL = 3600;
	// trending
	private const TRENDING_THUMB_SIZE = 368;
	// on this day (cargo) (may be deprecated in the future, in favor of Bucket)
	private const ON_THIS_DAY_CARGO_TABLE = 'Obbies';
	private const ON_THIS_DAY_LIMIT = 8;
	// discord
	private const DISCORD_ALT_TEXT_MAX = 1024;
	private const DISCORD_OBBYWIKI_EMOJI = [
		'id' => '1556328301260447867',
		'name' => 'obbywiki',
		// 'animated' => false
	];
	private const DISCORD_DISCORD_EMOJI = [
		'id' => '983995541807583242',
		'name' => 'discord',
		// 'animated' => false
	];

	/** @var array<string,array{label:string,hue:int}> */
	private const TRENDING_GENRE_CATEGORIES = [ // only controls the tags that are displayed, not which categories are actually used
		'Category:Tower Stage Obby' => [ 'label' => 'Tower Stage', 'hue' => 320 ],
		'Category:Difficulty Chart Obby' => [ 'label' => 'Difficulty Chart', 'hue' => 28 ],
		'Category:Classic Obby' => [ 'label' => 'Classic', 'hue' => 210 ],
		'Category:Tower Obby' => [ 'label' => 'Tower', 'hue' => 275 ],
		'Category:Gimmick Obby' => [ 'label' => 'Gimmick', 'hue' => 160 ],
		'Category:Tier Obby' => [ 'label' => 'Tiered', 'hue' => 45 ],
		'Category:Troll Obby' => [ 'label' => 'Troll', 'hue' => 350 ],
		'Category:Co-Op Obby' => [ 'label' => 'Co-Op', 'hue' => 195 ],
	];

	/** @var list<array{key:string,title:string,label:string,hue:int}> */
	private const SUB_GENRE_CARDS = [
		[ 'key' => 'coop', 'title' => 'Category:Co-Op Obby', 'label' => 'Co-Op Obby', 'hue' => 195 ],
		[ 'key' => 'tower', 'title' => 'Category:Tower Obby', 'label' => 'Tower Obby', 'hue' => 275 ],
		[ 'key' => 'towerstage', 'title' => 'Category:Tower Stage Obby', 'label' => 'Tower Stage Obby', 'hue' => 320 ],
		[ 'key' => 'dco', 'title' => 'Category:Difficulty Chart Obby', 'label' => 'Difficulty Chart Obby', 'hue' => 28 ],
		[ 'key' => 'gimmick', 'title' => 'Category:Gimmick Obby', 'label' => 'Gimmick Obby', 'hue' => 160 ],
		[ 'key' => 'tier', 'title' => 'Category:Tier Obby', 'label' => 'Tiered Obby', 'hue' => 45 ],
		[ 'key' => 'troll', 'title' => 'Category:Troll Obby', 'label' => 'Troll Obby', 'hue' => 350 ],
		[ 'key' => 'flood', 'title' => 'Category:Flood-type', 'label' => 'Flood-type', 'hue' => 195 ],
	];

	private static function isTargetPage( Title $title ): bool {
		global $wgObbyWikiHomePageTitle;
		$target = $wgObbyWikiHomePageTitle ?? 'Home';

		return $title->getNamespace() === NS_MAIN && $title->getDBkey() === str_replace( ' ', '_', $target );
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

		// inject modules, styles, and classes
		$out->addModuleStyles( [ 'ext.ObbyWikiHomePage.styles' ] );
		$out->addModules( [ 'ext.ObbyWikiHomePage.scripts' ] );
		$out->addBodyClasses( [ 'obbywiki-homepage' ] );

		$description = 'Welcome to the Obby Wiki! The leading community-run and independent wiki for information and archives on Roblox obbies that anyone can contribute to.'; // TODO convert to config

		$out->addMeta( 'description', $description );
		$out->addHeadItem(
			'og-description',
			'<meta property="og:description" content="' . htmlspecialchars( $description ) . '"/>'
		);

		$articlesCount = self::getSiteStatistics()['articles'];
		$discordInvite = self::getDiscordInvite();

		$embed = [
			'component' => [
				'type' => 17,
				'accent_color' => 25075,
				'components' => array_values( array_filter( [
					[ 'type' => 10, 'content' => '# <:obbywiki:1556328301260447867> [The Obby Wiki](https://obby.wiki)' ],
					self::buildDiscordSpotlightGallery(),
					[
						'type' => 10,
						'content' => 'The Obby Wiki is an independent and community-run encyclopedia and database dedicated to documenting Roblox obbies and everything surrounding them. Help contribute to the largest database and collection of Roblox obbies ever created, with over ' . number_format( $articlesCount ) . ' articles and counting.',
					],
					[ 'type' => 10, 'content' => '> From "Home - The Obby Wiki"' ],
					[
						'type' => 1,
						'components' => array_values( array_filter( [
							self::buildDiscordLinkButton( 'Home', 'Home', false, self::DISCORD_OBBYWIKI_EMOJI ),
							self::buildDiscordLinkButton( 'All Obbies', 'Category:Obby' ),
							self::buildDiscordLinkButton( 'About', 'Obby_Wiki:About', false, self::DISCORD_OBBYWIKI_EMOJI ),
							$discordInvite !== '' ? self::buildDiscordLinkButton( 'Discord', $discordInvite, true, self::DISCORD_DISCORD_EMOJI ) : null,
						] ) ),
					],
				] ) ),
			],
		];

		// JSON_HEX_TAG keeps a page title from closing the script element
		$out->addHeadItem(
			'discord-component-embed',
			'<script id="discord:component-embed" type="application/json">' . json_encode( $embed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'
		);
	}

	/**
	 * Lists avifenc and its SVT-AV1 encoder on Special:Version when the spotlight animation is AVIF.
	 */
	public static function onSoftwareInfo( array &$software ) {
		if ( !SpotlightAnimation::isEnabled() || SpotlightAnimation::getSettings()['format'] !== 'avif' ) {
			return;
		}

		$versions = SpotlightAnimation::getAvifencVersions();
		$software['[https://github.com/AOMediaCodec/libavif libavif] (avifenc)'] = $versions['libavif'] ?? 'not found; AVIF disabled';
		if ( $versions ) {
			$software['[https://gitlab.com/AOMediaCodec/SVT-AV1 SVT-AV1]'] = $versions['svt'] ?? 'not in this avifenc build; AVIF disabled';
		}
	}

	private static function buildDiscordSpotlightGallery(): ?array {
		// media gallery with the animated spotlight, or the first slide's static thumbnail until the animation is ready. returns null when there's nothing
		$items = SpotlightData::getItems();
		if ( !$items ) {
			return null;
		}

		$url = SpotlightAnimation::getState( $items );
		if ( $url === null && !empty( $items[0]['thumbnail'] ) ) {
			$url = MediaWikiServices::getInstance()->getUrlUtils()->expand( $items[0]['thumbnail'], PROTO_CANONICAL );
		}
		if ( !$url ) {
			return null;
		}

		$titles = array_map( [ SpotlightData::class, 'getPlainTitle' ], $items );
		$alt = 'Spotlight: ' . implode( ', ', $titles );
		if ( mb_strlen( $alt ) > self::DISCORD_ALT_TEXT_MAX ) {
			$alt = mb_substr( $alt, 0, self::DISCORD_ALT_TEXT_MAX - 1 ) . "...";
		}

		return [
			'type' => 12,
			'items' => [
				[
					'media' => [ 'url' => $url ],
					'description' => $alt,
				],
			]
		];
	}

	/**
	 * Configured Discord invite URL, or an empty string when unset or not http(s).
	 */
	private static function getDiscordInvite(): string {
		global $wgObbyWikiHomePageDiscordInvite;
		$invite = trim( (string)( $wgObbyWikiHomePageDiscordInvite ?? '' ) );
		return preg_match( '#^https?://#i', $invite ) ? $invite : '';
	}

	/**
	 * @param array{id?:string,name?:string,animated?:bool}|null $emoji
	 */
	private static function buildDiscordLinkButton( string $label, string $page, bool $external = false, ?array $emoji = null ): array {
		if ( $external ) {
			$url = $page;
		} else {
			$title = Title::newFromText( $page );
			$url = $title ? $title->getFullURL( '', false, PROTO_CANONICAL ) : '';
		}

		$button = [
			'type' => 2,
			'style' => 5,
			'label' => $label,
			'url' => $url,
		];

		if ( $emoji ) {
			$button['emoji'] = array_intersect_key( $emoji, [ 'id' => true, 'name' => true, 'animated' => true ] );
		}

		return $button;
	}

	private static function buildHomePageBusyFallback(): string {
		return '<div class="obbywiki-home obbywiki-home--loading"><p>The home page is currently being refreshed. Please refresh the page in 6-12 seconds.</p></div>';
	}

	private static function buildHomePage(): string {
		$logoSVG = self::logoSVG();
		$carouselItems = SpotlightData::getItems();
		$siteStats = self::getSiteStatistics();
		$thisMonthPages = self::getThisMonthPages();
		$archiveMonths = self::getArchiveMonths();
		$recentChanges = self::getRecentChanges();
		$onThisDay = self::getOnThisDayReleases();
		$blogPosts = self::getBlogPosts();
		$trendingPages = self::getTrendingPages();
		$subGenreCounts = self::fetchCategoryPageCounts( array_merge( array_column( self::SUB_GENRE_CARDS, 'title' ), [ 'Category:Obby' ] )
		);
		return self::buildHomePageHTML(
			$logoSVG,
			$carouselItems,
			$siteStats,
			$thisMonthPages,
			$archiveMonths,
			$recentChanges,
			$blogPosts,
			$trendingPages,
			$subGenreCounts,
			$onThisDay
		);
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

	private static function fetchCategoryPageCounts( array $titles ): array {
		$counts = [];
		foreach ( $titles as $title ) {
			$counts[$title] = 0;
		}

		if ( $titles === [] ) {
			return $counts;
		}

		$request = new FauxRequest( [
			'action' => 'query',
			'titles' => implode( '|', $titles ),
			'prop' => 'categoryinfo',
		] );

		$api = new ApiMain( $request, false );

		try {
			$api->execute();
		} catch ( \Throwable $e ) {
			return $counts;
		}

		$data = $api->getResult()->getResultData( null, [
			'Strip' => 'all',
		] );

		if ( isset( $data['query']['pages'] ) ) {
			foreach ( $data['query']['pages'] as $page ) {
				$pageTitle = $page['title'] ?? '';
				if ( $pageTitle === '' ) {
					continue;
				}
				$counts[$pageTitle] = (int)( $page['categoryinfo']['pages'] ?? 0 );
			}
		}

		return $counts;
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

		// start from last month and go back up to 12 months (UTC month boundaries for consistency)
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

	/** @return list<array{title:string,url:string,thumbnail:?string,description:?string,year:int}> */
	private static function getOnThisDayReleases(): array {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Cargo' ) ) {
			return [];
		}

		$today = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$request = new FauxRequest( [
			'action' => 'cargoquery',
			'tables' => self::ON_THIS_DAY_CARGO_TABLE,
			'fields' => '_pageID=id,year',
			'where' => 'month=' . (int)$today->format( 'n' ) . ' AND day=' . (int)$today->format( 'j' )
				. ' AND year IS NOT NULL',
			'order_by' => 'visits DESC',
			'limit' => 50,
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

		// pages that call the infobox twice store duplicate rows
		$years = [];
		foreach ( $data['cargoquery'] ?? [] as $row ) {
			$page_id = (int)( $row['title']['id'] ?? 0 );
			if ( $page_id <= 0 || isset( $years[$page_id] ) ) {
				continue;
			}
			$years[$page_id] = (int)( $row['title']['year'] ?? 0 );
			if ( count( $years ) >= self::ON_THIS_DAY_LIMIT ) {
				break;
			}
		}

		if ( $years === [] ) {
			return [];
		}

		$request = new FauxRequest( [
			'action' => 'query',
			'pageids' => implode( '|', array_keys( $years ) ),
			'prop' => 'pageimages|pageprops',
			'piprop' => 'thumbnail',
			'pithumbsize' => '80',
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

		$pages_by_id = [];
		foreach ( $data['query']['pages'] ?? [] as $page ) {
			if ( isset( $page['pageid'], $page['title'] ) ) {
				$pages_by_id[(int)$page['pageid']] = $page;
			}
		}

		// keep cargo's visits ordering
		$releases = [];
		foreach ( $years as $page_id => $year ) {
			$page = $pages_by_id[$page_id] ?? null;
			$title = $page ? Title::newFromText( $page['title'] ) : null;
			if ( !$title ) {
				continue;
			}

			$releases[] = [
				'title' => $page['pageprops']['displaytitle'] ?? $title->getText(),
				'url' => $title->getLocalURL(),
				'thumbnail' => $page['thumbnail']['source'] ?? null,
				'description' => $page['pageprops']['shortdesc'] ?? null,
				'year' => $year,
			];
		}

		return $releases;
	}

	// TrendingArticles soft-dep; top pages in a category by recent/all-time views
	/** @return list<array{title:string,url:string,thumbnail:?string,description:?string,genre:?string,genre_hue:?int,views:int,views_period:string}> */
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

		// prefer PageImages via TrendingPageMedia when available
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

	private static function formatRelativeTimeHTML( string $timestamp, string $extraClass = '' ): string {
		if ( $timestamp === '' ) {
			return '';
		}

		$unix = wfTimestamp( TS_UNIX, $timestamp );
		if ( !is_numeric( $unix ) || (int)$unix <= 0 ) {
			return '';
		}

		$label = self::getRelativeTime( $timestamp );
		$iso = wfTimestamp( TS_ISO_8601, $timestamp );
		$classes = 'obbywiki-relative-time';
		if ( $extraClass !== '' ) {
			$classes .= ' ' . $extraClass;
		}

		return '<time class="' . htmlspecialchars( $classes, ENT_QUOTES ) . '" datetime="'
			. htmlspecialchars( $iso, ENT_QUOTES ) . '">'
			. htmlspecialchars( $label, ENT_QUOTES ) . '</time>';
	}

	private static function formatBlogPostDateHTML( string $timestamp ): string {
		return self::formatRelativeTimeHTML( $timestamp, 'obbywiki-blog-card__date' );
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

	// MAIN
	// builds the full html
	private static function buildHomePageHTML( string $logoSVG, array $carouselItems, array $siteStats, array $thisMonthPages, array $archiveMonths, array $recentChanges = [], array $blogPosts = [], array $trendingPages = [], array $subGenreCounts = [], array $onThisDay = [] ): string {
		global $wgExtensionAssetsPath;
		$scriptPath = wfScript();
		$templateParser = new TemplateParser( dirname( __DIR__ ) . '/templates' );
		$blogPostsHTML = self::buildBlogPostsHTML( $blogPosts, $logoSVG );

		$clAssetBase = ( $wgExtensionAssetsPath ?? '/extensions' ) . '/ObbyWikiHomePage/resources/images/cl/webp/';

		// mini nav links
		$navLinks = [
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

			$navHTML .= '<a href="' . $urlEsc . '" class="obbywiki-home__nav-btn" aria-label="' . $labelEsc . '">'
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
				$mediaHTML = '<img class="obbywiki-spotlight__slide-img" src="'
					. $thumbEsc . '" alt="' . $titleEsc . '"' . $imgLoadingMode . '>';
			} else {
				$hash = crc32( $item['title'] );
				$hue = abs( $hash ) % 360;
				$initial = mb_substr( $item['title'], 0, 1 );
				$initialEsc = htmlspecialchars( $initial );
				$mediaHTML = '<div class="obbywiki-spotlight__slide-placeholder" style="--card-hue: '
					. $hue . '"><span>' . $initialEsc . '</span></div>';
			}

			$slidesHTML .= '<a href="' . $urlEsc . '" class="obbywiki-spotlight__slide' . $activeClass
				. '" data-index="' . $index . '">'
				. '<div class="obbywiki-spotlight__slide-info">'
				. '<h2 class="obbywiki-spotlight__slide-title">' . $titleEsc . '</h2>'
				// . $statsHtml
				. $descHTML
				. '</div>'
				. '<div class="obbywiki-spotlight__slide-media">' . $mediaHTML . '</div>'
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
				'label' => 'Recent Releases',
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
			// $clImage = htmlspecialchars( $cl['image'] );
			$clStyle = 'background-image: url(' . $cl['image'] . ')';
			if ( str_starts_with( $cl['image'], $clAssetBase ) && str_ends_with( $cl['image'], '.webp' ) ) {
				$clAvif = dirname( $clAssetBase ) . '/avif/' . basename( $cl['image'], '.webp' ) . '.avif';
				$clStyle .= "; background-image: image-set(url('" . $clAvif . "') type('image/avif'), url('" . $cl['image'] . "') type('image/webp'))";
			}
			$clPriority = (int)$cl['priority'];
			$clClass = 'obbywiki-content-link';
			if ( $clPriority > 5 ) {
				$clClass .= ' obbywiki-content-link--low-priority';
			}
			$contentLinksHTML .= '<a href="' . $clUrl
				. '" class="' . $clClass . '" data-priority="' . $clPriority
				. '" style="' . $clStyle . '"'
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

		// trending (TrendingArticles soft-dep)
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

				$genreHTML = $tpGenre !== ''
					? '<span class="obbywiki-trending__genre" style="--type-hue: ' . $tpHue . '">'
						. $tpGenre . '</span>'
					: '';

				$viewsHTML = $tpViews > 0
					? '<span class="obbywiki-trending__views" title="views last week">'
						. htmlspecialchars( number_format( $tpViews ) ) . '</span>'
					: '';

				$descHTML = $tpDesc !== ''
					? '<p class="obbywiki-trending__desc">' . $tpDesc . '</p>'
					: '';

				$infoHTML = '<span class="obbywiki-trending__info">'
					. '<span class="obbywiki-trending__name">' . $tpTitle . '</span>'
					. $descHTML
					. '</span>';

				if ( !empty( $tp['thumbnail'] ) ) {
					$tpThumb = htmlspecialchars( $tp['thumbnail'] );
					$mediaHTML = '<span class="obbywiki-trending__media">'
						. '<img class="obbywiki-trending__image" src="' . $tpThumb
						. '" alt="' . $tpTitle . '" loading="lazy" decoding="async">'
						. $infoHTML
						. '</span>';
				} else {
					$hash = crc32( $tp['title'] );
					$hue = abs( $hash ) % 360;
					$initial = mb_substr( $tp['title'], 0, 1 );
					$mediaHTML = '<span class="obbywiki-trending__media obbywiki-trending__media--placeholder" style="--thumb-hue: '
						. $hue . '">'
						. '<span class="obbywiki-trending__placeholder-initial" aria-hidden="true">' . htmlspecialchars( $initial ) . '</span>'
						. $infoHTML
						. '</span>';
				}

				$metaHTML = ( $genreHTML !== '' || $viewsHTML !== '' )
					? '<span class="obbywiki-trending__meta">' . $genreHTML . $viewsHTML . '</span>'
					: '';

				$trendingListHTML .= '<a href="' . $tpUrl . '" class="obbywiki-trending__card">'
					. $mediaHTML
					. $metaHTML
					. '</a>';
			}

			$trendingHTML = '<section class="obbywiki-trending" aria-label="Trending">' .
				'<div class="obbywiki-trending__header">' .
					'<span class="obbywiki-trending__icon"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="m136-240-56-56 296-298 160 160 208-206H640v-80h240v240h-80v-104L536-320 376-480 136-240Z"/></svg></span>' .
					'<h2 class="obbywiki-trending__title">Trending this week</h2>' .
				'</div>' .
				'<div class="obbywiki-trending__grid">' . $trendingListHTML . '</div>' .
			'</section>';
		}

		// build category URLs for the aside
		$categoryURLs = [
			'classic' => htmlspecialchars( Title::newFromText( 'Category:Classic Obby' )->getLocalURL() ),
			'coop' => htmlspecialchars( Title::newFromText( 'Category:Co-Op Obby' )->getLocalURL() ),
			'tower' => htmlspecialchars( Title::newFromText( 'Category:Tower Obby' )->getLocalURL() ),
			'towerstage' => htmlspecialchars( Title::newFromText( 'Category:Tower Stage Obby' )->getLocalURL() ),
			'dco' => htmlspecialchars( Title::newFromText( 'Category:Difficulty Chart Obby' )->getLocalURL() ),
			'gimmick' => htmlspecialchars( Title::newFromText( 'Category:Gimmick Obby' )->getLocalURL() ),
			'tier' => htmlspecialchars( Title::newFromText( 'Category:Tier Obby' )->getLocalURL() ),
			'troll' => htmlspecialchars( Title::newFromText( 'Category:Troll Obby' )->getLocalURL() ),
			'flood' => htmlspecialchars( Title::newFromText( 'Category:Flood-type' )->getLocalURL() ),
			'obby' => htmlspecialchars( Title::newFromText( 'Category:Obby' )->getLocalURL() ),
			'randomObby' => htmlspecialchars( Title::newFromText( 'Special:RandomInCategory/Obby' )->getLocalURL() ),
			'stubs' => htmlspecialchars( Title::newFromText( 'Category:Stubs' )->getLocalURL() ),
			'contributing' => htmlspecialchars( Title::newFromText( 'Help:Contributing' )->getLocalURL() ),
		];

		$obbyTotalLabel = htmlspecialchars( number_format( (int)( $subGenreCounts['Category:Obby'] ?? 0 ) ) );

		$typeGridHTML = '';
		foreach ( self::SUB_GENRE_CARDS as $card ) {
			$cardUrl = $categoryURLs[$card['key']] ?? '';
			$cardCount = (int)( $subGenreCounts[$card['title']] ?? 0 );
			$cardCountLabel = number_format( $cardCount );
			$typeGridHTML .= '<a href="' . $cardUrl . '" class="obbywiki-aside__type-card" style="--type-hue: '
				. (int)$card['hue'] . '">'
				. htmlspecialchars( $card['label'] )
				. ' <span class="obbywiki-aside__type-count">(' . htmlspecialchars( $cardCountLabel ) . ')</span>'
				. '</a>';
		}

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
				$rcTime = self::formatRelativeTimeHTML( $rc['timestamp'] );

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

		// on this day html (cargo soft-dep)
		$onThisDayHTML = '';
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Cargo' ) ) {
			$today = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$currentYear = (int)$today->format( 'Y' );

			// group by year (newest first); visits order is kept within a year
			$otdByYear = [];
			foreach ( $onThisDay as $otd ) {
				$otdByYear[$otd['year']][] = $otd;
			}
			krsort( $otdByYear );

			$otdListHTML = '';
			foreach ( $otdByYear as $year => $yearReleases ) {
				$yearsAgo = $currentYear - $year;
				if ( $yearsAgo <= 0 ) {
					$yearsAgoLabel = 'Today';
				} elseif ( $yearsAgo === 1 ) {
					$yearsAgoLabel = '1 year ago';
				} else {
					$yearsAgoLabel = $yearsAgo . ' years ago';
				}

				$otdListHTML .= '<h3 class="obbywiki-onthisday__year">' . (int)$year .
					'<span class="obbywiki-onthisday__year-ago">' . htmlspecialchars( $yearsAgoLabel ) . '</span>' .
				'</h3>';

				foreach ( $yearReleases as $otd ) {
					$otdUrl = htmlspecialchars( $otd['url'] );
					$otdTitle = htmlspecialchars( $otd['title'] );

					if ( $otd['thumbnail'] ) {
						$otdThumbHTML = '<img class="obbywiki-onthisday__thumb" src="'
							. htmlspecialchars( $otd['thumbnail'] ) . '" alt="' . $otdTitle . '" loading="lazy">';
					} else {
						$hue = abs( crc32( $otd['title'] ) ) % 360;
						$otdThumbHTML = '<span class="obbywiki-onthisday__thumb obbywiki-onthisday__thumb--placeholder" style="--thumb-hue: '
							. $hue . '">' . htmlspecialchars( mb_substr( $otd['title'], 0, 1 ) ) . '</span>';
					}

					$otdDescHTML = $otd['description']
						? '<span class="obbywiki-onthisday__item-desc">' . htmlspecialchars( $otd['description'] ) . '</span>'
						: '';

					$otdListHTML .= '<a href="' . $otdUrl . '" class="obbywiki-onthisday__item">' .
						$otdThumbHTML .
						'<span class="obbywiki-onthisday__item-body">' .
							'<span class="obbywiki-onthisday__item-title">' . $otdTitle . '</span>' .
							$otdDescHTML .
						'</span>' .
					'</a>';
				}
			}

			if ( $otdListHTML === '' ) {
				$otdListHTML = '<p class="obbywiki-onthisday__empty">No obbies on the wiki were released on this day.</p>';
			}

			$onThisDayHTML = '<section class="obbywiki-onthisday" aria-label="Released on this day">' .
				'<div class="obbywiki-onthisday__header">' .
					'<span class="obbywiki-recent__icon"><svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M480-400q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-188.5-11.5Q280-423 280-440t11.5-28.5Q303-480 320-480t28.5 11.5Q360-457 360-440t-11.5 28.5Q337-400 320-400t-28.5-11.5ZM640-400q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-188.5-11.5Q280-263 280-280t11.5-28.5Q303-320 320-320t28.5 11.5Q360-297 360-280t-11.5 28.5Q337-240 320-240t-28.5-11.5ZM640-240q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Z"/></svg></span>' .
					'<h2 class="obbywiki-recent__title">Released On This Day</h2>' .
					'<span class="obbywiki-onthisday__date">' . htmlspecialchars( $today->format( 'F j' ) ) . '</span>' .
				'</div>' .
				'<div class="obbywiki-onthisday__list">' . $otdListHTML . '</div>' .
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
		$coopURL = htmlspecialchars( Title::newFromText( 'Category:Co-Op Obby' )->getLocalURL() );
		$towerURL = htmlspecialchars( Title::newFromText( 'Category:Tower Obby' )->getLocalURL() );
		$dcoURL = htmlspecialchars( Title::newFromText( 'Category:Difficulty Chart Obby' )->getLocalURL() );
		$gimmickURL = htmlspecialchars( Title::newFromText( 'Category:Gimmick Obby' )->getLocalURL() );
		$articlesCount = number_format( $siteStats['articles'] );
		$userCount = number_format( $siteStats['users'] );
		$editsCount = number_format( $siteStats['edits'] );
		$filesCount = number_format( $siteStats['images'] );
		$citizenIconUrl = static function ( string $name ): string {
			return '/load.php?modules=skins.citizen.icons&image=' . rawurlencode( $name )
				. '&format=original&lang=en&skin=citizen';
		};
		$contributeStat = static function ( string $icon, string $value, string $label, bool $active ) use ( $citizenIconUrl ): string {
			$icon_url = $citizenIconUrl( $icon );
			return '<span class="obbywiki-aside__stat' . ( $active ? ' obbywiki-aside__stat--active' : '' ) . '" role="listitem" title="'
				. htmlspecialchars( $label ) . '" aria-label="'
				. htmlspecialchars( $value . ' ' . $label ) . '">'
				. '<span class="obbywiki-aside__stat-icon" aria-hidden="true" style="--icon-url: url(&quot;'
				. htmlspecialchars( $icon_url, ENT_QUOTES ) . '&quot;)"></span>'
				. '<span class="obbywiki-aside__stat-value">' . htmlspecialchars( $value ) . '</span>'
				. '<span class="obbywiki-aside__stat-label" aria-hidden="true">' . htmlspecialchars( $label ) . '</span>'
				. '</span>';
		};
		// one stat at a time, cycled through by the scripts module
		$contributeStatsHTML = '<div class="obbywiki-aside__stats" role="list" aria-label="Wiki statistics">'
			. $contributeStat( 'article', $articlesCount, 'Articles', true )
			. $contributeStat( 'image', $filesCount, 'Files', false )
			. $contributeStat( 'edit', $editsCount, 'Edits', false )
			. $contributeStat( 'userAvatar', $userCount, 'Users', false )
			. '</div>';
		$discordInvite = self::getDiscordInvite();
		$contributeDiscordHTML = $discordInvite === '' ? '' :
			'<a href="' . htmlspecialchars( $discordInvite ) . '" class="obbywiki-aside__discord" rel="noopener" target="_blank">'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.317 4.37a19.79 19.79 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.865-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.74 19.74 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.077.077 0 0 1-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 0 1 .078-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.009c.12.1.246.198.373.292a.077.077 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.84 19.84 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>'
			. '<span><span class="obbywiki-aside__discord-prefix">Join the </span>Discord</span>'
			. '</a>';
		$aboutHTML = '<section class="obbywiki-about" aria-label="About the Wiki">' .
			$templateParser->processTemplate(
				'Header',
				[
					'id' => 'about',
					'title' => 'About The Obby Wiki',
					'svg' => '<svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="M425-265h110v-255H425v255Zm97.5-332.25Q540-614.5 540-640t-17.25-42.75Q505.5-700 480-700t-42.75 17.25Q420-665.5 420-640t17.5 42.75Q455-580 480-580t42.5-17.25ZM480-46q-91 0-169.99-34.08-78.98-34.09-137.41-92.52-58.43-58.43-92.52-137.41Q46-389 46-480q0-91 34.08-169.99 34.09-78.98 92.52-137.41 58.43-58.43 137.41-92.52Q389-914 480-914q91 0 169.99 34.08 78.98 34.09 137.41 92.52 58.43 58.43 92.52 137.41Q914-571 914-480q0 91-34.08 169.99-34.09 78.98-92.52 137.41-58.43 58.43-137.41 92.52Q571-46 480-46Z"/></svg>',
				]
			) .
			'<div class="obbywiki-about__content">' .
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
					<button class="obbywiki-spotlight__arrow obbywiki-spotlight__toggle" aria-label="Pause" hidden>
						<svg class="obbywiki-spotlight__toggle-pause" xmlns="http://www.w3.org/2000/svg" height="16" width="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="4.5" y="4" width="2.5" height="8" rx="1"/><rect x="9" y="4" width="2.5" height="8" rx="1"/></svg>
						<svg class="obbywiki-spotlight__toggle-play" xmlns="http://www.w3.org/2000/svg" height="16" width="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.75 4.75v6.5L11.5 8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
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
				<div class="obbywiki-aside__header-actions">
					<a href="{$categoryURLs['obby']}" class="obbywiki-aside__all">View all ({$obbyTotalLabel})</a>
					<a href="{$categoryURLs['randomObby']}" class="obbywiki-aside__icon-btn" aria-label="Random Obby" title="Random Obby"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M342.5-257.5Q360-275 360-300t-17.5-42.5Q325-360 300-360t-42.5 17.5Q240-325 240-300t17.5 42.5Q275-240 300-240t42.5-17.5Zm0-360Q360-635 360-660t-17.5-42.5Q325-720 300-720t-42.5 17.5Q240-685 240-660t17.5 42.5Q275-600 300-600t42.5-17.5Zm180 180Q540-455 540-480t-17.5-42.5Q505-540 480-540t-42.5 17.5Q420-505 420-480t17.5 42.5Q455-420 480-420t42.5-17.5Zm180 180Q720-275 720-300t-17.5-42.5Q685-360 660-360t-42.5 17.5Q600-325 600-300t17.5 42.5Q635-240 660-240t42.5-17.5Zm0-360Q720-635 720-660t-17.5-42.5Q685-720 660-720t-42.5 17.5Q600-685 600-660t17.5 42.5Q635-600 660-600t42.5-17.5ZM200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/></svg></a>
				</div>
			</div>
			<div class="obbywiki-aside__type-grid">
				{$typeGridHTML}
			</div>
		</div>
		<div class="obbywiki-aside__card">
			<div class="obbywiki-aside__header">
				<div class="obbywiki-aside__header-main">
					<span class="obbywiki-aside__icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20"><title>edit</title><g fill="currentColor"><path d="m16.77 8 1.94-2a1 1 0 0 0 0-1.41l-3.34-3.3a1 1 0 0 0-1.41 0L12 3.23zM1 14.25V19h4.75l9.96-9.96-4.75-4.75z"/></g></svg></span>
					<h2 class="obbywiki-aside__title">Start Contributing</h2>
				</div>
			</div>
			<p class="obbywiki-aside__text">Help contribute to the largest obby database ever by adding a new obby, editing an existing article, or helping in another way.</p>
			<div class="obbywiki-featured__aside-cta-links">
				<div class="obbywiki-featured__aside-cta-row">
					<button type="button" class="obbywiki-featured__aside-cta-add owaf-new-article-trigger" aria-label="Create a new article">
						<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg>
					</button>
					<span class="obbywiki-featured__aside-cta-divider" aria-hidden="true">|</span>
					<div class="obbywiki-featured__aside-cta-stack">
						<a href="{$categoryURLs['contributing']}" class="obbywiki-featured__aside-cta-link">
							<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M10 1a9 9 0 109 9 9 9 0 00-9-9m1 14H9v-2h2zm0-4H9V5h2z"/></svg>
							How to Contribute
						</a>
						<a href="{$categoryURLs['stubs']}" class="obbywiki-featured__aside-cta-link">
							<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M15.5 1h-11A1.5 1.5 0 003 2.5v15A1.5 1.5 0 004.5 19h11a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0015.5 1M5 12h5.5v1H5zm0 3h3v1H5zm0-12h10v1H5zm0 3h10v1H5zm0 3h10v1H5z"/></svg>
							Pages that need improvement
						</a>
						<a href="/wiki/Special:WantedPages" class="obbywiki-featured__aside-cta-link" rel="nofollow"> <!-- engines cant crawl special pages -->
							<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M15.5 1h-11A1.5 1.5 0 003 2.5v15A1.5 1.5 0 004.5 19h11a1.5 1.5 0 001.5-1.5v-15A1.5 1.5 0 0015.5 1M5 12h5.5v1H5zm0 3h3v1H5zm0-12h10v1H5zm0 3h10v1H5zm0 3h10v1H5z"/></svg>
							Wanted new pages
						</a>
					</div>
				</div>
				<div class="obbywiki-aside__footer">
					{$contributeDiscordHTML}
					{$contributeStatsHTML}
				</div>
			</div>
		</div>
	</aside>

	{$archiveHTML}

	
	<div class="obbywiki-split">
		{$recentChangesHTML}
		<aside class="obbywiki-split__aside">{$onThisDayHTML}</aside>
	</div>

	{$trendingHTML}
	
	{$aboutHTML}

	{$blogPostsHTML}
</div>
HTML;
	}
}
