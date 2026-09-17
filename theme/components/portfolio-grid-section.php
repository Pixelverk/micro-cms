<?php
// theme/components/portfolio-grid-section.php

return [

'label' => 'Portfolio Work Grid Section',
'schema' => [
    'title' => ['type' => 'text', 'label' => 'Section Title', 'required' => true, 'default' => 'Our Work'],
    'subtitle' => ['type' => 'text', 'label' => 'Section Subtitle', 'required' => false, 'default' => 'Company portfolio'],
    'limit' => ['type' => 'text', 'label' => 'Number of Projects', 'required' => true, 'default' => '6'],
],
'children' => 'none',
'allowed_children' => [],
'css' => '',
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'portfolio-grid-' . uniqid();
    $projects = list_recent_content('portfolio_item', max(1, (int) ($props['limit'] ?? 6)));
    ?>
    <section id="<?= e($id) ?>" class="py-5">
        <div class="container px-5 my-5">
            <div class="text-center mb-5">
                <h1 class="fw-bolder"><?= e($props['title'] ?? 'Our Work') ?></h1>
                <?php if (!empty($props['subtitle'])): ?><p class="lead fw-normal text-muted mb-0"><?= e($props['subtitle']) ?></p><?php endif; ?>
            </div>
            <div class="row gx-5">
                <?php foreach ($projects as $project): ?>
                    <?php $meta = $project['meta'] ?? []; $image = $meta['thumbnail'] ?? $meta['image'] ?? '600x400.png'; ?>
                    <div class="col-lg-6"><div class="position-relative mb-5"><img class="img-fluid rounded-3 mb-3" src="<?= e(img($image)) ?>" alt="<?= e($project['title']) ?>" /><a class="h3 fw-bolder text-decoration-none link-dark stretched-link" href="<?= e(url('portfolio/' . $project['slug'])) ?>"><?= e($project['title']) ?></a></div></div>
                <?php endforeach; ?>
                <?php if (!$projects): ?><p class="text-muted">No published projects yet.</p><?php endif; ?>
            </div>
        </div>
    </section>
    <?php
},
];