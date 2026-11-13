<?php
/**
 * Plugin Name: Micelu - Popup de Productos Sugeridos en Checkout
 * Plugin URI:  https://micelu.co
 * Description: Muestra un popup con productos sugeridos (configurables por producto) justo antes de que el cliente vaya a pagar, permitiendo agregarlos al carrito sin salir del flujo de compra.
 * Version:     1.4.2
 * Author:      Micelu
 * Text Domain: micelu-cart-upsell-popup
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Seguridad: no acceso directo.
}

define( 'MICELU_CUP_VERSION', '1.4.2' );
define( 'MICELU_CUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MICELU_CUP_URL', plugin_dir_url( __FILE__ ) );
define( 'MICELU_CUP_META_KEY', '_micelu_popup_related_products' );
define( 'MICELU_CUP_MAX_PRODUCTS', 6 );

/**
 * Comprueba que WooCommerce esté activo antes de hacer nada.
 */
function micelu_cup_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Aviso admin si WooCommerce no está activo.
 */
add_action( 'admin_notices', function () {
	if ( ! micelu_cup_woocommerce_active() ) {
		echo '<div class="notice notice-error"><p><strong>Popup de Productos Sugeridos:</strong> este plugin requiere que WooCommerce esté activo.</p></div>';
	}
} );

/* ==========================================================================
   1. META BOX EN EL PRODUCTO (Administración)
   ========================================================================== */

add_action( 'add_meta_boxes', function () {
	if ( ! micelu_cup_woocommerce_active() ) {
		return;
	}
	add_meta_box(
		'micelu_cup_metabox',
		'Popup de Checkout · Productos Sugeridos',
		'micelu_cup_render_metabox',
		'product',
		'normal',
		'default'
	);
} );

function micelu_cup_render_metabox( $post ) {
	wp_nonce_field( 'micelu_cup_save_metabox', 'micelu_cup_nonce' );

	$selected_ids = get_post_meta( $post->ID, MICELU_CUP_META_KEY, true );
	if ( ! is_array( $selected_ids ) ) {
		$selected_ids = array();
	}

	// Asegura que el script de búsqueda mejorada de WooCommerce esté cargado en esta pantalla.
	if ( function_exists( 'wp_enqueue_script' ) ) {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}
	?>
	<p style="max-width:700px;">
		Selecciona los productos que quieres sugerir en el popup cuando este producto esté en el carrito
		del cliente y presione <em>"Continuar con el pago"</em> o <em>"Finalizar compra"</em>.
	</p>
	<p class="form-field micelu-cup-field">
		<label for="micelu_popup_products"><strong>Productos sugeridos</strong></label><br/>
		<select
			id="micelu_popup_products"
			name="micelu_popup_products[]"
			class="wc-product-search"
			multiple="multiple"
			style="width: 100%; max-width: 700px;"
			data-placeholder="Busca y selecciona productos…"
			data-action="woocommerce_json_search_products_and_variations"
			data-exclude="<?php echo esc_attr( $post->ID ); ?>"
		>
			<?php
			foreach ( $selected_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					echo '<option value="' . esc_attr( $product_id ) . '" selected="selected">' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
				}
			}
			?>
		</select>
	</p>
	<?php
}

add_action( 'save_post_product', function ( $post_id ) {
	if ( ! isset( $_POST['micelu_cup_nonce'] ) || ! wp_verify_nonce( $_POST['micelu_cup_nonce'], 'micelu_cup_save_metabox' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	$ids = array();
	if ( isset( $_POST['micelu_popup_products'] ) && is_array( $_POST['micelu_popup_products'] ) ) {
		$ids = array_map( 'absint', $_POST['micelu_popup_products'] );
		$ids = array_filter( $ids );
	}

	update_post_meta( $post_id, MICELU_CUP_META_KEY, $ids );
} );

/* ==========================================================================
   2. ASSETS EN EL FRONTEND
   ========================================================================== */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! micelu_cup_woocommerce_active() || is_admin() ) {
		return;
	}

	wp_enqueue_style(
		'micelu-cup-style',
		MICELU_CUP_URL . 'assets/css/popup.css',
		array(),
		MICELU_CUP_VERSION
	);

	// WooCommerce solo encola este script en la página de producto por defecto.
	// Lo forzamos aquí porque el popup puede mostrarse en carrito/checkout.
	wp_enqueue_script( 'wc-add-to-cart-variation' );

	wp_enqueue_script(
		'micelu-cup-script',
		MICELU_CUP_URL . 'assets/js/popup.js',
		array( 'jquery', 'wc-add-to-cart-variation' ),
		MICELU_CUP_VERSION,
		true
	);

	// El popup nunca debe abrirse en la página de checkout: ahí los upsells
	// no tienen sentido y un autoOpen pegado ahí causa un bucle.
	$micelu_auto_open = ! is_checkout() && isset( $_GET['micelu_popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['micelu_popup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	wp_localize_script( 'micelu-cup-script', 'MiceluPopupData', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'micelu_cup_nonce' ),
		'checkoutUrl' => wc_get_checkout_url(),
		'cartUrl'     => wc_get_cart_url(),
		'autoOpen'    => $micelu_auto_open,
		'isCheckout'  => is_checkout(),
		'currencyPos' => get_option( 'woocommerce_currency_pos' ),
		'i18n'        => array(
			'adding'  => 'Agregando…',
			'added'   => 'Agregado ✓',
			'error'   => 'No se pudo agregar el producto. Intenta de nuevo.',
			'loading' => 'Cargando sugerencias…',
		),
	) );
} );

/* ==========================================================================
   3. MARKUP DEL POPUP (se imprime una vez en el footer)
   ========================================================================== */

add_action( 'wp_footer', function () {
	if ( ! micelu_cup_woocommerce_active() || is_admin() ) {
		return;
	}
	?>
	<div id="micelu-cup-overlay" class="micelu-cup-overlay" aria-hidden="true">
		<div class="micelu-cup-modal" role="dialog" aria-modal="true" aria-labelledby="micelu-cup-title">
			<button type="button" class="micelu-cup-close" aria-label="Cerrar">&times;</button>
			<div class="micelu-cup-header">
				<h3 id="micelu-cup-title">¿Quieres agregar algo más?</h3>
				<p>Estos productos combinan bien con lo que llevas en tu carrito.</p>
			</div>
			<div class="micelu-cup-body">
				<div class="micelu-cup-loader">Cargando sugerencias…</div>
				<div class="micelu-cup-products-grid" style="display:none;"></div>
			</div>
			<div class="micelu-cup-footer">
				<span class="micelu-cup-footer-note">También puedes continuar sin agregar nada.</span>
				<a href="#" class="micelu-cup-continue">Continuar con el pago &rarr;</a>
			</div>
		</div>
	</div>
	<?php
} );

/* ==========================================================================
   4. AJAX: OBTENER PRODUCTOS SUGERIDOS SEGÚN EL CARRITO ACTUAL
   ========================================================================== */

add_action( 'wp_ajax_micelu_get_popup_products', 'micelu_cup_ajax_get_popup_products' );
add_action( 'wp_ajax_nopriv_micelu_get_popup_products', 'micelu_cup_ajax_get_popup_products' );

/**
 * Devuelve los IDs de productos sugeridos (con upsells configurados)
 * para el carrito actual, únicos y excluyendo lo que ya está en el carrito.
 *
 * @return int[]
 */
function micelu_cup_get_cart_suggested_ids() {
	$suggested_ids = array();

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $suggested_ids;
	}

	$cart_items       = WC()->cart->get_cart();
	$cart_product_ids = array();

	foreach ( $cart_items as $item ) {
		$cart_product_ids[] = (int) $item['product_id'];
		if ( ! empty( $item['variation_id'] ) ) {
			$cart_product_ids[] = (int) $item['variation_id'];
		}

		$related = get_post_meta( $item['product_id'], MICELU_CUP_META_KEY, true );
		if ( is_array( $related ) && ! empty( $related ) ) {
			foreach ( $related as $rid ) {
				$rid = (int) $rid;
				if ( $rid > 0 ) {
					$suggested_ids[] = $rid;
				}
			}
		}
	}

	// Únicos, excluyendo lo que ya está en el carrito.
	$suggested_ids = array_unique( $suggested_ids );
	$suggested_ids = array_diff( $suggested_ids, $cart_product_ids );

	return array_slice( $suggested_ids, 0, MICELU_CUP_MAX_PRODUCTS );
}

/**
 * URL de redirección tras agregar al carrito que respeta el popup de upsells:
 * si el carrito tiene sugerencias, devuelve la URL del producto recién
 * agregado con el flag que abre el popup en esa misma página, ANTES de
 * navegar al carrito (el cliente decide: cerrar → carrito, continuar →
 * checkout). Si no hay sugerencias, devuelve la URL de respaldo (checkout, etc.).
 *
 * @param string          $fallback       URL de destino cuando no hay upsells.
 * @param WC_Product|null $adding_to_cart Producto recién agregado al carrito.
 * @return string|false
 */
function micelu_cup_get_add_to_cart_redirect_url( $fallback, $adding_to_cart = null ) {
	if ( ! micelu_cup_woocommerce_active() ) {
		return $fallback;
	}

	$suggested_ids = micelu_cup_get_cart_suggested_ids();

	if ( ! empty( $suggested_ids ) ) {
		// Quedarse en la página del producto con el flag de auto-apertura.
		// Devolver una URL real (no false) también evita la opción de
		// WooCommerce "Redirigir al carrito tras agregar".
		if ( $adding_to_cart instanceof WC_Product ) {
			return add_query_arg( 'micelu_popup', '1', $adding_to_cart->get_permalink() );
		}

		// Sin referencia al producto: abrir el popup en la página del carrito.
		return add_query_arg( 'micelu_popup', '1', wc_get_cart_url() );
	}

	return $fallback;
}

function micelu_cup_ajax_get_popup_products() {
	check_ajax_referer( 'micelu_cup_nonce', 'nonce' );

	$suggested_ids = micelu_cup_get_cart_suggested_ids();

	if ( empty( $suggested_ids ) ) {
		wp_send_json_success( array( 'has_products' => false, 'html' => '' ) );
	}

	ob_start();
	foreach ( $suggested_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			continue;
		}

		$is_variable = $product->is_type( 'variable' );

		if ( $is_variable ) {
			$available_variations = $product->get_available_variations();
			if ( empty( $available_variations ) ) {
				continue; // No hay combinaciones comprables, se omite.
			}
		} elseif ( ! $product->is_in_stock() ) {
			continue;
		}

		$image = $product->get_image( 'woocommerce_thumbnail' );
		?>
		<div class="micelu-cup-product-card"
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-product-type="<?php echo $is_variable ? 'variable' : 'simple'; ?>"
			data-variation-id="0"
		>
			<div class="micelu-cup-product-image"><?php echo $image; // phpcs:ignore ?></div>
			<div class="micelu-cup-product-name"><?php echo esc_html( $product->get_name() ); ?></div>

			<?php if ( $is_variable ) : ?>
				<form class="variations_form cart micelu-cup-variations-form"
					data-product_id="<?php echo esc_attr( $product->get_id() ); ?>"
					data-product_variations="<?php echo esc_attr( wp_json_encode( $available_variations ) ); ?>"
				>
					<table class="variations" cellspacing="0" role="presentation">
						<tbody>
							<?php
							$attributes = $product->get_variation_attributes();
							foreach ( $attributes as $attribute_name => $options ) :
								?>
								<tr>
									<td class="label"><label><?php echo esc_html( wc_attribute_label( $attribute_name ) ); ?></label></td>
									<td class="value">
										<?php
										wc_dropdown_variation_attribute_options( array(
											'options'   => $options,
											'attribute' => $attribute_name,
											'product'   => $product,
										) );
										?>
									</td>
								</tr>
								<?php
							endforeach;
							?>
						</tbody>
					</table>
					<div class="single_variation_wrap">
						<div class="woocommerce-variation single_variation"></div>
					</div>
				</form>
				<div class="micelu-cup-product-price"><?php echo $product->get_price_html(); // phpcs:ignore ?></div>
				<button type="button" class="micelu-cup-add-btn" disabled>Selecciona opciones</button>
			<?php else : ?>
				<div class="micelu-cup-product-price"><?php echo $product->get_price_html(); // phpcs:ignore ?></div>
				<button type="button" class="micelu-cup-add-btn">Agregar</button>
			<?php endif; ?>
		</div>
		<?php
	}
	$html = ob_get_clean();

	if ( trim( $html ) === '' ) {
		wp_send_json_success( array( 'has_products' => false, 'html' => '' ) );
	}

	wp_send_json_success( array( 'has_products' => true, 'html' => $html ) );
}

/* ==========================================================================
   5. AJAX: AGREGAR PRODUCTO SUGERIDO AL CARRITO
   ========================================================================== */

add_action( 'wp_ajax_micelu_popup_add_to_cart', 'micelu_cup_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_micelu_popup_add_to_cart', 'micelu_cup_ajax_add_to_cart' );

function micelu_cup_ajax_add_to_cart() {
	check_ajax_referer( 'micelu_cup_nonce', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'Carrito no disponible.' ) );
	}

	$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;

	if ( ! $product_id ) {
		wp_send_json_error( array( 'message' => 'Producto inválido.' ) );
	}

	$variation_attrs = array();
	if ( $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( $variation ) {
			$variation_attrs = $variation->get_variation_attributes();
		}
	}

	$added = WC()->cart->add_to_cart( $product_id, 1, $variation_id, $variation_attrs );

	if ( ! $added ) {
		wp_send_json_error( array( 'message' => 'No se pudo agregar el producto al carrito.' ) );
	}

	WC()->cart->calculate_totals();

	// Fragmentos estándar de WooCommerce, para refrescar el mini-carrito.
	$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );

	wp_send_json_success( array(
		'cart_count'    => WC()->cart->get_cart_contents_count(),
		'cart_subtotal' => WC()->cart->get_cart_subtotal(),
		'fragments'     => $fragments,
	) );
}
