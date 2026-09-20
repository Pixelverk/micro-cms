<?php
declare(strict_types=1);

/* Blog post layout */

component($headerComponent, [], $page, $collectedJs, $collectedCss);

$meta = $page['meta'] ?? [];
$author = $meta['author'] ?? '';
$authorImage = $meta['author_image'] ?? '';
$thumbnail = $meta['thumbnail'] ?? $meta['image'] ?? '';
?>
<main id="main-content">
    <section class="py-5">
        <div class="container px-5 my-5">
            <div class="row gx-5">
                <?php if ($author): ?>
                    <div class="col-lg-3">
                        <div class="d-flex align-items-center mt-lg-5 mb-4">
                            <?php if ($authorImage): ?>
                                <?= render_image($authorImage, ['class' => 'img-fluid rounded-circle', 'alt' => $author]) ?>
                            <?php endif; ?>
                            <div class="ms-3">
                                <div class="fw-bold"><?= e($author) ?></div>
                                <?php if (!empty($meta['author_role'])): ?><div class="text-muted"><?= e($meta['author_role']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="<?= $author ? 'col-lg-9' : 'col-lg-10 offset-lg-1' ?>">
                    <article>
                        <header class="mb-4">
                            <h1 class="fw-bolder mb-1"><?= e($page['title'] ?? '') ?></h1>
                            <?php if (!empty($page['published_at'])): ?><div class="text-muted fst-italic mb-2"><?= e(format_date((int) $page['published_at'])) ?></div><?php endif; ?>
                            <?php foreach (($page['categories'] ?? []) as $category): ?><a class="badge bg-secondary text-decoration-none link-light me-1" href="<?= e(url('category/' . $category['slug'])) ?>"><?= e($category['name']) ?></a><?php endforeach; ?>
                            <?php foreach (($page['tags'] ?? []) as $tag): ?><a class="badge bg-secondary text-decoration-none link-light me-1" href="<?= e(url('tag/' . $tag['slug'])) ?>"><?= e($tag['name']) ?></a><?php endforeach; ?>
                        </header>
                        <?php if ($thumbnail !== ''): ?><figure class="mb-4"><?= render_image($thumbnail, ['class' => 'img-fluid rounded', 'alt' => $page['title'] ?? '']) ?></figure><?php endif; ?>
                        <section class="mb-5">
                            <?php render_components($page['components'] ?? [], $page, $collectedJs, $collectedCss); ?>
                        </section>
                    </article>
                </div>
            </div>
        </div>
    </section>
</main>
<?php

component($footerComponent, [], $page, $collectedJs, $collectedCss);