#!/usr/bin/env python3
"""Provision only the named local example databases, with least privilege runtime roles."""
from pathlib import Path
import subprocess,os
root=Path(__file__).resolve().parents[1]
def env(path):return dict(line.split('=',1) for line in path.read_text().splitlines() if '='in line)
admin=env(root/'.env')['POSTGRES_PASSWORD']
psql='/opt/homebrew/opt/libpq@18/bin/psql'
for name in ['alpha','beta','test']:
 d=env(root/f'.env.{name}');password=d['DB_PASSWORD']
 sql=f"CREATE ROLE base_{name} LOGIN PASSWORD '{password}'; ALTER DATABASE base_{name} OWNER TO base_{name}; REVOKE ALL ON DATABASE base_{name} FROM PUBLIC; GRANT ALL ON DATABASE base_{name} TO base_{name};"
 subprocess.run([psql,'-h','127.0.0.1','-p','55438','-U','bootstrap','-d','postgres','-v','ON_ERROR_STOP=1', ],env={**os.environ,'PGPASSWORD':admin},input=sql.encode(),stdout=subprocess.DEVNULL,check=True)
print('Local roles provisioned. Each role owns only its installation database.')
