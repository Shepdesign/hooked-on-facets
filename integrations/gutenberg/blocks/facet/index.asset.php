<?php
/**
 * Dependency manifest for index.js (editor script).
 *
 * WordPress reads this sidecar to enqueue the right wp-* packages before
 * the block editor script executes.
 *
 * @package HookedOnFacets
 */

return [
    'dependencies' => [
        'wp-blocks',
        'wp-block-editor',
        'wp-components',
        'wp-element',
        'wp-i18n',
        'wp-server-side-render',
    ],
    'version'      => '0.1.0',
];
