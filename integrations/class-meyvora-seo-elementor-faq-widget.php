<?php
/**
 * Elementor widget: Meyvora FAQ
 *
 * Renders the same accordion as the Gutenberg block.
 * Requires Elementor >= 3.0. Loaded only when Elementor is active.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Elementor\Widget_Base' ) ) {
    return;
}

class Meyvora_SEO_Elementor_FAQ_Widget extends \Elementor\Widget_Base {

    public function get_name(): string {
        return 'meyvora-faq';
    }

    public function get_title(): string {
        return esc_html__( 'Meyvora FAQ', 'meyvora-seo' );
    }

    public function get_icon(): string {
        return 'eicon-help-o';
    }

    public function get_categories(): array {
        return array( 'general' );
    }

    public function get_keywords(): array {
        return array( 'faq', 'accordion', 'questions', 'schema', 'seo', 'meyvora' );
    }

    /**
     * Enqueue frontend scripts/styles for the widget.
     */
    public function get_script_depends(): array {
        return array( 'meyvora-faq' );
    }

    public function get_style_depends(): array {
        return array( 'meyvora-faq' );
    }

    /**
     * Register controls (widget settings panel in Elementor).
     */
    protected function register_controls(): void {

        /* ---- Content: FAQ Items ---- */
        $this->start_controls_section( 'section_faq', array(
            'label' => esc_html__( 'FAQ Items', 'meyvora-seo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ) );

        $repeater = new \Elementor\Repeater();

        $repeater->add_control( 'question', array(
            'label'       => esc_html__( 'Question', 'meyvora-seo' ),
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 3,
            'default'     => esc_html__( 'What is your question?', 'meyvora-seo' ),
            'placeholder' => esc_html__( 'Enter your question here', 'meyvora-seo' ),
        ) );

        $repeater->add_control( 'answer', array(
            'label'       => esc_html__( 'Answer', 'meyvora-seo' ),
            'type'        => \Elementor\Controls_Manager::WYSIWYG,
            'default'     => esc_html__( 'Your answer goes here.', 'meyvora-seo' ),
            'placeholder' => esc_html__( 'Enter the answer here', 'meyvora-seo' ),
        ) );

        $this->add_control( 'faq_items', array(
            'label'       => esc_html__( 'FAQ Items', 'meyvora-seo' ),
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'default'     => array(
                array(
                    'question' => esc_html__( 'What services do you offer?', 'meyvora-seo' ),
                    'answer'   => esc_html__( 'We offer a wide range of services.', 'meyvora-seo' ),
                ),
                array(
                    'question' => esc_html__( 'How can I contact you?', 'meyvora-seo' ),
                    'answer'   => esc_html__( 'You can contact us via email or phone.', 'meyvora-seo' ),
                ),
            ),
            'title_field' => '{{{ question }}}',
        ) );

        $this->end_controls_section();

        /* ---- Content: Behaviour ---- */
        $this->start_controls_section( 'section_behaviour', array(
            'label' => esc_html__( 'Behaviour', 'meyvora-seo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ) );

        $this->add_control( 'display_mode', array(
            'label'   => esc_html__( 'Display mode', 'meyvora-seo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'accordion',
            'options' => array(
                'accordion' => esc_html__( 'Accordion (default)', 'meyvora-seo' ),
                'show-all'  => esc_html__( 'Show all open',       'meyvora-seo' ),
            ),
        ) );

        $this->add_control( 'open_first', array(
            'label'        => esc_html__( 'Open first item by default', 'meyvora-seo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Yes', 'meyvora-seo' ),
            'label_off'    => esc_html__( 'No',  'meyvora-seo' ),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => array( 'display_mode' => 'accordion' ),
        ) );

        $this->add_control( 'allow_multiple', array(
            'label'        => esc_html__( 'Allow multiple items open', 'meyvora-seo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Yes', 'meyvora-seo' ),
            'label_off'    => esc_html__( 'No',  'meyvora-seo' ),
            'return_value' => 'yes',
            'default'      => '',
            'condition'    => array( 'display_mode' => 'accordion' ),
        ) );

        $this->add_control( 'icon_style', array(
            'label'     => esc_html__( 'Icon style', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => 'chevron',
            'options'   => array(
                'chevron' => esc_html__( 'Chevron ▼', 'meyvora-seo' ),
                'plus'    => esc_html__( 'Plus/Cross ＋', 'meyvora-seo' ),
            ),
            'condition' => array( 'display_mode' => 'accordion' ),
        ) );

        $this->add_control( 'show_separator', array(
            'label'        => esc_html__( 'Separator between Q and A', 'meyvora-seo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Yes', 'meyvora-seo' ),
            'label_off'    => esc_html__( 'No',  'meyvora-seo' ),
            'return_value' => 'yes',
            'default'      => '',
        ) );

        $this->add_control( 'inject_schema', array(
            'label'        => esc_html__( 'Inject FAQPage schema (JSON-LD)', 'meyvora-seo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__( 'Yes', 'meyvora-seo' ),
            'label_off'    => esc_html__( 'No',  'meyvora-seo' ),
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => esc_html__( 'Outputs JSON-LD FAQPage schema inline for rich snippets. Disable if the main Meyvora SEO schema module already handles this page.', 'meyvora-seo' ),
        ) );

        $this->end_controls_section();

        /* ---- Style: Question ---- */
        $this->start_controls_section( 'section_style_question', array(
            'label' => esc_html__( 'Question', 'meyvora-seo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ) );

        $this->add_control( 'question_color', array(
            'label'     => esc_html__( 'Text color', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-question' => 'color: {{VALUE}};',
            ),
        ) );

        $this->add_control( 'question_bg', array(
            'label'     => esc_html__( 'Background', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-question' => 'background: {{VALUE}};',
            ),
        ) );

        $this->add_control( 'question_open_color', array(
            'label'     => esc_html__( 'Open-state text color', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-question[aria-expanded="true"]' => 'color: {{VALUE}};',
            ),
        ) );

        $this->add_control( 'question_open_bg', array(
            'label'     => esc_html__( 'Open-state background', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-question[aria-expanded="true"]' => 'background: {{VALUE}};',
            ),
        ) );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
            'name'     => 'question_typography',
            'selector' => '{{WRAPPER}} .meyvora-faq-question',
        ) );

        $this->add_responsive_control( 'question_padding', array(
            'label'      => esc_html__( 'Padding', 'meyvora-seo' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array( 'px', 'em', 'rem' ),
            'selectors'  => array(
                '{{WRAPPER}} .meyvora-faq-question' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ) );

        $this->end_controls_section();

        /* ---- Style: Answer ---- */
        $this->start_controls_section( 'section_style_answer', array(
            'label' => esc_html__( 'Answer', 'meyvora-seo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ) );

        $this->add_control( 'answer_color', array(
            'label'     => esc_html__( 'Text color', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-answer-inner' => 'color: {{VALUE}};',
            ),
        ) );

        $this->add_control( 'answer_bg', array(
            'label'     => esc_html__( 'Background', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-answer-inner' => 'background: {{VALUE}};',
            ),
        ) );

        $this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
            'name'     => 'answer_typography',
            'selector' => '{{WRAPPER}} .meyvora-faq-answer-inner',
        ) );

        $this->add_responsive_control( 'answer_padding', array(
            'label'      => esc_html__( 'Padding', 'meyvora-seo' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array( 'px', 'em', 'rem' ),
            'selectors'  => array(
                '{{WRAPPER}} .meyvora-faq-answer-inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ) );

        $this->end_controls_section();

        /* ---- Style: Container ---- */
        $this->start_controls_section( 'section_style_container', array(
            'label' => esc_html__( 'Container', 'meyvora-seo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ) );

        $this->add_control( 'border_color', array(
            'label'     => esc_html__( 'Border color', 'meyvora-seo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .meyvora-faq-list' => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .meyvora-faq-item' => 'border-color: {{VALUE}};',
            ),
        ) );

        $this->add_responsive_control( 'border_radius', array(
            'label'      => esc_html__( 'Border radius', 'meyvora-seo' ),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => array( 'px', 'em', '%' ),
            'selectors'  => array(
                '{{WRAPPER}} .meyvora-faq-list' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ),
        ) );

        $this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
            'name'     => 'container_shadow',
            'selector' => '{{WRAPPER}} .meyvora-faq-list',
        ) );

        $this->end_controls_section();
    }

    /**
     * Render the widget on the frontend.
     */
    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $items = isset( $settings['faq_items'] ) && is_array( $settings['faq_items'] ) ? $settings['faq_items'] : array();
        if ( empty( $items ) ) {
            return;
        }

        // Filter empty.
        $items = array_values( array_filter( $items, function ( $item ) {
            $q = isset( $item['question'] ) ? trim( wp_strip_all_tags( (string) $item['question'] ) ) : '';
            $a = isset( $item['answer'] )   ? trim( wp_strip_all_tags( (string) $item['answer'] ) )   : '';
            return $q !== '' && $a !== '';
        } ) );
        if ( empty( $items ) ) {
            return;
        }

        $display_mode   = isset( $settings['display_mode'] )   ? sanitize_key( $settings['display_mode'] )   : 'accordion';
        $open_first     = isset( $settings['open_first'] )     && $settings['open_first'] === 'yes';
        $allow_multiple = isset( $settings['allow_multiple'] ) && $settings['allow_multiple'] === 'yes';
        $icon_style     = isset( $settings['icon_style'] )     ? sanitize_key( $settings['icon_style'] )     : 'chevron';
        $show_sep       = isset( $settings['show_separator'] ) && $settings['show_separator'] === 'yes';
        $inject_schema  = isset( $settings['inject_schema'] )  && $settings['inject_schema'] === 'yes';

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

        $widget_id = $this->get_id();

        // Inline JSON-LD FAQPage schema (only if enabled and not already output by schema module).
        if ( $inject_schema ) {
            $main_entity = array();
            foreach ( $items as $item ) {
                $q = sanitize_text_field( trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) ) );
                $a = sanitize_text_field( trim( wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) ) );
                if ( $q === '' || $a === '' ) continue;
                $main_entity[] = array(
                    '@type'          => 'Question',
                    'name'           => $q,
                    'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ),
                );
            }
            if ( ! empty( $main_entity ) ) {
                $schema = array(
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $main_entity,
                );
				$ld = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( is_string( $ld ) && function_exists( 'meyvora_seo_print_ld_json_script' ) ) {
					meyvora_seo_print_ld_json_script( $ld );
				}
            }
        }
        ?>
        <div class="meyvora-faq-wrapper">
            <ol
                class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>"
                data-open-first="<?php echo esc_attr( $open_first ? 'true' : 'false' ); ?>"
                data-multiple="<?php echo esc_attr( $allow_multiple ? 'true' : 'false' ); ?>"
                itemscope
                itemtype="https://schema.org/FAQPage"
            >
                <?php foreach ( $items as $idx => $item ) :
                    $q = trim( (string) ( $item['question'] ?? '' ) );
                    $a = trim( (string) ( $item['answer'] ?? '' ) );
                    if ( $q === '' || $a === '' ) continue;
                    $item_id  = 'meyvora-faq-el-' . esc_attr( $widget_id ) . '-' . $idx;
                    $panel_id = $item_id . '-panel';
                ?>
                <li
                    class="meyvora-faq-item"
                    itemscope itemprop="mainEntity"
                    itemtype="https://schema.org/Question"
                >
                    <button
                        class="meyvora-faq-question"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $panel_id ); ?>"
                        id="<?php echo esc_attr( $item_id ); ?>"
                        itemprop="name"
                    >
                        <?php echo wp_kses_post( $q ); ?>
                        <svg class="meyvora-faq-icon meyvora-faq-icon--chevron" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                        <svg class="meyvora-faq-icon meyvora-faq-icon--plus" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </button>
                    <div
                        class="meyvora-faq-answer"
                        id="<?php echo esc_attr( $panel_id ); ?>"
                        role="region"
                        aria-labelledby="<?php echo esc_attr( $item_id ); ?>"
                        itemscope itemprop="acceptedAnswer"
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
    }

    /**
     * Render plain content for screen readers / Elementor's plain-text export.
     */
    public function render_plain_content(): void {
        $settings = $this->get_settings_for_display();
        $items    = isset( $settings['faq_items'] ) && is_array( $settings['faq_items'] ) ? $settings['faq_items'] : array();
        foreach ( $items as $item ) {
            $q = isset( $item['question'] ) ? wp_strip_all_tags( (string) $item['question'] ) : '';
            $a = isset( $item['answer'] )   ? wp_strip_all_tags( (string) $item['answer'] )   : '';
            if ( $q !== '' ) echo esc_html( $q ) . "\n";
            if ( $a !== '' ) echo esc_html( $a ) . "\n\n";
        }
    }
}
