<?php
declare(strict_types=1);

// Ensure Storage Directories Exist
foreach (['', '/cache', '/media', '/logs'] as $dir) {
    $full = STORAGE_PATH . $dir;
    if (!is_dir($full)) mkdir($full, 0775, true);
}

$dbPath = STORAGE_PATH . '/data.sqlite';

if (file_exists($dbPath)) {
    echo 'First setup tried to run, but there is already a file at /storage/data.sqlite <br>';
    echo 'Either remove the file or set the value of "setup_completed" in config.php to "true" <br>';
    exit;
}

/*
|--------------------------------------------------------------------------
| Create SQLite Database
|--------------------------------------------------------------------------
*/
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
    parent_id INTEGER NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    layout TEXT,
    header TEXT,
    footer TEXT,
    meta JSON,
    body JSON NOT NULL,
    published_at INTEGER,
    scheduled_at INTEGER,
    created_by INTEGER NULL,
    updated_by INTEGER NULL,
    search_text TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    deleted_at INTEGER NULL,
    UNIQUE(type, parent_id, slug)
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_visibility ON content (type, status, published_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_parent ON content (parent_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_search ON content (search_text)");

$pdo->exec("
CREATE TABLE content_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL,
    version INTEGER NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    layout TEXT,
    header TEXT,
    footer TEXT,
    meta JSON,
    body JSON NOT NULL,
    published_at INTEGER,
    scheduled_at INTEGER,
    reason TEXT NOT NULL DEFAULT 'save',
    content_hash TEXT NOT NULL,
    created_by INTEGER NULL,
    created_at INTEGER NOT NULL,
    UNIQUE(content_id, version)
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_content_versions_item ON content_versions (content_id, version DESC)");

$pdo->exec("
CREATE TABLE activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    username TEXT NULL,
    action TEXT NOT NULL,
    object_type TEXT NULL,
    object_id INTEGER NULL,
    summary TEXT NULL,
    meta JSON NULL,
    ip TEXT NULL,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log (created_at DESC)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_object ON activity_log (object_type, object_id)");

// Traffic counting. No IP addresses are stored; the only visitor-level value
// is a per-day hash (see core/helpers/analytics.php).
$pdo->exec("
CREATE TABLE page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT NOT NULL,
    content_id INTEGER NULL,
    referrer_host TEXT NULL,
    ua_hash TEXT NULL,
    visitor_hash TEXT NOT NULL,
    is_bot INTEGER NOT NULL DEFAULT 0,
    cache_hit INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 200,
    viewed_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_time ON page_views (viewed_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_path ON page_views (path, viewed_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_page_views_visitor ON page_views (visitor_hash, viewed_at)");

// Old URLs that must keep working (see core/helpers/redirects.php).
$pdo->exec("
CREATE TABLE redirects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_path TEXT NOT NULL UNIQUE,
    to_path TEXT NOT NULL,
    status INTEGER NOT NULL DEFAULT 301,
    hits INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
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
    role TEXT NOT NULL DEFAULT 'admin',
    ui_language TEXT NULL,
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

$pdo->exec("
CREATE TABLE form_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_type TEXT NOT NULL,
    page_id INTEGER NULL,
    data TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new',
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    original_name TEXT NOT NULL,
    base_path TEXT NOT NULL,

    mime_type TEXT NOT NULL,
    original_size INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,

    sizes_json TEXT NOT NULL,
    formats_json TEXT NOT NULL,
    lqip_base64 TEXT,

    title TEXT,
    alt_text TEXT,
    description TEXT,

    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
");

$pdo->exec("
CREATE TABLE taxonomy (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_type TEXT NOT NULL,
    content_type TEXT NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(taxonomy_type, slug)
);
");

$pdo->exec("
CREATE TABLE taxonomy_term_relationships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id INTEGER NOT NULL,
    taxonomy_id INTEGER NOT NULL,
    UNIQUE(content_type, content_id, taxonomy_id)
);
");

// Rate limiting: login lockouts and public-form throttling.
$pdo->exec("
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash TEXT NOT NULL UNIQUE,
    ip TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_attempt INTEGER NOT NULL,
    locked_until INTEGER NULL
);
");

$pdo->exec("
CREATE TABLE form_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_type TEXT NOT NULL,
    ip TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_form_rate_limits_lookup ON form_rate_limits (form_type, ip, created_at)");

// One-time password reset tokens: only the hash is stored, the row expires,
// and completing a reset consumes it.
$pdo->exec("
CREATE TABLE password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    ip TEXT NULL,
    expires_at INTEGER NOT NULL,
    used_at INTEGER NULL,
    created_at INTEGER NOT NULL
);
");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id)");

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
    'homepage_id'     => '1',
    'site_language'   => 'en',
    'timezone'        => 'Europe/Stockholm',
    'date_format'     => 'F j, Y',
    'admin_default_language' => 'en',
    'default_layout'  => 'default',
    'default_header'  => 'site-header',
    'default_footer'  => 'site-footer',
    'content_prefixes'=> [
        'page'           => '',
        'blog_post'      => 'blog',
        'portfolio_item' => 'portfolio'
    ],
    'menu_locations' => [
        'main' => 'main',
        'footer' => 'footer',
    ],
    'contact_email'   => 'test-admin@domain.com',
    'media_sizes' => [400, 800, 1200, 2000],
    'generate_webp' => true,
    'quality_webp' => 80,
    'strip_metadata' => true,
];

$stmt = $pdo->prepare("
    INSERT INTO settings (`key`, `value`, updated_at)
    VALUES (:key, :value, :updated_at)
    ON CONFLICT(`key`) DO NOTHING
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
    'label'  => 'Main Menu',
    'slug'  => 'main',
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
            'label'  => 'About',
            'target' => '_self',
            'children' => [],
            'slug'   => 'about',
        ],
        [
            'type'   => 'page',
            'label'  => 'Contact',
            'target' => '_self',
            'children' => [],
            'slug'   => 'contact',
        ],
        [
            'type'   => 'page',
            'label'  => 'Pricing',
            'target' => '_self',
            'children' => [],
            'slug'   => 'pricing',
        ],
        [
            'type'   => 'page',
            'label'  => 'FAQ',
            'target' => '_self',
            'children' => [],
            'slug'   => 'faq',
        ],
        [
            'type'   => 'url',
            'label'  => 'Blog',
            'target' => '_self',
            'children' => [
                [
                    'type' => 'url',
                    'label' => 'Blog Home',
                    'target' => '_self',
                    'children' => [],
                    'slug' => '/blog',
                ],
                [
                    'type' => 'url',
                    'label' => 'Blog Post',
                    'target' => '_self',
                    'children' => [],
                    'slug' => '/blog/welcome-to-our-blog',
                ],
            ],
            'slug'   => '#',
        ],
        [
            'type'   => 'url',
            'label'  => 'Portfolio',
            'target' => '_self',
            'children' => [
                [
                    'type' => 'url',
                    'label' => 'Portfolio Overview',
                    'target' => '_self',
                    'children' => [],
                    'slug' => '/portfolio',
                ],
                [
                    'type' => 'url',
                    'label' => 'Portfolio Item',
                    'target' => '_self',
                    'children' => [],
                    'slug' => '/portfolio/project-one',
                ],
            ],
            'slug'   => '#',
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

$footerMenu = [
    'label' => 'Footer Menu',
    'slug' => 'footer',
    'items' => [
        ['type' => 'page', 'label' => 'Privacy', 'target' => '_self', 'children' => [], 'slug' => 'privacy'],
        ['type' => 'url', 'label' => 'Terms', 'target' => '_self', 'children' => [], 'slug' => '#'],
        ['type' => 'page', 'label' => 'Contact', 'target' => '_self', 'children' => [], 'slug' => 'contact'],
    ],
    'updated_at' => $now,
];

$stmt->execute([
    'label' => $footerMenu['label'],
    'slug' => $footerMenu['slug'],
    'items' => json_encode($footerMenu['items'], JSON_THROW_ON_ERROR),
    'updated_at' => $footerMenu['updated_at'],
]);

/*
|--------------------------------------------------------------------------
| Insert Homepage page content
|--------------------------------------------------------------------------
*/

$homepage = [
    "type" => "page",
    "slug" => "home",
    "parent_id" => null,
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
                "title" => "A Bootstrap 5 template for modern businesses",
                "subtitle" => "Quickly design and customize responsive mobile-first sites with Bootstrap, the world's most popular front-end open source toolkit!",
                "image" => "600x400.png",
                "btn1_url" => "#features",
                "btn1_text" => "Get Started",
                "btn2_url" => "#",
                "btn2_text" => "Learn More"
            ],
            "children" => []
        ],
        [
            "type" => "features-section",
            "props" => [
                "title" => "A better way to start building.",
            ],
            "children" => [
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Fast Delivery",
                        "text" => "We deliver your project on time.",
                        "icon" => "bi-collection"
                    ],
                    "children" => []
                ],
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Proven Results",
                        "text" => "We focus on outcomes, not buzzwords.",
                        "icon" => "bi-building"
                    ],
                    "children" => []
                ],
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Proven Results",
                        "text" => "We focus on outcomes, not buzzwords.",
                        "icon" => "bi-toggles2"
                    ],
                    "children" => []
                ],
                [
                    "type" => "feature-card",
                    "props" => [
                        "title" => "Clear Communication",
                        "text" => "You always know what’s happening.",
                        "icon" => "bi-toggles2"
                    ],
                    "children" => []
                ]
            ]
        ],
        [
            "type" => "testimonial-section",
            "props" => [
                "quote" => "Working with Start Bootstrap templates has saved me tons of development time when building new projects! Starting with a Bootstrap template just makes things easier!",
                "name" => "Mr Mister",
                "job" => "Boss, BigCompany",
                "img" => "40x40.png"
            ],
            "children" => []
        ],
        [
            "type" => "blog-preview-section",
            "props" => [
                "title" => "From our blog",
                "subTitle" => "Lorem ipsum, dolor sit amet consectetur adipisicing elit. Eaque fugit ratione dicta mollitia. Officiis ad.",
            ],
            "children" => [
                [
                    "type" => "blog-card",
                    "props" => [
                        "title" => "Blog post title",
                        "text" => "Some quick example text to build on the card title and make up the bulk of the card content.",
                        "img" => "600x350.png",
                        "img2" => "40x40.png",
                    ],
                    "children" => []
                ],
                [
                    "type" => "blog-card",
                    "props" => [
                        "title" => "Blog post title",
                        "text" => "Some quick example text to build on the card title and make up the bulk of the card content.",
                        "img" => "600x350.png",
                        "img2" => "40x40.png",
                    ],
                    "children" => []
                ],
                [
                    "type" => "blog-card",
                    "props" => [
                        "title" => "Blog post title",
                        "text" => "Some quick example text to build on the card title and make up the bulk of the card content.",
                        "img" => "600x350.png",
                        "img2" => "40x40.png",
                    ],
                    "children" => []
                ]
            ]
        ],
    ],
    "status" => "published",
    "created_at" => $now,
    "updated_at" => $now,
    "published_at" => $now
];

insert_seed_content($pdo, $homepage);

/**
 * Insert a content seed using the same JSON shape as the editor.
 */
function insert_seed_content(PDO $pdo, array $item): int
{
    $timestamp = time();
    $stmt = $pdo->prepare("
        INSERT INTO content (type, slug, parent_id, title, status, layout, header, footer, meta, body, created_at, updated_at, published_at)
        VALUES (:type, :slug, :parent_id, :title, :status, :layout, :header, :footer, :meta, :body, :created_at, :updated_at, :published_at)
    ");

    $stmt->execute([
        'type' => $item['type'],
        'slug' => $item['slug'],
        'parent_id' => $item['parent_id'] ?? null,
        'title' => $item['title'],
        'status' => $item['status'] ?? 'published',
        'layout' => $item['layout'] ?? 'default',
        'header' => $item['header'] ?? 'site-header',
        'footer' => $item['footer'] ?? 'site-footer',
        'meta' => json_encode($item['meta'] ?? [], JSON_THROW_ON_ERROR),
        'body' => json_encode($item['components'] ?? [], JSON_THROW_ON_ERROR),
        'created_at' => $item['created_at'] ?? $timestamp,
        'updated_at' => $item['updated_at'] ?? $timestamp,
        'published_at' => $item['published_at'] ?? $timestamp,
    ]);

    return (int) $pdo->lastInsertId();
}

/* Reference-style About page */
insert_seed_content($pdo, [
    'type' => 'page',
    'slug' => 'about',
    'title' => 'About Us',
    'meta' => ['description' => 'Our mission is to make building websites easier for everyone.'],
    'components' => [
        ['type' => 'about-hero-section', 'props' => [
            'title' => 'Our mission is to make building websites easier for everyone.',
            'subtitle' => 'Quality, functional website templates and themes should be available to everyone.',
            'button_url' => '#scroll-target',
            'button_text' => 'Read our story',
        ], 'children' => []],
        ['type' => 'about-feature-section', 'props' => [
            'title' => 'Our founding',
            'text' => 'We started with a simple goal: make dependable web tools easier to use for small teams and growing businesses.',
            'image' => '600x400.png',
            'image_alt' => 'Our founding team at work',
            'image_position' => 'left',
            'background' => 'light',
        ], 'children' => []],
        ['type' => 'about-feature-section', 'props' => [
            'title' => 'Growth & beyond',
            'text' => 'Today we keep improving the tools, systems, and support that help people turn good ideas into useful websites.',
            'image' => '600x400.png',
            'image_alt' => 'A team planning the next project',
            'image_position' => 'right',
            'background' => 'white',
        ], 'children' => []],
        ['type' => 'team-section', 'props' => [
            'title' => 'Our team',
            'subtitle' => 'Dedicated to quality and your success',
            'background' => 'light',
        ], 'children' => [
            ['type' => 'team-member', 'props' => ['name' => 'Ibbie Eckart', 'role' => 'Founder & CEO', 'image' => '150x150.png'], 'children' => []],
            ['type' => 'team-member', 'props' => ['name' => 'Arden Vasek', 'role' => 'CFO', 'image' => '150x150.png'], 'children' => []],
            ['type' => 'team-member', 'props' => ['name' => 'Toribio Nerthus', 'role' => 'Operations Manager', 'image' => '150x150.png'], 'children' => []],
            ['type' => 'team-member', 'props' => ['name' => 'Malvina Cilla', 'role' => 'CTO', 'image' => '150x150.png'], 'children' => []],
        ]],
    ],
]);

/* Reference-style Pricing page */
insert_seed_content($pdo, [
    'type' => 'page',
    'slug' => 'pricing',
    'title' => 'Pricing',
    'meta' => ['description' => 'Simple pricing plans that grow with your business.'],
    'components' => [[
        'type' => 'pricing-section',
        'props' => ['title' => 'Pay as you grow', 'subtitle' => 'With our no hassle pricing plans'],
        'children' => [
            ['type' => 'pricing-plan', 'props' => ['name' => 'Free', 'price' => '$0', 'period' => '/ mo.', 'features' => "1 users\n5GB storage\nUnlimited public projects\nCommunity access\n-Unlimited private projects\n-Dedicated support\n-Free linked domain\n-Monthly status reports", 'button_url' => '#', 'button_text' => 'Choose plan', 'featured' => 'no'], 'children' => []],
            ['type' => 'pricing-plan', 'props' => ['name' => 'Pro', 'price' => '$9', 'period' => '/ mo.', 'features' => "5 users\n5GB storage\nUnlimited public projects\nCommunity access\nUnlimited private projects\nDedicated support\nFree linked domain\n-Monthly status reports", 'button_url' => '#', 'button_text' => 'Choose plan', 'featured' => 'yes'], 'children' => []],
            ['type' => 'pricing-plan', 'props' => ['name' => 'Enterprise', 'price' => '$49', 'period' => '/ mo.', 'features' => "Unlimited users\n5GB storage\nUnlimited public projects\nCommunity access\nUnlimited private projects\nDedicated support\nUnlimited linked domains\nMonthly status reports", 'button_url' => '#', 'button_text' => 'Choose plan', 'featured' => 'no'], 'children' => []],
        ],
    ]],
]);

/* Reference-style FAQ page */
insert_seed_content($pdo, [
    'type' => 'page',
    'slug' => 'faq',
    'title' => 'FAQ',
    'meta' => ['description' => 'Answers to frequently asked questions.'],
    'components' => [[
        'type' => 'faq-section',
        'props' => ['title' => 'Frequently Asked Questions', 'subtitle' => 'How can we help you?', 'heading' => 'Common Questions', 'contact_title' => 'Have more questions?', 'contact_email' => 'support@example.com'],
        'children' => [
            ['type' => 'faq-item', 'props' => ['question' => 'How do I get started?', 'answer' => 'Create a page, choose a layout, and add the components that match your content.', 'open' => 'yes'], 'children' => []],
            ['type' => 'faq-item', 'props' => ['question' => 'Can I change the page layout later?', 'answer' => 'Yes. Layouts control the page structure while your content remains editable in the CMS.', 'open' => 'no'], 'children' => []],
            ['type' => 'faq-item', 'props' => ['question' => 'Can I publish content later?', 'answer' => 'Yes. Content supports drafts, scheduled publishing, and published pages.', 'open' => 'no'], 'children' => []],
        ],
    ]],
]);

/* Reference-style Blog home page */
insert_seed_content($pdo, [
    'type' => 'page',
    'slug' => 'blog',
    'title' => 'Company Blog',
    'meta' => ['description' => 'News, updates, and stories from our team.'],
    'components' => [
        ['type' => 'blog-featured-section', 'props' => ['title' => 'Company Blog'], 'children' => []],
        ['type' => 'blog-news-section', 'props' => ['title' => 'News', 'limit' => '3', 'contact_email' => 'press@example.com'], 'children' => []],
        ['type' => 'blog-stories-section', 'props' => ['title' => 'Featured Stories', 'limit' => '3'], 'children' => []],
    ],
]);

/* Published demo blog posts */
insert_seed_content($pdo, [
    'type' => 'blog_post', 'slug' => 'welcome-to-our-blog', 'title' => 'Welcome to our blog',
    'layout' => 'blog', 'meta' => ['description' => 'A first look at what we are building.', 'excerpt' => 'A first look at the ideas, tools, and people behind our work.', 'thumbnail' => '900x400.png', 'author' => 'Valerie Luna', 'author_image' => '50x50.png'],
    'components' => [['type' => 'quill-editor', 'props' => ['content' => '<p>Welcome to our blog. We will share practical ideas, project notes, and useful resources here.</p><h2>What to expect</h2><p>Expect thoughtful writing about design, development, and the work of building better websites.</p>'], 'children' => []]],
]);
insert_seed_content($pdo, [
    'type' => 'blog_post', 'slug' => 'building-better-websites', 'title' => 'Building better websites',
    'layout' => 'blog', 'meta' => ['description' => 'A few principles for useful websites.', 'excerpt' => 'Good websites make complicated things feel clear.', 'thumbnail' => '600x350.png', 'author' => 'Kelly Rowan', 'author_image' => '40x40.png'],
    'components' => [['type' => 'quill-editor', 'props' => ['content' => '<p>The best websites respect their readers time. They make the next useful action easy to find.</p><p>That principle guides the way we design content, navigation, and reusable components.</p>'], 'children' => []]],
]);
insert_seed_content($pdo, [
    'type' => 'blog_post', 'slug' => 'a-practical-design-system', 'title' => 'A practical design system',
    'layout' => 'blog', 'meta' => ['description' => 'How reusable patterns make small sites stronger.', 'excerpt' => 'A small set of dependable patterns can carry a whole site.', 'thumbnail' => '600x350.png', 'author' => 'Josiah Barclay', 'author_image' => '40x40.png'],
    'components' => [['type' => 'quill-editor', 'props' => ['content' => '<p>A design system does not need to be enormous. It needs to be consistent, understandable, and easy to extend.</p><p>These CMS components are built around that idea.</p>'], 'children' => []]],
]);

/* Reference-style Portfolio overview and projects */
insert_seed_content($pdo, [
    'type' => 'page', 'slug' => 'portfolio', 'title' => 'Our Work',
    'meta' => ['description' => 'A selection of projects from our portfolio.'],
    'components' => [
        ['type' => 'portfolio-grid-section', 'props' => ['title' => 'Our Work', 'subtitle' => 'Company portfolio', 'limit' => '6'], 'children' => []],
        ['type' => 'portfolio-cta-section', 'props' => ['title' => "Let's build something together", 'button_url' => '/contact', 'button_text' => 'Contact us'], 'children' => []],
    ],
]);
insert_seed_content($pdo, [
    'type' => 'portfolio_item', 'slug' => 'project-one', 'title' => 'Project One', 'layout' => 'portfolio',
    'meta' => ['description' => 'A focused digital experience built for a growing team.', 'thumbnail' => '600x400.png', 'gallery' => ['1300x700.png', '600x400.png'], 'project_url' => '#'],
    'components' => [['type' => 'quill-editor', 'props' => ['content' => '<p>This project brought strategy, content, and a flexible publishing workflow together in one clear experience.</p>'], 'children' => []]],
]);
insert_seed_content($pdo, [
    'type' => 'portfolio_item', 'slug' => 'project-two', 'title' => 'Project Two', 'layout' => 'portfolio',
    'meta' => ['description' => 'A responsive platform designed around real user needs.', 'thumbnail' => '600x400.png', 'gallery' => ['1300x700.png', '600x400.png'], 'project_url' => '#'],
    'components' => [['type' => 'quill-editor', 'props' => ['content' => '<p>We shaped the project around accessible content, clear navigation, and a visual system that could grow over time.</p>'], 'children' => []]],
]);

/* Plain policy page */
insert_seed_content($pdo, [
    'type' => 'page', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'layout' => 'policy',
    'meta' => ['description' => 'How we collect, use, and protect information.'],
    'components' => [['type' => 'policy-section', 'props' => [], 'children' => [[
        'type' => 'quill-editor', 'props' => ['content' => '<h2>Overview</h2><p>This demo policy explains how a site can present its legal information in a simple, readable format.</p><h2>Information we collect</h2><p>We collect only the information needed to provide and improve the service.</p><h2>Contact</h2><p>Questions about this policy can be sent to privacy@example.com.</p>'], 'children' => [],
    ]]]],
]);

/*
|--------------------------------------------------------------------------
| Insert Services Page content
|--------------------------------------------------------------------------
*/

$servicesData = [
    'type'    => 'page',
    'slug'    => 'services',
    "parent_id" => null,
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

insert_seed_content($pdo, $servicesData);

/*
|--------------------------------------------------------------------------
| Insert Contact Page content
|--------------------------------------------------------------------------
*/

$contactData = [
    'type' => 'page',
    'slug' => 'contact',
    "parent_id" => null,
    'title' => 'Contact',
    'meta' => [
        'description' => 'Contact us to discuss your project or ask a question.'
    ],
    'layout' => 'default',
    'header' => 'site-header',
    'footer' => 'site-footer',
    'components' => [
        [
            'type' => 'contact-section',
            'props' => [
                'form_type' => 'contact',
                'title' => 'Get in touch',
                'description' => "We'd love to hear from you",
                'icon' => 'bi-envelope',
                'success_message' => 'Thanks! Your message has been sent.',
                'error_message' => 'Something went wrong. Please try again later.'
            ],
            'children' => []
        ],
        [
            'type' => 'contact-features-section',
            'props' => [
            ],
            'children' => [
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Chat with us',
                        'text' => 'Chat live with one of our support specialists.',
                        'icon' => 'bi-chat-dots',
                    ],
                    'children' => [],
                ],
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Ask the community',
                        'text' => 'Explore our community forums and communicate with other users.',
                        'icon' => 'bi-people',
                    ],
                    'children' => [],
                ],
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Support center',
                        'text' => "Browse FAQ's and support articles to find solutions.",
                        'icon' => 'bi-question-circle',
                    ],
                    'children' => [],
                ],
                [
                    'type' => 'feature-card',
                    'props' => [
                        'title' => 'Call us',
                        'text' => 'Call us during normal business hours at (555) 892-9403.',
                        'icon' => 'bi-telephone',
                    ],
                    'children' => [],
                ],
            ],
        ]
    ],
    'status' => 'published',
    'updated_at' => $now,
    'created_at' => $now,
    'published_at' => $now,
];

insert_seed_content($pdo, $contactData);

/*
|--------------------------------------------------------------------------
| Insert 404 Page content
|--------------------------------------------------------------------------
*/

$notFoundData = [
    'type' => 'page',
    'slug' => '404',
    "parent_id" => null,
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

insert_seed_content($pdo, $notFoundData);

/*
|--------------------------------------------------------------------------
| Build the search index
|--------------------------------------------------------------------------
| Seeds are inserted straight into the table, which bypasses save_content()
| and therefore the indexer. Build the index here so a fresh install is
| searchable without editing every item first.
*/

require_once CORE_PATH . '/helpers/common.php';
require_once CORE_PATH . '/helpers/search.php';
require_once CORE_PATH . '/db.php';

search_reindex_all();

/* update the config to say setup has been done */
function update_config_value(string $key, mixed $value): bool
{
    // Test runs must never rewrite the tracked config file.
    if (defined('CMS_SETUP_READONLY') && CMS_SETUP_READONLY) {
        return false;
    }

    $configFile = CMS_PATH . '/config.php';

    if (!is_writable($configFile)) {
        return false;
    }

    $contents = file_get_contents($configFile);
    if ($contents === false) {
        return false;
    }

    // Convert value to PHP literal
    $exported = var_export($value, true);

    // Replace only the specific key (top-level)
    $pattern = "/(['\"]" . preg_quote($key, '/') . "['\"]\s*=>\s*)([^,]+),/";
    $replacement = "$1{$exported},";

    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

    if ($count !== 1) {
        return false; // key not found or ambiguous
    }

    return file_put_contents($configFile, $updated, LOCK_EX) !== false;
}

update_config_value('setup_completed', true);

/*
|--------------------------------------------------------------------------
| First Run Complete
|--------------------------------------------------------------------------
*/

// Test runs seed the database and continue; a real first visit gets a message.
if (defined('CMS_SETUP_READONLY') && CMS_SETUP_READONLY) {
    return;
}

header('Refresh: 3');
echo('Initial setup completed, the page will now refresh.');
exit;