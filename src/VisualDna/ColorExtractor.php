<?php
/**
 * Visual DNA color extractor.
 *
 * Mirrors the client-side dominant-color algorithm so an indexed product's
 * LAB matches what the storefront JS would extract from the same image:
 *
 *   1. Load the featured image via WP_Image_Editor (GD or Imagick — whichever
 *      WordPress has wired up; Imagick is preferred when available).
 *   2. Downsample to ~96px on the long side so the per-pixel scan is cheap
 *      regardless of source resolution.
 *   3. Quantize every pixel to 4 bits per channel (4096 buckets total).
 *   4. Pick the heaviest bucket that isn't near-white or near-black, unless
 *      one of those clearly dominates (>60% of opaque pixels).
 *   5. Convert the bucket-averaged RGB → sRGB → linear → XYZ → LAB (D65).
 *
 * Returns null when there's no featured image or any step fails — callers
 * skip writing a LAB row in that case rather than poisoning the index.
 *
 * @package HookedOnFacets\VisualDna
 */

declare(strict_types=1);

namespace HookedOnFacets\VisualDna;

defined( 'ABSPATH' ) || exit;

final class ColorExtractor {

    /** Long-edge target for the downsample step. Smaller = faster + lossier. */
    private const SAMPLE_SIZE = 96;

    /** Minimum bucket weight (fraction of opaque pixels) to include in a palette. */
    private const PALETTE_MIN_WEIGHT = 0.05;

    /** Default palette size (top-N buckets) for v3 palette matching. */
    public const DEFAULT_PALETTE_SIZE = 5;

    /**
     * Extract LAB from the given post's featured image.
     *
     * @return array{L: float, a: float, b: float}|null
     */
    public function extract_from_post( int $post_id ): ?array {
        $attachment_id = (int) get_post_thumbnail_id( $post_id );
        if ( $attachment_id <= 0 ) {
            return null;
        }
        return $this->extract_from_attachment( $attachment_id );
    }

    /**
     * @return array{L: float, a: float, b: float}|null
     */
    public function extract_from_attachment( int $attachment_id ): ?array {
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! is_readable( $path ) ) {
            return null;
        }
        return $this->extract_from_path( $path );
    }

    /**
     * @return array{L: float, a: float, b: float}|null
     */
    public function extract_from_path( string $path ): ?array {
        return $this->run_extract( $path, function ( string $sample_path ): ?array {
            $rgb = $this->dominant_rgb_from_file( $sample_path );
            if ( $rgb === null ) {
                return null;
            }
            return self::rgb_to_lab( $rgb[0], $rgb[1], $rgb[2] );
        } );
    }

    /**
     * Top-N color palette for a product (v3). Each entry is a dominant
     * cluster with its weight (fraction of opaque pixels), ordered by
     * weight desc.
     *
     * @return array<int, array{L: float, a: float, b: float, weight: float}>|null
     */
    public function extract_palette_from_post( int $post_id, int $top_n = self::DEFAULT_PALETTE_SIZE ): ?array {
        $attachment_id = (int) get_post_thumbnail_id( $post_id );
        if ( $attachment_id <= 0 ) {
            return null;
        }
        return $this->extract_palette_from_attachment( $attachment_id, $top_n );
    }

    /**
     * @return array<int, array{L: float, a: float, b: float, weight: float}>|null
     */
    public function extract_palette_from_attachment( int $attachment_id, int $top_n = self::DEFAULT_PALETTE_SIZE ): ?array {
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! is_readable( $path ) ) {
            return null;
        }
        return $this->extract_palette_from_path( $path, $top_n );
    }

    /**
     * @return array<int, array{L: float, a: float, b: float, weight: float}>|null
     */
    public function extract_palette_from_path( string $path, int $top_n = self::DEFAULT_PALETTE_SIZE ): ?array {
        return $this->run_extract( $path, function ( string $sample_path ) use ( $top_n ): ?array {
            $palette = $this->palette_rgb_from_file( $sample_path, $top_n );
            if ( $palette === null ) {
                return null;
            }
            $out = [];
            foreach ( $palette as $entry ) {
                $lab = self::rgb_to_lab( $entry[0], $entry[1], $entry[2] );
                $out[] = [
                    'L'      => $lab['L'],
                    'a'      => $lab['a'],
                    'b'      => $lab['b'],
                    'weight' => (float) $entry[3],
                ];
            }
            return $out;
        } );
    }

    /**
     * Shared image-loading / downsample / temp-file pipeline. Calls
     * `$on_sample` with the path to the downsampled PNG and returns
     * whatever it returns. Handles cleanup either way.
     *
     * @param callable(string): mixed $on_sample
     */
    private function run_extract( string $path, callable $on_sample ): mixed {
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return null;
        }

        $size = $editor->get_size();
        if ( ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
            return null;
        }
        $long = max( (int) $size['width'], (int) $size['height'] );
        if ( $long > self::SAMPLE_SIZE ) {
            $editor->resize( self::SAMPLE_SIZE, self::SAMPLE_SIZE, false );
        }

        $tmp = wp_tempnam( 'hof_vdna_' );
        if ( ! $tmp ) {
            return null;
        }
        $saved = $editor->save( $tmp, 'image/png' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            @unlink( $tmp );
            return null;
        }

        try {
            return $on_sample( (string) $saved['path'] );
        } finally {
            @unlink( (string) $saved['path'] );
            if ( $saved['path'] !== $tmp ) {
                @unlink( $tmp );
            }
        }
    }

    /**
     * Collect 4-bits-per-channel buckets for every opaque pixel in the file.
     * Returned buckets are NOT sorted; caller orders by count.
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>|null
     *         Each entry is [count, r_sum, g_sum, b_sum]. Returns null on
     *         load failure, empty array when the image has no opaque pixels.
     */
    private function collect_buckets( string $path ): ?array {
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return null;
        }
        $data = @file_get_contents( $path );
        if ( $data === false ) {
            return null;
        }
        $img = @imagecreatefromstring( $data );
        if ( $img === false ) {
            return null;
        }

        $w = imagesx( $img );
        $h = imagesy( $img );

        $buckets = [];
        for ( $y = 0; $y < $h; $y++ ) {
            for ( $x = 0; $x < $w; $x++ ) {
                $rgba = imagecolorat( $img, $x, $y );
                // GD alpha: 0 = opaque, 127 = transparent. Skip near-transparent.
                $a = ( $rgba >> 24 ) & 0x7F;
                if ( $a >= 100 ) continue;
                $r = ( $rgba >> 16 ) & 0xFF;
                $g = ( $rgba >>  8 ) & 0xFF;
                $b =   $rgba         & 0xFF;
                $key = ( ( $r >> 4 ) << 8 ) | ( ( $g >> 4 ) << 4 ) | ( $b >> 4 );
                if ( isset( $buckets[ $key ] ) ) {
                    $buckets[ $key ][0]++;
                    $buckets[ $key ][1] += $r;
                    $buckets[ $key ][2] += $g;
                    $buckets[ $key ][3] += $b;
                } else {
                    $buckets[ $key ] = [ 1, $r, $g, $b ];
                }
            }
        }
        imagedestroy( $img );

        return $buckets;
    }

    /**
     * Single dominant non-extreme RGB bucket (or majority extreme as
     * fallback). Used by v2 single-color extraction.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function dominant_rgb_from_file( string $path ): ?array {
        $buckets = $this->collect_buckets( $path );
        if ( $buckets === null || empty( $buckets ) ) {
            return null;
        }
        usort( $buckets, static fn( $a, $b ) => $b[0] - $a[0] );
        $total = 0;
        foreach ( $buckets as $b ) $total += $b[0];

        foreach ( $buckets as $b ) {
            $count = $b[0];
            $avgR  = (int) round( $b[1] / $count );
            $avgG  = (int) round( $b[2] / $count );
            $avgB  = (int) round( $b[3] / $count );
            $isNearWhite = $avgR > 245 && $avgG > 245 && $avgB > 245;
            $isNearBlack = $avgR <  10 && $avgG <  10 && $avgB <  10;
            $isMajority  = ( $count / max( 1, $total ) ) > 0.6;
            if ( ! $isNearWhite && ! $isNearBlack ) {
                return [ $avgR, $avgG, $avgB ];
            }
            if ( $isMajority ) {
                return [ $avgR, $avgG, $avgB ];
            }
        }

        $top  = $buckets[0];
        return [
            (int) round( $top[1] / $top[0] ),
            (int) round( $top[2] / $top[0] ),
            (int) round( $top[3] / $top[0] ),
        ];
    }

    /**
     * Top-N palette (v3). Returns up to $top_n entries, each
     * [r, g, b, weight]. Weight is the bucket's fraction of opaque pixels.
     * Near-white and near-black buckets are dropped unless they're the
     * only candidates (matches dominant_rgb_from_file's "majority extreme
     * wins" behavior at the palette scale).
     *
     * Buckets below PALETTE_MIN_WEIGHT are excluded so noise/anti-aliasing
     * pixels don't pollute matching.
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: float}>|null
     */
    private function palette_rgb_from_file( string $path, int $top_n ): ?array {
        $buckets = $this->collect_buckets( $path );
        if ( $buckets === null || empty( $buckets ) ) {
            return null;
        }
        usort( $buckets, static fn( $a, $b ) => $b[0] - $a[0] );
        $total = 0;
        foreach ( $buckets as $b ) $total += $b[0];
        $total = max( 1, $total );

        $palette = [];
        $extremes_only = true;
        foreach ( $buckets as $b ) {
            if ( count( $palette ) >= $top_n ) break;
            $count  = $b[0];
            $weight = $count / $total;
            if ( $weight < self::PALETTE_MIN_WEIGHT ) break; // sorted desc — rest are too small too

            $avgR = (int) round( $b[1] / $count );
            $avgG = (int) round( $b[2] / $count );
            $avgB = (int) round( $b[3] / $count );

            $isNearWhite = $avgR > 245 && $avgG > 245 && $avgB > 245;
            $isNearBlack = $avgR <  10 && $avgG <  10 && $avgB <  10;
            if ( $isNearWhite || $isNearBlack ) {
                // Hold onto extremes — only include them if we end up with
                // nothing else.
                continue;
            }
            $extremes_only = false;
            $palette[] = [ $avgR, $avgG, $avgB, $weight ];
        }

        if ( empty( $palette ) ) {
            // Fall back to the dominant (which honors majority-extreme).
            $top = $this->dominant_rgb_from_file( $path );
            if ( $top === null ) return null;
            return [ [ $top[0], $top[1], $top[2], 1.0 ] ];
        }

        return $palette;
    }

    /**
     * sRGB → linear RGB → XYZ (D65) → LAB (D65). Same math as the JS side.
     *
     * @return array{L: float, a: float, b: float}
     */
    public static function rgb_to_lab( int $r8, int $g8, int $b8 ): array {
        $r = $r8 / 255.0;
        $g = $g8 / 255.0;
        $b = $b8 / 255.0;

        $lin = static fn( float $c ): float =>
            $c <= 0.04045 ? $c / 12.92 : (float) pow( ( $c + 0.055 ) / 1.055, 2.4 );

        $R = $lin( $r );
        $G = $lin( $g );
        $B = $lin( $b );

        $X = $R * 0.4124564 + $G * 0.3575761 + $B * 0.1804375;
        $Y = $R * 0.2126729 + $G * 0.7151522 + $B * 0.0721750;
        $Z = $R * 0.0193339 + $G * 0.1191920 + $B * 0.9503041;

        $Xn = 0.95047;
        $Yn = 1.00000;
        $Zn = 1.08883;

        $f = static fn( float $t ): float =>
            $t > 0.008856 ? (float) pow( $t, 1.0 / 3.0 ) : ( 7.787 * $t + 16.0 / 116.0 );

        $fx = $f( $X / $Xn );
        $fy = $f( $Y / $Yn );
        $fz = $f( $Z / $Zn );

        return [
            'L' => 116.0 * $fy - 16.0,
            'a' => 500.0 * ( $fx - $fy ),
            'b' => 200.0 * ( $fy - $fz ),
        ];
    }

    /**
     * `#rrggbb` → LAB. Returns null on malformed input.
     *
     * @return array{L: float, a: float, b: float}|null
     */
    public static function hex_to_lab( string $hex ): ?array {
        if ( ! preg_match( '/^#([0-9a-f]{6})$/i', $hex, $m ) ) {
            return null;
        }
        $r = (int) hexdec( substr( $m[1], 0, 2 ) );
        $g = (int) hexdec( substr( $m[1], 2, 2 ) );
        $b = (int) hexdec( substr( $m[1], 4, 2 ) );
        return self::rgb_to_lab( $r, $g, $b );
    }
}
