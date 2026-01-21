<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Create SQLite Database (First Run)
|--------------------------------------------------------------------------
*/

$dbPath = STORAGE_PATH . '/data.sqlite';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Create Tables
|--------------------------------------------------------------------------
*/

$pdo->exec("
CREATE TABLE content (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    slug TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    layout TEXT,
    header TEXT,
    footer TEXT,
    meta JSON,
    body JSON NOT NULL,
    published_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(type, slug)
);
");

$pdo->exec("
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    first_name TEXT,
    last_name TEXT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_login INTEGER
);
");

$pdo->exec("
CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    `key` TEXT NOT NULL UNIQUE,
    `value` TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE menus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    items JSON NOT NULL,
    updated_at INTEGER NOT NULL
);
");

// what time is it?
$now = time();

/*
|--------------------------------------------------------------------------
| Insert Initial Admin User
|--------------------------------------------------------------------------
*/

$defaultAdmin = [
    "username" => "demo",
    "first_name" => "Mister",
    "last_name" => "Administrator",
    "email" => "admin@example.com",
    "password" => password_hash('demo', PASSWORD_DEFAULT),
    "created" => $now,
    "last_login" => $now,
];

$stmt = $pdo->prepare("
INSERT INTO users (username, first_name, last_name, email, password_hash, created_at, last_login)
VALUES (:username, :first_name, :last_name, :email, :password_hash, :created_at, :last_login)
");

$stmt->execute([
    'username'      => $defaultAdmin["username"],
    'first_name'    => $defaultAdmin["first_name"],
    'last_name'     => $defaultAdmin["last_name"],
    'email'         => $defaultAdmin["email"],
    'password_hash' => $defaultAdmin["password"],
    'created_at'    => $defaultAdmin["created"],
    'last_login'    => $defaultAdmin["last_login"],
]);

/*
|--------------------------------------------------------------------------
| Insert Default Settings
|--------------------------------------------------------------------------
*/

$settings = [
    'site_title'      => 'Awesome site',
    'homepage_slug'   => 'home',
    'site_language'   => 'en',
    'default_layout'  => 'default',
    'default_header'  => 'site-header',
    'default_footer'  => 'site-footer',
    'content_prefixes'=> json_encode([
        'page'           => '',
        'blog_post'      => 'blawg',
        'portfolio_item' => 'portfolio'
    ], JSON_THROW_ON_ERROR),
    'contact_email'   => 'test-admin@domain.com',
];

$stmt = $pdo->prepare("
    INSERT INTO settings (`key`, `value`, updated_at)
    VALUES (:key, :value, :updated_at)
");

foreach ($settings as $key => $value) {
    $stmt->execute([
        'key'        => $key,
        'value'      => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
        'updated_at' => $now,
    ]);
}

/*
|--------------------------------------------------------------------------
| Insert Default Menu
|--------------------------------------------------------------------------
*/

$menu = [
    'label'  => 'My Menu #1',
    'slug'  => 'header1',
    'items' => [
        [
            'type'   => 'url',
            'label'  => 'Home',
            'target' => '_self',
            'children' => [],
            'slug'   => '/',
        ],
        [
            'type'   => 'page',
            'label'  => 'Services',
            'target' => '_self',
            'children' => [],
            'slug'   => 'services',
        ],
        [
            'type'   => 'page',
            'label'  => 'Contact',
            'target' => '_self',
            'children' => [],
            'slug'   => 'contact',
        ],
    ],
    "updated_at" => $now,
];

$stmt = $pdo->prepare("
    INSERT INTO menus (label, slug, items, updated_at)
    VALUES (:label, :slug, :items, :updated_at)
");

$stmt->execute([
    'label'      => $menu['label'],
    'slug'       => $menu['slug'],
    'items'      => json_encode($menu['items'], JSON_THROW_ON_ERROR),
    'updated_at' => $menu['updated_at'],
]);

/*
|--------------------------------------------------------------------------
| Insert Homepage page content
|--------------------------------------------------------------------------
*/

$homepage = [
    "type" => "page",
    "slug" => "home",
    "title" => "Home",
    "meta" => [
        "description" => "Helping your business shrink with strategic consulting."
    ],
    "layout" => "default",
    "header" => "site-header",
    "footer" => "site-footer",
    "components" => [
        [
            "type" => "hero-section",
            "props" => [
                "title" => "Helping Your Business Shrink",
                "subtitle" => "Strategic consulting, clear direction, and measurable results.",
                "image" => "placeholder.png"
            ],
            "children" => []
        ],
        [
            "type" => "features-section",
            "props" => [],
            "children" => [
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Fast Delivery",
                        "text" => "We deliver your project on time.",
                        "icon" => "🚀",
                        "image" => "placeholder.png"
                    ],
                    "children" => []
                ],
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Proven Results",
                        "text" => "We focus on outcomes, not buzzwords.",
                        "icon" => "📈",
                        "image" => "placeholder.png"
                    ],
                    "children" => []
                ],
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Clear Communication",
                        "text" => "You always know what’s happening.",
                        "icon" => "💬",
                        "image" => "placeholder.png"
                    ],
                    "children" => []
                ]
            ]
        ],
        [
            "type" => "cta-section",
            "props" => [
                "title" => "Ready to take the next step?",
                "text" => "Let’s talk about your project.",
                "url" => "/contact/",
                "linktext" => "Get in touch"
            ],
            "children" => []
        ],
        [
            "type" => "hero-section",
            "props" => [
                "title" => "Hero Two",
                "subtitle" => "It's another hero-section component, but CSS and JS will not load twice.",
                "image" => "placeholder.png"
            ],
            "children" => []
        ]
    ],
    "status" => "published",
    "created_at" => $now,
    "updated_at" => $now,
    "published_at" => $now
];

$stmt = $pdo->prepare("
INSERT INTO content (type, slug, title, status, layout, header, footer, meta, body, created_at, updated_at, published_at)
VALUES (:type, :slug, :title, :status, :layout, :header, :footer, :meta, :body, :created_at, :updated_at, :published_at)
");

$stmt->execute([
    'type' => $homepage['type'],
    'slug' => $homepage['slug'],
    'title' => $homepage['title'],
    'status' => $homepage['status'],
    'layout' => $homepage['layout'],
    'header' => $homepage['header'],
    'footer' => $homepage['footer'],
    'meta' => json_encode($homepage['meta'], JSON_THROW_ON_ERROR),
    'body' => json_encode($homepage['components'], JSON_THROW_ON_ERROR),
    'created_at' => $homepage['created_at'],
    'updated_at' => $homepage['updated_at'],
    'published_at' => $homepage['published_at'],
]);

/*
|--------------------------------------------------------------------------
| Insert Services Page content
|--------------------------------------------------------------------------
*/

$servicesData = [
    'type'    => 'page',
    'slug'    => 'services',
    'title'   => 'Services',
    'meta'    => [
        'description' => 'Our services and how we help your business grow.',
    ],
    'layout'  => 'default',
    'header'  => 'site-header',
    'footer'  => 'site-footer',
    'components' => [
        [
            'type' => 'hero-section',
            'props' => [
                'title' => 'Our Services',
                'subtitle' => 'Practical solutions designed to move your business forward.',
                'image' => 'placeholder.png',
            ],
            'children' => [],
        ],
        [
            'type' => 'features-section',
            'props' => [],
            'children' => [
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Strategy & Planning',
                        'text' => 'Clear roadmaps built around your real business goals.',
                        'icon' => '🧭',
                        'image' => 'placeholder.png',
                    ],
                    'children' => [],
                ],
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Execution & Delivery',
                        'text' => 'We turn plans into action and ship real results.',
                        'icon' => '⚙️',
                        'image' => 'placeholder.png',
                    ],
                    'children' => [],
                ],
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Review & Optimization',
                        'text' => 'Continuous improvement based on measurable outcomes.',
                        'icon' => '🔍',
                        'image' => 'placeholder.png',
                    ],
                    'children' => [],
                ],
            ],
        ],
        [
            'type' => 'cta-section',
            'props' => [
                'title' => 'Let’s work together',
                'text' => 'Tell us about your project and we’ll take it from there.',
                'url' => '/contact/',
                'linktext' => 'Get in touch',
            ],
            'children' => [],
        ],
    ],
    'status'     => 'published',
    'created_at' => $now,
    'updated_at' => $now,
    'published_at' => $now,
];

$stmt = $pdo->prepare("
INSERT INTO content (type, slug, title, status, layout, header, footer, meta, body, created_at, updated_at, published_at)
VALUES (:type, :slug, :title, :status, :layout, :header, :footer, :meta, :body, :created_at, :updated_at, :published_at)
");

$stmt->execute([
    'type' => $servicesData['type'],
    'slug' => $servicesData['slug'],
    'title' => $servicesData['title'],
    'status' => $servicesData['status'],
    'layout' => $servicesData['layout'],
    'header' => $servicesData['header'],
    'footer' => $servicesData['footer'],
    'meta' => json_encode($servicesData['meta'], JSON_THROW_ON_ERROR),
    'body' => json_encode($servicesData['components'], JSON_THROW_ON_ERROR),
    'created_at' => $servicesData['created_at'],
    'updated_at' => $servicesData['updated_at'],
    'published_at' => $servicesData['published_at'],
]);

/*
|--------------------------------------------------------------------------
| Insert Contact Page content
|--------------------------------------------------------------------------
*/

$contactData = [
    'type' => 'page',
    'slug' => 'contact',
    'title' => 'Contact',
    'meta' => [
        'description' => 'Contact us to discuss your project or ask a question.'
    ],
    'layout' => 'default',
    'header' => 'site-header',
    'footer' => 'site-footer',
    'components' => [
        [
            'type' => 'hero-section',
            'props' => [
                'title' => 'Contact Us',
                'subtitle' => 'We’d love to hear about your goals and challenges.',
                'image' => 'placeholder.png'
            ],
            'children' => []
        ],
        [
            'type' => 'cta-section',
            'props' => [
                'title' => 'Start the conversation',
                'text' => 'Reach out by email and we’ll get back to you shortly.',
                'url' => 'mailto:hello@example.com',
                'linktext' => 'Email us'
            ],
            'children' => []
        ],
        [
            'type' => 'contact-section',
            'props' => [
                'title' => 'Contact Us',
                'description' => 'Send us a message and we will get back to you.',
                'success_message' => 'Thanks! Your message has been sent.',
                'error_message' => 'Something went wrong. Please try again later.'
            ],
            'children' => []
        ]
    ],
    'status' => 'published',
    'updated_at' => $now,
    'created_at' => $now,
    'published_at' => $now,
];

$stmt = $pdo->prepare("
INSERT INTO content (type, slug, title, status, layout, header, footer, meta, body, created_at, updated_at, published_at)
VALUES (:type, :slug, :title, :status, :layout, :header, :footer, :meta, :body, :created_at, :updated_at, :published_at)
");

$stmt->execute([
    'type' => $contactData['type'],
    'slug' => $contactData['slug'],
    'title' => $contactData['title'],
    'status' => $contactData['status'],
    'layout' => $contactData['layout'],
    'header' => $contactData['header'],
    'footer' => $contactData['footer'],
    'meta' => json_encode($contactData['meta'], JSON_THROW_ON_ERROR),
    'body' => json_encode($contactData['components'], JSON_THROW_ON_ERROR),
    'created_at' => $contactData['created_at'],
    'updated_at' => $contactData['updated_at'],
    'published_at' => $contactData['published_at'],
]);

/*
|--------------------------------------------------------------------------
| Insert 404 Page content
|--------------------------------------------------------------------------
*/

$notFoundData = [
    'type' => 'page',
    'slug' => '404',
    'title' => '404',
    'meta' => [
        'description' => 'The page you are looking for could not be found.'
    ],
    'layout' => 'default',
    'header' => 'site-header',
    'footer' => 'site-footer',
    'components' => [
        [
            'type' => 'hero-section',
            'props' => [
                'title' => '404 – Not found',
                'subtitle' => 'There could be a page here, but we didn’t make one. Sorry!',
                'image' => 'placeholder.png'
            ],
            'children' => []
        ],
        [
            'type' => 'cta-section',
            'props' => [
                'title' => 'Try visiting the home page',
                'text' => 'It’s very nice.',
                'url' => '/',
                'linktext' => 'Go to Home Page'
            ],
            'children' => []
        ]
    ],
    'status' => 'published',
    'updated_at' => $now,
    'created_at' => $now,
    'published_at' => $now,
];

$stmt = $pdo->prepare("
INSERT INTO content (type, slug, title, status, layout, header, footer, meta, body, created_at, updated_at, published_at)
VALUES (:type, :slug, :title, :status, :layout, :header, :footer, :meta, :body, :created_at, :updated_at, :published_at)
");

$stmt->execute([
    'type' => $notFoundData['type'],
    'slug' => $notFoundData['slug'],
    'title' => $notFoundData['title'],
    'status' => $notFoundData['status'],
    'layout' => $notFoundData['layout'],
    'header' => $notFoundData['header'],
    'footer' => $notFoundData['footer'],
    'meta' => json_encode($notFoundData['meta'], JSON_THROW_ON_ERROR),
    'body' => json_encode($notFoundData['components'], JSON_THROW_ON_ERROR),
    'created_at' => $notFoundData['created_at'],
    'updated_at' => $notFoundData['updated_at'],
    'published_at' => $notFoundData['published_at'],
]);

/*
|--------------------------------------------------------------------------
| First Run Complete
|--------------------------------------------------------------------------
*/
header("Refresh: 0");
exit;
