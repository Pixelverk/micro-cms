<?php
declare(strict_types=1);

function render_children(array $children, array &$collectedJs = [], array &$collectedCss = []): void
{
    foreach ($children as $child) {
        $name = $child['type'] ?? $child['component'] ?? null;
        if (!$name) continue;

        $props = $child['props'] ?? [];

        // Merge nested children recursively
        if (!empty($child['children'])) {
            $props['children'] = $child['children'];
        }

        component($name, $props, $collectedJs, $collectedCss);
    }
}