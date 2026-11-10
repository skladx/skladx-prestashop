/**
 * SkladX for PrestaShop – back office helpers: dismissing notices, the
 * SkladX section of the product page and confirmations in the order box.
 */
( function () {
	'use strict';

	function post( url, data ) {
		var body = new window.URLSearchParams();

		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			body: body,
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, message: 'HTTP ' + response.status };
			} );
		} );
	}

	function dismiss( box, id ) {
		post( box.getAttribute( 'data-dismiss-url' ) + '&id=' + encodeURIComponent( id ), { id: id } );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var button = target.closest ? target.closest( '.skladx-notice__dismiss' ) : null;
		var box;

		if ( button ) {
			box = button.closest( '.skladx-notices' );
			var notice = button.closest( '.skladx-notice' );

			dismiss( box, notice.getAttribute( 'data-id' ) );
			notice.remove();

			return;
		}

		if ( target.closest && target.closest( '.skladx-notice__dismiss-all' ) ) {
			event.preventDefault();
			box = target.closest( '.skladx-notices' );
			dismiss( box, 'all' );
			box.remove();

			return;
		}

		var confirmable = target.closest ? target.closest( '[data-skladx-confirm]' ) : null;

		if ( confirmable && ! window.confirm( confirmable.getAttribute( 'data-skladx-confirm' ) ) ) { // eslint-disable-line no-alert
			event.preventDefault();

			return;
		}

		var save = target.closest ? target.closest( '.skladx-product-save, .skladx-product-sync' ) : null;

		if ( save ) {
			event.preventDefault();
			var tab = save.closest( '.skladx-product-tab' );
			var result = tab.querySelector( '.skladx-product-result' );
			var isSave = save.classList.contains( 'skladx-product-save' );

			save.disabled = true;
			result.textContent = '…';
			result.className = 'skladx-product-result';

			post( tab.getAttribute( 'data-ajax-url' ) + '&action=' + ( isSave ? 'saveProduct' : 'syncProduct' ), {
				id_product: tab.getAttribute( 'data-id-product' ),
				storage_class: tab.querySelector( '.skladx-storage-class' ).value,
				storage_class_custom: tab.querySelector( '.skladx-storage-class-custom' ).value,
			} ).then( function ( body ) {
				result.textContent = body.message || '';
				result.className = 'skladx-product-result ' + ( body.success ? 'text-success' : 'text-danger' );
			} ).catch( function ( error ) {
				result.textContent = error.message;
				result.className = 'skladx-product-result text-danger';
			} ).then( function () {
				save.disabled = false;
			} );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'skladx-storage-class' ) ) {
			var custom = event.target.closest( '.skladx-product-tab' ).querySelector( '.skladx-storage-class-custom' );

			custom.style.display = event.target.value === 'custom' ? '' : 'none';
		}
	} );
}() );
