<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form submissions
|--------------------------------------------------------------------------
|
| The inbox's shared helpers: the status workflow, the filter that listing and
| export must agree on, paging, and the CSV's column discovery.
|
| The export download itself goes through the real request path, in
| tests/forms-http.test.php.
|
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Insert a submission directly, bypassing the form endpoint.
 *
 * @param array<string, mixed> $data
 */
function seed_submission(string $formType, array $data, ?string $status = null, ?int $createdAt = null): int
{
    $now = $createdAt ?? time();

    $columns = 'form_type, data, created_at, updated_at';
    $values  = ':form_type, :data, :created_at, :updated_at';
    $params  = [
        'form_type'  => $formType,
        'data'       => json_encode($data, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if ($status !== null) {
        $columns .= ', status';
        $values  .= ', :status';
        $params['status'] = $status;
    }

    db()->prepare("INSERT INTO form_submissions ({$columns}) VALUES ({$values})")->execute($params);

    return (int) db()->lastInsertId();
}

t('a fresh install seeds the status column with a default', function () {
    $columns = db()->query('PRAGMA table_info(form_submissions)')->fetchAll(PDO::FETCH_COLUMN, 1);

    assert_true(in_array('status', $columns, true), 'form_submissions has a status column');
    assert_true(in_array('status', $columns, true) && $columns !== [], 'column list is readable');

    // A row inserted without a status takes the default rather than NULL.
    $id = seed_submission('contact', ['name' => 'Default Status']);
    $row = db()->query("SELECT status FROM form_submissions WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

    assert_eq('new', $row['status']);
});

t('the migration exists for installs older than the column', function () {
    $registry = migrate_registry();
    $names = array_keys($registry);

    $found = array_values(array_filter($names, fn($name) => str_contains($name, 'form_submission_status')));
    assert_count(1, $found, 'exactly one migration adds the status column');
});

t('form_submission_statuses() lists the workflow and validates input', function () {
    assert_eq(['new', 'waiting', 'handled', 'spam'], form_submission_statuses());

    foreach (form_submission_statuses() as $status) {
        assert_eq($status, form_submission_status_valid($status));
        assert_true(form_submission_status_label($status) !== '', "{$status} has a label");
    }

    assert_eq(null, form_submission_status_valid('bogus'));
    assert_eq(null, form_submission_status_valid(''));
    assert_eq(null, form_submission_status_valid(null));
});

t('the filter ignores unknown form types and statuses', function () {
    $filter = form_submission_filter(['form' => 'nope', 'status' => 'bogus']);

    assert_eq('', $filter['sql'], 'an unknown filter is not applied');
    assert_count(0, $filter['params']);

    // A declared form type the theme knows about does filter.
    $filter = form_submission_filter(['form' => 'contact', 'status' => 'waiting']);
    assert_contains('form_type = :form_type', $filter['sql']);
    assert_contains('status = :status', $filter['sql']);
    assert_eq('contact', $filter['params']['form_type']);
    assert_eq('waiting', $filter['params']['status']);
});

t('counting and paging agree with the filter', function () {
    db()->exec('DELETE FROM form_submissions');

    $now = time();
    // 5 contact: 3 new, 2 handled. 2 newsletter, both new.
    foreach (range(1, 3) as $i) {
        seed_submission('contact', ['name' => "Contact new {$i}"], 'new', $now - $i);
    }
    foreach (range(1, 2) as $i) {
        seed_submission('contact', ['name' => "Contact done {$i}"], 'handled', $now - 10 - $i);
    }
    foreach (range(1, 2) as $i) {
        seed_submission('newsletter', ['email' => "news{$i}@example.com"], 'new', $now - 20 - $i);
    }

    assert_eq(7, form_submission_count());
    assert_eq(5, form_submission_count(['form' => 'contact']));
    assert_eq(5, form_submission_count(['status' => 'new']));
    assert_eq(3, form_submission_count(['form' => 'contact', 'status' => 'new']));
    assert_eq(2, form_submission_count(['form' => 'contact', 'status' => 'handled']));
    assert_eq(0, form_submission_count(['form' => 'newsletter', 'status' => 'handled']));

    // Newest first, and only one page's worth.
    $page = form_submission_page([], 1, 3);
    assert_eq(7, $page['total']);
    assert_eq(3, $page['pages']);
    assert_count(3, $page['items']);
    assert_eq('Contact new 1', $page['items'][0]['data']['name'], 'newest first');

    $second = form_submission_page([], 2, 3);
    assert_count(3, $second['items']);

    $third = form_submission_page([], 3, 3);
    assert_count(1, $third['items'], 'the last page holds the remainder');

    // No row is repeated or skipped across pages.
    $ids = [];
    foreach ([1, 2, 3] as $pageNumber) {
        foreach (form_submission_page([], $pageNumber, 3)['items'] as $item) {
            $ids[] = $item['id'];
        }
    }
    assert_eq(7, count($ids));
    assert_eq(count($ids), count(array_unique($ids)));
});

t('form_submission_set_status() updates only real changes', function () {
    db()->exec('DELETE FROM form_submissions');

    $a = seed_submission('contact', ['name' => 'A'], 'new');
    $b = seed_submission('contact', ['name' => 'B'], 'new');
    $c = seed_submission('contact', ['name' => 'C'], 'new');

    assert_eq(2, form_submission_set_status([$a, $b], 'handled'), 'two rows change');

    $read = function (int $id): string {
        return (string) db()->query("SELECT status FROM form_submissions WHERE id = {$id}")->fetchColumn();
    };

    assert_eq('handled', $read($a));
    assert_eq('handled', $read($b));
    assert_eq('new', $read($c), 'an unselected row is untouched');

    // Re-applying the same status is a no-op, not a phantom update.
    assert_eq(0, form_submission_set_status([$a, $b], 'handled'));

    // A status the CMS does not know must never be written.
    assert_eq(0, form_submission_set_status([$c], 'bogus'));
    assert_eq('new', $read($c));

    // Junk ids and empty selections are ignored rather than throwing.
    assert_eq(0, form_submission_set_status([], 'handled'));
    assert_eq(0, form_submission_set_status([0, -3], 'handled'));
});

t('field names come from the data, in first-seen order', function () {
    db()->exec('DELETE FROM form_submissions');

    seed_submission('contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hi'], 'new', 100);
    // A newer form gained a field the first submission never had.
    seed_submission('contact', ['name' => 'Bob', 'email' => 'bob@example.com', 'phone' => '123', 'message' => 'Yo'], 'new', 200);

    $rows = form_submission_all();
    assert_eq(['name', 'email', 'message', 'phone'], form_submission_field_names($rows));

    assert_eq('Name', form_submission_field_label('name'));
    assert_eq('Your message', form_submission_field_label('your_message'));
});

t('form_submission_all() returns every match, not one page', function () {
    db()->exec('DELETE FROM form_submissions');

    foreach (range(1, 25) as $i) {
        seed_submission('newsletter', ['email' => "bulk{$i}@example.com"], 'new', time() - $i);
    }

    assert_count(25, form_submission_all());
    assert_count(25, form_submission_all(['form' => 'newsletter']));
    assert_count(0, form_submission_all(['status' => 'handled']));

    // Oldest first, so an export reads chronologically.
    $rows = form_submission_all();
    assert_eq('bulk25@example.com', $rows[0]['data']['email']);
});

t('a submission with unreadable JSON still lists', function () {
    db()->exec('DELETE FROM form_submissions');

    db()->prepare("INSERT INTO form_submissions (form_type, data, status, created_at, updated_at) VALUES ('contact', 'not json', 'new', :now, :now)")
        ->execute(['now' => time()]);

    $page = form_submission_page();
    assert_count(1, $page['items']);
    assert_eq([], $page['items'][0]['data'], 'broken data degrades to an empty set');
});

// ---------------------------------------------------------------------------
// Public submission validation
// ---------------------------------------------------------------------------

t('a required field is reported by its label', function () {
    $fields = [
        'name'  => ['type' => 'text', 'label' => 'Your name', 'required' => true],
        'email' => ['type' => 'email', 'required' => true],
    ];

    $result = form_submission_validate($fields, ['email' => 'ada@example.com']);

    assert_contains('Your name is required.', $result['errors']['name'] ?? '');
    assert_false(array_key_exists('name', $result['data']), 'a failed field is not stored');
    assert_eq('ada@example.com', $result['data']['email'] ?? '', 'the other field still validates');
});

t('typed fields are checked by their declared type', function () {
    $fields = [
        'email' => ['type' => 'email', 'required' => false],
        'phone' => ['type' => 'tel', 'required' => false],
        'site'  => ['type' => 'url', 'required' => false],
        'age'   => ['type' => 'number', 'required' => false],
    ];

    $bad = form_submission_validate($fields, [
        'email' => 'nope', 'phone' => 'not a phone', 'site' => 'example.com', 'age' => 'old',
    ]);

    assert_count(4, $bad['errors'], 'every invalid value is reported');

    $good = form_submission_validate($fields, [
        'email' => 'ada@example.com', 'phone' => '+46 (0)8 123 456', 'site' => 'https://example.com', 'age' => '42',
    ]);

    assert_count(0, $good['errors']);
    assert_eq('42', $good['data']['age']);
});

t('an optional empty field is kept as an empty string', function () {
    $fields = [
        'name'   => ['type' => 'text', 'required' => true],
        'phone'  => ['type' => 'tel', 'required' => false],
        'legacy' => ['email' => true, 'required' => false],
    ];

    $result = form_submission_validate($fields, ['name' => 'Ada']);

    assert_count(0, $result['errors']);
    assert_eq('', $result['data']['phone'], 'the inbox still shows the field');
    assert_eq('', $result['data']['legacy']);

    // The older `email => true` shorthand still validates.
    $result = form_submission_validate($fields, ['name' => 'Ada', 'legacy' => 'nope']);
    assert_contains('valid email', $result['errors']['legacy'] ?? '');
});

t('select and radio values must be declared options', function () {
    $fields = [
        'subject' => ['type' => 'select', 'required' => false, 'options' => ['general' => 'General', 'sales' => 'Sales']],
        'reply'   => ['type' => 'radio', 'required' => false, 'options' => ['email' => 'Email', 'phone' => 'Phone']],
    ];

    $bad = form_submission_validate($fields, ['subject' => 'other', 'reply' => 'fax']);
    assert_count(2, $bad['errors']);

    $good = form_submission_validate($fields, ['subject' => 'sales', 'reply' => 'phone']);
    assert_count(0, $good['errors']);
    assert_eq('sales', $good['data']['subject']);
});

t('length is bounded per field, with an optional max', function () {
    $fields = [
        'nick' => ['type' => 'text', 'required' => false],
        'code' => ['type' => 'text', 'required' => false, 'max' => 3],
        'bio'  => ['type' => 'textarea', 'required' => false],
    ];

    $over = form_submission_validate($fields, [
        'nick' => str_repeat('x', 501),
        'code' => 'abcd',
        'bio'  => str_repeat('y', 5001),
    ]);

    assert_count(3, $over['errors'], 'all three are over their bound');

    $at = form_submission_validate($fields, [
        'nick' => str_repeat('x', 500),
        'code' => 'abc',
        'bio'  => str_repeat('y', 5000),
    ]);

    assert_count(0, $at['errors'], 'the bounds are inclusive');
});

t('a notification setting can name several recipients', function () {
    assert_eq(
        ['a@example.com', 'b@example.com'],
        form_notification_recipients(' a@example.com , b@example.com ')
    );
    assert_eq(['a@example.com'], form_notification_recipients('a@example.com, a@example.com'), 'duplicates collapse');
    assert_eq([], form_notification_recipients('not-an-address'));
    assert_eq([], form_notification_recipients(''));
});

t('the submission search treats % and _ literally', function () {
    seed_submission('contact', ['name' => 'One Hundred 100% Certain']);

    assert_true(form_submission_count(['q' => '100%']) >= 1, 'a literal percent is found');
    assert_eq(0, form_submission_count(['q' => '%%']), '%% is not a match-everything wildcard');
    assert_eq(0, form_submission_count(['q' => '__']), '__ is not a match-everything wildcard');
});

exit(test_summary());
