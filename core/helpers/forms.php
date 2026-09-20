<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form submissions
|--------------------------------------------------------------------------
|
| Reading and working through the form inbox. Shared by admin/messages.php
| (list, filter, export) and admin/messages/update.php (status changes) so the
| filter rules exist once: a status change and an export must agree about what
| "waiting" submissions are.
|
*/

/**
 * The workflow statuses a submission can hold, in working order.
 *
 * @return list<string>
 */
function form_submission_statuses(): array
{
    return ['new', 'waiting', 'handled', 'spam'];
}

/**
 * Human label for a submission status.
 */
function form_submission_status_label(string $status): string
{
    $key = 'forms_status_' . $status;

    return admin_trans($key);
}

/**
 * A posted status, or null when it is not one we know.
 *
 * Returning null rather than a default keeps a bad value from silently
 * overwriting a real status.
 */
function form_submission_status_valid(?string $status): ?string
{
    $status = (string) $status;

    return in_array($status, form_submission_statuses(), true) ? $status : null;
}

/**
 * Translate the list filters into a WHERE fragment and its parameters.
 *
 * Shared by listing, counting and export so they can never disagree. An unknown
 * form type is treated as "no filter", matching what the admin offers.
 *
 * @param array{form?: ?string, status?: ?string, q?: ?string} $filters
 * @return array{sql: string, params: array<string, mixed>}
 */
function form_submission_filter(array $filters): array
{
    $theme     = theme_config();
    $formTypes = $theme['form_types'] ?? [];

    $where  = [];
    $params = [];

    $form = (string) ($filters['form'] ?? '');
    if ($form !== '' && isset($formTypes[$form])) {
        $where[] = 'form_type = :form_type';
        $params['form_type'] = $form;
    }

    $status = (string) ($filters['status'] ?? '');
    if ($status !== '' && in_array($status, form_submission_statuses(), true)) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    // Submitted values live in the JSON blob, so a LIKE over it searches every
    // field without knowing the form's shape.
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $where[] = 'data LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    return [
        'sql'    => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

/**
 * How many submissions match a filter.
 *
 * @param array{form?: ?string, status?: ?string, q?: ?string} $filters
 */
function form_submission_count(array $filters = []): int
{
    $filter = form_submission_filter($filters);

    $stmt = db()->prepare("SELECT COUNT(*) FROM form_submissions{$filter['sql']}");
    $stmt->execute($filter['params']);

    return (int) $stmt->fetchColumn();
}

/**
 * One page of submissions, newest first, with `data` decoded.
 *
 * @param array{form?: ?string, status?: ?string, q?: ?string} $filters
 * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
 */
function form_submission_page(array $filters = [], int $page = 1, int $perPage = 20): array
{
    $page    = max(1, $page);
    $perPage = max(1, $perPage);

    $filter = form_submission_filter($filters);
    $total  = form_submission_count($filters);

    $stmt = db()->prepare("
        SELECT *
        FROM form_submissions{$filter['sql']}
        ORDER BY created_at DESC, id DESC
        LIMIT {$perPage} OFFSET " . pagination_offset($page, $perPage)
    );
    $stmt->execute($filter['params']);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return pagination_result(array_map('form_submission_decode', $items), $total, $page, $perPage);
}

/**
 * Every submission matching a filter, for export. Not paginated on purpose.
 *
 * @param array{form?: ?string, status?: ?string, q?: ?string} $filters
 * @return list<array<string, mixed>>
 */
function form_submission_all(array $filters = []): array
{
    $filter = form_submission_filter($filters);

    $stmt = db()->prepare("
        SELECT *
        FROM form_submissions{$filter['sql']}
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute($filter['params']);

    return array_map('form_submission_decode', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Decode the stored JSON payload and normalise the row's scalar types.
 */
function form_submission_decode(array $row): array
{
    $data = json_decode((string) $row['data'], true);

    $row['id']         = (int) $row['id'];
    $row['created_at'] = (int) $row['created_at'];
    $row['updated_at'] = (int) $row['updated_at'];
    $row['data']       = is_array($data) ? $data : [];

    return $row;
}

/**
 * Set the status of one or more submissions.
 *
 * @param list<int> $ids
 * @return int Rows changed, which can be fewer than the ids given if some
 *             already held the requested status.
 */
function form_submission_set_status(array $ids, string $status): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));

    if (!$ids || form_submission_status_valid($status) === null) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("
        UPDATE form_submissions
        SET status = ?, updated_at = ?
        WHERE id IN ({$placeholders})
          AND status != ?
    ");

    $stmt->execute(array_merge([$status, time()], $ids, [$status]));

    return $stmt->rowCount();
}

/**
 * Field names present across a set of submissions, in first-seen order.
 *
 * Forms of the same type can gain fields over time, so the export builds its
 * columns from the data rather than from the current form definition.
 *
 * @param list<array<string, mixed>> $submissions
 * @return list<string>
 */
function form_submission_field_names(array $submissions): array
{
    $names = [];

    foreach ($submissions as $submission) {
        foreach (array_keys($submission['data'] ?? []) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = (string) $name;
            }
        }
    }

    return $names;
}

/**
 * Human column heading for a stored field name.
 */
function form_submission_field_label(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

/**
 * Delete submissions outright.
 *
 * An inbox has no trash: a submission is a message that has been read and dealt
 * with, and keeping it only to hide it adds a state nobody wants. The activity
 * log records what was removed.
 *
 * @param list<int> $ids
 * @return int Rows deleted.
 */
function form_submission_delete(array $ids): int
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id) => $id > 0
    )));

    if (!$ids) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("DELETE FROM form_submissions WHERE id IN ({$placeholders})");
    $stmt->execute($ids);

    return $stmt->rowCount();
}

/**
 * Submissions by id, oldest first, for exporting a selection.
 *
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function form_submission_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id) => $id > 0
    )));

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("
        SELECT *
        FROM form_submissions
        WHERE id IN ({$placeholders})
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute($ids);

    return array_map('form_submission_decode', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Stream a set of submissions as a CSV download.
 *
 * Shared by "export everything" and "export selected", so both produce the same
 * columns. Sends headers and ends the response; it does not return.
 *
 * @param list<array<string, mixed>> $submissions
 */
function form_submission_export_csv(array $submissions, string $filename): void
{
    $theme     = theme_config();
    $formTypes = $theme['form_types'] ?? [];

    $fields = form_submission_field_names($submissions);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    fputcsv($out, array_merge(
        [admin_trans('forms_submitted_at'), admin_trans('forms_form'), admin_trans('common_status')],
        array_map('form_submission_field_label', $fields)
    ));

    foreach ($submissions as $submission) {
        $line = [
            date('Y-m-d H:i', (int) $submission['created_at']),
            $formTypes[$submission['form_type']]['label'] ?? ucfirst((string) $submission['form_type']),
            form_submission_status_label((string) $submission['status']),
        ];

        foreach ($fields as $field) {
            $value = $submission['data'][$field] ?? '';
            // Arrays (a multi-select, say) are joined rather than dropped.
            $line[] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        fputcsv($out, $line);
    }

    fclose($out);
}

/*
|--------------------------------------------------------------------------
| Public form submission
|--------------------------------------------------------------------------
|
| Validation for a posted form, driven entirely by the field definitions in
| theme/theme.php. Kept here so the endpoint stays thin and the rules can be
| unit-tested without a request.
|
*/

/**
 * Validate a posted submission against the fields the theme declares.
 *
 * Optional fields left blank are kept as empty strings, so the inbox still
 * shows the field rather than silently dropping it.
 *
 * @param array<string, array<string, mixed>> $fields theme.php form_types[...]['fields']
 * @param array<string, mixed> $input Typically $_POST
 * @return array{data: array<string, string>, errors: array<string, string>}
 */
function form_submission_validate(array $fields, array $input): array
{
    $data   = [];
    $errors = [];

    foreach ($fields as $name => $rules) {
        if (!is_array($rules)) {
            continue;
        }

        $name  = (string) $name;
        $label = (string) ($rules['label'] ?? form_submission_field_label($name));
        $value = $input[$name] ?? '';

        // A field the browser sends as a list is not something these forms use.
        $value = trim(is_array($value) ? '' : (string) $value);

        if (($rules['required'] ?? false) && $value === '') {
            $errors[$name] = $label . ' is required.';
            continue;
        }

        if ($value !== '') {
            $error = form_submission_field_error($rules, $value, $label);

            if ($error !== null) {
                $errors[$name] = $error;
                continue;
            }
        }

        $data[$name] = $value;
    }

    return ['data' => $data, 'errors' => $errors];
}

/**
 * The field types a public form may declare.
 *
 * Anything else is treated as plain text by form_submission_field_error(), so a
 * typo silently drops that field's validation — which is why the theme check on
 * the Health page reports it.
 *
 * @return list<string>
 */
function form_submission_field_types(): array
{
    return ['text', 'textarea', 'email', 'tel', 'url', 'number', 'select', 'radio', 'checkbox'];
}

/**
 * The problem with one non-empty value, or null when it is acceptable.
 *
 * @param array<string, mixed> $rules
 */
function form_submission_field_error(array $rules, string $value, string $label): ?string
{
    // The `email => true` shorthand predates `type`; both still work.
    $type = (string) ($rules['type'] ?? (!empty($rules['email']) ? 'email' : 'text'));

    switch ($type) {
        case 'email':
            if (!validate_email($value)) {
                return $label . ' must be a valid email address.';
            }
            break;

        case 'tel':
            // Deliberately permissive: punctuation varies by country, so this
            // only rejects values that could not be a phone number at all.
            if (!preg_match('/^[0-9+()\-.\s]{3,40}$/', $value)) {
                return $label . ' does not look like a phone number.';
            }
            break;

        case 'url':
            if (!validate_url($value)) {
                return $label . ' must be a full web address (https://…).';
            }
            break;

        case 'number':
            if (!is_numeric($value)) {
                return $label . ' must be a number.';
            }
            break;

        case 'select':
        case 'radio':
            $options = array_map('strval', array_keys($rules['options'] ?? []));

            if ($options && !in_array($value, $options, true)) {
                return $label . ' has an unknown value.';
            }
            break;
    }

    $max = (int) ($rules['max'] ?? ($type === 'textarea' ? 5000 : 500));

    if ($max > 0 && mb_strlen($value) > $max) {
        return $label . ' is too long (at most ' . $max . ' characters).';
    }

    return null;
}

/**
 * Split a notification setting into the addresses it names.
 *
 * Blank entries and anything that is not an address are dropped rather than
 * failing the submission; the settings form already validated the list.
 *
 * @return list<string>
 */
function form_notification_recipients(string $value): array
{
    $recipients = [];

    foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $email) {
        $email = trim($email);

        if ($email !== '' && validate_email($email) && !in_array($email, $recipients, true)) {
            $recipients[] = $email;
        }
    }

    return $recipients;
}
