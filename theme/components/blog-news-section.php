<?php
// theme/components/blog-news-section.php

return [

'label' => 'Blog News Section',
'description' => "Recent posts in a column, with a sidebar card.",
'schema' => [
    'title' => ['type' => 'text', 'label' => 'Section Title', 'required' => true, 'default' => 'News'],
    'limit' => ['type' => 'text', 'label' => 'Number of Posts', 'required' => true, 'default' => '3'],
    'contact_email' => ['type' => 'email', 'label' => 'Press Email', 'required' => false, 'default' => 'press@example.com'],
],
'children' => 'none',
'allowed_children' => [],
'css' => '',
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'blog-news-' . uniqid();
    $limit = max(1, (int) ($props['limit'] ?? 3));
    $posts = list_recent_content('blog_post', $limit);
    ?>
    <section id="<?= e($id) ?>" class="py-5 bg-light">
        <div class="container px-5">
            <div class="row gx-5">
                <div class="col-xl-8">
                    <h2 class="fw-bolder fs-5 mb-4"><?= e($props['title'] ?? 'News') ?></h2>
                    <?php foreach ($posts as $post): ?>
                        <div class="mb-4">
                            <div class="small text-muted"><?= e(format_date((int) $post['published_at'])) ?></div>
                            <a class="link-dark" href="<?= e(url('blog/' . $post['slug'])) ?>"><h3><?= e($post['title']) ?></h3></a>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$posts): ?><p class="text-muted">No published posts yet.</p><?php endif; ?>
                </div>
                <div class="col-xl-4">
                    <div class="card border-0 h-100">
                        <div class="card-body p-4 d-flex align-items-center justify-content-center">
                            <div class="text-center"><div class="h6 fw-bolder">Contact</div><p class="text-muted mb-0">For press inquiries, email us at<br /><a href="mailto:<?= e($props['contact_email'] ?? '') ?>"><?= e($props['contact_email'] ?? '') ?></a></p></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
},
];