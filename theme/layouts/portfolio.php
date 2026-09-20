<?php
declare(strict_types=1);

/* Portfolio project layout */

component($headerComponent, [], $page, $collectedJs, $collectedCss);

$meta = $page['meta'] ?? [];
$description = $meta['description'] ?? $meta['excerpt'] ?? '';

// The gallery is what the project page shows, in the order the editor set it:
// the first image spans the page and the rest sit two to a row. The thumbnail
// is the listing image the portfolio grid and cards use, so it only stands in
// when there is no gallery to show.
$images = $meta['gallery'] ?? [];

if (!is_array($images)) {
    $images = [$images];
}

$images = array_values(array_filter($images));

if (!$images) {
    $images = array_values(array_filter([(string) ($meta['thumbnail'] ?? $meta['image'] ?? '')]));
}
?>
<main id="main-content">
    <section class="py-5">
        <div class="container px-5 my-5">
            <div class="row gx-5 justify-content-center">
                <div class="col-lg-6">
                    <div class="text-center mb-5">
                        <h1 class="fw-bolder"><?= e($page['title'] ?? '') ?></h1>
                        <?php if ($description): ?><p class="lead fw-normal text-muted mb-0"><?= e($description) ?></p><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($images): ?>
                <div class="row gx-5">
                    <?php foreach ($images as $index => $image): ?>
                        <div class="<?= $index === 0 ? 'col-12' : 'col-lg-6' ?>"><?= render_image($image, ['class' => 'img-fluid rounded-3 mb-5', 'alt' => $page['title'] ?? '']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="row gx-5 justify-content-center">
                <div class="col-lg-6">
                    <div class="mb-5">
                        <?php render_components($page['components'] ?? [], $page, $collectedJs, $collectedCss); ?>
                        <?php if (!empty($meta['project_url'])): ?><a class="text-decoration-none" href="<?= e($meta['project_url']) ?>">View project <i class="bi bi-arrow-right"></i></a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php component($footerComponent, [], $page, $collectedJs, $collectedCss); ?>