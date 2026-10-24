#!/usr/bin/env python3
"""Build an independent copy without the optional package, never mutate the working application."""
from pathlib import Path
import tempfile,shutil,json,subprocess,os
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='base-crm-core-only-')as tmp:
 target=Path(tmp)/'app';shutil.copytree(root,target,ignore=shutil.ignore_patterns('vendor','node_modules','.env*','storage','public/build','test-results','playwright-report','.local-fixtures.json'))
 for package in ['example-service-details','customer-service']:
  shutil.rmtree(target/'packages/awove'/package)
 d=json.loads((target/'composer.json').read_text());d['require'].pop('awove/example-service-details');d['require'].pop('awove/customer-service');(target/'composer.json').write_text(json.dumps(d))
 env={**os.environ,'CRM_INSTALLATION':'alpha','COMPOSER_MIRROR_PATH_REPOS':'1'}
 subprocess.run(['composer','update','awove/crm-core','awove/example-service-details','awove/customer-service','--no-scripts','--no-interaction'],cwd=target,env=env,check=True,stdout=subprocess.DEVNULL)
 for folder in ['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','bootstrap/cache']:(target/folder).mkdir(parents=True,exist_ok=True)
 for cache in (target/'bootstrap/cache').glob('*.php'):cache.unlink()
 subprocess.run(['php','artisan','package:discover'],cwd=target,env=env,check=True)
 routes=subprocess.check_output(['php','artisan','route:list','--json'],cwd=target,env=env,text=True)
 assert 'modules/service-details' not in routes
 assert not any((route.get('name') or '').startswith('customer-service.') for route in json.loads(routes))
 # Dependency tree is the same frontend tree; source resolution uses only installed vendor packages in the copied build context.
 os.symlink(root/'node_modules',target/'node_modules')
 subprocess.run(['npm','run','build'],cwd=target,env=env,check=True,stdout=subprocess.DEVNULL)
 shutil.copy2(root/'.env.testing',target/'.env.testing')
 phpunit=(target/'phpunit.xml').read_text().replace('<directory>packages/awove/example-service-details/tests</directory>','')
 (target/'phpunit.xml').write_text(phpunit)
 subprocess.run(['php','artisan','test','--filter=FoundationTest'],cwd=target,env=env,check=True)
 manifest=(target/'public/build/manifest.json').read_text();assert 'service-details' not in manifest
 assert not (target/'vendor/awove/example-service-details').exists()
 assert not (target/'vendor/awove/customer-service').exists()
 assert 'CustomerService/' not in manifest
 (root/'docs/core-only-build-result.json').write_text(json.dumps({'optional_package_absent':True,'php_package_discovery':'passed','backend_core_workflow':'passed','frontend_production_build':'passed','core_version':json.loads((root/'release.json').read_text())['core'],'mirrored_path_packages':True},indent=2)+'\n')
 print('Core-only PHP discovery and production frontend build passed without optional extension code.')
