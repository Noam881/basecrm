#!/usr/bin/env python3
# Create private local-only credentials once; never touch another CRM.
from pathlib import Path
import secrets,base64
root=Path(__file__).resolve().parents[1]
if (root/'.env.alpha').exists(): raise SystemExit('Local env already exists; refusing to replace keys or credentials.')
bootstrap=secrets.token_hex(24)
for name,port in [('alpha',8091),('beta',8092),('test',8093)]:
 password=secrets.token_hex(24)
 content=f'''APP_NAME="{name.title()} CRM"
APP_ENV=local
APP_KEY=base64:{base64.b64encode(secrets.token_bytes(32)).decode()}
APP_DEBUG=true
APP_URL=http://127.0.0.1:{port}
CRM_INSTALLATION={name if name!='test' else 'alpha'}
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55438
DB_DATABASE=base_{name}
DB_USERNAME=base_{name}
DB_PASSWORD={password}
SESSION_DRIVER=database
SESSION_COOKIE=base_{name}_session
SESSION_PATH=/{''}
CACHE_STORE=database
CACHE_PREFIX=base_{name}_
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1028
MAIL_FROM_ADDRESS=hello@{name}.example.test
MAIL_FROM_NAME="{name.title()} CRM"
LOG_CHANNEL=stack
'''
 p=root/f'.env.{name}';p.write_text(content);p.chmod(0o600)
p=root/'.env';p.write_text((root/'.env.alpha').read_text()+f'\nPOSTGRES_PASSWORD={bootstrap}\n');p.chmod(0o600)
print('Created private Alpha, Beta and test environments. No shared passwords or app keys.')
