/*
 * Meyvora SEO – plugin asset.
 * Canonical source repository: https://github.com/KalkiAutomation/meyvora-seo
 *
 * This file ships with the WordPress.org plugin package as readable source (not an opaque compiled bundle).
 * For the latest version and contribution workflow, clone or browse that repository.
 */

(function () {
    'use strict';

    var registerBlockType  = wp.blocks.registerBlockType;
    var el                 = wp.element.createElement;
    var __                 = wp.i18n.__;
    var useBlockProps      = wp.blockEditor.useBlockProps;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var useSelect          = wp.data.useSelect;
    var useDispatch        = wp.data.useDispatch;
    var RichText           = wp.blockEditor.RichText;
    var Button             = wp.components.Button;
    var PanelBody          = wp.components.PanelBody;
    var SelectControl      = wp.components.SelectControl;
    var ToggleControl      = wp.components.ToggleControl;
    var TextControl        = wp.components.TextControl;
    var ColorPalette       = wp.components.ColorPalette;
    var Fragment           = wp.element.Fragment;

    /* ------------------------------------------------------------------ */
    /* Tiny preview of how the FAQ will look in the editor (read-only)     */
    /* ------------------------------------------------------------------ */
    function FaqPreviewItem(props) {
        var pair       = props.pair;
        var idx        = props.idx;
        var iconStyle  = props.iconStyle || 'chevron';
        var q = pair && typeof pair.question === 'string' ? pair.question : '';
        var a = pair && typeof pair.answer   === 'string' ? pair.answer   : '';

        var icon = iconStyle === 'plus'
            ? el('span', { style: { fontSize: '18px', lineHeight: 1 } }, '＋')
            : el('span', { style: { fontSize: '14px', lineHeight: 1 } }, '▼');

        return el('div', {
            style: {
                marginBottom: '4px',
                border: '1px solid #e5e7eb',
                borderRadius: '6px',
                overflow: 'hidden',
                fontFamily: 'inherit',
            }
        },
            el('div', {
                style: {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '10px 14px',
                    background: '#f5f3ff',
                    fontWeight: 600,
                    fontSize: '0.95rem',
                    color: '#6d28d9',
                }
            },
                el('span', null, q || __('(empty question)', 'meyvora-seo')),
                icon
            ),
            el('div', {
                style: { padding: '8px 14px 12px', fontSize: '0.9rem', color: '#374151', background: '#fff' }
            },
                a
                    ? el(RichText.Content, { tagName: 'span', value: a })
                    : el('em', { style: { color: '#9ca3af' } }, __('(empty answer)', 'meyvora-seo'))
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Block definition                                                     */
    /* ------------------------------------------------------------------ */
    registerBlockType('meyvora-seo/faq', {
        apiVersion: 3,
        title: __('Meyvora FAQ', 'meyvora-seo'),
        description: __('Add FAQ pairs that render as an accordion on the frontend and generate FAQPage structured data for rich results.', 'meyvora-seo'),
        category: 'text',
        icon: 'editor-help',
        keywords: [__('faq'), __('questions'), __('schema'), __('seo'), __('accordion')],
        supports: { html: false, multiple: false },
        attributes: {
            pairs:          { type: 'string',  default: '' },
            displayMode:    { type: 'string',  default: 'accordion' },
            openFirst:      { type: 'boolean', default: true },
            allowMultiple:  { type: 'boolean', default: false },
            iconStyle:      { type: 'string',  default: 'chevron' },
            showSeparator:  { type: 'boolean', default: false },
            questionSize:   { type: 'string',  default: '' },
            questionColor:  { type: 'string',  default: '' },
            answerColor:    { type: 'string',  default: '' },
            borderColor:    { type: 'string',  default: '' },
            accentColor:    { type: 'string',  default: '' },
            borderRadius:   { type: 'string',  default: '' },
        },

        edit: function (props) {
            var attrs       = props.attributes;
            var setAttrs    = props.setAttributes;

            // Parse pairs from JSON string stored as attribute / meta.
            var pairs = [];
            if (typeof attrs.pairs === 'string' && attrs.pairs !== '') {
                try { pairs = JSON.parse(attrs.pairs); } catch (e) { pairs = []; }
            }
            if (!Array.isArray(pairs)) pairs = [];

            // Keep meta in sync (also write to post meta so schema module can read it).
            var meta     = useSelect(function (select) {
                return select('core/editor').getEditedPostAttribute('meta') || {};
            }, []);
            var editPost = useDispatch('core/editor').editPost;

            function savePairs(newPairs) {
                var json = JSON.stringify(newPairs);
                setAttrs({ pairs: json });
                var merged = Object.assign({}, meta, { _meyvora_seo_faq: json });
                editPost({ meta: merged });
            }

            function addPair()          { savePairs(pairs.concat([{ question: '', answer: '' }])); }
            function removePair(idx)    { savePairs(pairs.filter(function (_, i) { return i !== idx; })); }
            function updatePair(idx, field, val) {
                var next = pairs.slice();
                next[idx] = Object.assign({}, next[idx] || { question: '', answer: '' }, { [field]: val });
                savePairs(next);
            }

            var blockProps = useBlockProps({ className: 'meyvora-faq-block' });

            /* ---------- Sidebar: InspectorControls ---------- */
            var sidebar = el(InspectorControls, null,

                /* --- Display --- */
                el(PanelBody, { title: __('Display Settings', 'meyvora-seo'), initialOpen: true },
                    el(SelectControl, {
                        label: __('Display mode', 'meyvora-seo'),
                        value: attrs.displayMode,
                        options: [
                            { label: __('Accordion (default)', 'meyvora-seo'), value: 'accordion' },
                            { label: __('Show all open',       'meyvora-seo'), value: 'show-all'  },
                        ],
                        onChange: function (v) { setAttrs({ displayMode: v }); },
                        help: __('Accordion collapses items; "Show all" keeps every answer visible.', 'meyvora-seo'),
                    }),
                    attrs.displayMode === 'accordion'
                        ? el(Fragment, null,
                            el(ToggleControl, {
                                label:    __('Open first item by default', 'meyvora-seo'),
                                checked:  attrs.openFirst,
                                onChange: function (v) { setAttrs({ openFirst: v }); },
                            }),
                            el(ToggleControl, {
                                label:    __('Allow multiple items open', 'meyvora-seo'),
                                checked:  attrs.allowMultiple,
                                onChange: function (v) { setAttrs({ allowMultiple: v }); },
                            }),
                            el(SelectControl, {
                                label: __('Icon style', 'meyvora-seo'),
                                value: attrs.iconStyle,
                                options: [
                                    { label: __('Chevron ▼', 'meyvora-seo'), value: 'chevron' },
                                    { label: __('Plus/Cross ＋', 'meyvora-seo'), value: 'plus' },
                                ],
                                onChange: function (v) { setAttrs({ iconStyle: v }); },
                            }),
                            el(ToggleControl, {
                                label:    __('Show separator between Q and A', 'meyvora-seo'),
                                checked:  attrs.showSeparator,
                                onChange: function (v) { setAttrs({ showSeparator: v }); },
                            })
                          )
                        : null
                ),

                /* --- Typography & Colors --- */
                el(PanelBody, { title: __('Typography & Colors', 'meyvora-seo'), initialOpen: false },
                    el(TextControl, {
                        label:       __('Question font size', 'meyvora-seo'),
                        value:       attrs.questionSize,
                        placeholder: __('e.g. 1.1rem or 18px', 'meyvora-seo'),
                        onChange:    function (v) { setAttrs({ questionSize: v }); },
                        help:        __('Overrides the default question size. Leave blank for theme default.', 'meyvora-seo'),
                    }),
                    el('div', { style: { marginBottom: '16px' } },
                        el('label', { style: { display: 'block', marginBottom: '8px', fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', color: '#1e1e1e' } },
                            __('Question color', 'meyvora-seo')
                        ),
                        el(ColorPalette, {
                            value:    attrs.questionColor,
                            onChange: function (v) { setAttrs({ questionColor: v || '' }); },
                        })
                    ),
                    el('div', { style: { marginBottom: '16px' } },
                        el('label', { style: { display: 'block', marginBottom: '8px', fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', color: '#1e1e1e' } },
                            __('Accent / open-state color', 'meyvora-seo')
                        ),
                        el(ColorPalette, {
                            value:    attrs.accentColor,
                            onChange: function (v) { setAttrs({ accentColor: v || '' }); },
                        })
                    ),
                    el('div', { style: { marginBottom: '16px' } },
                        el('label', { style: { display: 'block', marginBottom: '8px', fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', color: '#1e1e1e' } },
                            __('Answer color', 'meyvora-seo')
                        ),
                        el(ColorPalette, {
                            value:    attrs.answerColor,
                            onChange: function (v) { setAttrs({ answerColor: v || '' }); },
                        })
                    ),
                    el('div', { style: { marginBottom: '16px' } },
                        el('label', { style: { display: 'block', marginBottom: '8px', fontWeight: 500, fontSize: '11px', textTransform: 'uppercase', color: '#1e1e1e' } },
                            __('Border color', 'meyvora-seo')
                        ),
                        el(ColorPalette, {
                            value:    attrs.borderColor,
                            onChange: function (v) { setAttrs({ borderColor: v || '' }); },
                        })
                    ),
                    el(TextControl, {
                        label:       __('Border radius', 'meyvora-seo'),
                        value:       attrs.borderRadius,
                        placeholder: __('e.g. 8px or 0', 'meyvora-seo'),
                        onChange:    function (v) { setAttrs({ borderRadius: v }); },
                    })
                )
            );

            /* ---------- Block editor canvas ---------- */
            var canvas = el('div', blockProps,
                /* Header */
                el('div', { className: 'meyvora-faq-block-header' },
                    el('span', { className: 'meyvora-faq-block-label' },
                        el('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: '#7c3aed', strokeWidth: 2 },
                            el('circle', { cx: 12, cy: 12, r: 10 }),
                            el('path', { d: 'M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3' }),
                            el('line', { x1: 12, y1: 17, x2: 12.01, y2: 17 })
                        ),
                        __('FAQ Block', 'meyvora-seo'),
                        el('span', { className: 'meyvora-faq-block-badge' },
                            pairs.length + ' Q&A' + (pairs.length !== 1 ? 's' : '')
                        ),
                        el('span', {
                            style: {
                                marginLeft: '8px',
                                fontSize: '11px',
                                fontWeight: 400,
                                color: '#6b7280',
                                background: attrs.displayMode === 'show-all' ? '#d1fae5' : '#ede9fe',
                                borderRadius: '4px',
                                padding: '1px 6px',
                            }
                        }, attrs.displayMode === 'show-all' ? __('Show all', 'meyvora-seo') : __('Accordion', 'meyvora-seo'))
                    )
                ),

                /* Q&A list (edit mode with RichText) */
                pairs.length === 0
                    ? el('p', { className: 'meyvora-faq-block-empty' }, __('No questions yet. Click "Add Question" below.', 'meyvora-seo'))
                    : el('div', { className: 'meyvora-faq-pairs' },
                        pairs.map(function (pair, idx) {
                            var q = pair && typeof pair.question === 'string' ? pair.question : '';
                            var a = pair && typeof pair.answer   === 'string' ? pair.answer   : '';
                            return el('div', { key: idx, className: 'meyvora-faq-pair' },
                                el('div', { className: 'meyvora-faq-pair-num' }, idx + 1),
                                el('div', { className: 'meyvora-faq-pair-fields' },
                                    el(RichText, {
                                        tagName: 'p',
                                        className: 'meyvora-faq-q',
                                        value: q,
                                        onChange: function (v) { updatePair(idx, 'question', v); },
                                        placeholder: __('Question…', 'meyvora-seo'),
                                        keepPlaceholderOnFocus: true,
                                    }),
                                    el(RichText, {
                                        tagName: 'p',
                                        className: 'meyvora-faq-a',
                                        value: a,
                                        onChange: function (v) { updatePair(idx, 'answer', v); },
                                        placeholder: __('Answer…', 'meyvora-seo'),
                                        keepPlaceholderOnFocus: true,
                                    })
                                ),
                                el(Button, {
                                    className: 'meyvora-faq-remove',
                                    isSmall: true,
                                    isDestructive: true,
                                    onClick: function () { removePair(idx); },
                                    'aria-label': __('Remove this Q&A', 'meyvora-seo'),
                                }, '×')
                            );
                        })
                    ),

                /* Footer */
                el('div', { className: 'meyvora-faq-block-footer' },
                    el(Button, {
                        isPrimary: true,
                        isSmall: true,
                        onClick: addPair,
                    }, '+ ' + __('Add Question', 'meyvora-seo'))
                )
            );

            return el(Fragment, null, sidebar, canvas);
        },

        /* save: null — server-side render_callback handles frontend output */
        save: function () { return null; },
    });
})();
