<?php
/**
 * Seed — generates synthetic WooCommerce products for HOF benchmarking.
 *
 * Bulk-inserts via $wpdb in batches of 1000 to hit ~50k–100k products/sec on
 * dev hardware. Skips the wc_get_product()/WC_Product_Simple::save() path
 * entirely; we don't need legal WC objects, we need indexable rows.
 *
 * Each product gets:
 *   - post_type='product', post_status='publish'
 *   - _price / _regular_price / _stock_status / _sku / _manage_stock meta
 *   - _hof_brand / _hof_color / _hof_size meta (custom benchmark facets)
 *   - 1 product_cat term
 *   - 1–3 product_tag terms
 *   - product_type=simple term (if the taxonomy exists)
 *
 * **Assumes auto_increment_increment = 1**. Reads back the inserted ID range
 * via LAST_INSERT_ID() + offset. Won't work correctly on Galera or any
 * cluster where IDs aren't contiguous.
 *
 * Invoke via:
 *   wp eval "require '/path/to/seed-products.php'; hof_seed_products(100000);"
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hof_seed_products' ) ) :

/**
 * Insert $count synthetic products. Echoes progress to stdout.
 */
function hof_seed_products( int $count = 1000 ): int {
    global $wpdb;

    if ( ! taxonomy_exists( 'product_cat' ) ) {
        fwrite( STDERR, "Error: WooCommerce isn't active. Run `wp plugin activate woocommerce` first.\n" );
        return 0;
    }

    $start = microtime( true );

    echo "→ Ensuring taxonomy terms exist…\n";
    $cat_tts  = hof_seed_ensure_terms( 'product_cat', [
        'Apparel', 'Footwear', 'Outdoor', 'Sports', 'Tech', 'Books', 'Toys',
        'Home', 'Garden', 'Beauty', 'Health', 'Auto', 'Pet', 'Music',
        'Office', 'Kitchen', 'Travel', 'Gaming', 'Crafts', 'Baby',
    ] );
    $tag_tts  = hof_seed_ensure_terms( 'product_tag', [
        'sale', 'new', 'bestseller', 'limited', 'organic', 'handmade',
        'imported', 'recycled', 'premium', 'budget', 'gift', 'seasonal',
        'kids', 'adult', 'unisex', 'eco', 'wireless', 'usb-c', 'travel', 'durable',
    ] );
    $simple_tts = taxonomy_exists( 'product_type' )
        ? hof_seed_ensure_terms( 'product_type', [ 'simple' ] )
        : [];

    $brands = [ 'Acme', 'Brio', 'Chronos', 'Drift', 'Evergreen', 'Forge', 'Glide',
                'Helio', 'Iris', 'Junket', 'Kestrel', 'Lumen', 'Maple', 'Nova', 'Orbit',
                'Pyre', 'Quartz', 'Reef', 'Sable', 'Talon' ];
    $colors = [ 'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray', 'brown', 'pink', 'purple' ];
    $sizes  = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];

    $batch_size = 1000;
    $total      = 0;
    $now        = current_time( 'mysql' );
    $now_gmt    = current_time( 'mysql', 1 );

    // Suppress object-cache churn during the run.
    wp_suspend_cache_invalidation( true );

    for ( $batch = 0; $batch < (int) ceil( $count / $batch_size ); $batch++ ) {
        $this_batch = (int) min( $batch_size, $count - $total );
        if ( $this_batch <= 0 ) break;

        // ── Posts ────────────────────────────────────────────────────────
        $post_values = [];
        for ( $i = 0; $i < $this_batch; $i++ ) {
            $idx   = $total + $i + 1;
            $brand = $brands[ $idx % count( $brands ) ];
            $title = sprintf( '%s Product %d', $brand, $idx );

            $post_values[] = $wpdb->prepare(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $now, $now_gmt,
                '', // post_content
                $title,
                'publish', 'closed', 'closed',
                '', // post_excerpt
                sanitize_title( $title ) . '-' . $idx,
                $now, $now_gmt,
                'product'
            );
        }

        $wpdb->query( "INSERT INTO {$wpdb->posts}
            (post_date, post_date_gmt, post_content, post_title, post_status,
             comment_status, ping_status, post_excerpt, post_name,
             post_modified, post_modified_gmt, post_type)
            VALUES " . implode( ', ', $post_values ) );
        $first_id = (int) $wpdb->insert_id;

        // ── Meta + term_relationships ────────────────────────────────────
        $meta_values = [];
        $rel_values  = [];

        for ( $i = 0; $i < $this_batch; $i++ ) {
            $post_id = $first_id + $i;
            $idx     = $total + $i + 1;
            $price   = (float) ( 5 + ( $idx % 500 ) + ( ( $idx * 7 ) % 99 ) / 100 );
            $sku     = sprintf( 'HOF-%07d', $idx );
            $brand   = $brands[ $idx % count( $brands ) ];
            $color   = $colors[ $idx % count( $colors ) ];
            $size    = $sizes[ $idx % count( $sizes ) ];

            // WooCommerce essentials.
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_price',          (string) $price );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_regular_price',  (string) $price );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_stock_status',   'instock' );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_manage_stock',   'no' );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_sku',            $sku );
            // Benchmark facets.
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_hof_brand',      $brand );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_hof_color',      $color );
            $meta_values[] = $wpdb->prepare( '(%d, %s, %s)', $post_id, '_hof_size',       $size );

            // Category — one per product.
            $cat_tt = $cat_tts[ $idx % count( $cat_tts ) ];
            $rel_values[] = $wpdb->prepare( '(%d, %d, %d)', $post_id, $cat_tt, 0 );

            // Tags — 1–3 per product, deterministic but well-distributed.
            $tag_count = 1 + ( $idx % 3 );
            $seen      = [];
            for ( $t = 0; $t < $tag_count; $t++ ) {
                $tag_idx = ( $idx + $t * 7 ) % count( $tag_tts );
                if ( isset( $seen[ $tag_idx ] ) ) continue;
                $seen[ $tag_idx ] = true;
                $rel_values[] = $wpdb->prepare( '(%d, %d, %d)', $post_id, $tag_tts[ $tag_idx ], 0 );
            }

            // product_type=simple.
            foreach ( $simple_tts as $tt ) {
                $rel_values[] = $wpdb->prepare( '(%d, %d, %d)', $post_id, $tt, 0 );
            }
        }

        foreach ( array_chunk( $meta_values, 500 ) as $chunk ) {
            $wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $chunk ) );
        }
        foreach ( array_chunk( $rel_values, 500 ) as $chunk ) {
            $wpdb->query( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode( ', ', $chunk ) );
        }

        $total  += $this_batch;
        $elapsed = microtime( true ) - $start;
        $rate    = $total / max( $elapsed, 0.001 );
        printf( "  batch %d: %d products inserted (%.0f/sec)\n", $batch + 1, $total, $rate );
    }

    wp_suspend_cache_invalidation( false );

    // ── Update taxonomy counts (one query per taxonomy) ─────────────────
    echo "→ Updating taxonomy counts…\n";
    foreach ( [ 'product_cat', 'product_tag', 'product_type' ] as $tax ) {
        if ( ! taxonomy_exists( $tax ) ) continue;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->term_taxonomy} tt
             SET count = (SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)
             WHERE tt.taxonomy = %s",
            $tax
        ) );
    }

    wp_cache_flush();

    $elapsed = microtime( true ) - $start;
    printf(
        "✓ %d products in %.2fs (%.0f/sec)\n",
        $total,
        $elapsed,
        $total / max( $elapsed, 0.001 )
    );

    return $total;
}

/**
 * Ensure taxonomy terms exist; return their term_taxonomy_ids.
 *
 * @param string[] $names
 * @return int[]
 */
function hof_seed_ensure_terms( string $taxonomy, array $names ): array {
    $tts = [];
    foreach ( $names as $name ) {
        $existing = get_term_by( 'name', $name, $taxonomy );
        if ( $existing && ! is_wp_error( $existing ) ) {
            $tts[] = (int) $existing->term_taxonomy_id;
            continue;
        }

        $inserted = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $inserted ) ) {
            continue;
        }
        $tts[] = (int) $inserted['term_taxonomy_id'];
    }
    return $tts;
}

endif;
