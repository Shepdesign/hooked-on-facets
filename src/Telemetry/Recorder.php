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
 *     loops:    { signatures: { "<sig>": { first, last, count } } },
 *     facets:   {
 *       usage:        { "<facet>": int },                       // applications per facet
 *       values:       { "<facet>": { "<value>": int } },        // applications per value
 *       zero_results: { "<sig>": { count, last } },             // filter combos that matched nothing
 *     }
 *   }
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Telemetry;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class Recorder implements Bootable, LoopHookRecorder {

    public const OPTION = 'hof_telemetry';

    /** Cap on the ring buffer of resolver samples persisted in the option. */
    private const SAMPLE_BUFFER = 100;

    /** Hard cap on samples captured in a single request — runaway protection. */
    private const SAMPLES_PER_REQUEST = 200;

    /** Cap on distinct values tracked per facet — prunes the long tail by count. */
    private const VALUES_PER_FACET = 100;

    /** Cap on distinct zero-result filter signatures retained — prunes oldest. */
    private const ZERO_RESULT_CAP = 50;

    /** @var float[] */
    private array $resolver_ms = [];

    /** @var array<string, int> signature → intercept count */
    private array $loop_hits = [];

    /** @var array<int, array{facets: array<string, mixed>, zero: bool}> per-request filter events */
    private array $filter_events = [];

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
     * Record one user filter application: which facets/values were active and
     * whether the combination matched anything. Called once per filter action
     * (the REST /filter endpoint), so each call is a genuine usage signal.
     * Reserved internal keys (`_visual_ids`, `_bin_ids`) are not facets and are
     * skipped. Buffered in-memory; merged into the option at flush().
     *
     * @param array<string, mixed> $filter_state
     */
    public function record_filter_usage( array $filter_state, int $result_count ): void {
        if ( count( $this->filter_events ) >= self::SAMPLES_PER_REQUEST ) {
            return;
        }
        $facets = [];
        foreach ( $filter_state as $name => $value ) {
            if ( str_starts_with( (string) $name, '_' ) ) {
                continue;
            }
            $facets[ (string) $name ] = $value;
        }
        if ( empty( $facets ) ) {
            return;
        }
        $this->filter_events[] = [ 'facets' => $facets, 'zero' => $result_count === 0 ];
    }

    /**
     * Merge the in-memory buffers into the persisted option.
     *
     * Safe to call multiple times; subsequent calls flush nothing because
     * the buffers are cleared after a write.
     */
    public function flush(): void {
        if ( empty( $this->resolver_ms ) && empty( $this->loop_hits ) && empty( $this->filter_events ) ) {
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

        // Filter usage — per-facet and per-value counts, plus zero-result combos.
        foreach ( $this->filter_events as $event ) {
            foreach ( $event['facets'] as $name => $value ) {
                $state['facets']['usage'][ $name ] = ( $state['facets']['usage'][ $name ] ?? 0 ) + 1;
                foreach ( self::scalar_values( $value ) as $v ) {
                    $state['facets']['values'][ $name ][ $v ] =
                        ( $state['facets']['values'][ $name ][ $v ] ?? 0 ) + 1;
                }
            }
            if ( $event['zero'] ) {
                $sig = self::filter_signature( $event['facets'] );
                $row = $state['facets']['zero_results'][ $sig ] ?? null;
                $state['facets']['zero_results'][ $sig ] = [
                    'count' => ( $row['count'] ?? 0 ) + 1,
                    'last'  => $now,
                ];
            }
        }
        $this->prune_facets( $state );

        update_option( self::OPTION, $state, false ); // autoload=false: only the admin Dashboard reads it.

        $this->resolver_ms   = [];
        $this->loop_hits     = [];
        $this->filter_events = [];
    }

    /**
     * Flatten a filter value into the scalar value strings to count. Arrays
     * (multi-select) yield one per element; ranges (min/max) contribute no
     * value-level rows — only the facet-level usage above. Truncated to 191.
     *
     * @return string[]
     */
    private static function scalar_values( mixed $value ): array {
        if ( is_array( $value ) ) {
            if ( isset( $value['min'] ) || isset( $value['max'] ) ) {
                return []; // a range has no discrete value to tally
            }
            $out = [];
            foreach ( $value as $v ) {
                if ( is_scalar( $v ) && (string) $v !== '' ) {
                    $out[] = substr( (string) $v, 0, 191 );
                }
            }
            return $out;
        }
        return is_scalar( $value ) && (string) $value !== '' ? [ substr( (string) $value, 0, 191 ) ] : [];
    }

    /**
     * Stable signature for a filter combination — sorted "facet=v1,v2&facet2=x"
     * so the same combo collapses to one zero-result row regardless of order.
     *
     * @param array<string, mixed> $facets
     */
    private static function filter_signature( array $facets ): string {
        ksort( $facets );
        $parts = [];
        foreach ( $facets as $name => $value ) {
            if ( is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) ) ) {
                $body = ( $value['min'] ?? '' ) . '-' . ( $value['max'] ?? '' );
            } elseif ( is_array( $value ) ) {
                $vals = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
                sort( $vals );
                $body = implode( ',', $vals );
            } else {
                $body = (string) $value;
            }
            $parts[] = $name . '=' . $body;
        }
        return substr( implode( '&', $parts ), 0, 191 );
    }

    /**
     * Keep the persisted facet maps bounded: top N values per facet by count,
     * and the most-recent zero-result signatures.
     *
     * @param array<string, mixed> $state
     */
    private function prune_facets( array &$state ): void {
        foreach ( $state['facets']['values'] as $facet => $values ) {
            if ( count( $values ) > self::VALUES_PER_FACET ) {
                arsort( $values );
                $state['facets']['values'][ $facet ] = array_slice( $values, 0, self::VALUES_PER_FACET, true );
            }
        }
        $zero = $state['facets']['zero_results'];
        if ( count( $zero ) > self::ZERO_RESULT_CAP ) {
            uasort( $zero, static fn( $a, $b ) => ( $b['last'] ?? 0 ) <=> ( $a['last'] ?? 0 ) );
            $state['facets']['zero_results'] = array_slice( $zero, 0, self::ZERO_RESULT_CAP, true );
        }
    }

    /**
     * Read-only snapshot for the admin (REST + bootstrap).
     *
     * Returns BOTH a `top` (capped, ordered by count) for the Dashboard hero
     * AND a `signatures` (all, sorted by last-seen) for the Query Loops view.
     * Distinct signature cardinality is inherently small on a real site —
     * post-type × intercept-type-set — so returning all of them is cheap.
     *
     * @return array{
     *   resolver: array{ avg_ms: ?float, p95_ms: ?float, sample_size: int, total_calls: int },
     *   loops:    array{
     *     count: int,
     *     total_hits: int,
     *     top:        array<int, array{signature: string, count: int, last: int}>,
     *     signatures: array<int, array{signature: string, type: string, post_type: string, count: int, first: int, last: int}>,
     *   }
     * }
     */
    public function snapshot(): array {
        $state = $this->load_option();

        $samples = $state['resolver']['samples_ms'];
        $n       = count( $samples );
        $avg     = null;
        $p50     = null;
        $p95     = null;
        $p99     = null;
        if ( $n > 0 ) {
            $avg    = array_sum( $samples ) / $n;
            $sorted = $samples;
            sort( $sorted );
            $p50 = $sorted[ (int) min( $n - 1, floor( $n * 0.50 ) ) ];
            $p95 = $sorted[ (int) min( $n - 1, floor( $n * 0.95 ) ) ];
            $p99 = $sorted[ (int) min( $n - 1, floor( $n * 0.99 ) ) ];
        }

        $sigs = $state['loops']['signatures'];

        // Top — by count, for the Dashboard hero (capped).
        $by_count = $sigs;
        uasort( $by_count, static fn( $a, $b ) => ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 ) );
        $top = [];
        foreach ( array_slice( $by_count, 0, 8, true ) as $signature => $row ) {
            $top[] = [
                'signature' => (string) $signature,
                'count'     => (int) ( $row['count'] ?? 0 ),
                'last'      => (int) ( $row['last']  ?? 0 ),
            ];
        }

        // Signatures — full list with parsed parts, sorted most-recent-first.
        $by_recent = $sigs;
        uasort( $by_recent, static fn( $a, $b ) => ( $b['last'] ?? 0 ) <=> ( $a['last'] ?? 0 ) );
        $signatures = [];
        foreach ( $by_recent as $signature => $row ) {
            [ $type, $post_type ] = self::parse_signature( (string) $signature );
            $signatures[] = [
                'signature' => (string) $signature,
                'type'      => $type,
                'post_type' => $post_type,
                'count'     => (int) ( $row['count'] ?? 0 ),
                'first'     => (int) ( $row['first'] ?? 0 ),
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
                'p50_ms'      => $p50 !== null ? round( $p50, 2 ) : null,
                'p95_ms'      => $p95 !== null ? round( $p95, 2 ) : null,
                'p99_ms'      => $p99 !== null ? round( $p99, 2 ) : null,
                'sample_size' => $n,
                'total_calls' => (int) $state['resolver']['total_calls'],
            ],
            'loops' => [
                'count'      => count( $sigs ),
                'total_hits' => $total_hits,
                'top'        => $top,
                'signatures' => $signatures,
            ],
            'facets' => $this->facets_analytics( $state['facets'] ),
        ];
    }

    /**
     * Shape the persisted facet maps for the admin: usage sorted desc, the top
     * values per facet, and the worst zero-result combos.
     *
     * @param array{usage: array<string,int>, values: array<string,array<string,int>>, zero_results: array<string,array{count:int,last:int}>} $facets
     * @return array{
     *   usage:        array<int, array{facet: string, count: int}>,
     *   top_values:   array<string, array<int, array{value: string, count: int}>>,
     *   zero_results: array<int, array{signature: string, count: int, last: int}>,
     *   total:        int
     * }
     */
    private function facets_analytics( array $facets ): array {
        $usage = $facets['usage'];
        arsort( $usage );
        $usage_rows = [];
        $total      = 0;
        foreach ( $usage as $facet => $count ) {
            $usage_rows[] = [ 'facet' => (string) $facet, 'count' => (int) $count ];
            $total       += (int) $count;
        }

        $top_values = [];
        foreach ( $facets['values'] as $facet => $values ) {
            arsort( $values );
            $rows = [];
            foreach ( array_slice( $values, 0, 10, true ) as $value => $count ) {
                $rows[] = [ 'value' => (string) $value, 'count' => (int) $count ];
            }
            $top_values[ (string) $facet ] = $rows;
        }

        $zero = $facets['zero_results'];
        uasort( $zero, static fn( $a, $b ) => ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 ) );
        $zero_rows = [];
        foreach ( array_slice( $zero, 0, 20, true ) as $sig => $row ) {
            $zero_rows[] = [
                'signature' => (string) $sig,
                'count'     => (int) ( $row['count'] ?? 0 ),
                'last'      => (int) ( $row['last'] ?? 0 ),
            ];
        }

        return [
            'usage'        => $usage_rows,
            'top_values'   => $top_values,
            'zero_results' => $zero_rows,
            'total'        => $total,
        ];
    }

    /**
     * Split "archive:product" → ["archive", "product"].
     *
     * @return array{0: string, 1: string}
     */
    private static function parse_signature( string $signature ): array {
        $parts = explode( ':', $signature, 2 );
        return [
            $parts[0] ?? 'unknown',
            $parts[1] ?? 'unknown',
        ];
    }

    public function reset(): void {
        delete_option( self::OPTION );
        $this->resolver_ms   = [];
        $this->loop_hits     = [];
        $this->filter_events = [];
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
            'facets' => [
                'usage'        => (array) ( $raw['facets']['usage'] ?? [] ),
                'values'       => (array) ( $raw['facets']['values'] ?? [] ),
                'zero_results' => (array) ( $raw['facets']['zero_results'] ?? [] ),
            ],
        ];
    }
}
