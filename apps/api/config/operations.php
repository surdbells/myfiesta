<?php

/*
 * Keeping the platform up and getting it back: backups, the readiness check,
 * and how long the things that expire are kept.
 *
 * See docs/OPERATIONS.md for what each of these is for and what to do when
 * one of them goes red.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Nightly database backups
    |--------------------------------------------------------------------------
    |
    | `php artisan backup:run`, scheduled nightly in routes/console.php. A
    | pg_dump in custom format (compressed, restorable table by table), taken
    | from the same snapshot as the row counts written beside it, so a restore
    | can be checked against exactly what was dumped.
    |
    | Where it goes is BACKUP_TARGET: "volume", a directory — the backups
    | volume in the compose file, or a mounted network share — or "s3", any
    | S3-compatible bucket. Never the media bucket: that one is public.
    |
    */

    'backup' => [
        'target' => env('BACKUP_TARGET') ?: 'volume',

        // The connection to dump. The live one, unless told otherwise.
        'connection' => env('BACKUP_DB_CONNECTION') ?: env('DB_CONNECTION', 'pgsql'),

        'volume' => [
            'path' => env('BACKUP_PATH') ?: '/var/backups/myfiesta',
        ],

        's3' => [
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION') ?: 'us-east-1',
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT') ?: null,
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
            // Where in the bucket, so one bucket can hold staging's too.
            'prefix' => env('BACKUP_S3_PREFIX') ?: 'database',
            // What the bucket encrypts each object with at rest: AES256 or
            // aws:kms on AWS. Empty sends nothing, for providers that encrypt
            // everything anyway (R2) or refuse the header.
            'server_side_encryption' => env('BACKUP_S3_SSE') ?: null,
        ],

        /*
         * Encrypted before it leaves the machine, whatever the target does.
         *
         * 32 random bytes, base64: `php artisan backup:key`. Kept somewhere
         * that is not this server and not the bucket — a backup and the key
         * that opens it in the same place protect nothing, and a key that is
         * lost with the server makes every backup useless. Empty stores the
         * dump as pg_dump wrote it, and backup:run says so every night;
         * production refuses to start that way (App\Support\Preflight).
         */
        'encryption_key' => env('BACKUP_ENCRYPTION_KEY') ?: null,

        // Kept: the newest of each of the last 7 days, 4 weeks and 6 months.
        'keep' => [
            'daily' => (int) (env('BACKUP_KEEP_DAILY') ?: 7),
            'weekly' => (int) (env('BACKUP_KEEP_WEEKLY') ?: 4),
            'monthly' => (int) (env('BACKUP_KEEP_MONTHLY') ?: 6),
        ],

        // The client tools. In the API image; see ops/docker/api.Dockerfile.
        'pg_dump' => env('BACKUP_PG_DUMP') ?: 'pg_dump',
        'pg_restore' => env('BACKUP_PG_RESTORE') ?: 'pg_restore',

        // An unencrypted dump never waits here: it is encrypted on the way
        // in. Cleared at the end of every run, successful or not.
        'work_dir' => env('BACKUP_WORK_DIR') ?: sys_get_temp_dir(),

        // A dump that takes longer than this has something wrong with it.
        'timeout' => (int) (env('BACKUP_TIMEOUT') ?: 3 * 3600),

        // Older than this and `app:health --only=backup` fails.
        'stale_after_hours' => 26,
    ],

    /*
    |--------------------------------------------------------------------------
    | Readiness
    |--------------------------------------------------------------------------
    |
    | GET /api/health/ready and `php artisan app:health`. Each heartbeat is a
    | time written into the cache: the scheduler's every minute, and the
    | queue's whenever a worker runs the job the scheduler sends it.
    |
    */

    'health' => [
        // The scheduler writes one every minute.
        'scheduler_stale_after' => 180,

        // Sent every minute, behind whatever else is queued — so a campaign
        // going out delays it. Ten minutes behind is a queue worth a look.
        'queue_stale_after' => 600,

        // Failed jobs in the last hour before the queue is called unwell:
        // enough that one bad address does not page anybody, few enough that
        // every email failing does.
        'failed_jobs_per_hour' => 10,

        // Written to and read back on each check. "private" holds identity
        // documents and data exports; "public" holds the pictures.
        'disks' => ['private', 'public'],
    ],

    /*
    |--------------------------------------------------------------------------
    | What expires, and how long it is kept after
    |--------------------------------------------------------------------------
    */

    'prune' => [
        // A failed job's payload is the job, buyer's name and address
        // included. Long enough to retry one after a weekend; the same thirty
        // days webhook deliveries are kept.
        'failed_jobs_days' => 30,

        // Sign-in tokens past their expiry. A week, so the admin still shows
        // when somebody was last active.
        'tokens_expired_hours' => 168,
    ],

];
