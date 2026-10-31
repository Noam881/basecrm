#!/usr/bin/env python3
"""Build a copied installation without optional packages; never mutate the working app.
Default proves core only. --keep finance --filter PayrollPreparationTest proves an
installed Finance package works with HR and every other optional package absent.
Backend verification uses the exclusive local base_test slot.
"""
from pathlib import Path
import argparse
import tempfile
import shutil
import json
import subprocess
import os

parser = argparse.ArgumentParser()
parser.add_argument('--keep', nargs='*', default=[])
parser.add_argument('--filter', default='FoundationTest')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
requirements = json.loads((root / 'composer.json').read_text())['require']
optional = [name for name in requirements if name.startswith('awove/') and name != 'awove/crm-core']
kept = {'awove/' + name.removeprefix('awove/') for name in args.keep}
if not kept.issubset(optional):
    raise RuntimeError('Keep only existing locked optional Awove packages.')
removed = [name for name in optional if name not in kept]
with tempfile.TemporaryDirectory(prefix='base-crm-profile-build-') as tmp:
    target = Path(tmp) / 'app'
    ignored_sources = shutil.ignore_patterns('.git', 'vendor', 'node_modules', '.env*', 'storage', 'public/build', 'test-results', 'playwright-report', '.local-fixtures.json')

    def source_ignores(directory, names):
        ignored = set(ignored_sources(directory, names))
        if Path(directory).resolve() == (root / 'public').resolve():
            ignored.add('build')
        return ignored

    shutil.copytree(root, target, ignore=source_ignores)
    definition = json.loads((target / 'composer.json').read_text())
    removed_namespaces = []
    for name in removed:
        package = target / 'packages' / name
        removed_namespaces.extend(json.loads((package / 'composer.json').read_text())['autoload']['psr-4'])
        shutil.rmtree(package)
        definition['require'].pop(name)
    (target / 'composer.json').write_text(json.dumps(definition))
    env = {**os.environ, 'CRM_INSTALLATION': 'alpha', 'COMPOSER_MIRROR_PATH_REPOS': '1'}
    subprocess.run(['composer', 'update', 'awove/crm-core', *removed, '--no-scripts', '--no-interaction'], cwd=target, env=env, check=True, stdout=subprocess.DEVNULL)
    for folder in ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']:
        (target / folder).mkdir(parents=True, exist_ok=True)
    for cache in (target / 'bootstrap/cache').glob('*.php'):
        cache.unlink()
    subprocess.run(['php', 'artisan', 'package:discover'], cwd=target, env=env, check=True)
    routes = json.loads(subprocess.check_output(['php', 'artisan', 'route:list', '--json'], cwd=target, env=env, text=True))
    for route in routes:
        if any(namespace in route.get('action', '') for namespace in removed_namespaces):
            raise RuntimeError('An absent package still contributes a route.')
    if (target / 'public/build').exists():
        raise RuntimeError('Generated public/build must be absent before the fresh profile build.')
    os.symlink(root / 'node_modules', target / 'node_modules')
    subprocess.run(['npm', 'run', 'build'], cwd=target, env=env, check=True, stdout=subprocess.DEVNULL)
    shutil.copy2(root / '.env.testing', target / '.env.testing')
    phpunit = (target / 'phpunit.xml').read_text()
    if 'awove/example-service-details' in removed:
        phpunit = phpunit.replace('<directory>packages/awove/example-service-details/tests</directory>', '')
    (target / 'phpunit.xml').write_text(phpunit)
    subprocess.run(['php', 'artisan', 'test', '--filter=' + args.filter], cwd=target, env=env, check=True)
    manifest = (target / 'public/build/manifest.json').read_text()
    for name in removed:
        if (target / 'vendor' / name).exists() or f'vendor/{name}/resources/' in manifest:
            raise RuntimeError('An absent package still contributes code or assets.')
    evidence = {'optional_package_absent': True, 'kept_packages': sorted(kept), 'removed_packages': removed, 'php_package_discovery': 'passed', 'backend_core_workflow': 'passed', 'backend_filter': args.filter, 'frontend_production_build': 'passed', 'core_version': json.loads((root / 'release.json').read_text())['core'], 'mirrored_path_packages': True}
    filename = 'core-only-build-result.json' if not kept else 'profile-build-' + '-'.join(sorted(name.split('/')[-1] for name in kept)) + '-result.json'
    (root / 'docs' / filename).write_text(json.dumps(evidence, indent=2) + '\n')
    print('Copied profile PHP discovery, backend workflow and production assets passed with absent optional packages:', ', '.join(removed))
