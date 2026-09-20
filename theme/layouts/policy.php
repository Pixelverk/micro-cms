<?php
declare(strict_types=1);

/* Plain policy/information page layout */

component($headerComponent, [], $page, $collectedJs, $collectedCss);
?>
<main id="main-content">
    <article class="py-5">
        <div class="container px-5 my-5">
            <div class="row justify-content-center">
                <div class="col-lg-9 col-xl-8">
                    <header class="mb-5">
                        <h1 class="fw-bolder mb-3"><?= e($page['title'] ?? '') ?></h1>
                        <?php if (!empty($page['published_at'])): ?>
                            <p class="text-muted mb-0">Last updated <?= e(format_date((int) $page['published_at'])) ?></p>
                        <?php endif; ?>
                    </header>
                    <div class="policy-page-body">
                        <?php render_components($page['components'] ?? [], $page, $collectedJs, $collectedCss); ?>
                    </div>
                </div>
            </div>
        </div>
    </article>
</main>
<?php component($footerComponent, [], $page, $collectedJs, $collectedCss); ?>