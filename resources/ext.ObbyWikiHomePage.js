( function () {
	'use strict';

	function init() {
		var viewport = document.querySelector( '.obbywiki-spotlight__viewport' );
		var slides = document.querySelectorAll( '.obbywiki-spotlight__slide' );
		var bars = document.querySelectorAll( '.obbywiki-spotlight__bar' );
		var prevBtn = document.querySelector( '.obbywiki-spotlight__arrow--prev' );
		var nextBtn = document.querySelector( '.obbywiki-spotlight__arrow--next' );
		var spotlight = document.querySelector( '.obbywiki-spotlight' );

		if ( !viewport || slides.length === 0 ) {
			return;
		}

		var current = 0;
		var count = slides.length;
		var interval = null;
		var INTERVAL_MS = 4000;

		function goTo( index ) {
			current = ( ( index % count ) + count ) % count;

			// slides
			for ( var i = 0; i < slides.length; i++ ) {
				if ( i === current ) {
					slides[ i ].classList.add( 'obbywiki-spotlight__slide--active' );
				} else {
					slides[ i ].classList.remove( 'obbywiki-spotlight__slide--active' );
				}
			}

			// progress bars
			for ( var j = 0; j < bars.length; j++ ) {
				var fill = bars[ j ].querySelector( '.obbywiki-spotlight__bar-fill' );
				if ( j === current ) {
					bars[ j ].classList.add( 'obbywiki-spotlight__bar--active' );
					// restart the CSS animation by removing and re-adding the element
					if ( fill ) {
						fill.style.animation = 'none';
						// reflow
						void fill.offsetWidth;
						fill.style.animation = '';
					}
				} else {
					bars[ j ].classList.remove( 'obbywiki-spotlight__bar--active' );

					if ( fill ) {
						fill.style.animation = 'none';
						fill.style.width = ( j < current ) ? '100%' : '0%';
					}
				}
			}
		}

		function next() {
			goTo( current + 1 );
		}

		function prev() {
			goTo( current - 1 );
		}

		function startAutoplay() {
			stopAutoplay();
			interval = setInterval( next, INTERVAL_MS );
		}

		function stopAutoplay() {
			if ( interval ) {
				clearInterval( interval );
				interval = null;
			}
		}

		// manual navigation
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function () {
				prev();
				startAutoplay();
			} );
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function () {
				next();
				startAutoplay();
			} );
		}

		// bar click navigation
		for ( var i = 0; i < bars.length; i++ ) {
			( function ( idx ) {
				bars[ idx ].addEventListener( 'click', function () {
					goTo( idx );
					startAutoplay();
				} );
			} )( i );
		}

		// pause on hover
		if ( spotlight ) {
			spotlight.addEventListener( 'mouseenter', function () {
				stopAutoplay();
				// pause the fill animation
				var activeFill = spotlight.querySelector( '.obbywiki-spotlight__bar--active .obbywiki-spotlight__bar-fill' );
				if ( activeFill ) {
					activeFill.style.animationPlayState = 'paused';
				}
			} );
			spotlight.addEventListener( 'mouseleave', function () {
				var activeFill = spotlight.querySelector( '.obbywiki-spotlight__bar--active .obbywiki-spotlight__bar-fill' );
				if ( activeFill ) {
					activeFill.style.animationPlayState = 'running';
				}
				startAutoplay();
			} );

			// Touch support
			spotlight.addEventListener( 'touchstart', function () {
				stopAutoplay();
			}, { passive: true } );
			spotlight.addEventListener( 'touchend', function () {
				startAutoplay();
			}, { passive: true } );
		}
		
		startAutoplay();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
