#!/usr/bin/env python3
"""Rehearse first-build additive upgrade + synthetic DB and private-file restore.
Only creates/drops new scratch databases with a base_rehearsal_ prefix.
"""
from pathlib import Path
import subprocess,os,json,hashlib,tempfile,tarfile
root=Path(__file__).resolve().parents[1]
binpath=Path(os.environ.get('CRM_POSTGRES_BIN','/opt/homebrew/opt/libpq@18/bin'))
def readenv(name):return dict(line.split('=',1) for line in (root/name).read_text().splitlines() if '='in line)
admin={**os.environ,'PGPASSWORD':readenv('.env')['POSTGRES_PASSWORD']}
def sql(command):subprocess.run([str(binpath/'psql'),'-h','127.0.0.1','-p','55438','-U','bootstrap','-d','postgres','-v','ON_ERROR_STOP=1'],input=command.encode(),env=admin,check=True,stdout=subprocess.DEVNULL)
def run(args,env):return subprocess.check_output(args,cwd=root,env=env,text=True).strip()
results=[]
with tempfile.TemporaryDirectory(prefix='base-crm-rehearsal-') as tmp:
 for installation in ['alpha','beta']:
  db=f'base_rehearsal_{installation}_{os.getpid()}';restored=db+'_restored';role=f'base_{installation}'
  for database in [db,restored]:sql(f'CREATE DATABASE {database} OWNER {role}; REVOKE ALL ON DATABASE {database} FROM PUBLIC;')
  settings=readenv(f'.env.{installation}');env={**os.environ,**{k:v.strip('"') for k,v in settings.items()},'DB_DATABASE':db,'CRM_INSTALLATION':installation,'CRM_REHEARSAL':'1','APP_ENV':'local','QUEUE_CONNECTION':'sync'}
  run(['php','artisan','migrate','--force','--path=database/migrations','--path=packages/awove/crm-core/database/migrations/2026_10_22_000100_create_core.php','--path=packages/awove/example-service-details/database/migrations/2026_10_22_000150_create_service_details.php'],env)
  before=run(['php','scripts/rehearsal-fixture.php','seed'],env)
  run(['php','artisan','migrate','--force'],env);run(['php','artisan','crm:install'],env)
  after=run(['php','scripts/rehearsal-fixture.php','snapshot'],env)
  if before!=after:raise RuntimeError('Data changed during additive upgrade: '+installation)
  dump=Path(tmp)/f'{installation}.dump';pg={**env,'PGPASSWORD':settings['DB_PASSWORD']}
  subprocess.run([str(binpath/'pg_dump'),'-h','127.0.0.1','-p','55438','-U',role,'-d',db,'-Fc','-f',str(dump)],env=pg,check=True)
  subprocess.run([str(binpath/'pg_restore'),'-h','127.0.0.1','-p','55438','-U',role,'-d',restored,'--no-owner',str(dump)],env=pg,check=True)
  restored_fingerprint=run(['php','scripts/rehearsal-fixture.php','snapshot'],{**env,'DB_DATABASE':restored})
  if restored_fingerprint!=after:raise RuntimeError('Restored data differs: '+installation)
  private=Path(tmp)/f'{installation}-private';private.mkdir();(private/'synthetic.txt').write_text('Synthetic private storage '+installation)
  archive=Path(tmp)/f'{installation}-files.tar'
  with tarfile.open(archive,'w')as tar:tar.add(private,arcname='private')
  target=Path(tmp)/f'{installation}-restored-files';target.mkdir()
  with tarfile.open(archive)as tar:tar.extractall(target)
  assert (target/'private/synthetic.txt').read_bytes()==(private/'synthetic.txt').read_bytes()
  results.append({'installation':installation,'fixture':'explicit first-build pre-update schema (not a historical release)','additive_migration':'2026_10_22_000200_add_lead_external_reference','data_retained':before==after,'backup_restore_matches':restored_fingerprint==after,'private_file_restore_matches':True,'fingerprint':after})
  for database in [db,restored]:sql(f'DROP DATABASE {database};')
(root/'docs').mkdir(exist_ok=True);(root/'docs/upgrade-rehearsal-results.json').write_text(json.dumps(results,indent=2)+'\n');print(json.dumps(results,indent=2))
