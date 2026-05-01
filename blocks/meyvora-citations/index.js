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
    var PanelBody         = wp.components.PanelBody;
    var Button            = wp.components.Button;
    var TextControl       = wp.components.TextControl;
    var Fragment          = wp.element.Fragment;

    registerBlockType('meyvora-seo/citations', {
        apiVersion: 3,
        title: __('Meyvora Citations', 'meyvora-seo'),
        description: __('Add a references list at the end of an article. Each citation is output as a &lt;cite&gt; with a link and contributes to E-E-A-T and ClaimReview schema.', 'meyvora-seo'),
        category: 'text',
        icon: 'book-alt',
        keywords: [__('citations'), __('references'), __('sources'), __('eeat'), __('schema')],
        supports: { html: false, multiple: true },
        attributes: {
            citations: {
                type: 'array',
                default: [],
            },
        },

        edit: function (props) {
            var attrs = props.attributes;
            var setAttrs = props.setAttributes;
            var citations = Array.isArray(attrs.citations) ? attrs.citations.slice() : [];

            function addCitation() {
                setAttrs({ citations: citations.concat([{ title: '', url: '' }]) });
            }
            function removeCitation(idx) {
                setAttrs({ citations: citations.filter(function (_, i) { return i !== idx; }) });
            }
            function updateCitation(idx, field, value) {
                var next = citations.slice();
                next[idx] = Object.assign({}, next[idx] || { title: '', url: '' }, { [field]: value });
                setAttrs({ citations: next });
            }

            var blockProps = useBlockProps({ className: 'meyvora-citations-block' });

            var sidebar = el(InspectorControls, null,
                el(PanelBody, { title: __('Citations', 'meyvora-seo'), initialOpen: true },
                    el('p', { style: { marginBottom: '12px', fontSize: '12px', color: '#50575e' } },
                        __('Each reference is rendered as a numbered list with a &lt;cite&gt; tag and link. Used for E-E-A-T and ClaimReview schema.', 'meyvora-seo')
                    ),
                    el(Button, { isPrimary: true, isSmall: true, onClick: addCitation }, '+ ' + __('Add citation', 'meyvora-seo'))
                )
            );

            var list = citations.length === 0
                ? el('p', { className: 'meyvora-citations-empty' }, __('No citations yet. Add one via the block sidebar or button below.', 'meyvora-seo'))
                : citations.map(function (c, idx) {
                    var title = (c && c.title) || '';
                    var url = (c && c.url) || '';
                    return el('div', { key: idx, className: 'meyvora-citation-row', style: { marginBottom: '12px', padding: '10px', border: '1px solid #e5e7eb', borderRadius: '6px' } }, [
                        el(TextControl, {
                            label: __('Title / Label', 'meyvora-seo'),
                            value: title,
                            onChange: function (v) { updateCitation(idx, 'title', v || ''); },
                            placeholder: __('e.g. Source name or article title', 'meyvora-seo'),
                        }),
                        el(TextControl, {
                            label: __('URL', 'meyvora-seo'),
                            type: 'url',
                            value: url,
                            onChange: function (v) { updateCitation(idx, 'url', v || ''); },
                            placeholder: 'https://…',
                        }),
                        el(Button, {
                            isSmall: true,
                            isDestructive: true,
                            onClick: function () { removeCitation(idx); },
                            'aria-label': __('Remove citation', 'meyvora-seo'),
                            style: { marginTop: '4px' },
                        }, '× ' + __('Remove', 'meyvora-seo')),
                    ]);
                });

            var canvas = el('div', blockProps, [
                el('div', { className: 'meyvora-citations-block-header', style: { marginBottom: '12px', fontWeight: 600, color: '#374151' } },
                    __('References', 'meyvora-seo'),
                    el('span', { style: { marginLeft: '8px', fontSize: '12px', fontWeight: 400, color: '#6b7280' } }, '(' + citations.length + ')'),
                ),
                list,
                el(Button, { isSecondary: true, isSmall: true, onClick: addCitation, style: { marginTop: '12px' } }, '+ ' + __('Add citation', 'meyvora-seo')),
            ]);

            return el(Fragment, null, sidebar, canvas);
        },

        save: function () { return null; },
    });
})();
