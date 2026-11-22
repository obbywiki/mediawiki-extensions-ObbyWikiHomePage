<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * Renders and stores a generated home page image: the spotlight animation for the Discord embed (kind 'spotlight', the default for jobs queued before the card existed) or the link preview card (kind 'card').
 */
class RenderSpotlightJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'ObbyWikiHomePageRenderSpotlight', $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		// failures are logged and negatively cached inside
		$hash = (string)( $this->params['hash'] ?? '' );
		if ( ( $this->params['kind'] ?? 'spotlight' ) === 'card' ) {
			SocialCard::renderAndStore( $hash );
		} else {
			SpotlightAnimation::renderAndStore( $hash );
		}
		return true;
	}
}
