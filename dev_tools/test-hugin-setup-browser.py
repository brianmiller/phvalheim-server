#!/usr/bin/env python3
# requires: manual — isolated fixture, real database/API, Chromium and local provider
import json,os,subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
from fixture_analytics import assert_isolated
f=json.loads(Path(os.environ['PHV_PRESENTATION_FIXTURE']).read_text());name=f['name']
assert_isolated(json.loads(subprocess.check_output(['docker','inspect',name]))[0])
def cfg(data):
 subprocess.run(['docker','exec','-i',name,'tee','/tmp/hugin-fixture/hugin-provider.json'],input=json.dumps(data),text=True,stdout=subprocess.DEVNULL,check=True)
with sync_playwright() as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1000});errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 def api(action,data=None):
  url=f['admin']+'/adminAPI.php?action='+action
  return (page.request.get(url) if data is None else page.request.post(url,data=data)).json()
 cfg({'models':[{'id':'old-model','context_length':64000}]})
 provider={'kind':'openai_compatible','label':'Fixture Gateway','base_url':'http://127.0.0.1:18999/v1','model':'old-model','api_key':'fixture-key','enabled':1}
 r=api('saveAiProvider',provider);assert r['success'],r;pid=r['id'];provider['id']=pid
 try:
  # Simulate a pre-Hugin-Setup RC schema with an existing credential/model row.
  subprocess.run(['docker','exec',name,'mysql','-uphvalheim_user','-pphvalheim_secretpassword','phvalheim','-e','ALTER TABLE ai_providers DROP COLUMN runtime_limits'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  subprocess.run(['docker','exec',name,'bash','/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh'],check=True,stdout=subprocess.DEVNULL)
  initial=api('getAiProviders')['providers'];p=next(p for p in initial if p['id']==pid)
  assert p['models'][0]['id']=='old-model' and p['models_fetched_at']>0
  # Failed forced refresh retains previous DB list and the previous successful fetch time.
  cfg({'fail':True});r=api('getAiProviders&refresh='+str(pid));stale=next(p for p in r['providers'] if p['id']==pid)
  assert stale['models']==p['models'] and stale['models_fetched_at']==p['models_fetched_at'] and stale['models_error']
  cfg({'models':[{'id':'new-model','context_length':32000}]})
  page.goto(f['admin']+'/settings.php#ai');page.get_by_role('button',name='Hugin Setup',exact=True).click()
  page.locator('[data-edit="'+str(pid)+'"]').click()
  page.locator('#aiWizNext').click();page.locator('#aiWizNext').click()
  expect(page.locator('[data-m="new-model"]')).to_be_visible()
  expect(page.locator('#aiWizManual')).to_have_value('old-model')
  expect(page.locator('#aiWizModelInfo')).to_contain_text('not in the fetched list')
  assert 'just now' not in page.locator('#aiWizBody').inner_text()
  page.locator('[data-m="new-model"]').click()
  expect(page.locator('#aiWizModelInfo')).to_contain_text('32,000')
  cfg({'fail':True});page.locator('#aiWizRefresh').click()
  expect(page.get_by_role('alert')).to_contain_text('Previous list and selection retained')
  expect(page.locator('#aiWizManual')).to_have_value('new-model');expect(page.locator('[data-m="new-model"]')).to_be_visible()
  cfg({'models':[{'id':'new-model','context_length':32000},{'id':'another-model'}]})
  page.locator('#aiWizRefresh').click();expect(page.locator('[data-m="another-model"]')).to_be_visible()
  page.locator('#aiWizNext').click();expect(page.locator('[data-ai-limit]')).to_have_count(10)
  page.locator('[data-ai-limit="request_timeout_s"]').fill('250')
  page.locator('[data-ai-limit="context_tokens"]').fill('30000')
  page.locator('[data-ai-limit="response_tokens"]').fill('900')
  page.locator('[data-ai-limit="concurrency"]').fill('1')
  page.locator('#aiWizNext').click();expect(page.locator('#aiWizTestOut')).not_to_have_text('Testing…',timeout=20000)
  page.locator('#aiWizNext').click();expect(page.locator('#aiWizardOverlay')).not_to_have_class('mods-modal-overlay show')
  saved=next(p for p in api('getAiProviders')['providers'] if p['id']==pid)
  assert saved['runtime_limits']['request_timeout_s']==250 and saved['runtime_limits']['concurrency']==1 and saved['model']=='new-model'
  assert saved['has_key'] and 'api_key' not in saved
  # Real chat SSE crosses nginx/FPM, compacts history and produces an answer with saved limits.
  response=page.request.post(f['admin']+'/aiStream.php',data={'provider_id':pid,'model':'new-model','message':'Retain the current task','history':[{'role':'user','content':'previous context '*6000},{'role':'assistant','content':'Earlier answer'}]},timeout=30000)
  events=[json.loads(line[6:]) for line in response.text().splitlines() if line.startswith('data: ')]
  assert 'compaction' in [e['type'] for e in events] and 'memory' in [e['type'] for e in events],events
  assert events[-1]['type']=='done' and events[-1]['content']=='Fixture reply.',events
  # A saved five-second timeout reaches the actual chat stream and is recoverable.
  limit={**saved['runtime_limits'],'request_timeout_s':5}
  assert api('saveAiProvider',{**provider,'model':'new-model','runtime_limits':limit})['success']
  cfg({'delay':6})
  response=page.request.post(f['admin']+'/aiStream.php',data={'provider_id':pid,'model':'new-model','message':'Short request'},timeout=15000)
  events=[json.loads(line[6:]) for line in response.text().splitlines() if line.startswith('data: ')]
  assert events[-1]['type']=='error' and 'timed out' in events[-1]['error'],events
  import time;time.sleep(2);cfg({'models':[{'id':'new-model','context_length':32000}]})
  assert api('saveAiProvider',{**provider,'model':'new-model','runtime_limits':saved['runtime_limits']})['success']
  bad=api('saveAiProvider',{**provider,'runtime_limits':{'concurrency':0}});assert not bad['success']
  assert next(p for p in api('getAiProviders')['providers'] if p['id']==pid)['runtime_limits']==saved['runtime_limits']
  # Migration rerun on an existing RC database preserves settings.
  subprocess.run(['docker','exec',name,'bash','/opt/stateless/engine/dbUpdates/dbUpdate_2.62.sh'],check=True,stdout=subprocess.DEVNULL)
  assert next(p for p in api('getAiProviders')['providers'] if p['id']==pid)['runtime_limits']==saved['runtime_limits']
  page.reload();page.get_by_role('button',name='Hugin Setup',exact=True).click();page.locator('[data-edit="'+str(pid)+'"]').click()
  page.locator('#aiWizNext').click();page.locator('#aiWizNext').click();expect(page.locator('#aiWizRefresh')).to_be_enabled();page.locator('#aiWizNext').click()
  expect(page.locator('[data-ai-limit="request_timeout_s"]')).to_have_value('250')
  for width in [390,1440]:
   page.set_viewport_size({'width':width,'height':900})
   assert page.evaluate('document.documentElement.scrollWidth<=window.innerWidth')
   page.screenshot(path='/tmp/phv-hugin-'+str(width)+'.png',full_page=True)
  assert not errors,errors
  print('Hugin Setup browser/API/cache/persistence/upgrade checks passed')
 finally:
  page.evaluate('if(typeof aiWizClose==="function") aiWizClose()')
  api('deleteAiProvider',{'id':pid});browser.close()
