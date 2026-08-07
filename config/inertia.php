<?php

/*
 * Only the page-location settings are overridden here; every other key falls back
 * to the package's own defaults, which the service provider merges in.
 *
 * WHY THIS FILE EXISTS AT ALL. The package looks for page components under
 * `resources/js/Pages`, capital P. This project's directory is `resources/js/pages`,
 * lower case, which is what `resources/js/app.tsx` globs — so the paths below are the
 * one place that difference is reconciled. Without it,
 * `AssertableInertia::component('Home')` fails with "page component file does not
 * exist" for a page that does exist, and the temptation is to switch the assertion off
 * rather than to fix the path.
 *
 * The `testing` block is written out in full, including the two keys that are identical
 * to the package's defaults. `mergeConfigFrom()` merges only at the top level, so a
 * partial `testing` array would REPLACE the package's — quietly dropping
 * `ensure_pages_exist` and every page extension.
 */

return [

    'page_paths' => [

        resource_path('js/pages'),

    ],

    'testing' => [

        // Left on, deliberately: it is what turns a mistyped component name in a
        // controller into a failing test rather than a blank page in a browser.
        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/pages'),

        ],

        'page_extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
