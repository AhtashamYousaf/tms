#!/bin/sh
set -e

# The project directory is bind-mounted for local development, which means its
# ownership follows the host user, not the image's www-data. Rather than
# chown-ing (which would flip the host directory's ownership to www-data on
# every container start/restart and lock the host user out again), just make
# sure the directories Laravel writes to are group/world-writable so both the
# host user and the container's www-data worker processes can write to them.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwX,o+rwX storage bootstrap/cache

exec "$@"
