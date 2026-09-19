<?php
// theme/components/blog-stories-section.php

return [

'label' => 'Blog Featured Stories Section',
'schema' => [
    'title' => ['type' => 'text', 'label' => 'Section Title', 'required' => true, 'default' => 'Featured Stories'],
    'limit' => ['type' => 'text', 'label' => 'Number of Stories', 'required' => true, 'default' => '3'],
],
'children' => 'none',
'allowed_children' => [],
'css' => '',
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'blog-stories-' . uniqid();
    $posts = list_recent_content('blog_post', max(1, (int) ($props['limit'] ?? 3)));
    ?>
    <section id="<?= e($id) ?>" class="py-5">
        <div class="container px-5">
            <h2 class="fw-bolder fs-5 mb-4"><?= e($props['title'] ?? 'Featured Stories') ?></h2>
            <div class="row gx-5">
                <?php foreach ($posts as $post): ?>
                    <?php $meta = $post['meta'] ?? []; $image = $meta['thumbnail'] ?? $meta['image'] ?? '600x350.png'; $excerpt = $meta['excerpt'] ?? ''; ?>
                    <div class="col-lg-4 mb-5"><div class="card h-100 shadow border-0"><?= render_image($image, ['class' => 'card-img-top', 'alt' => $post['title']]) ?><div class="card-body p-4"><div class="badge bg-primary bg-gradient rounded-pill mb-2">News</div><a class="text-decoration-none link-dark stretched-link" href="<?= e(url('blog/' . $post['slug'])) ?>"><div class="h5 card-title mb-3"><?= e($post['title']) ?></div></a><p class="card-text mb-0"><?= e($excerpt) ?></p></div><div class="card-footer p-4 pt-0 bg-transparent border-top-0"><div class="small text-muted"><?= e(format_date((int) $post['published_at'])) ?></div></div></div></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
},
];