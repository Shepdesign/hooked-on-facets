<?php
/**
 * Settings — read/write AI integration config (API key, model override).
 *
 * Stored in WP options under `hof_ai`. The API key never leaves PHP — REST
 * responses, admin UI, and frontend bundle never see it.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Ai;

final class Settings {

    public const OPTION = 'hof_ai';

    public function api_key(): string {
        $opt = (array) get_option( self::OPTION, [] );
        return (string) ( $opt['api_key'] ?? '' );
    }

    public function set_api_key( string $key ): void {
        $opt              = (array) get_option( self::OPTION, [] );
        $opt['api_key']   = $key;
        update_option( self::OPTION, $opt, false );
    }

    public function is_configured(): bool {
        return $this->api_key() !== '';
    }
}
