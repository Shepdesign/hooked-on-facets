/**
 * hof/facet — editor binding.
 *
 * Vanilla wp.element calls (no JSX, no bundler) so this file ships as-is
 * via block.json's editorScript hint. Editor experience: a slug field in
 * the sidebar that drives a ServerSideRender preview.
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks ) {
        return;
    }

    var el                = wp.element.createElement;
    var __                = wp.i18n.__;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps     = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var TextControl       = wp.components.TextControl;
    var PanelBody         = wp.components.PanelBody;
    var ServerSideRender  = wp.serverSideRender;

    registerBlockType( 'hof/facet', {
        edit: function ( props ) {
            var attributes    = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps    = useBlockProps();

            var inspector = el(
                InspectorControls,
                null,
                el(
                    PanelBody,
                    { title: __( 'Facet', 'hooked-on-facets' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Facet slug', 'hooked-on-facets' ),
                        help: __( 'The "Slug" of a facet you created in the HOF admin.', 'hooked-on-facets' ),
                        value: attributes.facetName || '',
                        onChange: function ( value ) {
                            setAttributes( { facetName: ( value || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' ) } );
                        },
                    } )
                )
            );

            var body = attributes.facetName
                ? el( ServerSideRender, {
                    block: 'hof/facet',
                    attributes: attributes,
                    EmptyResponsePlaceholder: function () {
                        return el( 'p', null, __( 'No matching facet — check the slug.', 'hooked-on-facets' ) );
                    },
                } )
                : el( 'p', { style: { padding: '12px', border: '1px dashed #c3c4c7', color: '#646970' } },
                    __( 'Set a facet slug in the sidebar to preview.', 'hooked-on-facets' )
                );

            return el( 'div', blockProps, inspector, body );
        },
        save: function () {
            return null; // Dynamic block — rendered server-side.
        },
    } );
} )( window.wp );
