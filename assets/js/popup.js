/* =====================================================================
   Micelu - Popup de Productos Sugeridos · v1.1
   Compatibilidad: WooCommerce + Variation Swatches for WooCommerce (Pro)
   ===================================================================== */
( function ( $ ) {
	'use strict';

	if ( typeof MiceluPopupData === 'undefined' ) {
		return;
	}

	var $overlay     = null;
	var $grid        = null;
	var $loader      = null;
	var $continueBtn = null;
	var pendingUrl   = null;
	var isFetching   = false;
	var popupContext = 'click';

	/* ------------------------------------------------------------------
	   INIT
	------------------------------------------------------------------ */
	function init() {
		$overlay     = $( '#micelu-cup-overlay' );
		$grid        = $overlay.find( '.micelu-cup-products-grid' );
		$loader      = $overlay.find( '.micelu-cup-loader' );
		$continueBtn = $overlay.find( '.micelu-cup-continue' );

		if ( ! $overlay.length ) {
			return;
		}

		// Intercepta cualquier enlace que apunte al checkout (nunca en el checkout)
		$( document ).on( 'click', 'a', function ( e ) {
			var href = $( this ).attr( 'href' ) || '';
			if ( MiceluPopupData.isCheckout || href.indexOf( MiceluPopupData.checkoutUrl ) === -1 ) {
				return;
			}
			// Si el cliente ya vio las sugerencias en esta pestaña, no insistir.
			if ( hasSeenPopup() ) {
				return;
			}
			e.preventDefault();
			pendingUrl = href;
			openPopupWithSuggestions( 'click' );
		} );

		// Cerrar (X): flujo post add-to-cart → carrito; flujo de enlace → checkout
		$overlay.on( 'click', '.micelu-cup-close', function ( e ) {
			e.preventDefault();
			closeAndNavigate();
		} );

		// Clic en el fondo del overlay → mismo destino que cerrar
		$overlay.on( 'click', function ( e ) {
			if ( e.target === $overlay.get( 0 ) ) {
				closeAndNavigate();
			}
		} );

		// Botón "Continuar con el pago"
		$continueBtn.on( 'click', function ( e ) {
			e.preventDefault();
			goToCheckout();
		} );

		// ── Eventos de variaciones ──────────────────────────────────────
		// WooCommerce nativo + Variation Swatches disparan found_variation
		// sobre el .variations_form. Usamos delegación desde el overlay.
		$overlay.on( 'found_variation', 'form.variations_form', function ( event, variation ) {
			handleVariationFound( $( this ), variation );
		} );

		// Cuando se deselecciona / no hay match
		$overlay.on( 'reset_data hide_variation', 'form.variations_form', function () {
			handleVariationReset( $( this ) );
		} );

		// Variation Swatches Pro puede disparar 'woocommerce_variation_has_changed'
		// sobre el document; lo capturamos y re-evaluamos todos los forms del popup
		$( document ).on( 'woocommerce_variation_has_changed', function () {
			$grid.find( 'form.variations_form' ).trigger( 'check_variations' );
		} );

		// Botón Agregar
		$overlay.on( 'click', '.micelu-cup-add-btn', function () {
			var $btn  = $( this );
			var $card = $btn.closest( '.micelu-cup-product-card' );

			if ( $btn.hasClass( 'is-added' ) || $btn.prop( 'disabled' ) ) {
				return;
			}

			if ( $card.data( 'product-type' ) === 'variable' ) {
				var variationId = parseInt( $card.attr( 'data-variation-id' ), 10 ) || 0;
				if ( ! variationId ) {
					return;
				}
			}

			addProductToCart( $card, $btn );
		} );

		// Añadir al carrito vía AJAX (producto, archivo, quick view…): abre el
		// popup en el sitio, antes de cualquier navegación, sin depender del theme.
		$( document.body ).on( 'added_to_cart', function ( event, fragments, cartHash, button ) {
			if ( button && $( button ).closest( '#micelu-cup-overlay' ).length ) {
				return; // El añadido viene del propio popup.
			}
			openPopupWithSuggestions( 'add' );
		} );

		// Auto-abre el popup en el flujo post add-to-cart
		// (?micelu_popup=1 en la URL o sesión marcada por el servidor).
		if ( MiceluPopupData.autoOpen ) {
			removePopupUrlFlag();
			openPopupWithSuggestions( 'add' );
		}

		// API pública para integraciones (p. ej. add-to-cart vía AJAX en el theme).
		window.MiceluPopup = {
			open: function () {
				openPopupWithSuggestions( 'add' );
			}
		};
	}

	/* ------------------------------------------------------------------
	   HANDLERS DE VARIACIONES
	------------------------------------------------------------------ */
	function handleVariationFound( $form, variation ) {
		var $card  = $form.closest( '.micelu-cup-product-card' );
		var $btn   = $card.find( '.micelu-cup-add-btn' );
		var $price = $card.find( '.micelu-cup-product-price' );

		$card.attr( 'data-variation-id', variation.variation_id );

		if ( variation.is_purchasable && variation.is_in_stock ) {
			$btn.prop( 'disabled', false )
				.removeClass( 'is-added' )
				.text( 'Agregar' );
		} else {
			$btn.prop( 'disabled', true ).text( 'No disponible' );
		}

		if ( variation.price_html ) {
			$price.html( variation.price_html );
		}
	}

	function handleVariationReset( $form ) {
		var $card  = $form.closest( '.micelu-cup-product-card' );
		var $price = $card.find( '.micelu-cup-product-price' );

		$card.attr( 'data-variation-id', '0' );
		$card.find( '.micelu-cup-add-btn' )
			.prop( 'disabled', true )
			.removeClass( 'is-added' )
			.text( 'Selecciona opciones' );

		// Restaura el precio inicial guardado al cargar las tarjetas
		var initialPrice = $price.data( 'initial-price' );
		if ( initialPrice ) {
			$price.html( initialPrice );
		}
	}

	/* ------------------------------------------------------------------
	   ABRIR POPUP Y CARGAR SUGERENCIAS
	------------------------------------------------------------------ */
	function openPopupWithSuggestions( context ) {
		if ( isFetching || MiceluPopupData.isCheckout ) {
			return;
		}
		popupContext = context || 'click';
		isFetching = true;

		// NO mostramos el popup aún. Primero verificamos si hay productos.
		$.post( MiceluPopupData.ajaxUrl, {
			action : 'micelu_get_popup_products',
			nonce  : MiceluPopupData.nonce
		} ).done( function ( response ) {
			isFetching = false;

			if ( ! response || ! response.success || ! response.data.has_products ) {
				// No hay sugerencias: no abrimos popup, vamos directo al checkout.
				goToCheckout();
				return;
			}

			// SÍ hay productos: ahora abrimos el popup y mostramos el contenido
			markSeen();
			$overlay.addClass( 'is-open' ).attr( 'aria-hidden', 'false' );
			$loader.hide();
			$grid.html( response.data.html ).show();

			// Guardar precio inicial de cada tarjeta ANTES de que
			// found_variation lo sobreescriba con el de la variante
			$grid.find( '.micelu-cup-product-price' ).each( function () {
				$( this ).data( 'initial-price', $( this ).html() );
			} );

			initVariationForms();

		} ).fail( function () {
			isFetching = false;
			goToCheckout();
		} );
	}

	/* ------------------------------------------------------------------
	   INICIALIZAR FORMULARIOS DE VARIACIONES (inyectados vía AJAX)

	   Variation Swatches for WooCommerce Pro reemplaza los <select>
	   con swatches después de que wc_variation_form() corre.
	   Necesitamos dispararle los eventos correctos para que:
	   - Convierta los <select> a swatches
	   - Registre sus listeners de clic
	   - Evalúe correctamente found_variation al seleccionar
	------------------------------------------------------------------ */
	function initVariationForms() {
		$grid.find( 'form.variations_form' ).each( function () {
			var $form = $( this );

			// 1. Init nativo de WooCommerce (registra found_variation, etc.)
			if ( typeof $form.wc_variation_form === 'function' ) {
				$form.wc_variation_form();
			}

			// 2. Variation Swatches Pro escucha 'wc_variation_form' en document
			//    para detectar nuevos forms y transformar sus selects en swatches
			$( document ).trigger( 'wc_variation_form', [ $form ] );

			// 3. Trigger sobre el form también (algunos hooks lo escuchan aquí)
			$form.trigger( 'wc_variation_form' );

			// 4. Objeto global del plugin TA (Variation Swatches by ThemeAtelier / getwooplugins)
			if ( typeof window.tawcvs !== 'undefined' && typeof window.tawcvs.initForm === 'function' ) {
				window.tawcvs.initForm( $form );
			}

			// 5. Variante del plugin que expone jQuery plugin
			if ( typeof $.fn.wcVariationSwatches === 'function' ) {
				$form.wcVariationSwatches();
			}

			// 6. Check inicial (evalúa si ya hay una combinación válida seleccionada)
			$form.trigger( 'check_variations' );
		} );

		// Notificar al document que el popup tiene forms listos
		$( document ).trigger( 'micelu_cup_forms_ready', [ $grid ] );
	}

	/* ------------------------------------------------------------------
	   AGREGAR AL CARRITO
	------------------------------------------------------------------ */
	function addProductToCart( $card, $btn ) {
		var productId   = $card.attr( 'data-product-id' );
		var variationId = $card.attr( 'data-variation-id' ) || 0;

		$btn.prop( 'disabled', true ).text( MiceluPopupData.i18n.adding );

		$.post( MiceluPopupData.ajaxUrl, {
			action      : 'micelu_popup_add_to_cart',
			nonce       : MiceluPopupData.nonce,
			product_id  : productId,
			variation_id: variationId
		} ).done( function ( response ) {
			if ( response && response.success ) {
				$btn.addClass( 'is-added' ).text( MiceluPopupData.i18n.added );

				// Refresca fragmentos (mini-carrito, contador, etc.)
				if ( response.data.fragments ) {
					$.each( response.data.fragments, function ( key, value ) {
						$( key ).replaceWith( value );
					} );
				}
				$( document.body ).trigger( 'wc_fragment_refresh' );
				$( document.body ).trigger( 'added_to_cart', [
					response.data.fragments,
					response.data.cart_hash,
					$btn
				] );
			} else {
				$btn.prop( 'disabled', false ).text( MiceluPopupData.i18n.error );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( MiceluPopupData.i18n.error );
		} );
	}

	/* ------------------------------------------------------------------
	   NAVEGACIÓN
	------------------------------------------------------------------ */
	function goToCheckout() {
		var url = pendingUrl || MiceluPopupData.checkoutUrl;
		closePopup();
		window.location.href = url;
	}

	// Cerrar (X o fondo): en el flujo post add-to-cart lleva al carrito
	// (donde ya no se volverán a mostrar los upsells); en el flujo de
	// enlace hacia checkout, sigue al checkout original.
	function closeAndNavigate() {
		var url = MiceluPopupData.cartUrl;
		if ( popupContext !== 'add' ) {
			url = pendingUrl || MiceluPopupData.checkoutUrl;
		}
		closePopup();
		window.location.href = url;
	}

	function closePopup() {
		$overlay.removeClass( 'is-open' ).attr( 'aria-hidden', 'true' );
	}

	// Marca que el cliente ya vio las sugerencias en esta pestaña, para no
	// volver a mostrarlas (p. ej. al pulsar "Finalizar compra" en el carrito).
	function markSeen() {
		try {
			window.sessionStorage.setItem( 'micelu_popup_seen', '1' );
		} catch ( err ) {}
	}

	function hasSeenPopup() {
		try {
			return window.sessionStorage.getItem( 'micelu_popup_seen' ) === '1';
		} catch ( err ) {
			return false;
		}
	}

	// Limpia ?micelu_popup=1 de la URL para que recargar o volver atrás
	// no vuelva a abrir el popup.
	function removePopupUrlFlag() {
		try {
			var url = new window.URL( window.location.href );
			url.searchParams.delete( 'micelu_popup' );
			if ( typeof window.history.replaceState === 'function' ) {
				window.history.replaceState( null, '', url.toString() );
			}
		} catch ( err ) {}
	}

	$( init );

} )( jQuery );
