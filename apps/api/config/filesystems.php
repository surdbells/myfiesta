<?php

/*
 * Where posters, logos and gallery pictures live: this server's own disk, or
 * a bucket.
 *
 * MEDIA_DISK=local keeps them under storage/app/public, served at
 * APP_URL/storage by nginx (or by `php artisan storage:link` on a host without
 * the compose file). MEDIA_DISK=s3 puts them in any S3-compatible bucket —
 * AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO — described by the AWS_*
 * variables. See docs/DEPLOYMENT.md, "Uploaded pictures".
 *
 * Either way the disk is still called "public", and every caller asks it for a
 * URL rather than building one. That is what makes this a setting rather than
 * a migration: rows hold paths, the disk turns a path into an address, and the
 * address is right for whichever of the two is configured today.
 *
 * Empty lines in .env read as "", which env()'s own default does not replace,
 * so the defaults below are given with `?:` wherever an empty line is likely —
 * as it is for every AWS_* line in .env.production.example.
 */
$media = env('MEDIA_DISK') ?: 'local';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
         * Not served. Laravel's default serves this disk at /storage behind
         * signed URLs, which is the same address the pictures below are
         * published at: a request for a poster that missed nginx would land
         * here instead and answer 404 from the wrong disk. Nothing in this
         * application hands out signed URLs to it.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Posters, brand logos and gallery pictures — see MEDIA_DISK above.
         *
         * Served directly and cached hard. Every file written here is named
         * afresh (a replaced banner is a new file, never an overwrite), so a
         * year's cache can never show somebody yesterday's picture.
         *
         * Relative paths are what reach the database, never URLs. A full URL
         * in a column hardcodes the driver it was written under.
         */
        'public' => $media === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                // R2 takes "auto"; AWS and Spaces take the bucket's region.
                'region' => env('AWS_DEFAULT_REGION') ?: 'us-east-1',
                'bucket' => env('AWS_BUCKET'),
                // The public address of the bucket — a CDN, a custom domain,
                // R2's public bucket URL. Required for R2, whose API endpoint
                // below is not a public address at all; left empty, AWS builds
                // its own https://{bucket}.s3.{region}.amazonaws.com/ URL.
                //
                // `?: null` because an empty `AWS_URL=` line reads as "" and
                // Laravel treats "" as a base URL, which makes every picture
                // address relative — to the site, which does not have them.
                'url' => env('AWS_URL') ?: null,
                // Empty for AWS. R2: https://{account}.r2.cloudflarestorage.com.
                // Spaces: https://{region}.digitaloceanspaces.com.
                'endpoint' => env('AWS_ENDPOINT') ?: null,
                // MinIO and some self-hosted stores want bucket-in-the-path.
                'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                /*
                 * How a file is made readable, which differs by provider.
                 *
                 * "private" sends no grant, and the bucket's own policy (AWS)
                 * or public access setting (R2) is what lets people read it.
                 * New AWS buckets and R2 both refuse a public-read ACL
                 * outright, so this is the default. DigitalOcean Spaces has no
                 * bucket-wide switch and needs "public" on every file.
                 */
                'visibility' => env('MEDIA_VISIBILITY') ?: 'private',
                'options' => [
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ],
                'throw' => false,
                'report' => false,
            ]
            : [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],

        /*
         * Identity documents, settlement exports, anything personal.
         *
         * Deliberately a separate disk rather than a folder: different
         * visibility, different retention, different backup rules. Never
         * web-reachable — reaching a file here goes through a controller that
         * checks the caller, and the access is logged. It never shares the
         * media bucket above, which is public by design.
         */
        'private' => [
            'driver' => env('FILESYSTEM_PRIVATE_DRIVER', 'local'),
            'root' => storage_path('app/private-secure'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
