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
    | Structured data
    |--------------------------------------------------------------------------
    | Emit JSON-LD in <head> (core/helpers/seo.php). The homepage also carries
    | an Organization entry.
    */
    'schema' => true,

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

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | The last fallback for a page's layout, header and footer: a page's own
    | value wins, then the content type, then the settings, then these. Every
    | read site ends its chain here, so all three must name something that
    | exists — the Health page reports it if not.
    */
    'defaults' => [
        'layout' => 'default',
        'header' => 'site-header',
        'footer' => 'site-footer',
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
                'blog-list-section',
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
                'quill-editor',
            ],
            // A post is written, not assembled: the editor shows this type's one
            // rich-text component as a single field and offers nothing to add.
            // The default is 'components', the Add component editor.
            'editor' => 'rich-text',
            'url_prefix' => 'blog',
            'taxonomy_layout' => 'blog-archive',
            // Presentation images the layout renders. Keys are stored in the
            // item's meta JSON and get a matching image field in the editor;
            // values may be media ids, theme filenames or absolute URLs.
            'images' => [
                'thumbnail' => ['label' => 'Featured image'],
            ],
        ],
        'portfolio_item' => [
            'label' => 'Portfolio Item',
            'default_layout' => 'portfolio',
            'default_header' => 'site-header',
            'default_footer' => 'site-footer',
            'available_components' => [
                'quill-editor',
            ],
            'editor' => 'rich-text',
            'url_prefix' => 'portfolio',
            'images' => [
                'thumbnail' => ['label' => 'Featured image'],
                'gallery'   => ['label' => 'Gallery', 'multiple' => true],
            ],
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
                'name'    => ['type' => 'text', 'label' => 'Your name', 'required' => true],
                'email'   => ['type' => 'email', 'label' => 'Email address', 'required' => true],
                'phone'   => ['type' => 'tel', 'label' => 'Phone', 'required' => true],
                'subject' => [
                    'type'     => 'select',
                    'label'    => 'Subject',
                    'required' => false,
                    'options'  => [
                        'general' => 'General enquiry',
                        'support' => 'Support',
                        'sales'   => 'Sales',
                    ],
                ],
                'reply_by' => [
                    'type'     => 'radio',
                    'label'    => 'Preferred reply',
                    'required' => false,
                    'options'  => [
                        'email' => 'Email',
                        'phone' => 'Phone',
                    ],
                ],
                'message' => ['type' => 'textarea', 'label' => 'Message', 'required' => true],
            ],
            'notification_email_setting' => 'contact_email',
            'store_submission' => true,
        ],
        'newsletter' => [
            'label' => 'Newsletter',
            'fields' => [
                'email'  => ['type' => 'email', 'label' => 'Email address', 'required' => true],
                'opt_in' => ['type' => 'checkbox', 'label' => 'Yes, send me the newsletter', 'required' => false],
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
        // The colour the browser tints its chrome with, and the splash colour an
        // installed site starts from. Settings can override the first.
        'theme_color'      => '#212529',
        'background_color' => '#ffffff',
        // Optional: used when the visitor's system is in dark mode.
        'theme_color_dark' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Icons
    |--------------------------------------------------------------------------
    */
    'icons' => [
        'favicon' => 'favicon.ico',
        // Square PNGs an installed site uses: the manifest lists them, and the
        // largest is the apple touch icon. Replaced by an uploaded logo or
        // favicon when Settings has one.
        'app' => [
            'icon-192.png',
            'icon-512.png',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stylesheets (order matters)
    |--------------------------------------------------------------------------
    | utilities.css is the shared class layer (grid, spacing, cards, buttons).
    | style.css holds theme tokens and theme-wide rules. Per-component CSS is
    | collected by core/render.php and injected after both, so a component can
    | always override this layer.
    |
    | Each URL is stamped with its file's modification time (asset()), so an
    | edit needs no version bump here.
    */
    'styles' => [
        'layout.css',
        'utilities.css',
        'style.css',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scripts
    |--------------------------------------------------------------------------
    | src is stamped the same way as the stylesheets above.
    */
    'scripts' => [
        [
            'src'   => 'main.js',
            'defer' => true,
        ],
    ],
];