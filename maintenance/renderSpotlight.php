<?php

namespace MediaWiki\Extension\ObbyWikiHomePage\Maintenance;

use Imagick;
use MediaWiki\Extension\ObbyWikiHomePage\SpotlightAnimation;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Renders the Discord spotlight animation on demand.
 *
 * php maintenance/run.php ObbyWikiHomePage:renderSpotlight --out /tmp/spotlight.webp --force
 */
class RenderSpotlight extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Render the animated spotlight image used in the Discord embed.' );
		$this->addOption( 'out', 'Write the animation to this path instead of storing it in the upload backend', false, true );
		$this->addOption( 'force', 'Render even when the stored file for the current hash already exists' );
		$this->addOption( 'dump-frames', 'Also write every frame as a PNG plus frames.txt (file, delay in 1/100 s) to this directory', false, true );
		$this->requireExtension( 'ObbyWikiHomePage' );
	}

	public function execute() {
		$start = microtime( true );
		$result = SpotlightAnimation::renderAndStore(
			null,
			$this->hasOption( 'force' ),
			$this->getOption( 'out' ),
			$this->getOption( 'dump-frames' )
		);
		$seconds = microtime( true ) - $start;

		$this->output( "Status:       {$result['status']}\n" );
		$this->output( "Hash:         {$result['hash']}\n" );
		foreach ( [ 'path' => 'Path', 'url' => 'URL', 'error' => 'Error' ] as $key => $label ) {
			if ( isset( $result[$key] ) ) {
				$this->output( str_pad( "$label:", 14 ) . $result[$key] . "\n" );
			}
		}
		if ( isset( $result['frames'] ) ) {
			$this->output( "Format:       {$result['format']} {$result['width']}x{$result['height']}\n" );
			$this->output( "Frames:       {$result['frames']}\n" );
			$this->output( sprintf( "Size:         %d bytes (%.2f MB)\n", $result['bytes'], $result['bytes'] / 1048576 ) );
			$this->output( "Budget step:  {$result['step']} ({$result['stepLabel']})\n" );
		}
		$this->output( sprintf( "Render time:  %.2f s\n", $seconds ) );
		$this->output( sprintf( "Peak memory:  %.1f MB (PHP)\n", memory_get_peak_usage( true ) / 1048576 ) );
		if ( class_exists( Imagick::class ) ) {
			$this->output( sprintf(
				"Imagick:      memory %.1f MB, map %.1f MB, disk %.1f MB (current)\n",
				Imagick::getResource( Imagick::RESOURCETYPE_MEMORY ) / 1048576,
				Imagick::getResource( Imagick::RESOURCETYPE_MAP ) / 1048576,
				Imagick::getResource( Imagick::RESOURCETYPE_DISK ) / 1048576
			) );
		}

		if ( in_array( $result['status'], [ 'failed', 'unsupported' ], true ) ) {
			$this->fatalError( 'Render failed.' );
		}
	}
}

$maintClass = RenderSpotlight::class;
require_once RUN_MAINTENANCE_IF_MAIN;
