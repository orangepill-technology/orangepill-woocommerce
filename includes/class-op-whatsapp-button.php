<?php
/**
 * WhatsApp click-to-chat buttons for Orangepill WooCommerce stores.
 *
 * Renders two surfaces controlled by plugin settings:
 *  - Product-page button (below Add to Cart) — pre-fills product name.
 *  - Sticky floating button (wp_footer, all pages) — generic help message.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OP_WhatsApp_Button {

    private string $number;
    private bool   $sticky_enabled;

    public function __construct( string $number, bool $sticky_enabled ) {
        $this->number         = $number;
        $this->sticky_enabled = $sticky_enabled;
    }

    public function init(): void {
        add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_button' ) );

        if ( $this->sticky_enabled ) {
            add_action( 'wp_footer', array( $this, 'render_sticky_button' ) );
        }
    }

    public function render_product_button(): void {
        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $message = sprintf(
            'Hola Copifam, quiero consultar sobre: %s',
            $product->get_name()
        );

        $url = $this->build_wa_url( $message );

        echo '<div style="width:100%;margin-top:8px;">'
            . '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"'
            . ' class="button alt copifam-wa-button"'
            . ' style="background-color:#25D366;color:#fff;width:100%;display:flex;'
            . 'align-items:center;gap:8px;justify-content:center;border-color:#25D366;">'
            . $this->whatsapp_icon() // phpcs:ignore WordPress.Security.EscapeOutput
            . ' ' . esc_html__( 'Consultar por WhatsApp', 'orangepill-wc' )
            . '</a></div>';
    }

    public function render_sticky_button(): void {
        $url = $this->build_wa_url( 'Hola Copifam, necesito ayuda' );
        ?>
        <style>
        .op-sticky-wa {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            background: #25D366; color: #fff !important; border-radius: 50px;
            padding: 12px 20px; display: flex; align-items: center; gap: 8px;
            text-decoration: none !important; font-weight: 600; font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,.25); line-height: 1;
        }
        .op-sticky-wa:hover { background: #1ebe57; }
        </style>
        <a href="<?php echo esc_url( $url ); ?>" class="op-sticky-wa"
           target="_blank" rel="noopener noreferrer">
            <?php echo $this->whatsapp_icon( 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php esc_html_e( 'WhatsApp', 'orangepill-wc' ); ?>
        </a>
        <?php
    }

    private function build_wa_url( string $message ): string {
        return 'https://wa.me/' . rawurlencode( $this->number )
            . '?text=' . rawurlencode( $message );
    }

    private function whatsapp_icon( int $size = 20 ): string {
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15'
            . '-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475'
            . '-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52'
            . '.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207'
            . '-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372'
            . '-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2'
            . ' 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719'
            . ' 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
            . '<path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.524 5.855L.057 23.012'
            . 'a.75.75 0 00.931.931l5.157-1.467A11.943 11.943 0 0012 24c6.627 0 12-5.373 12-12'
            . 'S18.627 0 12 0zm0 22c-1.967 0-3.807-.542-5.384-1.484l-.385-.23-3.062.871.872-3.062'
            . '-.23-.385A9.944 9.944 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>'
            . '</svg>',
            $size
        );
    }
}
