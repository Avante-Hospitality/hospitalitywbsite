<?php

/*
 * Storage layer - the PHP counterpart of server/db.js, on MySQL/MariaDB
 * (the database xneelo supports natively).
 *
 * created_at is stored in UTC, matching the previous implementation, by
 * pinning the connection's time zone to +00:00.
 */

function avante_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) avante_config_get('db.host', 'localhost');
    $port = (int) avante_config_get('db.port', 3306);
    $name = (string) avante_config_get('db.name', '');
    $user = (string) avante_config_get('db.user', '');
    $pass = (string) avante_config_get('db.pass', '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database name and user are not set in config.php (see the README).');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Fail in seconds rather than letting a form hang if the database
            // host is wrong or unreachable.
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function avante_schema_sql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS submissions (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            form_type    VARCHAR(32) NOT NULL,
            email        VARCHAR(255) NULL,
            name         VARCHAR(255) NULL,
            reference    VARCHAR(255) NULL,
            email_status VARCHAR(16) NOT NULL DEFAULT 'pending',
            email_error  VARCHAR(500) NULL,
            payload      MEDIUMTEXT NOT NULL,
            ip           VARCHAR(45) NULL,
            user_agent   VARCHAR(400) NULL,
            PRIMARY KEY (id),
            KEY submissions_form_created (form_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS throttle (
            bucket     VARCHAR(32) NOT NULL,
            key_hash   CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY throttle_lookup (bucket, key_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function avante_ensure_schema(): void
{
    foreach (avante_schema_sql() as $sql) {
        avante_db()->exec($sql);
    }
}

function avante_is_missing_table(PDOException $e): bool
{
    // 42S02 = base table not found, 42S22 = unknown column (an older schema).
    return in_array((string) $e->getCode(), ['42S02', '42S22'], true);
}

/*
 * Runs a query, creating the tables first if this is the very first request
 * after deploying, so nobody has to remember to run an install step.
 */
function avante_with_schema(callable $query)
{
    try {
        return $query();
    } catch (PDOException $e) {
        if (!avante_is_missing_table($e)) {
            throw $e;
        }
        avante_ensure_schema();
        return $query();
    }
}

function avante_json_payload($payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return $json === false ? '{}' : $json;
}

function avante_insert_submission(array $row): int
{
    return (int) avante_with_schema(function () use ($row) {
        $stmt = avante_db()->prepare(
            'INSERT INTO submissions (form_type, email, name, reference, payload, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) $row['form_type'],
            $row['email'] ?? null,
            $row['name'] ?? null,
            $row['reference'] ?? null,
            avante_json_payload($row['payload'] ?? []),
            $row['ip'] ?? null,
            $row['user_agent'] === null ? null : mb_substr((string) $row['user_agent'], 0, 400),
        ]);
        return avante_db()->lastInsertId();
    });
}

function avante_mark_email_status(int $id, string $status, ?string $error): void
{
    avante_with_schema(function () use ($id, $status, $error) {
        $stmt = avante_db()->prepare('UPDATE submissions SET email_status = ?, email_error = ? WHERE id = ?');
        $stmt->execute([
            $status,
            $error === null ? null : mb_substr($error, 0, 500),
            $id,
        ]);
        return true;
    });
}

function avante_list_submissions(?string $formType, int $limit = 20, int $offset = 0): array
{
    $capped = min(max($limit, 1), 5000);
    $skip = max($offset, 0);

    return avante_with_schema(function () use ($formType, $capped, $skip) {
        if ($formType !== null) {
            $stmt = avante_db()->prepare(
                'SELECT * FROM submissions WHERE form_type = ? ORDER BY id DESC LIMIT ' . $capped . ' OFFSET ' . $skip
            );
            $stmt->execute([$formType]);
        } else {
            $stmt = avante_db()->query(
                'SELECT * FROM submissions ORDER BY id DESC LIMIT ' . $capped . ' OFFSET ' . $skip
            );
        }
        return $stmt->fetchAll();
    });
}

function avante_count_submissions(?string $formType = null): int
{
    return (int) avante_with_schema(function () use ($formType) {
        if ($formType !== null) {
            $stmt = avante_db()->prepare('SELECT COUNT(*) FROM submissions WHERE form_type = ?');
            $stmt->execute([$formType]);
        } else {
            $stmt = avante_db()->query('SELECT COUNT(*) FROM submissions');
        }
        return $stmt->fetchColumn();
    });
}

function avante_prune_older_than(int $days): int
{
    return (int) avante_with_schema(function () use ($days) {
        return avante_db()
            ->exec('DELETE FROM submissions WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max($days, 0) . ' DAY)');
    });
}
