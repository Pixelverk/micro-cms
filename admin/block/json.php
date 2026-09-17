<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Block JSON endpoint
|--------------------------------------------------------------------------
| Used by the content editor to insert a saved block and to save the current
| component tree as a new one. Returns JSON; every request is CSRF-protected
| by the admin bootstrap except GET reads.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

if (!blocks_enabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'Blocks are disabled.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ----------------------------
// Read: list, or one block's tree
// ----------------------------
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $type = (string) ($_GET['type'] ?? 'page');

    if ($id > 0) {
        $block = load_block($id);

        if (!$block) {
            http_response_code(404);
            echo json_encode(['error' => 'Block not found.']);
            exit;
        }

        echo json_encode([
            'id'    => (int) $block['id'],
            'slug'  => $block['slug'],
            'label' => $block['label'],
            'tree'  => block_tree($block),
        ]);
        exit;
    }

    $blocks = array_map(static function (array $block): array {
        return [
            'id'    => (int) $block['id'],
            'slug'  => $block['slug'],
            'label' => $block['label'],
            'count' => blocks_count_components(block_tree($block)),
        ];
    }, blocks_for_type($type));

    echo json_encode(['blocks' => $blocks]);
    exit;
}

// ----------------------------
// Write: save the posted tree as a block
// ----------------------------
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_capability('content.create');

$tree = json_decode((string) ($_POST['tree'] ?? '[]'), true);

$result = save_block([
    'label'        => $_POST['label'] ?? '',
    'description'  => $_POST['description'] ?? '',
    'tree'         => is_array($tree) ? $tree : [],
    'content_type' => $_POST['content_type'] ?? 'page',
]);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $result['errors'] ?? []), 'fields' => $result['errors'] ?? []]);
    exit;
}

echo json_encode([
    'ok'    => true,
    'id'    => $result['id'],
    'slug'  => $result['slug'],
    'label' => (string) ($_POST['label'] ?? ''),
]);
