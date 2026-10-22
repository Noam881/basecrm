#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<'SQL'
CREATE DATABASE base_alpha;
CREATE DATABASE base_beta;
CREATE DATABASE base_test;
SQL
