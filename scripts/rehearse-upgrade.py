#!/usr/bin/env python3
"""Rehearse a first-build additive upgrade and synthetic backup/restore.
Only creates/drops exact new scratch databases owned by this invocation.
"""
from pathlib import Path
import json
import os
import secrets
import subprocess
import tarfile
import tempfile

root = Path(__file__).resolve().parents[1]
binpath = Path(os.environ.get('CRM_POSTGRES_BIN', '/opt/homebrew/opt/libpq@18/bin'))


def readenv(name):
    return dict(line.split('=', 1) for line in (root / name).read_text().splitlines() if '=' in line)


admin = {**os.environ, 'PGPASSWORD': readenv('.env')['POSTGRES_PASSWORD']}


def sql(command, database='postgres'):
    return subprocess.check_output(
        [str(binpath / 'psql'), '-h', '127.0.0.1', '-p', '55438', '-U', 'bootstrap', '-d', database, '-At', '-v', 'ON_ERROR_STOP=1'],
        input=command, env=admin, text=True,
    ).strip()


def run(args, env):
    return subprocess.check_output(args, cwd=root, env=env, text=True).strip()


results = []
created_databases = []
run_id = secrets.token_hex(6)
additive_migrations = [
    path.stem for path in sorted((root / 'packages/awove').glob('*/database/migrations/*.php'))
    if path.name not in ['2026_10_22_000100_create_core.php', '2026_10_22_000150_create_service_details.php']
]
try:
    with tempfile.TemporaryDirectory(prefix='base-crm-rehearsal-') as tmp:
        for installation in ['alpha', 'beta']:
            db = f'base_rehearsal_{installation}_{run_id}'
            restored = db + '_restored'
            role = f'base_{installation}'
            for database in [db, restored]:
                # CREATE must succeed before a name becomes eligible for cleanup.
                sql(f'CREATE DATABASE {database} OWNER {role};')
                created_databases.append(database)
                sql(f'REVOKE ALL ON DATABASE {database} FROM PUBLIC;')
            settings = readenv(f'.env.{installation}')
            env = {**os.environ, **{k: v.strip('"') for k, v in settings.items()}, 'DB_DATABASE': db, 'CRM_INSTALLATION': installation, 'CRM_REHEARSAL': '1', 'APP_ENV': 'local', 'QUEUE_CONNECTION': 'sync'}
            run(['php', 'artisan', 'migrate', '--force', '--path=database/migrations', '--path=packages/awove/crm-core/database/migrations/2026_10_22_000100_create_core.php', '--path=packages/awove/example-service-details/database/migrations/2026_10_22_000150_create_service_details.php'], env)
            before = run(['php', 'scripts/rehearsal-fixture.php', 'seed'], env)
            run(['php', 'artisan', 'migrate', '--force'], env)
            run(['php', 'artisan', 'crm:install'], env)
            after = run(['php', 'scripts/rehearsal-fixture.php', 'snapshot'], env)
            if before != after:
                raise RuntimeError('Data changed during additive upgrade: ' + installation)
            applied = set(sql('SELECT migration FROM migrations;', db).splitlines())
            if not set(additive_migrations).issubset(applied):
                raise RuntimeError('An additive migration was not applied: ' + installation)
            dump = Path(tmp) / f'{installation}.dump'
            pg = {**env, 'PGPASSWORD': settings['DB_PASSWORD']}
            subprocess.run([str(binpath / 'pg_dump'), '-h', '127.0.0.1', '-p', '55438', '-U', role, '-d', db, '-Fc', '-f', str(dump)], env=pg, check=True)
            subprocess.run([str(binpath / 'pg_restore'), '-h', '127.0.0.1', '-p', '55438', '-U', role, '-d', restored, '--no-owner', str(dump)], env=pg, check=True)
            restored_fingerprint = run(['php', 'scripts/rehearsal-fixture.php', 'snapshot'], {**env, 'DB_DATABASE': restored})
            if restored_fingerprint != after:
                raise RuntimeError('Restored data differs: ' + installation)
            private = Path(tmp) / f'{installation}-private'
            private.mkdir()
            (private / 'synthetic.txt').write_text('Synthetic private storage ' + installation)
            archive = Path(tmp) / f'{installation}-files.tar'
            with tarfile.open(archive, 'w') as tar:
                tar.add(private, arcname='private')
            target = Path(tmp) / f'{installation}-restored-files'
            target.mkdir()
            with tarfile.open(archive) as tar:
                tar.extractall(target)
            if (target / 'private/synthetic.txt').read_bytes() != (private / 'synthetic.txt').read_bytes():
                raise RuntimeError('Restored private file differs: ' + installation)
            results.append({'installation': installation, 'fixture': 'explicit first-build pre-update schema (not a historical release)', 'additive_migrations': additive_migrations, 'data_retained': before == after, 'backup_restore_matches': restored_fingerprint == after, 'private_file_restore_matches': True, 'fingerprint': after})
finally:
    cleanup_failed = []
    for database in reversed(created_databases):
        try:
            sql(f'DROP DATABASE {database};')
        except subprocess.CalledProcessError:
            cleanup_failed.append(database)
    if cleanup_failed:
        raise RuntimeError('Rehearsal cleanup failed; only these invocation-owned databases remain: ' + ', '.join(cleanup_failed))

(root / 'docs').mkdir(exist_ok=True)
(root / 'docs/upgrade-rehearsal-results.json').write_text(json.dumps(results, indent=2) + '\n')
print(json.dumps(results, indent=2))
