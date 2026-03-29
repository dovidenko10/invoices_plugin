( function() {
	'use strict';

	function invoiceDividiToggleCompanyFields() {
		var cb     = document.getElementById( 'invoice_dividi_is_company' );
		var fields = document.getElementById( 'invoice-dividi-company-fields' );
		if ( ! cb || ! fields ) {
			return;
		}
		fields.style.display = cb.checked ? '' : 'none';
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		var cb = document.getElementById( 'invoice_dividi_is_company' );
		if ( cb ) {
			cb.addEventListener( 'change', invoiceDividiToggleCompanyFields );
			invoiceDividiToggleCompanyFields();
		}
	} );
} )();
