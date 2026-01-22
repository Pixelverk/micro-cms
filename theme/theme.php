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
    | Available Content Types
    |------------------------------------------------------------ --------------
    */
    'content_types' => [
        'page' => [
            'label' => 'Page',
            'default_layout' => 'default',
            'default_header' => 'site-header',
            'default_footer' => 'site-footer',
            'available_components' => [
                'hero-section',
                'features-section',
                'feature-card',
                'cta-section',
                'contact-section',
                'quill-editor',
            ],
            'url_prefix' => '',
        ],
        'blog_post' => [
            'label' => 'Blog Post',
            'default_layout' => 'blog',
            'default_header' => 'blog-header',
            'default_footer' => 'blog-footer',
            'available_components' => [
                'hero-section',
                'features-section',
                'feature-card',
                'cta-section',
            ],
            'url_prefix' => 'blog',
        ],
        'portfolio_item' => [
            'label' => 'Portfolio Item',
            'default_layout' => 'portfolio',
            'default_header' => 'portfolio-header',
            'default_footer' => 'portfolio-footer',
            'available_components' => [
                'hero-section',
                'features-section',
                'feature-card',
                'cta-section',
            ],
            'url_prefix' => 'portfolio',
        ],
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