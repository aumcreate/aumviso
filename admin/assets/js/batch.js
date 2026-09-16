/* AumViso — Batch AI runner.
 * Resolves the target list once, then processes it in small chunks so a long
 * run never hits a PHP timeout. Shows a live progress bar + per-item log. */
( function ( $ ) {
	'use strict';

	var cfg = window.AumVisoAdvBatch || {};
	var i18n = cfg.i18n || {};
	var running = false;
	var stopReq = false;

	function byId( id ) { return document.getElementById( id ); }
	function setBar( pct ) { var b = byId( 'avp-batch-bar' ); if ( b ) { b.style.width = pct + '%'; } }
	function setStatus( t ) { var s = byId( 'avp-batch-status' ); if ( s ) { s.textContent = t; } }

	function logRow( r ) {
		var cls = r.status === 'ok' ? 'ok' : ( r.status === 'skip' ? 'skip' : 'err' );
		var icon = r.status === 'ok' ? '✓' : ( r.status === 'skip' ? '–' : '✗' );
		var li = document.createElement( 'li' );
		li.className = 'avp-log-' + cls;
		li.textContent = icon + '  ' + ( r.title || ( '#' + r.id ) ) + ' — ' + ( r.message || '' );
		byId( 'avp-batch-log' ).appendChild( li );
	}

	function finish() {
		running = false;
		$( '#avp-batch-run' ).prop( 'disabled', false );
		$( '#avp-batch-stop' ).hide();
	}

	function fail( res ) {
		setStatus( ( res && res.data && res.data.message ) || i18n.error || 'Error.' );
		finish();
	}

	function done( total, tally ) {
		setBar( 100 );
		setStatus( ( i18n.done || 'Done.' ) + '  ✓ ' + tally.ok + '   – ' + tally.skip + '   ✗ ' + tally.err );
		finish();
	}

	function processChunks( ids, idx, params, tally ) {
		if ( stopReq || idx >= ids.length ) {
			done( ids.length, tally );
			return;
		}
		var slice = ids.slice( idx, idx + params.chunk );
		setStatus( idx + ' / ' + ids.length );

		$.post( cfg.ajaxUrl, {
			action: 'aumviso_adv_batch_run',
			nonce: params.nonce,
			op: params.op,
			overwrite: params.overwrite,
			ids: slice
		} ).done( function ( res ) {
			if ( res && res.success && res.data.results ) {
				res.data.results.forEach( function ( r ) {
					logRow( r );
					tally[ r.status === 'ok' ? 'ok' : ( r.status === 'skip' ? 'skip' : 'err' ) ]++;
				} );
			}
			advance( ids, idx + slice.length, params, tally );
		} ).fail( function () {
			slice.forEach( function ( id ) {
				logRow( { id: id, title: '#' + id, status: 'error', message: i18n.reqfail || 'Request failed' } );
				tally.err++;
			} );
			advance( ids, idx + slice.length, params, tally );
		} );
	}

	function advance( ids, next, params, tally ) {
		setBar( Math.round( ( next / ids.length ) * 100 ) );
		processChunks( ids, next, params, tally );
	}

	function start( params ) {
		running = true;
		stopReq = false;
		$( '#avp-batch-progress' ).show();
		byId( 'avp-batch-log' ).innerHTML = '';
		setBar( 0 );
		setStatus( i18n.loading || 'Loading…' );
		$( '#avp-batch-run' ).prop( 'disabled', true );
		$( '#avp-batch-stop' ).show();

		$.post( cfg.ajaxUrl, {
			action: 'aumviso_adv_batch_targets',
			nonce: params.nonce,
			post_type: params.post_type,
			missing_only: params.missing_only
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) { fail( res ); return; }
			var ids = res.data.ids || [];
			if ( ! ids.length ) { setStatus( i18n.none || 'Nothing to process.' ); finish(); return; }
			processChunks( ids, 0, params, { ok: 0, skip: 0, err: 0 } );
		} ).fail( function () { fail(); } );
	}

	$( function () {
		var $run = $( '#avp-batch-run' );
		if ( ! $run.length ) { return; }
		var nonce = $( '#avp-batch' ).data( 'nonce' );
		var chunk = cfg.chunk || 4;

		$run.on( 'click', function () {
			if ( running ) { return; }
			start( {
				nonce: nonce,
				chunk: chunk,
				post_type: $( '#avp-batch-pt' ).val(),
				op: $( '#avp-batch-op' ).val(),
				missing_only: $( '#avp-batch-missing' ).is( ':checked' ) ? 1 : 0,
				overwrite: $( '#avp-batch-overwrite' ).is( ':checked' ) ? 1 : 0
			} );
		} );

		$( '#avp-batch-stop' ).on( 'click', function () { stopReq = true; } );
	} );
} )( jQuery );
