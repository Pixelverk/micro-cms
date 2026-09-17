<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| In-app documentation
|--------------------------------------------------------------------------
| Editor guide, theme developer guide and reference, rendered from
| admin/partials/docs-content.php. No build step and no markdown parser.
*/

require_capability('docs.view');

// Content lives in a partial so it can be edited without touching the view.
require_once CMS_PATH . '/admin/partials/docs-content.php';

$docs = docs_content();

$tabs = array_keys($docs);
$activeTab = (string) ($_GET['tab'] ?? '');
$activeTab = in_array($activeTab, $tabs, true) ? $activeTab : $tabs[0];

// Anchor for a section within a tab, used by the help panels.
$activeSection = (string) ($_GET['section'] ?? '');

$tabUrl = static fn(string $tab, string $section = ''): string =>
    url('admin/docs') . '?' . http_build_query(array_filter(['tab' => $tab, 'section' => $section]));

/**
 * Render one content block.
 */
$renderBlock = static function (array $block): void {
    if (isset($block['p'])) {
        echo '<p>' . e((string) $block['p']) . '</p>';
        return;
    }

    if (isset($block['h'])) {
        echo '<h3>' . e((string) $block['h']) . '</h3>';
        return;
    }

    if (isset($block['ul']) && is_array($block['ul'])) {
        echo '<ul>';
        foreach ($block['ul'] as $item) {
            echo '<li>' . e((string) $item) . '</li>';
        }
        echo '</ul>';
        return;
    }

    if (isset($block['code'])) {
        echo '<pre class="docs-code"><code>' . e((string) $block['code']) . '</code></pre>';
        return;
    }

    if (isset($block['table']) && is_array($block['table'])) {
        echo '<table class="content-table docs-table"><tbody>';
        foreach ($block['table'] as $key => $value) {
            echo '<tr><th>' . e((string) $key) . '</th><td>' . e((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
        return;
    }
};

$page = $docs[$activeTab];

ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2><?= e(admin_trans('docs')) ?></h2>
        <p><?= e((string) $page['intro']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn-small btn-muted" href="<?= e(url('admin/dashboard')) ?>"><?= e(admin_trans('dashboard')) ?></a>
    </div>
</div>

<nav class="docs-tabs" aria-label="<?= e(admin_trans('docs')) ?>">
    <?php foreach ($docs as $tab => $tabPage): ?>
        <a href="<?= e($tabUrl($tab)) ?>" class="status-tab <?= $tab === $activeTab ? 'active' : '' ?>">
            <?= e((string) $tabPage['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="docs-layout">

    <nav class="docs-toc" aria-label="<?= e(admin_trans('on_this_page')) ?>">
        <h3><?= e(admin_trans('on_this_page')) ?></h3>
        <ul>
            <?php foreach (array_keys($page['sections']) as $sectionTitle): ?>
                <?php $anchor = sanitize_slug($sectionTitle); ?>
                <li>
                    <a href="#<?= e($anchor) ?>" class="<?= $activeSection === $anchor ? 'active' : '' ?>">
                        <?= e($sectionTitle) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="docs-body">
        <form class="docs-filter" method="get" action="<?= e(url('admin/docs')) ?>">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <input type="search" id="docs-filter" placeholder="<?= e(admin_trans('filter_docs')) ?>"
                   aria-label="<?= e(admin_trans('filter_docs')) ?>">
        </form>

        <?php foreach ($page['sections'] as $sectionTitle => $blocks): ?>
            <?php $anchor = sanitize_slug($sectionTitle); ?>
            <section id="<?= e($anchor) ?>" class="docs-section" data-docs-section>
                <h2><?= e($sectionTitle) ?></h2>
                <?php foreach ($blocks as $block) { $renderBlock($block); } ?>
            </section>
        <?php endforeach; ?>

        <p class="docs-empty" id="docs-empty" hidden><?= e(admin_trans('no_docs_match')) ?></p>
    </div>

</div>

<script>
// Client-side filter over the section headings and body text.
(() => {
    const input = document.getElementById('docs-filter');
    const sections = Array.from(document.querySelectorAll('[data-docs-section]'));
    const empty = document.getElementById('docs-empty');
    const toc = Array.from(document.querySelectorAll('.docs-toc a'));

    if (!input) return;

    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        let visible = 0;

        sections.forEach(section => {
            const match = term === '' || section.textContent.toLowerCase().includes(term);
            section.hidden = !match;
            if (match) visible++;
        });

        toc.forEach(link => {
            const target = document.querySelector(link.getAttribute('href'));
            link.parentElement.hidden = target ? target.hidden : false;
        });

        if (empty) empty.hidden = visible !== 0;
    });
})();
</script>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('docs')) ?></h3>
<p><?= e(admin_trans('docs_help')) ?></p>
<ul>
    <li><?= e(admin_trans('docs_editor_help')) ?></li>
    <li><?= e(admin_trans('docs_developer_help')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();

include CMS_PATH . '/admin/partials/layout.php';
