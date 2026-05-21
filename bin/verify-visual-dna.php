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
        // Splash a 60×60 accent rectangle (~5% of the canvas).
        $a_rgb = [
            hexdec(substr($accent_hex, 1, 2)),
            hexdec(substr($accent_hex, 3, 2)),
            hexdec(substr($accent_hex, 5, 2)),
        ];
        $acc = imagecolorallocate($img, $a_rgb[0], $a_rgb[1], $a_rgb[2]);
        imagefilledrectangle($img, 10, 10, 70, 70, $acc);
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
        $assignments[$pid] = ['expected_hex' => $hex, 'label' => $label, 'kind' => 'solid'];
    }
}

foreach ($mixed_palette as [$primary, $accent, $label]) {
    $path = $generate_image($primary, $accent, $label);
    $pid  = $pids[$idx++];
    $att  = $sideload($path, $pid);
    if ($att) {
        set_post_thumbnail($pid, $att);
        $assignments[$pid] = ['expected_hex' => $primary, 'label' => $label, 'kind' => 'mixed'];
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

// ── Read back, compare ────────────────────────────────────────────────────

$rows = $wpdb->get_results(
    "SELECT object_id, lab_l, lab_a, lab_b FROM {$wpdb->prefix}hof_index WHERE facet_name='_visual_dna_lab' ORDER BY object_id ASC",
    ARRAY_A
);
$by_pid = [];
foreach ($rows as $r) {
    $by_pid[(int) $r['object_id']] = $r;
}

echo "\n" . str_repeat('─', 78) . "\n";
echo sprintf("%-25s %-9s %-9s %-9s %-9s %-9s %-9s %-7s\n",
    'label', 'L_exp', 'a_exp', 'b_exp', 'L_got', 'a_got', 'b_got', 'ΔE76');
echo str_repeat('─', 78) . "\n";

$pass = 0; $warn = 0; $fail = 0;
foreach ($assignments as $pid => $a) {
    $row = $by_pid[$pid] ?? null;
    if (!$row) {
        printf("%-25s  no LAB row written  ❌\n", $a['label']);
        $fail++;
        continue;
    }
    $exp = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab($a['expected_hex']);
    $dE  = sqrt(
        pow($row['lab_l'] - $exp['L'], 2) +
        pow($row['lab_a'] - $exp['a'], 2) +
        pow($row['lab_b'] - $exp['b'], 2)
    );
    // ΔE thresholds: <2 is imperceptible; <5 is fine; >5 looks off.
    $badge = $dE < 2 ? '✅' : ($dE < 5 ? '⚠️ ' : '❌');
    printf("%-25s %+8.2f %+8.2f %+8.2f %+8.2f %+8.2f %+8.2f  %5.2f %s\n",
        $a['label'],
        $exp['L'], $exp['a'], $exp['b'],
        $row['lab_l'], $row['lab_a'], $row['lab_b'],
        $dE, $badge
    );
    if ($dE < 2) $pass++; elseif ($dE < 5) $warn++; else $fail++;
}

echo str_repeat('─', 78) . "\n";
echo sprintf("Summary: %d pass, %d warn, %d fail (n=%d)\n", $pass, $warn, $fail, count($assignments));

// ── Endpoint smoke ────────────────────────────────────────────────────────

echo "\n--- /visual-dna ranking probe ---\n";
$probes = [
    ['#dc2626', 'red'],
    ['#2563eb', 'blue'],
    ['#16a34a', 'green'],
];
foreach ($probes as [$hex, $label]) {
    $lab = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab($hex);
    $top = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT object_id,
                    SQRT(POW(lab_l - %f, 2) + POW(lab_a - %f, 2) + POW(lab_b - %f, 2)) AS dE
             FROM {$wpdb->prefix}hof_index
             WHERE facet_name = '_visual_dna_lab'
             ORDER BY dE ASC
             LIMIT 1",
            $lab['L'], $lab['a'], $lab['b']
        ),
        ARRAY_A
    );
    $top_pid    = (int) $top['object_id'];
    $top_label  = $assignments[$top_pid]['label'] ?? '?';
    $expected   = $label;
    $match      = stripos($top_label, $expected) !== false ? '✅' : '❌';
    printf("  %s → top: pid %d (%s) dE=%.2f %s\n", $hex, $top_pid, $top_label, (float) $top['dE'], $match);
}
