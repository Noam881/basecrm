from pathlib import Path
import subprocess,json
root=Path(__file__).resolve().parents[1]
task=subprocess.check_output(['php','scripts/concurrency-fixture.php','setup'],cwd=root,text=True).strip()
with open('/tmp/base-crm-reminder-workers.log','w')as log:
 workers=[subprocess.Popen(['php','artisan','queue:work','--stop-when-empty','--tries=3'],cwd=root,stdout=log,stderr=log)for _ in range(2)]
 for worker in workers:
  if worker.wait()!=0:raise RuntimeError('Reminder worker failed.')
result=subprocess.check_output(['php','scripts/concurrency-fixture.php','check',task],cwd=root,text=True)
(root/'docs/reminder-concurrency-result.json').write_text(result+'\n');print(result)
