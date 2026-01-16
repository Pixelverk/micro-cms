<?php
// config / manifest for the theme, declare assets here.

return [

    /*
    |--------------------------------------------------------------------------
    | Theme meta
    |--------------------------------------------------------------------------
    */
    'name' => 'Default Theme',

    /*
    |--------------------------------------------------------------------------
    | Available Layouts / Headers  / Footers
    |--------------------------------------------------------------------------
    */
    'layouts' => [
        'default' => 'Default Layout',
        'landing' => 'Landing Page Layout',
    ],

    'headers' => [
        'site-header' => 'Default Header',
    ],

    'footers' => [
        'site-footer' => 'Default Footer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Layout / Header / Footer (user may override in site settings)
    |------------------------------------------------------------ --------------
    */
    'defaults' => [
        'layout' => 'default',        // layout file in /theme/layouts/
        'header' => 'site-header',    // component name for top of page
        'footer' => 'site-footer',    // component name for bottom of page
    ],

    /*
    |--------------------------------------------------------------------------
    | Head meta
    |--------------------------------------------------------------------------
    */
    'meta' => [
        'viewport' => 'width=device-width, initial-scale=1.0',
        'charset'  => 'UTF-8',
    ],

    /*
    |--------------------------------------------------------------------------
    | Icons
    |--------------------------------------------------------------------------
    */
    'icons' => [
        'favicon' => 'favicon.ico',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stylesheets (order matters)
    |--------------------------------------------------------------------------
    */
    'styles' => [
        'style.css',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scripts
    |--------------------------------------------------------------------------
    */
    'scripts' => [
        [
            'src'   => 'main.js',
            'defer' => true,
        ],
        [
            'src'   => 'vendor/instant-page.min.js',
            'defer' => true,
        ],
    ],
];