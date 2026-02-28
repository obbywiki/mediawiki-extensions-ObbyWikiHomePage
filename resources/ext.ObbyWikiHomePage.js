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

	// sync "This Month" list height to carousel (independent of carousel)
	function syncMonthListHeight() {
		var carousel = document.querySelector( '.obbywiki-spotlight' );
		var aside = document.querySelector( '.obbywiki-featured__aside-inner' );
		var monthList = document.querySelector( '.obbywiki-featured__aside-month-list' );
		var monthCard = monthList ? monthList.closest( '.obbywiki-featured__aside-card' ) : null;

		if ( !carousel || !aside || !monthList || !monthCard ) {
			return;
		}

		// reset so we can measure natural layout
		monthList.style.maxHeight = '';

		// only apply when aside is beside the carousel (not stacked)
		var carouselRect = carousel.getBoundingClientRect();
		var asideRect = aside.getBoundingClientRect();
		if ( asideRect.top >= carouselRect.bottom - 1 ) {
			// Stacked layout — no constraint
			return;
		}

		var carouselHeight = carousel.offsetHeight;

		// sum heights of all other cards + gaps in the aside
		var cards = aside.querySelectorAll( '.obbywiki-featured__aside-card' );
		var gapStr = window.getComputedStyle( aside ).gap || '0';
		var gap = parseFloat( gapStr ) || 0;
		var otherCardsHeight = 0;
		for ( var i = 0; i < cards.length; i++ ) {
			if ( cards[ i ] !== monthCard ) {
				otherCardsHeight += cards[ i ].offsetHeight;
			}
		}
		var totalGaps = ( cards.length - 1 ) * gap;

		// available height for the entire month card
		var availableForCard = carouselHeight - otherCardsHeight - totalGaps;
		if ( availableForCard <= 0 ) {
			return;
		}

		// subtract the card's own padding, border, header and gap from the available space
		var cardStyle = window.getComputedStyle( monthCard );
		var cardPaddingTop = parseFloat( cardStyle.paddingTop ) || 0;
		var cardPaddingBottom = parseFloat( cardStyle.paddingBottom ) || 0;
		var cardBorderTop = parseFloat( cardStyle.borderTopWidth ) || 0;
		var cardBorderBottom = parseFloat( cardStyle.borderBottomWidth ) || 0;
		var cardGap = parseFloat( cardStyle.gap ) || 0;
		var header = monthCard.querySelector( '.obbywiki-featured__aside-header' );
		var headerHeight = header ? header.offsetHeight : 0;

		var maxListHeight = availableForCard - cardPaddingTop - cardPaddingBottom - cardBorderTop - cardBorderBottom - headerHeight - cardGap;
		if ( maxListHeight > 0 ) {
			monthList.style.maxHeight = maxListHeight + 'px';
		}
	}

	function initMonthSync() {
		syncMonthListHeight();
		window.addEventListener( 'resize', syncMonthListHeight );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			initMonthSync();
		} );
	} else {
		init();
		initMonthSync();
	}
}() );
