<?php
/**
 * SwatchTermFields — adds image + color fields to term-edit screens
 * for taxonomies that back a `swatch` facet.
 *
 * Term meta keys:
 *   _hof_swatch_image  → attachment ID (int)
 *   _hof_swatch_color  → CSS color string (#RRGGBB)
 *
 * Hooks are wired only for taxonomies named as the `source` of a
 * `display=swatch` facet, so an unrelated taxonomy doesn't get an
 * extra field cluttering its edit screen.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Admin;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Indexer;

defined( 'ABSPATH' ) || exit;

final class SwatchTermFields implements Bootable {

    public const META_IMAGE = '_hof_swatch_image';
    public const META_COLOR = '_hof_swatch_color';

    public function register_hooks(): void {
        // Hook the field renderers + savers for each taxonomy used by a swatch facet.
        foreach ( $this->swatch_taxonomies() as $taxonomy ) {
            add_action( "{$taxonomy}_add_form_fields",  [ $this, 'render_add_form' ] );
            add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_edit_form' ], 10, 2 );
            add_action( "created_{$taxonomy}",          [ $this, 'save_term_meta' ] );
            add_action( "edited_{$taxonomy}",           [ $this, 'save_term_meta' ] );
        }

        // Enqueue the WP media library on term edit screens (so the image picker works).
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_media' ] );
    }

    // ── Field renderers ─────────────────────────────────────────────────────

    public function render_add_form( string $taxonomy ): void {
        wp_nonce_field( 'hof_swatch_term_meta', 'hof_swatch_nonce' );
        ?>
        <div class="form-field hof-swatch-field">
            <label for="hof-swatch-color"><?php esc_html_e( 'Swatch color', 'hooked-on-facets' ); ?></label>
            <input type="text" id="hof-swatch-color" name="hof_swatch_color" value="" placeholder="#5b6cff" />
            <p><?php esc_html_e( 'Optional. CSS color used when no swatch image is set.', 'hooked-on-facets' ); ?></p>
        </div>
        <div class="form-field hof-swatch-field">
            <label><?php esc_html_e( 'Swatch image', 'hooked-on-facets' ); ?></label>
            <?php $this->render_image_picker( 0 ); ?>
        </div>
        <?php
    }

    public function render_edit_form( \WP_Term $tag, string $taxonomy ): void {
        $color    = (string) get_term_meta( $tag->term_id, self::META_COLOR, true );
        $image_id = (int) get_term_meta( $tag->term_id, self::META_IMAGE, true );
        wp_nonce_field( 'hof_swatch_term_meta', 'hof_swatch_nonce' );
        ?>
        <tr class="form-field hof-swatch-field">
            <th scope="row">
                <label for="hof-swatch-color"><?php esc_html_e( 'Swatch color', 'hooked-on-facets' ); ?></label>
            </th>
            <td>
                <input type="text" id="hof-swatch-color" name="hof_swatch_color"
                       value="<?php echo esc_attr( $color ); ?>" placeholder="#5b6cff" />
                <p class="description">
                    <?php esc_html_e( 'Optional. CSS color used when no swatch image is set.', 'hooked-on-facets' ); ?>
                </p>
            </td>
        </tr>
        <tr class="form-field hof-swatch-field">
            <th scope="row"><?php esc_html_e( 'Swatch image', 'hooked-on-facets' ); ?></th>
            <td><?php $this->render_image_picker( $image_id ); ?></td>
        </tr>
        <?php
    }

    private function render_image_picker( int $image_id ): void {
        $thumb_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
        ?>
        <div class="hof-swatch-image-picker" data-hof-swatch-picker>
            <input type="hidden" name="hof_swatch_image" value="<?php echo esc_attr( (string) $image_id ); ?>"
                   data-hof-swatch-input />
            <div class="hof-swatch-image-preview" data-hof-swatch-preview>
                <?php if ( $thumb_url ) : ?>
                    <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
                <?php endif; ?>
            </div>
            <button type="button" class="button" data-hof-swatch-choose>
                <?php esc_html_e( 'Choose image', 'hooked-on-facets' ); ?>
            </button>
            <button type="button" class="button-link-delete" data-hof-swatch-remove
                <?php echo $image_id > 0 ? '' : 'hidden'; ?>>
                <?php esc_html_e( 'Remove', 'hooked-on-facets' ); ?>
            </button>
        </div>
        <?php
    }

    // ── Save ────────────────────────────────────────────────────────────────

    public function save_term_meta( int $term_id ): void {
        if ( ! isset( $_POST['hof_swatch_nonce'] )
            || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['hof_swatch_nonce'] ) ), 'hof_swatch_term_meta' ) ) {
            return;
        }
        $term = get_term( $term_id );
        if ( ! $term instanceof \WP_Term ) {
            return;
        }
        $tax_object = get_taxonomy( $term->taxonomy );
        if ( ! $tax_object || ! current_user_can( $tax_object->cap->edit_terms ) ) {
            return;
        }

        if ( array_key_exists( 'hof_swatch_color', $_POST ) ) {
            $raw   = (string) wp_unslash( $_POST['hof_swatch_color'] );
            $color = $this->sanitize_color( $raw );
            if ( $color === '' ) {
                delete_term_meta( $term_id, self::META_COLOR );
            } else {
                update_term_meta( $term_id, self::META_COLOR, $color );
            }
        }

        if ( array_key_exists( 'hof_swatch_image', $_POST ) ) {
            $image_id = (int) $_POST['hof_swatch_image'];
            if ( $image_id > 0 && get_post_type( $image_id ) === 'attachment' ) {
                update_term_meta( $term_id, self::META_IMAGE, $image_id );
            } else {
                delete_term_meta( $term_id, self::META_IMAGE );
            }
        }
    }

    // ── Asset enqueue ───────────────────────────────────────────────────────

    public function maybe_enqueue_media( string $hook ): void {
        // Both the term list ('edit-tags.php') and edit-term ('term.php') screens
        // host the form. Limit by taxonomy too so we don't load media JS on
        // unrelated taxonomies.
        if ( $hook !== 'edit-tags.php' && $hook !== 'term.php' ) {
            return;
        }
        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        if ( $taxonomy === '' || ! in_array( $taxonomy, $this->swatch_taxonomies(), true ) ) {
            return;
        }

        wp_enqueue_media();
        add_action( 'admin_footer', [ $this, 'print_picker_script' ] );
    }

    /**
     * Inline picker script — small enough to not warrant a Vite entry.
     */
    public function print_picker_script(): void {
        ?>
        <script>
        (function () {
            if (!window.wp || !wp.media) return;
            var frame = null;

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-hof-swatch-choose]');
                if (btn) {
                    e.preventDefault();
                    var picker = btn.closest('[data-hof-swatch-picker]');
                    frame = wp.media({
                        title:  '<?php echo esc_js( __( 'Choose swatch image', 'hooked-on-facets' ) ); ?>',
                        button: { text: '<?php echo esc_js( __( 'Use this image', 'hooked-on-facets' ) ); ?>' },
                        library:  { type: 'image' },
                        multiple: false,
                    });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        picker.querySelector('[data-hof-swatch-input]').value = att.id;
                        var preview = picker.querySelector('[data-hof-swatch-preview]');
                        preview.innerHTML = '';
                        var url = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) || att.url;
                        var img = document.createElement('img');
                        img.src = url;
                        preview.appendChild(img);
                        picker.querySelector('[data-hof-swatch-remove]').hidden = false;
                    });
                    frame.open();
                    return;
                }

                var rm = e.target.closest('[data-hof-swatch-remove]');
                if (rm) {
                    e.preventDefault();
                    var p = rm.closest('[data-hof-swatch-picker]');
                    p.querySelector('[data-hof-swatch-input]').value = '0';
                    p.querySelector('[data-hof-swatch-preview]').innerHTML = '';
                    rm.hidden = true;
                }
            });
        })();
        </script>
        <style>
        .hof-swatch-image-picker { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hof-swatch-image-preview { width: 48px; height: 48px; background: #f0f0f1; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .hof-swatch-image-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
        </style>
        <?php
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Distinct taxonomy names used as the source of any swatch facet.
     *
     * @return array<int, string>
     */
    private function swatch_taxonomies(): array {
        $taxes  = [];
        $facets = (array) get_option( Indexer::OPTION_FACETS, [] );
        foreach ( $facets as $facet ) {
            if ( ! is_array( $facet ) ) {
                continue;
            }
            if ( ( $facet['display'] ?? '' ) !== 'swatch' ) {
                continue;
            }
            if ( ( $facet['kind'] ?? '' ) !== 'taxonomy' ) {
                continue;
            }
            $source = (string) ( $facet['source'] ?? '' );
            if ( $source !== '' && taxonomy_exists( $source ) ) {
                $taxes[ $source ] = true;
            }
        }
        return array_keys( $taxes );
    }

    /**
     * Accept #RGB, #RRGGBB, or named CSS colors. Reject anything else.
     */
    private function sanitize_color( string $raw ): string {
        $val = trim( $raw );
        if ( $val === '' ) {
            return '';
        }
        // sanitize_hex_color() handles #RGB / #RRGGBB (incl. alpha variants in core 6.x).
        $hex = sanitize_hex_color( $val );
        if ( $hex ) {
            return $hex;
        }
        // Named colors — keep it simple, allow lowercase alpha words.
        if ( preg_match( '/^[a-zA-Z]{3,32}$/', $val ) ) {
            return strtolower( $val );
        }
        return '';
    }
}
