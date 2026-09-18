<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pager
|--------------------------------------------------------------------------
| Shared prev/next markup for listings. Included by a layout that has a
| $pagination array (the shape pagination_result() returns).
|
| The classes are the ones the search layout already uses, so a listing pager
| needs no new CSS. The page number is deliberately dropped on page 1 so the
| first page keeps its clean, cacheable URL.
|
| Expects:
| - $pagination (array) from pagination_result()
| - $pagerUrl   (string) base URL of the listing, without the page parameter
| - $pagerLabel (string, optional) aria-label for the nav element
*/

$pager = $pagination ?? null;

if (!is_array($pager) || (int) ($pager['pages'] ?? 1) <= 1) {
    return;
}

$pagerUrl   = $pagerUrl ?? url();
$pagerLabel = $pagerLabel ?? 'Listing pages';
$current    = (int) $pager['page'];
$last       = (int) $pager['pages'];
?>
<nav class="pagination search-pagination" aria-label="<?= e($pagerLabel) ?>">
    <?php if ($current > 1): ?>
        <a class="btn btn-outline-primary" href="<?= e(pagination_url($pagerUrl, $current - 1)) ?>">&larr; Previous</a>
    <?php endif; ?>

    <span class="text-muted">
        Page <?= $current ?> of <?= $last ?>
    </span>

    <?php if ($current < $last): ?>
        <a class="btn btn-outline-primary" href="<?= e(pagination_url($pagerUrl, $current + 1)) ?>">Next &rarr;</a>
    <?php endif; ?>
</nav>
