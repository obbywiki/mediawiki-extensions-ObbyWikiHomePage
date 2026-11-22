<?php

namespace MediaWiki\Extension\ObbyWikiHomePage\Maintenance;

use MediaWiki\Extension\ObbyWikiHomePage\SpotlightData;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Shows the scored spotlight candidates and what would be picked, for tuning $wgObbyWikiHomePageSpotlight. Bypasses the cache.
 *
 * php maintenance/run.php ObbyWikiHomePage:previewSpotlight --top 30
 */
class PreviewSpotlight extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'List the scored spotlight candidates and the current main and wide picks.' );
		$this->addOption( 'top', 'How many candidates to list (default 25, 0 for all)', false, true );
		$this->addOption( 'seed', 'Rotation seed to preview instead of the current one, e.g. to see tomorrow\'s pick', false, true );
		$this->requireExtension( 'ObbyWikiHomePage' );
	}

	public function execute() {
		if ( $this->hasOption( 'seed' ) ) {
			$settings = SpotlightData::getSettings();
			$period = max( 1, $settings['rotation'] );
			\MWTimestamp::setFakeTime( (int)$this->getOption( 'seed' ) * $period );
		}

		$result = SpotlightData::explain();
		$this->output( "Source: {$result['source']}, seed: {$result['seed']}, candidates: " . count( $result['candidates'] ) . "\n\n" );

		$top = (int)$this->getOption( 'top', 25 );
		$main = array_flip( array_column( $result['main'], 'pageId' ) );
		$wide = array_flip( array_column( $result['wide'], 'pageId' ) );
		$names = [ 'visits', 'rating', 'favorites', 'length', 'description', 'recency' ];

		$this->output( sprintf( "%-4s %-5s %s %-18s %s\n", 'pick', 'score', implode( ' ', array_map( static fn ( $n ) => str_pad( substr( $n, 0, 5 ), 5 ), $names ) ), 'subgenre', 'title' ) );
		foreach ( $result['candidates'] as $i => $c ) {
			if ( $top > 0 && $i >= $top ) {
				break;
			}

			$id = $c['item']['pageId'];
			$pick = isset( $main[$id] ) ? 'main' : ( isset( $wide[$id] ) ? 'wide' : '' );
			$signals = array_map( static fn ( $n ) => $c['signals'][$n] === null ? '  -  ' : sprintf( '%.2f ', $c['signals'][$n] ), $names );
			$this->output( sprintf( "%-4s %.3f %s %-18s %s\n", $pick, $c['score'], implode( ' ', $signals ), mb_substr( (string)$c['subgenre'], 0, 18 ), SpotlightData::getPlainTitle( $c['item'] ) ) );
		}

		$this->output( "\nMain pick:\n" );
		foreach ( $result['main'] as $i => $item ) {
			$this->output( '  ' . ( $i + 1 ) . '. ' . SpotlightData::getPlainTitle( $item ) . "\n" );
		}
		$this->output( "Wide pick extras:\n" );
		foreach ( array_slice( $result['wide'], count( $result['main'] ) ) as $item ) {
			$this->output( '  - ' . SpotlightData::getPlainTitle( $item ) . "\n" );
		}
	}
}

$maintClass = PreviewSpotlight::class;
require_once RUN_MAINTENANCE_IF_MAIN;
