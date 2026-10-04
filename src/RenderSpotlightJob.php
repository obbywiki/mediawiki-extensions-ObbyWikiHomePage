<?php

namespace MediaWiki\Extension\ObbyWikiHomePage;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * Renders and stores the spotlight animation for the Discord embed.
 */
class RenderSpotlightJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'ObbyWikiHomePageRenderSpotlight', $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		// failures are logged and negatively cached inside
		SpotlightAnimation::renderAndStore( (string)( $this->params['hash'] ?? '' ) );
		return true;
	}
}
