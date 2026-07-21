#!/bin/sh

set -eu

if [ "$(psql -U "$POSTGRES_USER" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = 'rateguru_test'")" != "1" ]; then
    createdb -U "$POSTGRES_USER" rateguru_test
fi
