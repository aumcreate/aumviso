/* AumViso - Knowledge Builder.
 * Generates FAQ / Guide drafts from verified first-layer facts. Drafts must be
 * reviewed and published before Automation treats them as trusted knowledge. */
( function ( $ ) {
	'use strict';

	var cfg = window.AumVisoAdvFactory || {};
	var i18n = cfg.i18n || {};
	var busy = false;

	function status( text ) { $( '#avp-factory-status' ).show().text( text ); }
	function finish() { busy = false; $( '#avp-factory-run' ).prop( 'disabled', false ); }
	function fail( res ) { status( ( res && res.data && res.data.message ) || i18n.error || 'Error.' ); finish(); }

	function selectedSource() {
		var raw = String( $( '#avp-factory-source' ).val() || 'entity|' ).split( '|' );
		return { type: raw[0] || 'entity', id: raw.slice( 1 ).join( '|' ) || '' };
	}

	function run( nonce ) {
		var topic = $.trim( $( '#avp-factory-topic' ).val() );
		var material = $( '#avp-factory-material' ).val();
		var kind = $( '#avp-factory-kind' ).val();
		var source = selectedSource();
		if ( ! topic ) { status( i18n.notopic || 'Please enter a topic.' ); return; }

		busy = true;
		$( '#avp-factory-run' ).prop( 'disabled', true );
		$( '#avp-factory-result' ).hide().empty();
		status( i18n.writing || 'Building knowledge draft...' );

		$.post( cfg.ajaxUrl, {
			action: 'aumviso_adv_factory_generate',
			nonce: nonce,
			topic: topic,
			material: material,
			kind: kind,
			source_type: source.type,
			source_id: source.id
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) { fail( res ); return; }
			var d = res.data;
			$( '#avp-factory-result' ).show().html(
				'<div class="aml-pill aml-pill-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
				+ ( i18n.created || 'Draft created' ) + ' - ' + d.words + ' ' + ( i18n.words || 'words' ) + '</div> '
				+ '<a class="aml-btn" href="' + d.edit_url + '" target="_blank" rel="noopener"><span class="dashicons dashicons-edit"></span> '
				+ ( i18n.edit || 'Edit draft' ) + '</a>'
			);
			status( i18n.done || 'Done.' );
			finish();
		} ).fail( function () { fail(); } );
	}

	function renderTopics( list ) {
		var $box = $( '#avp-factory-topics' ).empty();
		if ( ! list || ! list.length ) {
			$box.hide();
			status( i18n.notopics || 'No suggestions found for this source yet.' );
			return;
		}
		list.forEach( function ( item ) {
			var topic = typeof item === 'string' ? item : item.topic;
			var material = typeof item === 'string' ? '' : item.material;
			$( '<button type="button" class="button"></button>' )
				.css( { margin: '0 6px 6px 0' } )
				.text( topic )
				.on( 'click', function () {
					$( '#avp-factory-topic' ).val( topic );
					if ( material ) {
						$( '#avp-factory-material' ).val( material );
					}
				} )
				.appendTo( $box );
		} );
		$box.show();
		status( i18n.picktopic || 'Pick a suggestion, then review the notes before generating.' );
	}

	function suggestTopics( nonce ) {
		var source = selectedSource();
		var $button = $( '#avp-factory-fill' ).prop( 'disabled', true );
		$.post( cfg.ajaxUrl, {
			action: 'aumviso_adv_factory_topics',
			nonce: nonce,
			mode: 'builder',
			kind: $( '#avp-factory-kind' ).val(),
			source_type: source.type,
			source_id: source.id
		} )
			.done( function ( res ) {
				if ( res && res.success ) { renderTopics( res.data.topics ); }
				else { status( ( res && res.data && res.data.message ) || i18n.error || 'Error.' ); }
			} )
			.fail( function () { status( i18n.error || 'Error.' ); } )
			.always( function () { $button.prop( 'disabled', false ); } );
	}

	$( function () {
		var $run = $( '#avp-factory-run' );
		if ( ! $run.length ) { return; }
		var nonce = $( '#avp-factory' ).data( 'nonce' );
		$run.on( 'click', function () { if ( ! busy ) { run( nonce ); } } );
		$( '#avp-factory-fill' ).on( 'click', function () { suggestTopics( nonce ); } );
	} );
} )( jQuery );
