<?php
/**
 * NlFilter — convert a natural-language query into structured facet filters
 * via the Anthropic messages API + tool_use.
 *
 * Design notes:
 *
 * - Model: claude-haiku-4-5. The task is mechanical entity extraction
 *   constrained to a known vocabulary, not deep reasoning — Haiku is fast
 *   (~300–700ms) and cheap, and fits a user-facing search box latency
 *   budget. Override via the `hof_ai_model` filter.
 *
 * - Tool design: one tool named `apply_facet_filters` with one typed param
 *   per configured facet. Taxonomy + meta-string facets get string-array
 *   params with an `enum` of valid values from the index, so the model can't
 *   hallucinate values that don't exist. Range facets get `{min, max}`.
 *   `tool_choice: {type: "tool", ...}` forces the call so the response is
 *   always a structured tool_use block.
 *
 * - Prompt caching: the system prompt + tool schema is identical until the
 *   admin changes facet config or new index values arrive. `cache_control`
 *   sits on the last system block, which (per Anthropic's render order
 *   tools → system → messages) caches tools+system together. Min cacheable
 *   prefix on Haiku 4.5 is ~4096 tokens; tiny facet configs silently won't
 *   cache, but that's a no-op cost rather than an error.
 *
 * - Retry: one retry on 429/500/529 with a 250ms delay. 6s hard timeout.
 *   Auth/schema errors fail fast.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Ai;

use HookedOnFacets\Filter\Resolver;

final class NlFilter {

    public const ENDPOINT     = 'https://api.anthropic.com/v1/messages';
    public const MODEL_HAIKU  = 'claude-haiku-4-5';
    public const TIMEOUT_SECS = 6;
    public const MAX_TOKENS   = 1024;
    public const TOOL_NAME    = 'apply_facet_filters';

    public function __construct(
        private Resolver $resolver,
        private Settings $settings,
    ) {}

    /**
     * Translate a user query into structured filter params.
     *
     * @return array{
     *     ok: bool,
     *     filters?: array<string, mixed>,
     *     usage?: array<string, int>,
     *     error?: string,
     *     error_code?: string,
     * }
     */
    /**
     * @param array<string, mixed> $prior_state Currently-accepted constraints from
     *                                          earlier turns (same shape as the returned
     *                                          `filters`). Empty array for the first turn.
     */
    public function translate( string $query, array $prior_state = [] ): array {
        $query = trim( $query );
        if ( $query === '' ) {
            return [ 'ok' => false, 'error' => 'empty query', 'error_code' => 'empty_query' ];
        }

        $api_key = $this->settings->api_key();
        if ( $api_key === '' ) {
            return [ 'ok' => false, 'error' => 'no API key configured', 'error_code' => 'no_api_key' ];
        }

        $facet_context = $this->build_facet_context();
        if ( empty( $facet_context['facets'] ) ) {
            return [ 'ok' => false, 'error' => 'no facets configured', 'error_code' => 'no_facets' ];
        }

        $user_message = $this->compose_user_message( $query, $prior_state );

        $payload = [
            'model'       => apply_filters( 'hof_ai_model', self::MODEL_HAIKU ),
            'max_tokens'  => self::MAX_TOKENS,
            'system'      => [
                [
                    'type'          => 'text',
                    'text'          => $this->system_prompt( $facet_context['facets'] ),
                    'cache_control' => [ 'type' => 'ephemeral' ],
                ],
            ],
            'tools'       => [ $this->tool_schema( $facet_context['facets'] ) ],
            'tool_choice' => [ 'type' => 'tool', 'name' => self::TOOL_NAME ],
            'messages'    => [
                [ 'role' => 'user', 'content' => $user_message ],
            ],
        ];

        $response = $this->call_with_retry( $payload, $api_key );
        if ( ! $response['ok'] ) {
            return $response;
        }

        $filters = $this->extract_tool_input( $response['body'] );
        if ( $filters === null ) {
            return [
                'ok'         => false,
                'error'      => 'model did not return a tool call',
                'error_code' => 'no_tool_call',
            ];
        }

        $normalized = $this->normalize_filters( $filters, $facet_context['facets'] );

        return [
            'ok'      => true,
            'filters' => $normalized,
            'usage'   => $this->extract_usage( $response['body'] ),
        ];
    }

    // ── Prompt + schema ────────────────────────────────────────────────────

    /**
     * Build the per-facet context: type, label, allowed values (enum) for
     * categorical facets, or {min,max} bounds for range facets.
     *
     * Reuses Resolver::resolve() on an empty filter state so we get the same
     * term lists / numeric bounds the public renderer would show.
     *
     * @return array{facets: array<int, array<string, mixed>>}
     */
    private function build_facet_context(): array {
        $resolved = $this->resolver->resolve( [] );
        $counts   = $resolved['counts'] ?? [];
        $defs     = (array) get_option( \HookedOnFacets\Indexer::OPTION_FACETS, [] );

        $out = [];
        foreach ( $defs as $def ) {
            if ( ! is_array( $def ) || empty( $def['name'] ) ) {
                continue;
            }
            $name    = (string) $def['name'];
            $label   = (string) ( $def['label'] ?? $name );
            $display = (string) ( $def['display'] ?? 'checkbox' );
            $kind    = (string) ( $def['kind'] ?? 'taxonomy' );
            $info    = $counts[ $name ] ?? [];
            $type    = $info['type'] ?? null;

            // Skip view facets — they orchestrate other facets but aren't themselves filter targets.
            if ( $kind === 'view' || $display === 'two_d_slider' || $display === 'ask' ) {
                continue;
            }

            if ( $display === 'range' && $type === 'range' ) {
                $out[] = [
                    'name'    => $name,
                    'label'   => $label,
                    'display' => 'range',
                    'min'     => (float) $info['min'],
                    'max'     => (float) $info['max'],
                ];
                continue;
            }

            if ( $type === 'values' ) {
                $values = array_map(
                    static fn( array $b ): array => [
                        'value'   => (string) $b['value'],
                        'display' => (string) ( $b['display'] ?? $b['value'] ),
                    ],
                    $info['buckets'] ?? []
                );
                $out[] = [
                    'name'    => $name,
                    'label'   => $label,
                    'display' => 'multi',
                    'values'  => $values,
                ];
                continue;
            }

            // Display 'search' returns 'type=search' counts with no enum; skip
            // — the user would just type into the search field directly.
        }

        return [ 'facets' => $out ];
    }

    private function system_prompt( array $facets ): string {
        $lines   = [];
        $lines[] = 'You translate natural-language shopping queries into structured product filters for a WooCommerce store.';
        $lines[] = '';
        $lines[] = 'Available facets:';
        foreach ( $facets as $f ) {
            if ( $f['display'] === 'range' ) {
                $lines[] = sprintf(
                    '- %s (numeric range, min=%s max=%s)',
                    $f['name'],
                    rtrim( rtrim( sprintf( '%.2f', $f['min'] ), '0' ), '.' ),
                    rtrim( rtrim( sprintf( '%.2f', $f['max'] ), '0' ), '.' )
                );
                continue;
            }
            $samples = array_slice( $f['values'], 0, 50 );
            $tokens  = array_map(
                static fn( array $v ): string => $v['value'] === $v['display']
                    ? $v['value']
                    : sprintf( '%s ("%s")', $v['value'], $v['display'] ),
                $samples
            );
            $lines[] = sprintf(
                '- %s (one or more of: %s)',
                $f['name'],
                implode( ', ', $tokens )
            );
        }
        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- Always call the apply_facet_filters tool exactly once.';
        $lines[] = '- The tool call MUST contain the FULL resulting state of every active filter — not just what changed. The caller replaces its state with whatever you return.';
        $lines[] = '- Only set parameters you can confidently infer from the conversation so far. Leave a parameter unset if nothing said anything about it — do NOT guess.';
        $lines[] = '- Multi-value parameters take an array. Use the canonical value (left side of any "X (\"Y\")" entry above), not the display name.';
        $lines[] = '- For range parameters, only set min or max if the user gave an explicit bound. "under $50" → max=50. "between $20 and $40" → min=20, max=40.';
        $lines[] = '- When the user adds a new constraint, keep prior constraints unless they explicitly contradict or release them. "...also under $50" extends the prior state.';
        $lines[] = '- When the user tightens a constraint ("...and rated 4+"), update only that parameter; keep the rest.';
        $lines[] = '- When the user broadens or releases a constraint ("actually any color", "any price"), omit that parameter in your response.';
        $lines[] = '- If the new turn matches nothing and changes nothing, return the prior state unchanged.';

        return implode( "\n", $lines );
    }

    /**
     * Fold the prior state into the user message so Claude sees both the
     * cumulative conversation context and the fresh turn.
     *
     * @param array<string, mixed> $prior_state
     */
    private function compose_user_message( string $query, array $prior_state ): string {
        if ( empty( $prior_state ) ) {
            return $query;
        }

        $json = wp_json_encode( $prior_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }

        return sprintf(
            "Current accepted state from earlier turns:\n%s\n\nNew turn from the shopper:\n%s",
            $json,
            $query
        );
    }

    private function tool_schema( array $facets ): array {
        $properties = [];
        $required   = [];

        foreach ( $facets as $f ) {
            if ( $f['display'] === 'range' ) {
                $properties[ $f['name'] ] = [
                    'type'        => 'object',
                    'description' => sprintf( '%s as a numeric range. Either or both of min/max may be omitted.', $f['label'] ),
                    'properties'  => [
                        'min' => [ 'type' => 'number' ],
                        'max' => [ 'type' => 'number' ],
                    ],
                ];
                continue;
            }
            $enum                       = array_values( array_map( static fn( array $v ): string => $v['value'], $f['values'] ) );
            $properties[ $f['name'] ]   = [
                'type'        => 'array',
                'description' => sprintf( '%s — pick one or more of the allowed values.', $f['label'] ),
                'items'       => [
                    'type' => 'string',
                    'enum' => $enum,
                ],
            ];
        }

        return [
            'name'         => self::TOOL_NAME,
            'description'  => 'Apply the structured filters extracted from the user\'s natural-language query. Omit any parameter that the query does not address.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => $properties,
                'required'             => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    // ── HTTP ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, body?: array<string, mixed>, error?: string, error_code?: string}
     */
    private function call_with_retry( array $payload, string $api_key ): array {
        $attempt = 0;
        $max     = 2;

        while ( $attempt < $max ) {
            $attempt++;
            $result = $this->call_once( $payload, $api_key );

            if ( $result['ok'] ) {
                return $result;
            }

            $code = $result['http_code'] ?? 0;
            $retryable = in_array( $code, [ 429, 500, 502, 503, 529 ], true );
            if ( ! $retryable || $attempt >= $max ) {
                unset( $result['http_code'] );
                return $result;
            }
            usleep( 250 * 1000 );
        }

        return [ 'ok' => false, 'error' => 'retry exhausted', 'error_code' => 'retry_exhausted' ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, body?: array<string, mixed>, error?: string, error_code?: string, http_code?: int}
     */
    private function call_once( array $payload, string $api_key ): array {
        $response = wp_remote_post( self::ENDPOINT, [
            'timeout' => self::TIMEOUT_SECS,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'         => false,
                'error'      => $response->get_error_message(),
                'error_code' => 'transport_' . $response->get_error_code(),
                'http_code'  => 0,
            ];
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $json   = json_decode( $body, true );

        if ( $status < 200 || $status >= 300 ) {
            $api_err = is_array( $json ) ? ( $json['error']['message'] ?? '' ) : '';
            $api_typ = is_array( $json ) ? ( $json['error']['type'] ?? '' ) : '';
            return [
                'ok'         => false,
                'error'      => sprintf( 'HTTP %d %s: %s', $status, $api_typ, $api_err ),
                'error_code' => $api_typ !== '' ? $api_typ : 'http_' . $status,
                'http_code'  => $status,
            ];
        }

        if ( ! is_array( $json ) ) {
            return [
                'ok'         => false,
                'error'      => 'response was not JSON',
                'error_code' => 'invalid_json',
                'http_code'  => $status,
            ];
        }

        return [ 'ok' => true, 'body' => $json ];
    }

    // ── Response parsing ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function extract_tool_input( array $body ): ?array {
        foreach ( (array) ( $body['content'] ?? [] ) as $block ) {
            if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'tool_use' && ( $block['name'] ?? '' ) === self::TOOL_NAME ) {
                $input = $block['input'] ?? null;
                return is_array( $input ) ? $input : [];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, int>
     */
    private function extract_usage( array $body ): array {
        $u = (array) ( $body['usage'] ?? [] );
        return [
            'input_tokens'                => (int) ( $u['input_tokens'] ?? 0 ),
            'output_tokens'               => (int) ( $u['output_tokens'] ?? 0 ),
            'cache_creation_input_tokens' => (int) ( $u['cache_creation_input_tokens'] ?? 0 ),
            'cache_read_input_tokens'     => (int) ( $u['cache_read_input_tokens'] ?? 0 ),
        ];
    }

    /**
     * Normalize the tool's raw input into the same URL-state shape the
     * resolver consumes (`hof[<name>]=...`): drop empty params, convert
     * range objects to `{min,max}` with string-safe values.
     *
     * @param array<string, mixed> $raw
     * @param array<int, array<string, mixed>> $facets
     * @return array<string, mixed>
     */
    private function normalize_filters( array $raw, array $facets ): array {
        $by_name = [];
        foreach ( $facets as $f ) {
            $by_name[ $f['name'] ] = $f;
        }

        $out = [];
        foreach ( $raw as $name => $value ) {
            if ( ! isset( $by_name[ $name ] ) ) {
                continue;
            }
            $f = $by_name[ $name ];
            if ( $f['display'] === 'range' ) {
                if ( ! is_array( $value ) ) {
                    continue;
                }
                $range = [];
                if ( isset( $value['min'] ) && is_numeric( $value['min'] ) ) {
                    $range['min'] = (string) $value['min'];
                }
                if ( isset( $value['max'] ) && is_numeric( $value['max'] ) ) {
                    $range['max'] = (string) $value['max'];
                }
                if ( ! empty( $range ) ) {
                    $out[ $name ] = $range;
                }
                continue;
            }
            // Multi-value: array of canonical values.
            if ( ! is_array( $value ) ) {
                continue;
            }
            $clean = array_values( array_filter(
                array_map(
                    static fn( $v ): ?string => is_scalar( $v ) ? (string) $v : null,
                    $value
                ),
                static fn( ?string $v ): bool => $v !== null && $v !== ''
            ) );
            if ( ! empty( $clean ) ) {
                $out[ $name ] = $clean;
            }
        }

        return $out;
    }
}
