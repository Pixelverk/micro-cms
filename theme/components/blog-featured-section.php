<?php
// theme/components/blog-featured-section.php

return [

'label' => 'Blog Featured Post Section',
'schema' => [
    'title' => ['type' => 'text', 'label' => 'Section Title', 'required' => true, 'default' => 'Company Blog'],
    'post_id' => ['type' => 'text', 'label' => 'Featured Post ID (optional)', 'required' => false, 'default' => ''],
],
'children' => 'none',
'allowed_children' => [],
'css' => <<<CSS
.bg-featured-blog { min-height: 100%; background-position: center; background-size: cover; }
CSS,
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'blog-featured-' . uniqid();
    $posts = !empty($props['post_id']) ? [load_content_by_id((int) $props['post_id'])] : list_recent_content('blog_post', 1);
    $post = $posts[0] ?? null;
    if (!$post) return;

    $meta = $post['meta'] ?? [];
    $image = $meta['thumbnail'] ?? $meta['image'] ?? '700x350.png';
    $excerpt = $meta['excerpt'] ?? '';
    $slug = $post['slug'] ?? '';
    ?>
    <section id="<?= e($id) ?>" class="py-5">
        <div class="container px-5">
            <h2 class="fw-bolder fs-5 mb-4"><?= e($props['title'] ?? 'Company Blog') ?></h2>
            <div class="card border-0 shadow rounded-3 overflow-hidden">
                <div class="card-body p-0">
                    <div class="row gx-0">
                        <div class="col-lg-6 col-xl-5 py-lg-5">
                            <div class="p-4 p-md-5">
                                <div class="badge bg-primary bg-gradient rounded-pill mb-2">News</div>
                                <div class="h2 fw-bolder"><?= e($post['title']) ?></div>
                                <?php if ($excerpt): ?><p><?= e($excerpt) ?></p><?php endif; ?>
                                <a class="stretched-link text-decoration-none" href="<?= e(url('blog/' . $slug)) ?>">Read more <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                        <div class="col-lg-6 col-xl-7"><div class="bg-featured-blog" style="background-image: url('<?= e(resolve_image_value((string) $image, 1200)) ?>')"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
},
];