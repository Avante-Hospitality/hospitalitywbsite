<?php

/*
 * Per-IP throttling, kept in the database so it survives between requests
 * (PHP shares nothing in memory between page loads).
 *
 * Mirrors the in-memory limits in the previous Node implementation: allow up
 * to the limit inside the window, then refuse.
 */

const AVANTE_SUBMIT_WINDOW_SECONDS = 900;   // 15 minutes
const AVANTE_SUBMIT_MAX_HITS = 15;

const AVANTE_LOGIN_WINDOW_SECONDS = 900;    // 15 minutes
const AVANTE_LOGIN_MAX_HITS = 10;

function avante_throttle_exceeded(string $bucket, string $key, int $windowSeconds, int $maxHits): bool
{
    try {
        return (bool) avante_with_schema(function () use ($bucket, $key, $windowSeconds, $maxHits) {
            $pdo = avante_db();
            $hash = hash('sha256', $key);
            $window = max($windowSeconds, 1);

            // Now and then clear out buckets that can no longer matter, so the
            // table does not grow without limit on a busy site.
            if (random_int(1, 20) === 1) {
                $pdo->exec(
                    'DELETE FROM throttle WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                    . max($window, 3600) . ' SECOND)'
                );
            }

            $prune = $pdo->prepare(
                'DELETE FROM throttle WHERE bucket = ? AND key_hash = ? AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                . $window . ' SECOND)'
            );
            $prune->execute([$bucket, $hash]);

            $pdo->prepare('INSERT INTO throttle (bucket, key_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())')
                ->execute([$bucket, $hash]);

            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM throttle WHERE bucket = ? AND key_hash = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                . $window . ' SECOND)'
            );
            $count->execute([$bucket, $hash]);

            return ((int) $count->fetchColumn()) > $maxHits;
        });
    } catch (Throwable $e) {
        // Throttling is best-effort: never let it take the forms down.
        error_log('[avante] throttle unavailable: ' . $e->getMessage());
        return false;
    }
}

function avante_submit_rate_limited(string $ip): bool
{
    return avante_throttle_exceeded('submit', $ip, AVANTE_SUBMIT_WINDOW_SECONDS, AVANTE_SUBMIT_MAX_HITS);
}

function avante_login_rate_limited(string $ip): bool
{
    return avante_throttle_exceeded('login', $ip, AVANTE_LOGIN_WINDOW_SECONDS, AVANTE_LOGIN_MAX_HITS);
}
