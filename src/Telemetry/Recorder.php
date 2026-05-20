<?php
/**
 * Recorder — collects resolver timings and loop-hook events per request,
 * flushes to a single option write at shutdown.
 *
 * Why this lives in its own service:
 *   The Resolver fires many times in a typical request (REST /filter does 1
 *   ids query + N count queries). We can't afford an option write per call,
 *   so we buffer in-memory and flush once when PHP shuts down — after the
 *   response is already on the wire.
 *
 * Storage shape (option `hof_telemetry`):
 *   {
 *     resolver: { samples_ms: float[100], total_calls: int },
 *     loops:    { signatures: { "<sig>": { first, last, count } } }
 *   }
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Telemetry;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class Recorder implements Bootable {

    public const OPTION = 'hof_telemetry';

    /** Cap on the ring buffer of resolver samples persisted in the option. */
    private const SAMPLE_BUFFER = 100;

    /** Hard cap on samples captured in a single request — runaway protection. */
    private const SAMPLES_PER_REQUEST = 200;

    /** @var float[] */
    private array $resolver_ms = [];

    /** @var array<string, int> signature → intercept count */
    private array $loop_hits = [];

    private bool $shutdown_registered = false;

    public function register_hooks(): void {
        // Use PHP's native shutdown hook rather than the WP `shutdown` action so
        // we run after the response body is flushed even in fastcgi_finish_request
        // scenarios — the recorder must never add to user-perceived latency.
        if ( ! $this->shutdown_registered ) {
            register_shutdown_function( [ $this, 'flush' ] );
            $this->shutdown_registered = true;
        }
    }

    public function record_resolver_ms( float $ms ): void {
        if ( count( $this->resolver_ms ) >= self::SAMPLES_PER_REQUEST ) {
            return;
        }
        $this->resolver_ms[] = $ms;
    }

    public function record_loop_hook( string $signature ): void {
        $signature = substr( $signature, 0, 64 );
        if ( $signature === '' ) {
            return;
        }
        $this->loop_hits[ $signature ] = ( $this->loop_hits[ $signature ] ?? 0 ) + 1;
    }

    /**
     * Merge the in-memory buffers into the persisted option.
     *
     * Safe to call multiple times; subsequent calls flush nothing because
     * the buffers are cleared after a write.
     */
    public function flush(): void {
        if ( empty( $this->resolver_ms ) && empty( $this->loop_hits ) ) {
            return;
        }

        $state = $this->load_option();
        $now   = time();

        // Resolver — append into ring buffer, keep the tail.
        foreach ( $this->resolver_ms as $ms ) {
            $state['resolver']['samples_ms'][] = $ms;
        }
        if ( count( $state['resolver']['samples_ms'] ) > self::SAMPLE_BUFFER ) {
            $state['resolver']['samples_ms'] = array_slice(
                $state['resolver']['samples_ms'],
                -self::SAMPLE_BUFFER
            );
        }
        $state['resolver']['total_calls'] += count( $this->resolver_ms );

        // Loop hits — merge by signature.
        foreach ( $this->loop_hits as $sig => $count ) {
            $existing = $state['loops']['signatures'][ $sig ] ?? null;
            $state['loops']['signatures'][ $sig ] = [
                'first' => $existing['first'] ?? $now,
                'last'  => $now,
                'count' => ( $existing['count'] ?? 0 ) + $count,
            ];
        }

        update_option( self::OPTION, $state, false ); // autoload=false: only the admin Dashboard reads it.

        $this->resolver_ms = [];
        $this->loop_hits   = [];
    }

    /**
     * Read-only snapshot for the admin (REST + bootstrap).
     *
     * @return array{
     *   resolver: array{ avg_ms: ?float, p95_ms: ?float, sample_size: int, total_calls: int },
     *   loops:    array{ count: int, total_hits: int, top: array<int, array{signature: string, count: int, last: int}> }
     * }
     */
    public function snapshot(): array {
        $state = $this->load_option();

        $samples = $state['resolver']['samples_ms'];
        $n       = count( $samples );
        $avg     = null;
        $p95     = null;
        if ( $n > 0 ) {
            $avg    = array_sum( $samples ) / $n;
            $sorted = $samples;
            sort( $sorted );
            $p95 = $sorted[ (int) min( $n - 1, $n * 0.95 ) ];
        }

        $sigs = $state['loops']['signatures'];
        uasort( $sigs, static fn( $a, $b ) => ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 ) );
        $top = [];
        foreach ( array_slice( $sigs, 0, 8, true ) as $signature => $row ) {
            $top[] = [
                'signature' => (string) $signature,
                'count'     => (int) ( $row['count'] ?? 0 ),
                'last'      => (int) ( $row['last']  ?? 0 ),
            ];
        }
        $total_hits = 0;
        foreach ( $sigs as $row ) {
            $total_hits += (int) ( $row['count'] ?? 0 );
        }

        return [
            'resolver' => [
                'avg_ms'      => $avg !== null ? round( $avg, 2 ) : null,
                'p95_ms'      => $p95 !== null ? round( $p95, 2 ) : null,
                'sample_size' => $n,
                'total_calls' => (int) $state['resolver']['total_calls'],
            ],
            'loops' => [
                'count'      => count( $sigs ),
                'total_hits' => $total_hits,
                'top'        => $top,
            ],
        ];
    }

    public function reset(): void {
        delete_option( self::OPTION );
        $this->resolver_ms = [];
        $this->loop_hits   = [];
    }

    /**
     * @return array{
     *   resolver: array{ samples_ms: float[], total_calls: int },
     *   loops:    array{ signatures: array<string, array{first: int, last: int, count: int}> }
     * }
     */
    private function load_option(): array {
        $raw = get_option( self::OPTION, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        return [
            'resolver' => [
                'samples_ms'  => array_values( array_map( 'floatval', (array) ( $raw['resolver']['samples_ms'] ?? [] ) ) ),
                'total_calls' => (int) ( $raw['resolver']['total_calls'] ?? 0 ),
            ],
            'loops' => [
                'signatures' => (array) ( $raw['loops']['signatures'] ?? [] ),
            ],
        ];
    }
}
