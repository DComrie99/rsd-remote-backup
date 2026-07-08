jQuery( function ( $ ) {
    'use strict';

    // --- Tab switching ---------------------------------------------------
    var $tabs    = $( '.rsd-rb-wrap .nav-tab' );
    var $panels  = $( '.rsd-rb-wrap .rsd-rb-tab' );

    function activateTab( target ) {
        $tabs.removeClass( 'nav-tab-active' );
        $tabs.filter( '[href="' + target + '"]' ).addClass( 'nav-tab-active' );
        $panels.hide();
        $( target ).show();
    }

    $tabs.on( 'click', function ( e ) {
        e.preventDefault();
        activateTab( $( this ).attr( 'href' ) );
    } );

    // Restore tab on page load.
    // 1. ?rb_tab=status query param (used by Refresh button — forces a real page reload).
    // 2. Fall back to URL hash (used by OAuth/action redirects).
    var urlParams = new URLSearchParams( window.location.search );
    var rbTab     = urlParams.get( 'rb_tab' );
    if ( rbTab && $( '#tab-' + rbTab ).length ) {
        activateTab( '#tab-' + rbTab );
    } else if ( window.location.hash && $( window.location.hash ).length ) {
        activateTab( window.location.hash );
    }

    // --- Provider section toggle -----------------------------------------
    var $providerSelect = $( '[name="rsd_rb_provider"]' );

    function showProviderSection() {
        var val = $providerSelect.val();
        $( '.rsd-rb-provider-section' ).hide();
        $( '#provider-' + val ).show();
    }

    $providerSelect.on( 'change', showProviderSection );
    showProviderSection();
} );
