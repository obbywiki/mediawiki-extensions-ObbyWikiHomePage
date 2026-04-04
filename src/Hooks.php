<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use Article;
use MediaWiki\Api\ApiMain;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
use Skin;

class Hooks {
	private const DISCOURSE_FEATURED_EXCERPT_MAX_CHARS = 360;

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
			$cacheKey = $cache->makeKey( 'obbywikihomepage', 'html', 'v7' );
			$html = $cache->getWithSetCallback(
				$cacheKey,
				$ttl,
				function () {
					return self::buildHomePage();
				}
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

	private static function buildHomePage(): string {
		$logoSVG = self::logoSVG();
		$carouselItems = self::getObbyPages();
		$siteStats = self::getSiteStatistics();
		$thisMonthPages = self::getThisMonthPages();
		$archiveMonths = self::getArchiveMonths();
		$recentChanges = self::getRecentChanges();
		$announcements = self::getDiscourseAnnouncements();
		return self::buildHomePageHTML( $logoSVG, $carouselItems, $siteStats, $thisMonthPages, $archiveMonths, $recentChanges, $announcements );
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
			return [ 'articles' => 0, 'pages' => 0, 'edits' => 0, 'images' => 0 ];
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
		];
	}

	private static function getThisMonthPages(): array {
		$monthName = date( 'F Y' ); // e.g. "February 2026"
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

				if ( count( $pages ) >= 8 ) {
					break;
				}
			}
		}

		return $pages;
	}

	private static function getArchiveMonths(): array {
		$months = [];
		// start from last month and go back up to 8 months
		for ( $i = 1; $i <= 12; $i++ ) {
			$timestamp = strtotime( "-{$i} months" );
			$monthName = date( 'F Y', $timestamp ); // e.g. "February 2026"
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
				continue;
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

			if ( $count > 0 ) {
				$title = Title::newFromText( $catTitle );
				if ( $title ) {
					$months[] = [
						'label' => $monthName,
						'url' => $title->getLocalURL(),
						'count' => $count,
					];
				}
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
			'rcprop' => 'title|timestamp|user|sizes|comment|ids',
			'rclimit' => 6,
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
		if ( isset( $data['query']['recentchanges'] ) ) {
			foreach ( $data['query']['recentchanges'] as $rc ) {
				$title = Title::newFromText( $rc['title'] );
				if ( !$title ) continue;

				$changes[] = [
					'title' => $title->getPrefixedText(),
					'url' => $title->getLocalURL(),
					'user' => $rc['user'] ?? '',
					'timestamp' => $rc['timestamp'] ?? '',
					'comment' => $rc['comment'] ?? '',
					'oldlen' => $rc['oldlen'] ?? 0,
					'newlen' => $rc['newlen'] ?? 0,
					'revid' => $rc['revid'] ?? 0,
					'old_revid' => $rc['old_revid'] ?? 0,
				];
			}
		}

		return $changes;
	}

	private static function normalizeDiscourseExcerpt( string $excerpt ): string {
		$t = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( '/\[\/?(quote|img|video|audio)[^\]]*\]/i', '', $t );
		$t = preg_replace( '/\s+/u', ' ', $t );
		return trim( $t );
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
	
	private static function discourseFetchPrimaryTopicEnrichment( string $forum_base, int $topic_id ): ?array {
		if ( $topic_id <= 0 ) {
			return null;
		}

		$url = $forum_base . '/t/' . $topic_id . '.json';
		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $http->create(
			$url,
			[
				'method' => 'GET',
				'timeout' => 6,
				'userAgent' => 'ObbyWikiHomePage/0.1 (MediaWiki; +https://obbywiki.com)',
			],
			__METHOD__
		);

		$status = $req->execute();
		if ( !$status->isOK() ) {
			return null;
		}

		$body = $req->getContent();
		if ( $body === '' ) {
			return null;
		}

		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			return null;
		}

		$plain = '';
		$posts = $data['post_stream']['posts'] ?? null;
		if ( is_array( $posts ) && $posts !== [] && is_array( $posts[0] ) ) {
			$cooked = $posts[0]['cooked'] ?? '';
			if ( is_string( $cooked ) && $cooked !== '' ) {
				$plain = self::normalizeDiscourseExcerpt(
					html_entity_decode( strip_tags( $cooked ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				);
			}
		}

		$display_name = '';
		$username = '';
		$avatar_template = '';
		$created_by = $data['details']['created_by'] ?? null;
		if ( is_array( $created_by ) ) {
			$username = isset( $created_by['username'] ) && is_string( $created_by['username'] )
				? $created_by['username']
				: '';
			$display_name = isset( $created_by['name'] ) && is_string( $created_by['name'] )
				? $created_by['name']
				: '';
			if ( $display_name === '' ) {
				$display_name = $username;
			}
			$avatar_template = isset( $created_by['avatar_template'] ) && is_string( $created_by['avatar_template'] )
				? $created_by['avatar_template']
				: '';
		}

		return [
			'plain' => $plain,
			'display_name' => $display_name,
			'username' => $username,
			'avatar_template' => $avatar_template,
		];
	}

	private static function discourseAvatarSrc( string $forum_base, string $avatar_template, int $size = 48 ): string {
		if ( $avatar_template === '' ) {
			return '';
		}
		$path = str_replace( '{size}', (string)$size, $avatar_template );
		if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
			return $path;
		}
		return $forum_base . $path;
	}

	private static function discourseTopicOriginalPoster( array $topic, array $user_by_id ): array {
		$posters = $topic['posters'] ?? [];
		$op_id = 0;
		if ( is_array( $posters ) ) {
			foreach ( $posters as $poster ) {
				if ( !is_array( $poster ) ) {
					continue;
				}
				$desc = $poster['description'] ?? '';
				if ( is_string( $desc ) && str_contains( $desc, 'Original Poster' ) ) {
					$op_id = (int)( $poster['user_id'] ?? 0 );
					break;
				}
			}
			if ( !$op_id && isset( $posters[0]['user_id'] ) ) {
				$op_id = (int)$posters[0]['user_id'];
			}
		}

		$u = $user_by_id[$op_id] ?? [];
		$username = is_array( $u ) && isset( $u['username'] ) && is_string( $u['username'] )
			? $u['username']
			: '';
		$display = '';
		if ( is_array( $u ) && isset( $u['name'] ) && is_string( $u['name'] ) && $u['name'] !== '' ) {
			$display = $u['name'];
		} else {
			$display = $username;
		}

		$tpl = is_array( $u ) && isset( $u['avatar_template'] ) && is_string( $u['avatar_template'] )
			? $u['avatar_template']
			: '';

		return [
			'display_name' => $display,
			'username' => $username,
			'avatar_template' => $tpl,
		];
	}

	private static function getDiscourseAnnouncements(): array {
		global $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl;
		global $wgObbyWikiHomePageDiscourseFetchPrimaryTopic;
		$url = trim( (string)( $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl ?? '' ) );
		if ( $url === '' ) {
			return [];
		}

		$parsed = wfParseUrl( $url );
		if ( !$parsed || ( $parsed['scheme'] ?? '' ) !== 'https' || empty( $parsed['host'] ) ) {
			return [];
		}

		$forum_base = 'https://' . $parsed['host'];

		$fetch_url = wfAppendQuery( $url, [ 'per_page' => 30 ] );

		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $http->create(
			$fetch_url,
			[
				'method' => 'GET',
				'timeout' => 8,
				'userAgent' => 'ObbyWikiHomePage/0.1 (MediaWiki; +https://obbywiki.com)',
			],
			__METHOD__
		);

		$status = $req->execute();
		if ( !$status->isOK() ) {
			return [];
		}

		$body = $req->getContent();
		if ( $body === '' ) {
			return [];
		}

		$data = json_decode( $body, true );
		if ( !is_array( $data ) || !isset( $data['topic_list']['topics'] ) || !is_array( $data['topic_list']['topics'] ) ) {
			return [];
		}

		$user_by_id = [];
		if ( isset( $data['users'] ) && is_array( $data['users'] ) ) {
			foreach ( $data['users'] as $u ) {
				if ( is_array( $u ) && isset( $u['id'] ) ) {
					$user_by_id[(int)$u['id']] = $u;
				}
			}
		}

		$topics = [];
		foreach ( $data['topic_list']['topics'] as $t ) {
			if ( !is_array( $t ) ) {
				continue;
			}

			$id = $t['id'] ?? null;
			$slug = $t['slug'] ?? '';
			if ( $id === null || $slug === '' || !is_string( $slug ) ) {
				continue;
			}

			$title = $t['fancy_title'] ?? null;
			if ( !is_string( $title ) || $title === '' ) {
				$title = $t['title'] ?? '';
			}
			if ( !is_string( $title ) || $title === '' ) {
				continue;
			}

			$created_at = $t['created_at'] ?? '';
			if ( !is_string( $created_at ) ) {
				$created_at = '';
			}

			$excerpt = $t['excerpt'] ?? '';
			if ( !is_string( $excerpt ) ) {
				$excerpt = '';
			}

			$excerpt_plain = self::normalizeDiscourseExcerpt( $excerpt );

			$poster = self::discourseTopicOriginalPoster( $t, $user_by_id );
			$avatar_url = self::discourseAvatarSrc( $forum_base, $poster['avatar_template'], 48 );

			$topics[] = [
				'topic_id' => (int)$id,
				'title' => $title,
				'url' => $forum_base . '/t/' . $slug . '/' . (int)$id,
				'excerpt_plain' => $excerpt_plain,
				'created_at' => $created_at,
				'poster_display_name' => $poster['display_name'],
				'poster_username' => $poster['username'],
				'poster_avatar_url' => $avatar_url,
			];
		}

		usort(
			$topics,
			static function ( array $a, array $b ): int {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		$topics = array_slice( $topics, 0, 6 );

		$fetch_primary = (bool)( $wgObbyWikiHomePageDiscourseFetchPrimaryTopic ?? true );
		if ( $fetch_primary && $topics !== [] ) {
			$primary_id = (int)$topics[0]['topic_id'];
			$enriched = self::discourseFetchPrimaryTopicEnrichment( $forum_base, $primary_id );
			if ( $enriched !== null ) {
				if ( $enriched['plain'] !== '' ) {
					$topics[0]['excerpt_plain'] = self::truncateAnnouncementPlaintext(
						$enriched['plain'],
						self::DISCOURSE_FEATURED_EXCERPT_MAX_CHARS
					);
				}
				if ( $enriched['username'] !== '' ) {
					$topics[0]['poster_username'] = $enriched['username'];
					$topics[0]['poster_display_name'] = $enriched['display_name'] !== ''
						? $enriched['display_name']
						: $enriched['username'];
				}
				if ( $enriched['avatar_template'] !== '' ) {
					$topics[0]['poster_avatar_url'] = self::discourseAvatarSrc(
						$forum_base,
						$enriched['avatar_template'],
						48
					);
				}
			}
		}

		if ( $topics !== [] && $topics[0]['excerpt_plain'] === '' ) {
			$topics[0]['excerpt_plain'] = 'Read the full announcement on the forum.';
		}

		$topic_count = count( $topics );
		for ( $i = 1; $i < $topic_count; $i++ ) {
			if ( $topics[$i]['excerpt_plain'] === '' ) {
				$topics[$i]['excerpt_plain'] = 'Read more on the forum.';
			}
		}

		foreach ( $topics as $i => $_ ) {
			unset( $topics[$i]['topic_id'] );
		}

		return $topics;
	}

	private static function formatAnnouncementDateHtml( string $created_at ): string {
		if ( $created_at === '' ) {
			return '';
		}
		$unix = wfTimestamp( TS_UNIX, $created_at );
		if ( !is_numeric( $unix ) ) {
			return '';
		}
		if ( (int)$unix <= 0 ) {
			return '';
		}
		$label = self::getRelativeTime( $created_at );
		return '<time class="obbywiki-announce__date" datetime="'
			. htmlspecialchars( $created_at, ENT_QUOTES ) . '">'
			. htmlspecialchars( $label, ENT_QUOTES ) . '</time>';
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

	private static function buildAnnouncementAvatarHtml( array $item, int $size ): string {
		$avatar_url = (string)( $item['poster_avatar_url'] ?? '' );
		$username = (string)( $item['poster_username'] ?? '' );
		$display = (string)( $item['poster_display_name'] ?? '' );
		$initial_src = $username !== '' ? $username : ( $display !== '' ? $display : '?' );
		$initial = mb_substr( $initial_src, 0, 1 );
		$hue = abs( crc32( $initial_src ) ) % 360;
		$size_attr = (string)$size;

		if ( $avatar_url !== '' ) {
			return '<img class="obbywiki-announce__avatar" src="' . htmlspecialchars( $avatar_url, ENT_QUOTES )
				. '" alt="" width="' . $size_attr . '" height="' . $size_attr . '" loading="lazy" decoding="async">';
		}

		return '<span class="obbywiki-announce__avatar obbywiki-announce__avatar--placeholder" style="--announce-hue: '
			. $hue . '">' . htmlspecialchars( $initial, ENT_QUOTES ) . '</span>';
	}

	private static function buildAnnouncementPosterMetaHtml( array $item ): string {
		$display = (string)( $item['poster_display_name'] ?? '' );
		$username = (string)( $item['poster_username'] ?? '' );

		$html = '<div class="obbywiki-announce__poster-meta">';
		if ( $display !== '' && ( $username === '' || strcasecmp( $display, $username ) !== 0 ) ) {
			$html .= '<span class="obbywiki-announce__poster-display">' . htmlspecialchars( $display, ENT_QUOTES ) . '</span>';
		}
		if ( $username !== '' ) {
			$html .= '<span class="obbywiki-announce__poster-user">@' . htmlspecialchars( $username, ENT_QUOTES ) . '</span>';
		} elseif ( $display !== '' ) {
			$html .= '<span class="obbywiki-announce__poster-user">' . htmlspecialchars( $display, ENT_QUOTES ) . '</span>';
		}
		$html .= '</div>';

		return $html;
	}

	private static function buildAnnouncementsHTML( array $announcements ): string {
		if ( $announcements === [] ) {
			return '';
		}

		global $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl;
		$view_all_url = 'https://forum.wou.gg/c/obby-wiki/obby-wiki-meta/13';
		$p = wfParseUrl( trim( (string)( $wgObbyWikiHomePageDiscourseAnnouncementsJsonUrl ?? '' ) ) );
		if ( $p && !empty( $p['host'] ) ) {
			$scheme = ( $p['scheme'] ?? '' ) === 'http' ? 'http' : 'https';
			$view_all_url = $scheme . '://' . $p['host'] . '/c/obby-wiki/obby-wiki-meta/13';
		}

		$featured = $announcements[0];
		$rest = array_slice( $announcements, 1 );

		$view_all_esc = htmlspecialchars( $view_all_url, ENT_QUOTES );
		$featured_title = htmlspecialchars( $featured['title'], ENT_QUOTES );
		$featured_url = htmlspecialchars( $featured['url'], ENT_QUOTES );
		$featured_blurb = htmlspecialchars( $featured['excerpt_plain'], ENT_QUOTES );

		$featured_date = self::formatAnnouncementDateHtml( (string)( $featured['created_at'] ?? '' ) );

		$featured_top = '<div class="obbywiki-announce__featured-top">'
			. self::buildAnnouncementAvatarHtml( $featured, 52 )
			. '<div class="obbywiki-announce__featured-head">'
			. '<h4 class="obbywiki-announce__featured-title">' . $featured_title . '</h4>'
			. self::buildAnnouncementPosterMetaHtml( $featured )
			. $featured_date
			. '</div>'
			. '</div>';

		$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M720-440v-80h160v80H720Zm48 280-128-96 48-64 128 96-48 64Zm-80-480-48-64 128-96 48 64-128 96ZM200-200v-160h-40q-33 0-56.5-23.5T80-440v-80q0-33 23.5-56.5T160-600h160l200-120v480L320-360h-40v160h-80Zm360-146v-268q27 24 43.5 58.5T620-480q0 41-16.5 75.5T560-346Z"/></svg>';

		$body_class = $rest !== [] ? ' obbywiki-announce__body--split' : '';


		
		$community_links = [
			[ 'label' => 'Discussions', 'url' => 'https://forum.wou.gg/c/obby-wiki/12' ],
			[ 'label' => 'Page Feedback', 'url' => 'https://forum.wou.gg/c/obby-wiki/obby-wiki-page-feedback/14' ],
		];

		$html = '<section class="obbywiki-announce" aria-label="Announcements">'
			. '<div class="obbywiki-announce__header">'
			. '<div class="obbywiki-announce__header-main">'
			. '<span class="obbywiki-announce__icon">' . $icon_svg . '</span>'
			. '<h3 class="obbywiki-announce__title">Announcements</h3>'
			. '</div>'
			. '<a href="' . $view_all_esc . '" class="obbywiki-announce__all">View all</a>'
			. '</div>'
			. '<div class="obbywiki-announce__body' . $body_class . '">'
			. '<a href="' . $featured_url . '" class="obbywiki-announce__featured">'
			. $featured_top
			. '<p class="obbywiki-announce__featured-blurb">' . $featured_blurb . '</p>'
			. '</a>';

		if ( $rest !== [] ) {
			$html .= '<div class="obbywiki-announce__list">'
				. '<div class="obbywiki-announce__community-nav">';
			
			foreach ( $community_links as $link ) {
				$html .= '<a href="' . htmlspecialchars( $link['url'], ENT_QUOTES ) . '" class="obbywiki-announce__community-link">'
					. htmlspecialchars( $link['label'], ENT_QUOTES )
					. '</a>';
			}

			$html .= '</div>'
				. '<div class="obbywiki-announce__compact-list">';

			foreach ( $rest as $item ) {
				$title_esc = htmlspecialchars( $item['title'], ENT_QUOTES );
				$url_esc = htmlspecialchars( $item['url'], ENT_QUOTES );
				$blurb_esc = htmlspecialchars( $item['excerpt_plain'], ENT_QUOTES );
				$avatar = self::buildAnnouncementAvatarHtml( $item, 36 );
				$meta = self::buildAnnouncementPosterMetaHtml( $item );
				$compact_date = self::formatAnnouncementDateHtml( (string)( $item['created_at'] ?? '' ) );
				$html .= '<a href="' . $url_esc . '" class="obbywiki-announce__compact">'
					. '<div class="obbywiki-announce__compact-inner">'
					. '<div class="obbywiki-announce__compact-avatar">' . $avatar . '</div>'
					. '<div class="obbywiki-announce__compact-main">'
					. '<span class="obbywiki-announce__compact-title">' . $title_esc . '</span>'
					. '<p class="obbywiki-announce__compact-blurb">' . $blurb_esc . '</p>'
					. '<div class="obbywiki-announce__compact-meta">'
					. $compact_date
					. '</div>'
					. '</div>'
					. '</div>'
					. '</a>';
			}
			$html .= '</div></div>';
		}

		$html .= '</div></section>';

		return $html;
	}

	// MAIN
	// builds the full html
	private static function buildHomePageHTML( string $logoSVG, array $carouselItems, array $siteStats, array $thisMonthPages, array $archiveMonths, array $recentChanges = [], array $announcements = [] ): string {
		$scriptPath = wfScript();
		$announcementsHTML = self::buildAnnouncementsHTML( $announcements );

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
				'url' => 'https://forum.wou.gg',
				'label' => 'Forums',
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
				$mediaHtml = '<img class="obbywiki-spotlight__slide-img" src="'
					. $thumbEsc . '" alt="' . $titleEsc . '" loading="lazy">';
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
				. '<h3 class="obbywiki-spotlight__slide-title">' . $titleEsc . '</h3>'
				// . $statsHtml
				. $descHTML
				. '</div>'
				. '<div class="obbywiki-spotlight__slide-media">' . $mediaHtml . '</div>'
				. '</a>';

			$dotActive = $index === 0 ? ' obbywiki-spotlight__bar--active' : '';
			$dotsHTML .= '<button class="obbywiki-spotlight__bar' . $dotActive
				. '" data-index="' . $index . '" aria-label="Slide ' . ( $index + 1 ) . '">'
				. '<span class="obbywiki-spotlight__bar-fill"></span></button>';

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
				'image' => 'https://2q2bp9cu5u.ufs.sh/f/jHfjIa1SBA5fIl5MUAMPWrH12SVDENKiCGARJyhbM7uqsxkj',
				'priority' => 1, // always shown
			],
			[
				'url' => Title::newFromText( 'New' )->getLocalURL(),
				'label' => 'New Releases',
				'image' => 'https://ss1-legacy.content.wolfite.dev/ss1/backgrounds/wlftgbg/v1/collections/default-1/XDCOV3Medium1.png',
				'priority' => 2,
			],
			[
				'url' => Title::newFromText( 'Category:Studio' )->getLocalURL(),
				'label' => 'Studios',
				'image' => 'https://2q2bp9cu5u.ufs.sh/f/jHfjIa1SBA5f2Fjbht9Xth87gDveuM46VKbLTBUms3wzar0R',
				'priority' => 3,
			],
			[
				'url' => Title::newFromText( 'Tiers' )->getLocalURL(),
				'label' => 'Tiers',
				'image' => 'https://2q2bp9cu5u.ufs.sh/f/jHfjIa1SBA5fkTObkuNkYhSuFOPtb54ULfXz8ICG1yjvgxcM',
				'priority' => 4,
			],
			// [
			// 	'url' => Title::newFromText( 'Category:Developers' )->getLocalURL(),
			// 	'label' => 'Developers',
			// 	'image' => 'https://dummyimage.com/400x200/dc2626/ffffff&text=Developers',
			// 	'priority' => 4,
			// ],
			// [
			// 	'url' => Title::newFromText( 'Development:Get Started' )->getLocalURL(),
			// 	'label' => 'Get Started',
			// 	'image' => 'https://dummyimage.com/400x200/ea580c/ffffff&text=Get+Started',
			// 	'priority' => 5,
			// ],
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
			$contentLinksHTML .= '<a href="' . $clUrl
				. '" class="obbywiki-content-link" data-priority="' . $clPriority
				. '" style="background-image: url(' . $clImage . ')"'
				. '>'
				. '<span class="obbywiki-content-link__label">' . $clLabel . '</span>'
				. '</a>';
		}

		// this month
		$thisMonthHTML = '';
		$monthName = date( 'F' );
		if ( empty( $thisMonthPages ) ) {
			$thisMonthHTML = '<p class="obbywiki-featured__aside-month-empty">No new releases this month yet.</p>';
		} else {
			foreach ( $thisMonthPages as $mp ) {
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

		// build category URLs for the aside
		$categoryURLs = [
			'classic' => htmlspecialchars( Title::newFromText( 'Category:Classic Obby' )->getLocalURL() ),
			'tower' => htmlspecialchars( Title::newFromText( 'Category:Tower Obby' )->getLocalURL() ),
			'dco' => htmlspecialchars( Title::newFromText( 'Category:Difficulty Chart Obby' )->getLocalURL() ),
			'gimmick' => htmlspecialchars( Title::newFromText( 'Category:Gimmick Obby' )->getLocalURL() ),
			'tier' => htmlspecialchars( Title::newFromText( 'Category:Tier Obby' )->getLocalURL() ),
			'troll' => htmlspecialchars( Title::newFromText( 'Category:Troll Obby' )->getLocalURL() ),
			'coop' => htmlspecialchars( Title::newFromText( 'Category:Co-Op Obby' )->getLocalURL() ),
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
				$amDesc = htmlspecialchars( "View all {$amCount} obbies released in {$am['label']}" );
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
			$rcCardsHTML = '';
			foreach ( $recentChanges as $rc ) {
				$diff = $rc['newlen'] - $rc['oldlen'];
				if ( $diff > 0 ) {
					$diffClass = 'obbywiki-recent__card-diff--pos';
					$diffText = '+' . $diff;
				} elseif ( $diff < 0 ) {
					$diffClass = 'obbywiki-recent__card-diff--neg';
					$diffText = $diff;
				} else {
					$diffClass = 'obbywiki-recent__card-diff--zero';
					$diffText = '0';
				}

				$rcArticleURL = htmlspecialchars( $rc['url'] );
				$rcDiffURL = htmlspecialchars( $rc['url'] . ( $rc['revid'] ? '?diff=' . $rc['revid'] . '&oldid=' . $rc['old_revid'] : '' ) );
				$rcHistURL = htmlspecialchars( $rc['url'] . '?action=history' );

				$rcTitle = htmlspecialchars( $rc['title'] );
				$rcUser = htmlspecialchars( $rc['user'] );
				$rcTime = htmlspecialchars( self::getRelativeTime( $rc['timestamp'] ) );
				$rcComment = htmlspecialchars( $rc['comment'] );

				$commentHTML = $rcComment ? '<div class="obbywiki-recent__card-comment">' . $rcComment . '</div>' : '';

				$rcCardsHTML .= '<div class="obbywiki-recent__card">' .
					'<div class="obbywiki-recent__card-top">' .
						'<a href="' . $rcArticleURL . '" class="obbywiki-recent__card-title">' . $rcTitle . '</a>' .
						'<span class="obbywiki-recent__card-diff ' . $diffClass . '">' . $diffText . '</span>' .
					'</div>' .
					'<div class="obbywiki-recent__card-meta">' .
						'<span class="obbywiki-recent__card-user"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="M480-480q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47ZM160-160v-32q0-34 17.5-62.5T224-294q62-31 126-46.5T480-356q66 0 130 15.5T736-294q29 15 46.5 43.5T800-192v32H160Z"/></svg>' . $rcUser . '</span>' .
						'<span class="obbywiki-recent__card-time"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm112-232L440-464v-216h80v184l128 128-56 56ZM480-480Z"/></svg>' . $rcTime . '</span>' .
						'<div class="obbywiki-recent__card-actions">' .
							'<a href="' . $rcDiffURL . '" class="obbywiki-recent__card-action" title="View diff" rel="nofollow"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="M500-520h80v-80h80v-80h-80v-80h-80v80h-80v80h80v80Zm-80 160h240v-80H420v80ZM320-200q-33 0-56.5-23.5T240-280v-560q0-33 23.5-56.5T320-920h280l240 240v400q0 33-23.5 56.5T760-200H320ZM160-40q-33 0-56.5-23.5T80-120v-560h80v560h440v80H160Z"/></svg></a>' .
							'<a href="' . $rcHistURL . '" class="obbywiki-recent__card-action" title="View history" rel="nofollow"><svg xmlns="http://www.w3.org/2000/svg" height="14" viewBox="0 -960 960 960" width="14" fill="currentColor"><path d="M478-86q-152 0-264.5-101T87-440h127q15 99 89.5 163.5T478-212q112 0 190-78t78-190q0-112-78-190t-190-78q-57 0-109 23.5T279-657h82v97H94v-265h95v79q56-62 130.5-95T478-874q81 0 153 31t125.5 84.5Q810-705 841-633t31 153q0 81-31 153t-84.5 125.5Q703-148 631-117T478-86Zm107-218L433-456v-224h95v184l125 124-68 68Z"/></svg></a>' .
						'</div>' .
					'</div>' .
					$commentHTML .
				'</div>';
			}

			$recentChangesHTML = '<section class="obbywiki-recent" aria-label="Recent Changes">' .
				'<div class="obbywiki-recent__header">' .
					'<span class="obbywiki-recent__icon"><svg xmlns="http://www.w3.org/2000/svg" height="18" viewBox="0 -960 960 960" width="18" fill="currentColor"><path d="M478-86q-152 0-264.5-101T87-440h127q15 99 89.5 163.5T478-212q112 0 190-78t78-190q0-112-78-190t-190-78q-57 0-109 23.5T279-657h82v97H94v-265h95v79q56-62 130.5-95T478-874q81 0 153 31t125.5 84.5Q810-705 841-633t31 153q0 81-31 153t-84.5 125.5Q703-148 631-117T478-86Zm107-218L433-456v-224h95v184l125 124-68 68Z"/></svg></span>' .
					'<h3 class="obbywiki-recent__title">Recently Changed</h3>' .
				'</div>' .
				'<div class="obbywiki-recent__grid">' . $rcCardsHTML . '</div>' .
			'</section>';
		}

		// about section html
		$aboutURL = htmlspecialchars( Title::newFromText( 'OW:About' )->getLocalURL() );
		$obbyURL = htmlspecialchars( Title::newFromText( 'Obby' )->getLocalURL() );
		$aboutHTML = '<section class="obbywiki-about" aria-label="About the Wiki">' .
			'<div class="obbywiki-about__header">' .
				'<span class="obbywiki-about__icon">' .
					'<svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="M425-265h110v-255H425v255Zm97.5-332.25Q540-614.5 540-640t-17.25-42.75Q505.5-700 480-700t-42.75 17.25Q420-665.5 420-640t17.5 42.75Q455-580 480-580t42.5-17.25ZM480-46q-91 0-169.99-34.08-78.98-34.09-137.41-92.52-58.43-58.43-92.52-137.41Q46-389 46-480q0-91 34.08-169.99 34.09-78.98 92.52-137.41 58.43-58.43 137.41-92.52Q389-914 480-914q91 0 169.99 34.08 78.98 34.09 137.41 92.52 58.43 58.43 92.52 137.41Q914-571 914-480q0 91-34.08 169.99-34.09 78.98-92.52 137.41-58.43 58.43-137.41 92.52Q571-46 480-46Z"/></svg>' .
				'</span>' .
				'<h3 class="obbywiki-about__title">About The Obby Wiki</h3>' .
			'</div>' .
			'<div class="obbywiki-about__content">' .
				'<p class="obbywiki-about__text">The Obby Wiki is an independent and community-run wiki/encyclopedia dedicated to documenting <a href="' . $obbyURL . '">Roblox obby</a> games (obbies), their creators, mechanics, conventions, terminology, etc. Our goal is to provide the most comprehensive, accurate, and complete information about as many obbies as possible.</p>' .
				'<p class="obbywiki-about__text">Whether you want detailed information about a certain obby, or you want to search our database for a new obby to play, information about a certain mechanic or glitch, or just anything related to obbies, you can find it here.</p>' .
				'<br />' .
				'<p class="obbywiki-about__text">Anyone can contribute by editing articles, adding new information, or participating in discussions on the <a href="https://forum.wou.gg">Obby Forum</a>.</p>' .
				'<p class="obbywiki-about__text">Learn more <a href="' . $aboutURL . '">about our project</a> and our advantages as well as how you can help or why you should use us over existing platforms.</p>' .
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
				<form action="{$scriptPath}" method="get" class="obbywiki-home__search-form" role="search" aria-label="Search the wiki">
					<input type="hidden" name="title" value="Special:Search">
					<div class="obbywiki-home__search-input citizen-search-trigger">Search the wiki…</div>
					<button type="submit" class="obbywiki-home__search-btn" aria-label="Search">
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
				<span class="obbywiki-spotlight__chip">OBBY WIKI 	HIGHLIGHTS</span>
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
				<h3 class="obbywiki-month__title">This Month — {$monthName}</h3>
			</div>
			<div class="obbywiki-month__list">
				{$thisMonthHTML}
			</div>
		</div>
	</section>

	
	{$archiveHTML}
	
	{$announcementsHTML}

	<aside class="obbywiki-aside">
		<div class="obbywiki-aside__card">
			<div class="obbywiki-aside__header">
				<span class="obbywiki-aside__icon"><svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="m240-160 40-160H120l20-80h160l40-160H180l20-80h160l40-160h80l-40 160h160l40-160h80l-40 160h160l-20 80H660l-40 160h160l-20 80H600l-40 160h-80l40-160H360l-40 160h-80Zm140-240h160l40-160H420l-40 160Z"/></svg></span>
				<h3 class="obbywiki-aside__title">Browse by Type</h3>
			</div>
			<div class="obbywiki-featured__aside-tags">
				<a href="{$categoryURLs['classic']}" class="obbywiki-featured__aside-tag">Classic Obby</a>
				<a href="{$categoryURLs['tower']}" class="obbywiki-featured__aside-tag">Tower Obby</a>
				<a href="{$categoryURLs['dco']}" class="obbywiki-featured__aside-tag">Difficulty Chart Obby</a>
				<a href="{$categoryURLs['gimmick']}" class="obbywiki-featured__aside-tag">Gimmick Obby</a>
				<a href="{$categoryURLs['tier']}" class="obbywiki-featured__aside-tag">Tiered Obby</a>
				<a href="{$categoryURLs['troll']}" class="obbywiki-featured__aside-tag">Troll Obby</a>
				<a href="{$categoryURLs['coop']}" class="obbywiki-featured__aside-tag">Co-Op Obby</a>
			</div>
		</div>
		<div class="obbywiki-aside__card">
			<div class="obbywiki-aside__header">
				<span class="obbywiki-aside__icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20"><title>edit</title><g fill="currentColor"><path d="m16.77 8 1.94-2a1 1 0 0 0 0-1.41l-3.34-3.3a1 1 0 0 0-1.41 0L12 3.23zM1 14.25V19h4.75l9.96-9.96-4.75-4.75z"/></g></svg></span>
				<h3 class="obbywiki-aside__title">Start Contributing</h3>
			</div>
			<p class="obbywiki-aside__text">Whether you're a casual obby player, a content creator, or a developer, there's a place for you here. Learn more below.</p>
			<div class="obbywiki-featured__aside-cta-links">
				<a href="{$categoryURLs['contributing']}" class="obbywiki-featured__aside-cta-link">
					<svg viewBox="0 0 20 20" width="14" height="14" fill="currentColor"><path d="M10 1a9 9 0 109 9 9 9 0 00-9-9m1 14H9v-2h2zm0-4H9V5h2z"/></svg>
					How to Contribute
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

	{$recentChangesHTML}

	{$aboutHTML}

	<section class="obbywiki-rules" aria-label="Wiki Rules">
		<div class="obbywiki-rules__header">
			<span class="obbywiki-rules__icon">
				<svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="currentColor"><path d="M270-80q-45 0-77.5-30.5T160-186v-558q0-38 23.5-68t61.5-38l395-78v640l-379 76q-9 2-15 9.5t-6 16.5q0 11 9 18.5t21 7.5h450v-640h80v720H270Zm10-217 80-16v-478l-80 16v478Z"/></svg>
			</span>
			<h3 class="obbywiki-rules__title">Wiki Rules</h3>
		</div>
		<div class="obbywiki-rules__content">
			<ul class="obbywiki-rules__list">
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">1. Respect other contributors</h4>
					<p class="obbywiki-rules__item-text">The bare minimum on the Obby Wiki is that you respect other's time and effort. Do not insult, harass, or demean other contributors. Please do not remove existing contributions without a good reason stated in your edit summary.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">2. Provide accurate information</h4>
					<p class="obbywiki-rules__item-text">Use sources/references when available. Do not spread misinformation or rumors, especially without a disclaimer.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">3. Do not vandalize</h4>
					<p class="obbywiki-rules__item-text">Vandalism is the act of intentionally damaging or defacing the wiki or a page. This includes, but is not limited to, deleting or modifying existing content without a good reason, creating pages that are not relevant to the wiki, or making claims that you know cannot be backed up by sources.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">4. Do not move pages without a good reason</h4>
					<p class="obbywiki-rules__item-text">If you want to delete a page, first mark it with the <a href="/wiki/Template:Candidate_for_deletion">{{Candidate for deletion}}</a> template. Only move player pages when their @username changes, not their display name and always leave a redirect.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">5. Make sure a topic meets the eligibility requirements and criteria before making a new page</h4>
					<p class="obbywiki-rules__item-text">Before making a page, always consult the <a href="/wiki/OW:Eligibility_requirements">eligibility requirements</a> and ensure the topic has enough content about it before making an entirely new page. If you're not sure if a topic meets the eligibility requirements, ask an admin.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">6. Only upload relevant files</h4>
					<p class="obbywiki-rules__item-text">Only upload files that are relevant to the topic you are uploading them to. If a file is relevant to no page or topic on the wiki, please note that it may be deleted without notice or warning. Please use all media you upload in articles before 7 days after uploading them.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">7. Make sure a similar page doesn't already exist</h4>
					<p class="obbywiki-rules__item-text">Before making a page, always check if a similar page already exists. If it does, please edit the existing page instead of creating a new one.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">8. Use proper headings</h4>
					<p class="obbywiki-rules__item-text">Organize your content using proper heading levels. Clear structure helps both readers and search engines understand what the page is about.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">9. Link to other relevant wiki pages</h4>
					<p class="obbywiki-rules__item-text">When mentioning another obby, person, or term that has a page or should have a page, add an internal link to it, even if the page doesn't exist yet. This significantly improves site navigation for everyone.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">10. Use US English</h4>
					<p class="obbywiki-rules__item-text">Use US English for all content on the wiki. This includes spelling, grammar, and vocabulary. Content can be translated into English International later. This does not apply to user comments.</p>
				</li>
				<li class="obbywiki-rules__item">
					<h4 class="obbywiki-rules__item-title">11. Do not plagiarize</h4>
					<p class="obbywiki-rules__item-text">Do not plagiarize content from other sources. This includes copying and pasting content from other wikis or websites without proper attribution. If another source is already covering a certain topic, consider its signficance and consider mentioning it in your page.</p>
				</li>
			</ul>
		</div>
	</section>
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
