<?php
/**
 * Meyvora FAQ Block – server-side render + frontend assets + Elementor widget.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue frontend CSS + JS for FAQ accordion on pages that have FAQ data.
 */
add_action( 'wp_enqueue_scripts', 'meyvora_faq_enqueue_frontend_assets' );
function meyvora_faq_enqueue_frontend_assets(): void {
    // Only enqueue on singular pages/posts that have FAQ data.
    if ( ! is_singular() ) {
        return;
    }
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return;
    }
    $raw = get_post_meta( $post_id, '_meyvora_seo_faq', true );
    if ( ! $raw || $raw === '[]' ) {
        return;
    }
    $pairs = json_decode( $raw, true );
    if ( ! is_array( $pairs ) || empty( $pairs ) ) {
        return;
    }

    $css_path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'assets/css/meyvora-faq.css' : '';
    $js_path  = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'assets/js/meyvora-faq.js' : '';
    $ver      = defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0';
    $url      = defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '';

    if ( $css_path && file_exists( $css_path ) ) {
        wp_enqueue_style( 'meyvora-faq', $url . 'assets/css/meyvora-faq.css', array(), $ver );
    }
    if ( $js_path && file_exists( $js_path ) ) {
        wp_enqueue_script( 'meyvora-faq', $url . 'assets/js/meyvora-faq.js', array(), $ver, true );
    }
}

/**
 * Register the Gutenberg block with server-side render callback.
 * The block stores data in post meta; render_callback reads meta and outputs HTML.
 */
add_action( 'init', 'meyvora_faq_register_block' );
function meyvora_faq_register_block(): void {
    if ( ! function_exists( 'register_block_type' ) ) {
        return;
    }
    $url = defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '';
    $ver = defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0';
    wp_register_style(
        'meyvora-faq-block-editor',
        $url . 'admin/assets/css/meyvora-admin.css',
        array(),
        $ver
    );
    register_block_type( 'meyvora-seo/faq', array(
        'editor_style'   => 'meyvora-faq-block-editor',
        'render_callback' => 'meyvora_faq_render_block',
        'attributes'      => array(
            'pairs'     => array( 'type' => 'string', 'default' => '' ),
            // Sidebar settings saved as block attributes
            'displayMode'   => array( 'type' => 'string', 'default' => 'accordion' ),  // accordion | show-all
            'openFirst'     => array( 'type' => 'boolean', 'default' => true ),
            'allowMultiple' => array( 'type' => 'boolean', 'default' => false ),
            'iconStyle'     => array( 'type' => 'string', 'default' => 'chevron' ),    // chevron | plus
            'showSeparator' => array( 'type' => 'boolean', 'default' => false ),
            'questionSize'  => array( 'type' => 'string', 'default' => '' ),           // custom CSS value e.g. "1.1rem"
            'questionColor' => array( 'type' => 'string', 'default' => '' ),           // hex
            'answerColor'   => array( 'type' => 'string', 'default' => '' ),
            'borderColor'   => array( 'type' => 'string', 'default' => '' ),
            'accentColor'   => array( 'type' => 'string', 'default' => '' ),
            'borderRadius'  => array( 'type' => 'string', 'default' => '' ),           // e.g. "12px"
        ),
    ) );
}

/**
 * Server-side render callback for meyvora-seo/faq block.
 *
 * @param array<string, mixed> $attrs Block attributes.
 * @return string  HTML output.
 */
function meyvora_faq_render_block( array $attrs ): string {
    global $post;
    $post_id = $post ? $post->ID : get_the_ID();

    // Prefer post meta (canonical source), fallback to block attribute.
    $raw = $post_id ? get_post_meta( $post_id, '_meyvora_seo_faq', true ) : '';
    if ( ! $raw || $raw === '[]' ) {
        $raw = isset( $attrs['pairs'] ) ? $attrs['pairs'] : '';
    }
    if ( ! $raw || $raw === '[]' ) {
        return '';
    }

    $pairs = json_decode( $raw, true );
    if ( ! is_array( $pairs ) || empty( $pairs ) ) {
        return '';
    }

    // Filter out incomplete pairs.
    $pairs = array_values( array_filter( $pairs, function ( $p ) {
        $q = isset( $p['question'] ) ? trim( wp_strip_all_tags( (string) $p['question'] ) ) : '';
        $a = isset( $p['answer'] )   ? trim( wp_strip_all_tags( (string) $p['answer'] ) )   : '';
        return $q !== '' && $a !== '';
    } ) );
    if ( empty( $pairs ) ) {
        return '';
    }

    // Attributes with defaults.
    $display_mode   = isset( $attrs['displayMode'] )   ? sanitize_key( $attrs['displayMode'] )   : 'accordion';
    $open_first     = isset( $attrs['openFirst'] )     ? (bool) $attrs['openFirst']               : true;
    $allow_multiple = isset( $attrs['allowMultiple'] ) ? (bool) $attrs['allowMultiple']           : false;
    $icon_style     = isset( $attrs['iconStyle'] )     ? sanitize_key( $attrs['iconStyle'] )     : 'chevron';
    $show_sep       = isset( $attrs['showSeparator'] ) ? (bool) $attrs['showSeparator']           : false;

    // Custom CSS vars.
    $css_vars  = '';
    $color_map = array(
        'questionColor' => '--meyvora-faq-q-color',
        'questionSize'  => '--meyvora-faq-q-size',
        'answerColor'   => '--meyvora-faq-a-color',
        'borderColor'   => '--meyvora-faq-border',
        'accentColor'   => '--meyvora-faq-q-open-color',
        'borderRadius'  => '--meyvora-faq-radius',
    );
    foreach ( $color_map as $attr_key => $var_name ) {
        $val = isset( $attrs[ $attr_key ] ) ? trim( (string) $attrs[ $attr_key ] ) : '';
        if ( $val !== '' ) {
            $css_vars .= esc_attr( $var_name ) . ':' . esc_attr( $val ) . ';';
        }
    }

    // Build CSS classes for the list wrapper.
    $list_classes = array( 'meyvora-faq-list' );
    if ( $display_mode === 'show-all' ) {
        $list_classes[] = 'meyvora-faq-list--show-all';
    }
    if ( $icon_style === 'plus' ) {
        $list_classes[] = 'meyvora-faq-list--icon-plus';
    }
    if ( $show_sep ) {
        $list_classes[] = 'meyvora-faq-list--separator';
    }

    // Data attrs for JS.
    $data_open_first     = $open_first     ? 'true' : 'false';
    $data_allow_multiple = $allow_multiple ? 'true' : 'false';

    ob_start();
    ?>
    <div class="meyvora-faq-wrapper wp-block-meyvora-seo-faq">
        <ol
            class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>"
            style="<?php echo esc_attr( $css_vars ); ?>"
            data-open-first="<?php echo esc_attr( $data_open_first ); ?>"
            data-multiple="<?php echo esc_attr( $data_allow_multiple ); ?>"
            itemscope
            itemtype="https://schema.org/FAQPage"
        >
            <?php foreach ( $pairs as $idx => $pair ) :
                $q = trim( (string) ( $pair['question'] ?? '' ) );
                $a = trim( (string) ( $pair['answer']   ?? '' ) );
                if ( $q === '' || $a === '' ) continue;
                $item_id = 'meyvora-faq-' . esc_attr( (string) ( $post_id ?: 0 ) ) . '-' . $idx;
                $region_id = $item_id . '-panel';
            ?>
            <li
                class="meyvora-faq-item"
                itemscope
                itemprop="mainEntity"
                itemtype="https://schema.org/Question"
            >
                <button
                    class="meyvora-faq-question"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr( $region_id ); ?>"
                    id="<?php echo esc_attr( $item_id ); ?>"
                    itemprop="name"
                >
                    <?php echo wp_kses_post( $q ); ?>
                    <!-- Chevron icon -->
                    <svg class="meyvora-faq-icon meyvora-faq-icon--chevron" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                    <!-- Plus/cross icon -->
                    <svg class="meyvora-faq-icon meyvora-faq-icon--plus" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </button>
                <div
                    class="meyvora-faq-answer"
                    id="<?php echo esc_attr( $region_id ); ?>"
                    role="region"
                    aria-labelledby="<?php echo esc_attr( $item_id ); ?>"
                    itemscope
                    itemprop="acceptedAnswer"
                    itemtype="https://schema.org/Answer"
                >
                    <div class="meyvora-faq-answer-inner" itemprop="text">
                        <?php echo wp_kses_post( $a ); ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php
    return ob_get_clean();
}
