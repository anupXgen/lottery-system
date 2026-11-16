<?php
/**
 * Lottery ticket validation, CRUD and CSV/Excel import.
 */

const TICKET_NUMBER_MAX = 30;
const TICKET_NAME_MAX   = 100;
const TICKET_IMPORT_MAX_ROWS = 50000;

/** Normalise a lottery number from user/spreadsheet input. */
function normalize_ticket_number($raw): string
{
    if (is_float($raw) && floor($raw) == $raw && abs($raw) < 1e15) {
        $raw = number_format($raw, 0, '.', ''); // Excel stores 1001 as 1001.0
    }
    $v = clean_text((string) $raw);
    $v = str_replace(' ', '', $v);
    if (preg_match('/^\d+\.0+$/', $v)) {
        $v = substr($v, 0, strpos($v, '.'));
    }
    return $v;
}

/**
 * Validate a single ticket. Returns a list of error messages (empty = valid).
 */
function validate_ticket(string $number, string $soldBy, string $soldTo): array
{
    $errors = [];
    if ($number === '') {
        $errors[] = 'Lottery Number is required.';
    } elseif (mb_strlen($number) > TICKET_NUMBER_MAX) {
        $errors[] = 'Lottery Number must be at most ' . TICKET_NUMBER_MAX . ' characters.';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/_.]*$/', $number)) {
        $errors[] = 'Lottery Number may contain only letters, digits and - / _ . characters.';
    }
    if ($soldBy === '') {
        $errors[] = 'Sold By is required.';
    } elseif (mb_strlen($soldBy) > TICKET_NAME_MAX) {
        $errors[] = 'Sold By must be at most ' . TICKET_NAME_MAX . ' characters.';
    }
    if (mb_strlen($soldTo) > TICKET_NAME_MAX) {
        $errors[] = 'Sold To must be at most ' . TICKET_NAME_MAX . ' characters.';
    }
    return $errors;
}

function ticket_number_exists(string $number, int $exceptId = 0): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM lottery_tickets WHERE lottery_number = ? AND id <> ?');
    $st->execute([$number, $exceptId]);
    return (int) $st->fetchColumn() > 0;
}

function find_ticket(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM lottery_tickets WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Create a ticket. Returns list of errors (empty on success). */
function ticket_create(string $number, string $soldBy, string $soldTo): array
{
    $number = normalize_ticket_number($number);
    if ($reason = setup_lock_reason()) {
        return [$reason];
    }
    $errors = validate_ticket($number, $soldBy, $soldTo);
    if (!$errors && ticket_number_exists($number)) {
        $errors[] = "Lottery Number $number already exists.";
    }
    if ($errors) {
        return $errors;
    }
    try {
        $st = db()->prepare('INSERT INTO lottery_tickets (lottery_number, sold_by, sold_to) VALUES (?, ?, ?)');
        $st->execute([$number, $soldBy, $soldTo === '' ? null : $soldTo]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ["Lottery Number $number already exists."];
        }
        throw $e;
    }
    audit_log('TICKET_ADD', ['lottery_number' => $number]);
    bump_draw_version();
    return [];
}

function ticket_update(int $id, string $number, string $soldBy, string $soldTo): array
{
    $number = normalize_ticket_number($number);
    if ($reason = setup_lock_reason()) {
        return [$reason];
    }
    $ticket = find_ticket($id);
    if (!$ticket) {
        return ['Ticket not found.'];
    }
    $errors = validate_ticket($number, $soldBy, $soldTo);
    if (!$errors && ticket_number_exists($number, $id)) {
        $errors[] = "Lottery Number $number already exists.";
    }
    if ($errors) {
        return $errors;
    }
    try {
        $st = db()->prepare('UPDATE lottery_tickets SET lottery_number = ?, sold_by = ?, sold_to = ? WHERE id = ?');
        $st->execute([$number, $soldBy, $soldTo === '' ? null : $soldTo, $id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ["Lottery Number $number already exists."];
        }
        throw $e;
    }
    audit_log('TICKET_EDIT', [
        'id'   => $id,
        'from' => [$ticket['lottery_number'], $ticket['sold_by'], $ticket['sold_to']],
        'to'   => [$number, $soldBy, $soldTo],
    ]);
    bump_draw_version();
    return [];
}

function ticket_delete(int $id): array
{
    if ($reason = setup_lock_reason()) {
        return [$reason];
    }
    $ticket = find_ticket($id);
    if (!$ticket) {
        return ['Ticket not found.'];
    }
    $st = db()->prepare("DELETE FROM lottery_tickets WHERE id = ? AND status = 'AVAILABLE'");
    $st->execute([$id]);
    if ($st->rowCount() !== 1) {
        return ['This ticket cannot be deleted.'];
    }
    audit_log('TICKET_DELETE', ['lottery_number' => $ticket['lottery_number']]);
    bump_draw_version();
    return [];
}

function ticket_delete_all(): array
{
    if ($reason = setup_lock_reason()) {
        return [$reason];
    }
    $n = db()->exec("DELETE FROM lottery_tickets WHERE status = 'AVAILABLE'");
    audit_log('TICKET_DELETE_ALL', ['deleted' => $n]);
    bump_draw_version();
    return [];
}

/* ------------------------------------------------------------------ */
/* Import                                                              */
/* ------------------------------------------------------------------ */

/**
 * Read raw rows from an uploaded CSV / XLSX / XLS / ODS file.
 * Returns array of [rowNumber => [col0, col1, col2]].
 * @throws RuntimeException on unreadable files
 */
function read_ticket_file(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $rows = [];

    if ($ext === 'csv' || $ext === 'txt') {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new RuntimeException('Could not open the uploaded file.');
        }
        $first = (string) fgets($fh);
        rewind($fh);
        $delimiter = ',';
        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delimiter)) {
                $delimiter = $d;
            }
        }
        $n = 0;
        while (($cols = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            $n++;
            if ($n === 1 && isset($cols[0])) {
                $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]); // strip BOM
            }
            $rows[$n] = $cols;
            if ($n > TICKET_IMPORT_MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);
        return $rows;
    }

    if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('Excel support is not installed (run composer install). Please upload a CSV file instead.');
        }
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read the spreadsheet. Make sure it is a valid Excel file.');
        }
        $sheet = $book->getSheet(0);
        $highestRow = min($sheet->getHighestDataRow(), TICKET_IMPORT_MAX_ROWS + 2);
        for ($r = 1; $r <= $highestRow; $r++) {
            $cols = [];
            foreach (['A', 'B', 'C'] as $c) {
                $cols[] = $sheet->getCell($c . $r)->getCalculatedValue();
            }
            $rows[$r] = $cols;
        }
        $book->disconnectWorksheets();
        return $rows;
    }

    throw new RuntimeException('Unsupported file type. Please upload a .csv, .xlsx or .xls file.');
}

/**
 * Validate raw rows. Returns:
 *  ['total' => int, 'valid' => [[row, number, sold_by, sold_to]...], 'errors' => [[row, number, message]...]]
 */
function validate_ticket_rows(array $rows): array
{
    $valid = [];
    $errors = [];
    $seen = [];
    $total = 0;
    $headerChecked = false;

    foreach ($rows as $rowNum => $cols) {
        $cols = array_pad(array_values((array) $cols), 3, '');
        $number = normalize_ticket_number($cols[0] === null ? '' : $cols[0]);
        $soldBy = mb_substr(clean_text((string) $cols[1]), 0, 200);
        $soldTo = mb_substr(clean_text((string) $cols[2]), 0, 200);

        if ($number === '' && $soldBy === '' && $soldTo === '') {
            continue; // blank line
        }
        if (!$headerChecked) {
            $headerChecked = true;
            if (preg_match('/lottery|number|ticket/i', $number . ' ' . $soldBy)) {
                continue; // header row
            }
        }
        $total++;
        if ($total > TICKET_IMPORT_MAX_ROWS) {
            $errors[] = [$rowNum, $number, 'Too many rows. Maximum is ' . TICKET_IMPORT_MAX_ROWS . ' per upload.'];
            break;
        }

        $rowErrors = validate_ticket($number, $soldBy, $soldTo);
        $key = mb_strtolower($number);
        if (!$rowErrors && isset($seen[$key])) {
            $rowErrors[] = 'Duplicate Lottery Number in this file (first seen on row ' . $seen[$key] . ').';
        }
        if ($rowErrors) {
            $errors[] = [$rowNum, $number, implode(' ', $rowErrors)];
            continue;
        }
        $seen[$key] = $rowNum;
        $valid[] = [$rowNum, $number, $soldBy, $soldTo];
    }

    // Check against existing tickets in the database (in chunks).
    if ($valid) {
        $existing = [];
        foreach (array_chunk(array_column($valid, 1), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = db()->prepare("SELECT lottery_number FROM lottery_tickets WHERE lottery_number IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $existing[mb_strtolower($n)] = true;
            }
        }
        if ($existing) {
            $keep = [];
            foreach ($valid as $v) {
                if (isset($existing[mb_strtolower($v[1])])) {
                    $errors[] = [$v[0], $v[1], 'Lottery Number already exists in the system.'];
                } else {
                    $keep[] = $v;
                }
            }
            $valid = $keep;
        }
    }

    usort($errors, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    return ['total' => $total, 'valid' => $valid, 'errors' => $errors];
}

/**
 * Save validated rows in one transaction. Returns number inserted.
 */
function import_ticket_rows(array $valid, string $sourceName): int
{
    if (!$valid) {
        return 0;
    }
    $inserted = with_transaction(function (PDO $pdo) use ($valid) {
        // Lock the draw row so an import cannot overlap with a draw starting.
        $s = draw_session_row(true);
        if ($s['state'] === 'ROLLING' || $s['state'] === 'REVEALED' || confirmed_draw_count() > 0) {
            throw new DrawException('Tickets are locked because the lottery draw has started.');
        }
        $count = 0;
        foreach (array_chunk($valid, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
            $params = [];
            foreach ($chunk as $v) {
                $params[] = $v[1];
                $params[] = $v[2];
                $params[] = $v[3] === '' ? null : $v[3];
            }
            $st = $pdo->prepare("INSERT INTO lottery_tickets (lottery_number, sold_by, sold_to) VALUES $placeholders");
            $st->execute($params);
            $count += $st->rowCount();
        }
        $pdo->exec('UPDATE draw_session SET version = version + 1 WHERE id = 1');
        return $count;
    });
    audit_log('TICKET_IMPORT', ['file' => $sourceName, 'inserted' => $inserted]);
    return $inserted;
}
