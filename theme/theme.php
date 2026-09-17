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
        'search' => 'Search Results Layout',
        'landing' => 'Landing Page Layout',
        'blog' => 'Blog Post Layout',
        'portfolio' => 'Portfolio Project Layout',
        'policy' => 'Policy Page Layout',
    ],

    'headers' => [
        'site-header' => 'Default Header',
    ],

    'footers' => [
        'site-footer' => 'Default Footer',
    ],

    'menu_locations' => [
        'main' => 'Main Menu',
        'footer' => 'Footer Menu',
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
                'blog-preview-section',
                'blog-card',
                'testimonial-section',
                'hero-section',
                'about-hero-section',
                'about-feature-section',
                'team-section',
                'team-member',
                'features-section',
                'feature-card',
                'cta-section',
                'contact-section',
                'contact-features-section',
                'pricing-section',
                'pricing-plan',
                'faq-section',
                'faq-item',
                'blog-featured-section',
                'blog-news-section',
                'blog-stories-section',
                'portfolio-grid-section',
                'portfolio-cta-section',
                'policy-section',
                'quill-editor',
            ],
            'url_prefix' => ''
        ],
        'blog_post' => [
            'label' => 'Blog Post',
            'default_layout' => 'blog',
            'default_header' => 'site-header',
            'default_footer' => 'site-footer',
            'available_components' => [
                // A post is written, not assembled: rich text is the main tool.
                'quill-editor',
                'blog-featured-section',
                'blog-news-section',
                'blog-stories-section',
            ],
            'url_prefix' => 'blog',
            'taxonomy_layout' => 'blog-archive',
        ],
        'portfolio_item' => [
            'label' => 'Portfolio Item',
            'default_layout' => 'portfolio',
            'default_header' => 'site-header',
            'default_footer' => 'site-footer',
            'available_components' => [
                'quill-editor',
            ],
            'url_prefix' => 'portfolio'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Available Form Types
    |--------------------------------------------------------------------------
    */
    'form_types' => [
        'contact' => [
            'label' => 'Contact',
            'fields' => [
                'name'    => ['type' => 'text', 'required' => true],
                'email'   => ['type' => 'email', 'required' => true],
                'phone'   => ['type' => 'tel', 'required' => true],
                'message' => ['type' => 'textarea', 'required' => true],
            ],
            'notification_email_setting' => 'contact_email',
            'store_submission' => true,
        ],
        'newsletter' => [
            'label' => 'Newsletter',
            'fields' => [
                'email' => ['type' => 'email', 'required' => true],
                'opt_in' => ['type' => 'checkbox', 'required' => false],
            ],
            'store_submission' => true,
        ]
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
    | utilities.css is the shared class layer (grid, spacing, cards, buttons).
    | style.css holds theme tokens and theme-wide rules. Per-component CSS is
    | collected by core/render.php and injected after both, so a component can
    | always override this layer.
    */
    'styles' => [
        'layout.css',
        'utilities.css?v=5',
        'style.css?v=5',
        'vendor/bootstrap-icons/bootstrap-icons.css',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scripts
    |--------------------------------------------------------------------------
    */
    'scripts' => [
        [
            'src'   => 'main.js?v=4',
            'defer' => true,
        ],
    ],
];