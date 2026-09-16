/**
 * Automation tab — confirmation before enabling full-auto publishing.
 *
 * Lives in its own file rather than an inline <script> so it goes through
 * wp_enqueue_script() like everything else. The warning text arrives via
 * wp_localize_script so it stays translatable.
 */
( function () {
	var cfg = window.AumVisoAdvAuto || {};
	var form = document.getElementById( 'avp-auto-form' );

	if ( ! form ) {
		return;
	}

	form.addEventListener( 'submit', function ( e ) {
		var isSave = e.submitter && e.submitter.name === 'avp_auto_save';
		var mode   = document.getElementById( 'avp-auto-mode' );

		if ( isSave && mode && mode.value === 'full' && cfg.confirmFullAuto ) {
			if ( ! window.confirm( cfg.confirmFullAuto ) ) {
				e.preventDefault();
			}
		}
	} );
}() );
