<?php
/**
 * Admin notice for unlicensed installs.
 *
 * In soft enforcement (the default, and what alpha builds ship with) the
 * plugin works without a license but surfaces a dismissible notice on
 * admin pages so users see the path to a real license.
 *
 * Strict enforcement (future, post-launch) will add hard-gating in the
 * Renderer / REST layer; this class only handles the notice.
 *
 * Suppressed entirely:
 *   - on the License admin page itself (the panel is already screaming
 *     about it; another banner would be noise)
 *   - in dev mode (HOF_LICENSE_DEV_MODE)
 *   - when the user dismisses it via the standard WP notice X (per-user)
 *
 * @package HookedOnFacets\Admin
 */

declare(strict_types=1);

namespace HookedOnFacets\Admin;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Licensing\LicenseManager;

defined( 'ABSPATH' ) || exit;

final class LicenseNotice implements Bootable {

    private const DISMISSED_KEY_PREFIX = 'hof_license_notice_dismissed_';

    private LicenseManager $license;

    public function __construct( LicenseManager $license ) {
        $this->license = $license;
    }

    public function register_hooks(): void {
        add_action( 'admin_notices',           [ $this, 'maybe_render' ] );
        add_action( 'wp_ajax_hof_dismiss_license_notice', [ $this, 'ajax_dismiss' ] );
        add_action( 'admin_print_footer_scripts', [ $this, 'dismiss_script' ] );
    }

    public function maybe_render(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( LicenseManager::is_dev_mode() ) return;
        if ( $this->on_license_page() ) return;
        if ( $this->is_dismissed_for_current_version() ) return;

        $state  = $this->license->state();
        $status = (string) $state['status'];
        if ( $status === 'valid' ) return; // Licensed — no notice.

        $is_expired = $status === 'expired';
        $is_unset   = ! $state['configured'];

        $title = $is_expired
            ? 'Hooked on Facets — your license has expired'
            : ( $is_unset ? 'Hooked on Facets — unlicensed install' : 'Hooked on Facets — license issue' );

        $body = $is_expired
            ? 'Renewing keeps you on the update channel and unlocks any features gated to active licenses.'
            : ( $is_unset
                ? 'You\'re running an unlicensed copy. Everything works during this pre-alpha period, but plugin updates and licensed features require a key.'
                : sprintf( 'Current status: <code>%s</code>. Updates are disabled until this is resolved.', esc_html( $status ) ) );

        $admin_url = esc_url( admin_url( 'admin.php?page=hooked-on-facets#license' ) );
        $buy_url   = esc_url( LicenseManager::store_url() );

        ?>
        <div class="notice notice-warning is-dismissible" data-hof-license-notice>
            <p>
                <strong><?php echo esc_html( $title ); ?></strong>
                — <?php echo wp_kses_post( $body ); ?>
            </p>
            <p>
                <a href="<?php echo $admin_url; ?>" class="button button-primary">Enter license key</a>
                <a href="<?php echo $buy_url; ?>" class="button" target="_blank" rel="noopener">Get a license</a>
            </p>
        </div>
        <?php
    }

    public function ajax_dismiss(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '', 403 );
        check_ajax_referer( 'hof_license_notice', 'nonce' );
        update_user_meta( get_current_user_id(), $this->dismissed_key(), '1' );
        wp_send_json_success();
    }

    public function dismiss_script(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( LicenseManager::is_dev_mode() ) return;
        $nonce = wp_create_nonce( 'hof_license_notice' );
        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        ?>
        <script>
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-hof-license-notice] .notice-dismiss');
            if (!btn) return;
            const body = new URLSearchParams({
                action: 'hof_dismiss_license_notice',
                nonce:  <?php echo wp_json_encode( $nonce ); ?>,
            });
            fetch(<?php echo wp_json_encode( $ajax ); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
        });
        </script>
        <?php
    }

    private function on_license_page(): bool {
        // Suppress on the HOF admin SPA itself — the License panel is right
        // there, no need to nag.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && strpos( (string) $screen->id, 'hooked-on-facets' ) !== false ) {
            return true;
        }
        return false;
    }

    private function dismissed_key(): string {
        // Reset the dismissal on every plugin version bump so users see the
        // notice again after a major upgrade.
        $version = defined( 'HOF_VERSION' ) ? (string) HOF_VERSION : 'unknown';
        return self::DISMISSED_KEY_PREFIX . preg_replace( '/[^a-z0-9_]/i', '_', $version );
    }

    private function is_dismissed_for_current_version(): bool {
        return (string) get_user_meta( get_current_user_id(), $this->dismissed_key(), true ) === '1';
    }
}
