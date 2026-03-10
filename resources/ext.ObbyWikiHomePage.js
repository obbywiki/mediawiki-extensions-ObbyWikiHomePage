( function () {
	'use strict';

	function init() {
		var viewport = document.querySelector( '.obbywiki-spotlight__viewport' );
		var track = document.querySelector( '.obbywiki-spotlight__track' );
		var originalSlides = document.querySelectorAll( '.obbywiki-spotlight__slide' );
		var bars = document.querySelectorAll( '.obbywiki-spotlight__bar' );
		var prevBtn = document.querySelector( '.obbywiki-spotlight__arrow--prev' );
		var nextBtn = document.querySelector( '.obbywiki-spotlight__arrow--next' );
		var spotlight = document.querySelector( '.obbywiki-spotlight' );

		if ( !viewport || !track || originalSlides.length === 0 ) {
			return;
		}

		var current = 0; // logical index
		var count = originalSlides.length;
		var interval = null;
		var INTERVAL_MS = 4000;
		var isTransitioning = false;

		var hasClones = count > 1;

		if ( hasClones ) {
			// clone first and last slides for infinite loop
			var firstClone = originalSlides[ 0 ].cloneNode( true );
			var lastClone = originalSlides[ count - 1 ].cloneNode( true );

			firstClone.setAttribute( 'aria-hidden', 'true' );
			lastClone.setAttribute( 'aria-hidden', 'true' );
			
			// remove tab index from clones so they aren't focusable
			firstClone.tabIndex = -1;
			lastClone.tabIndex = -1;

			track.appendChild( firstClone );
			track.insertBefore( lastClone, originalSlides[ 0 ] );

			// initial position
			track.style.transition = 'none';
			track.style.transform = 'translateX(-100%)';
			void track.offsetWidth;
			track.style.transition = '';
		}

		function goTo( newIndex ) {
			if ( isTransitioning || newIndex === current ) {
				return;
			}

			var isAdjacent = Math.abs( newIndex - current ) === 1;
			if ( hasClones ) {
				if ( current === count - 1 && newIndex === count ) isAdjacent = true;
				if ( current === 0 && newIndex === -1 ) isAdjacent = true;
			}

			var physicalIndex;

			if ( newIndex >= count ) {
				physicalIndex = count + 1;
				current = 0;
			} else if ( newIndex < 0 ) {
				physicalIndex = 0;
				current = count - 1;
			} else {
				current = newIndex;
				physicalIndex = hasClones ? current + 1 : current;
			}

			if ( !isAdjacent ) {
				track.style.transition = 'none';
			}

			track.style.transform = 'translateX(-' + ( physicalIndex * 100 ) + '%)';

			if ( !isAdjacent ) {
				void track.offsetWidth;
				track.style.transition = '';
			} else if ( hasClones && ( physicalIndex === 0 || physicalIndex === count + 1 ) ) {
				isTransitioning = true;
				// Wait for the slide transition to complete, then snap instantly to the real slide
				setTimeout( function () {
					track.style.transition = 'none';
					var snapPhysical = current + 1;
					track.style.transform = 'translateX(-' + ( snapPhysical * 100 ) + '%)';
					void track.offsetWidth;
					track.style.transition = '';
					isTransitioning = false;
				}, 650 ); // Mapped to the CSS 0.65s transition
			}

			updateBars();
		}

		function updateBars() {
			for ( var j = 0; j < bars.length; j++ ) {
				var fill = bars[ j ].querySelector( '.obbywiki-spotlight__bar-fill' );
				if ( j === current ) {
					bars[ j ].classList.add( 'obbywiki-spotlight__bar--active' );
					if ( fill ) {
						fill.style.animation = 'none';
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

		// ── button navigation ──

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

		// ── bar click navigation ──

		for ( var i = 0; i < bars.length; i++ ) {
			( function ( idx ) {
				bars[ idx ].addEventListener( 'click', function () {
					goTo( idx );
					startAutoplay();
				} );
			} )( i );
		}

		// ── touch: real-time drag with momentum snap ──

		var touchStartX = 0;
		var lastTouchX = 0;
		var isDragging = false;
		var SWIPE_THRESHOLD = 40;

		viewport.addEventListener( 'touchstart', function ( e ) {
			if ( isTransitioning ) {
				return;
			}
			stopAutoplay();
			isDragging = true;
			touchStartX = e.touches[ 0 ].clientX;
			lastTouchX = touchStartX;
			track.style.transition = 'none';
		}, { passive: true } );

		viewport.addEventListener( 'touchmove', function ( e ) {
			if ( !isDragging ) {
				return;
			}
			lastTouchX = e.touches[ 0 ].clientX;
			var delta = lastTouchX - touchStartX;
			var physicalIndex = hasClones ? current + 1 : current;
			var baseOffset = -physicalIndex * viewport.offsetWidth;

			if ( !hasClones ) {
				// rubber-band resistance
				if ( ( current === 0 && delta > 0 ) || ( current === count - 1 && delta < 0 ) ) {
					delta *= 0.25;
				}
			}

			track.style.transform = 'translateX(' + ( baseOffset + delta ) + 'px)';
		}, { passive: true } );

		viewport.addEventListener( 'touchend', function () {
			if ( !isDragging ) {
				return;
			}
			isDragging = false;
			track.style.transition = '';

			var delta = lastTouchX - touchStartX;

			if ( delta < -SWIPE_THRESHOLD ) {
				goTo( current + 1 );
			} else if ( delta > SWIPE_THRESHOLD ) {
				goTo( current - 1 );
			} else {
				// snap back
				var physicalIndex = hasClones ? current + 1 : current;
				track.style.transform = 'translateX(-' + ( physicalIndex * 100 ) + '%)';
			}

			startAutoplay();
		}, { passive: true } );

		// ── hover pause ──

		if ( spotlight ) {
			spotlight.addEventListener( 'mouseenter', function () {
				stopAutoplay();
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
		}

		startAutoplay();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
