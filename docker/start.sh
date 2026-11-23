#!/bin/sh
# Container entrypoint: prepare the app, start the background processes, then serve HTTP.
set -e

# Environment variables are only available at runtime, so cache config here, not at build time.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Queue worker: submits generations to Pixelforge. The loop restarts it if it exits,
# and --max-time recycles it hourly to keep memory in check.
( while true; do php artisan queue:work --sleep=1 --max-time=3600 || true; sleep 1; done ) &

# Scheduler: runs the every-minute polling fallback for generations that missed a webhook.
( while true; do php artisan schedule:work || true; sleep 1; done ) &

exec frankenphp php-server --root /app/public --listen ":${PORT}"
