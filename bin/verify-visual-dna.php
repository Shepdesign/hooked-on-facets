<?php
/**
 * End-to-end verification of the Visual DNA v2 extractor.
 *
 *  1. Generate test PNGs (solid colors + a handful of mixed-color)
 *  2. Sideload each as a WP attachment
 *  3. Attach as the featured image of a distinct product
 *  4. Wipe prior hand-seeded _visual_dna_lab rows
 *  5. Run reindex_object on each affected product
 *  6. Diff extracted vs expected LAB via ΔE76
 *  7. Probe the /visual-dna endpoint internally and confirm ranking
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

global $wpdb;

// ── Test palette ──────────────────────────────────────────────────────────

// (hex, label)
$solid_palette = [
    ['#dc2626', 'red'],
    ['#2563eb', 'blue'],
    ['#16a34a', 'green'],
    ['#eab308', 'yellow'],
    ['#9333ea', 'purple'],
    ['#f97316', 'orange'],
    ['#ec4899', 'pink'],
    ['#92400e', 'brown'],
    ['#1e3a8a', 'navy'],
    ['#dc143c', 'crimson'],
    ['#7c3aed', 'violet'],
    ['#06b6d4', 'cyan'],
    ['#14b8a6', 'teal'],
    ['#84cc16', 'lime'],
    ['#f59e0b', 'amber'],
    ['#7f1d1d', 'maroon'],
    ['#ff7f50', 'coral'],
    ['#40e0d0', 'turquoise'],
    ['#d2b48c', 'tan'],
    ['#36454f', 'charcoal'],
];

// Mixed-color images — one dominant color + extreme accent. Dominant should win.
$mixed_palette = [
    // [dominant_hex, accent_hex, label]
    ['#dc2626', '#ffffff', 'red-with-white-accent'],   // white shouldn't win
    ['#16a34a', '#000000', 'green-with-black-accent'], // black shouldn't win
    ['#2563eb', '#ffd700', 'blue-with-gold-accent'],   // gold is bigger but not majority
    ['#7f1d1d', '#ffffff', 'maroon-with-white-accent'],
    ['#06b6d4', '#000000', 'cyan-with-black-accent'],
];

// ── Pick target products ──────────────────────────────────────────────────

$total = count($solid_palette) + count($mixed_palette);
$pids  = get_posts([
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => $total,
    'fields'         => 'ids',
    'orderby'        => 'ID',
    'order'          => 'ASC',
]);
if (count($pids) < $total) {
    fwrite(STDERR, "Need $total products, only " . count($pids) . " available\n");
    exit(1);
}

// ── Generate, sideload, attach ────────────────────────────────────────────

$upload_dir = wp_upload_dir();
$test_subdir = $upload_dir['basedir'] . '/hof-test-vdna';
if (!is_dir($test_subdir)) {
    wp_mkdir_p($test_subdir);
}

$generate_image = function (string $primary_hex, ?string $accent_hex, string $label) use ($test_subdir): string {
    $w = 256; $h = 256;
    $img = imagecreatetruecolor($w, $h);
    $pri_rgb = [
        hexdec(substr($primary_hex, 1, 2)),
        hexdec(substr($primary_hex, 3, 2)),
        hexdec(substr($primary_hex, 5, 2)),
    ];
    $pri = imagecolorallocate($img, $pri_rgb[0], $pri_rgb[1], $pri_rgb[2]);
    imagefill($img, 0, 0, $pri);

    if ($accent_hex !== null) {
        // 80×80 accent on a 256×256 canvas → ~9.8% of pixels. Comfortably
        // above the 5% palette-inclusion threshold but well below dominant.
        $a_rgb = [
            hexdec(substr($accent_hex, 1, 2)),
            hexdec(substr($accent_hex, 3, 2)),
            hexdec(substr($accent_hex, 5, 2)),
        ];
        $acc = imagecolorallocate($img, $a_rgb[0], $a_rgb[1], $a_rgb[2]);
        imagefilledrectangle($img, 10, 10, 90, 90, $acc);
    }

    $path = $test_subdir . '/' . $label . '-' . substr(md5($primary_hex . ($accent_hex ?? '')), 0, 8) . '.png';
    imagepng($img, $path);
    imagedestroy($img);
    return $path;
};

$sideload = function (string $path, int $post_id): int {
    $filename = basename($path);
    $upload   = wp_upload_dir();
    $dest     = $upload['path'] . '/' . wp_unique_filename($upload['path'], $filename);
    if (!@copy($path, $dest)) {
        return 0;
    }
    $attachment_id = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title'     => $filename,
        'post_status'    => 'inherit',
    ], $dest, $post_id);
    if (is_wp_error($attachment_id) || !$attachment_id) {
        return 0;
    }
    $meta = wp_generate_attachment_metadata($attachment_id, $dest);
    wp_update_attachment_metadata($attachment_id, $meta);
    return $attachment_id;
};

echo "Generating + attaching " . $total . " test images…\n";

$assignments = []; // pid → ['expected' => ['#hex'], 'label' => ...]
$idx = 0;

foreach ($solid_palette as [$hex, $label]) {
    $path = $generate_image($hex, null, $label);
    $pid  = $pids[$idx++];
    $att  = $sideload($path, $pid);
    if ($att) {
        set_post_thumbnail($pid, $att);
        $assignments[$pid] = [
            'expected_hex' => $hex,
            'expected_palette' => [$hex],
            'label' => $label, 'kind' => 'solid',
        ];
    }
}

foreach ($mixed_palette as [$primary, $accent, $label]) {
    $path = $generate_image($primary, $accent, $label);
    $pid  = $pids[$idx++];
    $att  = $sideload($path, $pid);
    if ($att) {
        set_post_thumbnail($pid, $att);
        // For mixed images, the palette extractor should keep the accent IFF
        // it isn't near-white/black. v3's PALETTE_MIN_WEIGHT (5%) is below
        // the accent's ~10% area so non-extreme accents should appear.
        $r = hexdec(substr($accent, 1, 2)); $g = hexdec(substr($accent, 3, 2)); $b = hexdec(substr($accent, 5, 2));
        $accent_kept = !($r > 245 && $g > 245 && $b > 245) && !($r < 10 && $g < 10 && $b < 10);
        $expected_palette = $accent_kept ? [$primary, $accent] : [$primary];
        $assignments[$pid] = [
            'expected_hex' => $primary,
            'expected_palette' => $expected_palette,
            'label' => $label, 'kind' => 'mixed',
        ];
    }
}

echo "  attached to " . count($assignments) . " products\n";

// ── Wipe prior LAB rows, reindex the affected products ────────────────────

$pid_list = implode(',', array_keys($assignments));
$wpdb->query("DELETE FROM {$wpdb->prefix}hof_index WHERE facet_name='_visual_dna_lab'");

$indexer = new \HookedOnFacets\Indexer();
echo "\nReindexing…\n";
$started = microtime(true);
foreach (array_keys($assignments) as $pid) {
    $indexer->reindex_object($pid, 'post');
}
$elapsed = microtime(true) - $started;
echo sprintf("  done in %.2fs (%d products)\n", $elapsed, count($assignments));

// ── Read back ─────────────────────────────────────────────────────────────

$rows = $wpdb->get_results(
    "SELECT object_id, lab_l, lab_a, lab_b, facet_numeric AS weight
     FROM {$wpdb->prefix}hof_index
     WHERE facet_name='_visual_dna_lab'
     ORDER BY object_id ASC, facet_numeric DESC",
    ARRAY_A
);
// pid → array of {L,a,b,weight} rows, ordered by weight desc.
$by_pid = [];
foreach ($rows as $r) {
    $by_pid[(int) $r['object_id']][] = [
        'L'      => (float) $r['lab_l'],
        'a'      => (float) $r['lab_a'],
        'b'      => (float) $r['lab_b'],
        'weight' => (float) $r['weight'],
    ];
}

// ── Section 1: dominant-color check (v2 contract) ─────────────────────────

echo "\n=== Section 1: dominant color (v2 contract) ===\n";
echo str_repeat('─', 78) . "\n";
echo sprintf("%-25s %-9s %-9s %-9s %-9s %-9s %-9s %-7s\n",
    'label', 'L_exp', 'a_exp', 'b_exp', 'L_got', 'a_got', 'b_got', 'ΔE76');
echo str_repeat('─', 78) . "\n";

$pass = 0; $warn = 0; $fail = 0;
foreach ($assignments as $pid => $a) {
    $palette = $by_pid[$pid] ?? null;
    if (!$palette) {
        printf("%-25s  no LAB row written  ❌\n", $a['label']);
        $fail++;
        continue;
    }
    $dominant = $palette[0]; // top-weighted
    $exp = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab($a['expected_hex']);
    $dE  = sqrt(
        pow($dominant['L'] - $exp['L'], 2) +
        pow($dominant['a'] - $exp['a'], 2) +
        pow($dominant['b'] - $exp['b'], 2)
    );
    $badge = $dE < 2 ? '✅' : ($dE < 5 ? '⚠️ ' : '❌');
    printf("%-25s %+8.2f %+8.2f %+8.2f %+8.2f %+8.2f %+8.2f  %5.2f %s\n",
        $a['label'],
        $exp['L'], $exp['a'], $exp['b'],
        $dominant['L'], $dominant['a'], $dominant['b'],
        $dE, $badge
    );
    if ($dE < 2) $pass++; elseif ($dE < 5) $warn++; else $fail++;
}
echo str_repeat('─', 78) . "\n";
echo sprintf("Dominant: %d pass, %d warn, %d fail (n=%d)\n", $pass, $warn, $fail, count($assignments));

// ── Section 2: palette completeness (v3 contract) ─────────────────────────

echo "\n=== Section 2: palette extraction (v3 contract) ===\n";
echo "  For each test image, every expected palette color must appear in\n";
echo "  the extracted palette within ΔE < 2.\n";
echo str_repeat('─', 78) . "\n";

$p_pass = 0; $p_fail = 0;
foreach ($assignments as $pid => $a) {
    $palette = $by_pid[$pid] ?? [];
    $expected = $a['expected_palette'];
    $misses = [];
    foreach ($expected as $exp_hex) {
        $exp_lab = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab($exp_hex);
        $closest = INF;
        foreach ($palette as $entry) {
            $dE = sqrt(
                pow($entry['L'] - $exp_lab['L'], 2) +
                pow($entry['a'] - $exp_lab['a'], 2) +
                pow($entry['b'] - $exp_lab['b'], 2)
            );
            if ($dE < $closest) $closest = $dE;
        }
        if ($closest >= 2.0) $misses[] = sprintf('%s (closest dE=%.2f)', $exp_hex, $closest);
    }
    $expected_count = count($expected);
    $actual_count   = count($palette);
    if (empty($misses)) {
        printf("  %-25s expected %d, got %d entries  ✅\n",
            $a['label'], $expected_count, $actual_count);
        $p_pass++;
    } else {
        printf("  %-25s missing %s  ❌\n", $a['label'], implode(', ', $misses));
        $p_fail++;
    }
}
echo str_repeat('─', 78) . "\n";
echo sprintf("Palette: %d pass, %d fail (n=%d)\n", $p_pass, $p_fail, count($assignments));

// ── Endpoint smoke ────────────────────────────────────────────────────────

// Helper mirroring the new endpoint logic: MIN over palette × query.
$rank_single = function (array $hex_list, int $limit = 5) use ($wpdb) {
    $exprs = []; $params = [];
    foreach ($hex_list as $h) {
        $lab = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab($h);
        if (!$lab) continue;
        $exprs[]  = "SQRT(POW(lab_l - %f, 2) + POW(lab_a - %f, 2) + POW(lab_b - %f, 2))";
        $params[] = $lab['L']; $params[] = $lab['a']; $params[] = $lab['b'];
    }
    if (empty($exprs)) return [];
    $row_de = count($exprs) === 1 ? $exprs[0] : ('LEAST(' . implode(', ', $exprs) . ')');
    $params[] = $limit;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT object_id, MIN({$row_de}) AS dE
         FROM {$wpdb->prefix}hof_index
         WHERE facet_name = '_visual_dna_lab'
         GROUP BY object_id
         ORDER BY dE ASC
         LIMIT %d",
        $params
    ), ARRAY_A) ?: [];
};

echo "\n=== Section 3: /visual-dna single-hex ranking ===\n";
$single_probes = [
    ['#dc2626', 'red'],
    ['#2563eb', 'blue'],
    ['#16a34a', 'green'],
];
foreach ($single_probes as [$hex, $label]) {
    $top = $rank_single([$hex], 1);
    if (!$top) {
        printf("  %s → no results ❌\n", $hex);
        continue;
    }
    $top_pid   = (int) $top[0]['object_id'];
    $top_label = $assignments[$top_pid]['label'] ?? '?';
    $match     = stripos($top_label, $label) !== false ? '✅' : '❌';
    printf("  %s → top: pid %d (%s) dE=%.2f %s\n", $hex, $top_pid, $top_label, (float) $top[0]['dE'], $match);
}

// v3 multi-color probe: a palette query containing a single dominant color
// should still pick the closest product. A query containing TWO colors
// should pick the product whose palette contains EITHER color.
echo "\n=== Section 4: /visual-dna multi-hex (palette) ranking ===\n";

// Pure red palette → red product first
$top = $rank_single(['#dc2626', '#dc2626'], 1);
if ($top) {
    $lbl = $assignments[(int) $top[0]['object_id']]['label'] ?? '?';
    printf("  {red, red} → top: %s dE=%.2f %s\n", $lbl, (float) $top[0]['dE'],
        stripos($lbl, 'red') !== false ? '✅' : '❌');
}

// Mixed-query: red + a color that exists only as an accent on one product.
// The mixed-color test set has 'blue-with-gold-accent' (#ffd700 gold).
// A query {#ffd700, #000000} should rank that product highly because gold
// is in its palette, even though the dominant is blue.
$top = $rank_single(['#ffd700'], 5);
echo "  Query {gold #ffd700} top 5:\n";
foreach ($top as $row) {
    $lbl = $assignments[(int) $row['object_id']]['label'] ?? '?';
    printf("    pid %d (%s) dE=%.2f\n", (int) $row['object_id'], $lbl, (float) $row['dE']);
}
$gold_top_label = $assignments[(int) $top[0]['object_id']]['label'] ?? '?';
$gold_match     = stripos($gold_top_label, 'gold') !== false ? '✅' : '❌';
printf("  → expecting 'blue-with-gold-accent' first; got '%s' %s\n", $gold_top_label, $gold_match);
