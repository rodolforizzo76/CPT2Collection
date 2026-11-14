#!/usr/bin/env python3
"""Native HTTP REST verification; creates only a new /tmp WordPress fixture/database.

Requires an already extracted local WordPress distribution, PHP and MariaDB.
No download, provider request, existing wp-config or existing database is permitted.
The caller owns archive extraction and final database/root cleanup.
"""
import argparse
import html
import re
import base64
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import time
import urllib.parse
import urllib.error
import urllib.request

p = argparse.ArgumentParser()
p.add_argument('--wordpress-root', required=True)
p.add_argument('--socket', required=True)
p.add_argument('--schema', default='0.21.0')
p.add_argument('--plugin-root', required=True)
p.add_argument('--benchmark-reset', action='store_true', help='Run only the clean POST/GET reset loop on the native seed')
p.add_argument('--permalinks', choices=['pretty','plain'], default='pretty')
a = p.parse_args()
wp = Path(a.wordpress_root).resolve()
plugin = Path(a.plugin_root).resolve()
if not str(wp).startswith('/tmp/') or not (wp/'wp-load.php').is_file():
    raise RuntimeError('An extracted task-owned /tmp WordPress distribution is required')
if (wp/'wp-config.php').exists():
    raise RuntimeError('Refuse an existing configured installation')
database = 'c2c49_native_'+secrets.token_hex(6)
with socket.socket() as s:
    s.bind(('127.0.0.1', 0))
    port = s.getsockname()[1]
subprocess.run(['mariadb', '--no-defaults', '--socket='+a.socket, '-uroot', '-e', 'CREATE DATABASE '+database], check=True)
(wp.parent/'native-resources.json').write_text(json.dumps({'database':database,'wordpress_root':str(wp),'port':port}, indent=2)+'\n')
config = "<?php\n"
for k, v in {'DB_NAME':database, 'DB_USER':'root', 'DB_PASSWORD':'', 'DB_HOST':'localhost:'+a.socket,
             'DB_CHARSET':'utf8mb4', 'DB_COLLATE':'', 'WP_ENVIRONMENT_TYPE':'local',
             'WP_HOME':f'http://127.0.0.1:{port}', 'WP_SITEURL':f'http://127.0.0.1:{port}',
             **{k:secrets.token_hex(32) for k in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']}}.items():
    config += 'define('+json.dumps(k)+', '+json.dumps(v)+');\n'
config += "define('WP_HTTP_BLOCK_EXTERNAL',true); define('DISABLE_WP_CRON',true); define('AUTOMATIC_UPDATER_DISABLED',true);\n$table_prefix='wp49_'; require __DIR__.'/wp-settings.php';\n"
(wp/'wp-config.php').write_text(config)
os.chmod(wp/'wp-config.php', 0o600)
mu = wp/'wp-content/mu-plugins'
mu.mkdir(exist_ok=True)
(mu/'closure-fixture.php').write_text("<?php\nadd_filter('pre_wp_mail','__return_true'); add_filter('pre_http_request',static fn()=>new WP_Error('offline_fixture','External HTTP disabled'),10,3); add_action('init',static function(){register_post_type('closure_product',['public'=>true,'show_ui'=>true]);});\n")
(wp/'wp-content/plugins/cpt2collection').symlink_to(plugin, target_is_directory=True)
env = os.environ.copy()
env.update(C2C_NATIVE_DISPOSABLE='phase49', C2C_NATIVE_WP_ROOT=str(wp), C2C_NATIVE_PORT=str(port), C2C_NATIVE_PERMALINKS=a.permalinks)
helper = Path(__file__).parent/'support/native-wordpress-closure.php'
def cli(mode):
    result = subprocess.run(['php', str(helper), mode], env=env, capture_output=True, text=True, check=True)
    return json.loads(result.stdout)
cli('install')
auth = cli('auth')  # Credentials remain in memory and the disposable database only.
checks = 0
def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1
    print('PASS: '+label, flush=True)
check(auth['schema']==a.schema, 'native fresh activation reaches schema '+a.schema)
seed = cli('seed')
server_log = wp.parent/'native-http-server.log'
server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(wp)], stdout=server_log.open('w'), stderr=subprocess.STDOUT)
root = '/cpt2collection/v1/commerce/resets'
def request(method, route, body=None, role=None, nonce=True, application=False, query_route=None, extra_query=None):
    if query_route is None:
        query_route = a.permalinks == 'plain'
    headers = {}
    if role:
        headers['Cookie'] = auth[role]['cookie']
        if nonce:
            headers['X-WP-Nonce'] = auth[role]['nonce'] if nonce is True else nonce
    if application:
        headers['Authorization'] = 'Basic '+base64.b64encode(('closure_admin:'+auth['application_password']).encode()).decode()
        if nonce is not True and nonce:
            headers['X-WP-Nonce'] = nonce
    if body is not None:
        headers['Content-Type'] = 'application/json'
    url = f'http://127.0.0.1:{port}'+('/?rest_route='+route if query_route else '/wp-json'+route)
    if extra_query:
        url += ('&' if '?' in url else '?') + urllib.parse.urlencode(extra_query)
    req = urllib.request.Request(url, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read()), response.headers
def deletion_body(order, snapshot):
    return {'confirmation':'DELETE ORDER C2C-'+str(order), 'expected_context': {
        'installation_id':snapshot['control']['installation_id'],
        'epoch_id':str(snapshot['control']['current_epoch_id']), 'epoch_identity':snapshot['epoch_identity']}}
def admin_page(order, role='admin', inspection=False):
    url=f'http://127.0.0.1:{port}/wp-admin/admin.php?page=cpt2collection-orders&order_id={order}&order_status=pending&paged=2&redirect=https%3A%2F%2Fevil.invalid'
    if inspection: url+='&deletion_inspection=1'
    req=urllib.request.Request(url,headers={'Cookie':auth[role]['cookie']})
    try: response=urllib.request.urlopen(req,timeout=30)
    except urllib.error.HTTPError as error: response=error
    return response.status, response.read().decode()
try:
    for attempt in range(50):
        try:
            with socket.create_connection(('127.0.0.1',port), timeout=.2):
                break
        except OSError:
            time.sleep(.1)
    status, index, _ = request('GET', '/')
    check(status==200 and root in index['routes'] and root+'/(?P<operation_identity>[^/]+)/resume' in index['routes'], 'rest_api_init exposes all real registered reset routes')
    identity = secrets.token_hex(16)
    snap = cli('snapshot')
    epoch = snap['epoch_identity']
    body = {'operation_identity':identity,'expected_epoch_identity':epoch,'confirmation':'FULL COMMERCE RESET'}
    if a.benchmark_reset:
        at = time.perf_counter()
        status, ack, _ = request('POST', root, body, role='admin')
        check(status == 202 and ack['operation']['revision'] == '0', 'native benchmark starts at revision zero')
        for revision in range(1000):
            status, info, _ = request('GET', root+'/'+identity, role='admin')
            op = info['operation']
            check(op == ack['operation'] and op['revision'] == str(revision), 'fresh native status agrees at revision '+str(revision))
            if op['completed']:
                elapsed = time.perf_counter() - at
                check(status == 200 and op['checkpoint'] == 'z1_n_0_done', 'native benchmark verifies completion')
                print('NATIVE_BENCHMARK '+json.dumps({
                    'wordpress': auth['version'], 'permalinks': a.permalinks,
                    'revision': revision, 'http_requests': 2 * (revision + 1), 'seconds': elapsed,
                }), flush=True)
                break
            check(status == 202 and op['can_resume'], 'native benchmark remains resumable')
            status, ack, _ = request('POST', root+'/'+identity+'/resume', {
                'expected_epoch_identity': epoch, 'revision': str(revision),
                'confirmation': 'FULL COMMERCE RESET '+identity,
            }, role='admin')
            check(status in (200, 202), 'native benchmark commits one bounded continuation')
        else:
            raise AssertionError('Native benchmark failed to converge')
        final = cli('snapshot')
        check(final['stock'] == snap['stock'] and final['control']['state'] == 'ready', 'native benchmark preserves stock and reopens commerce')
        check(all(final[k]['count'] == 0 for k in ['carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events']), 'native benchmark verifies operational erasure')
        raise SystemExit(0)
    for role, nonce, expected in [(None,False,401),('subscriber',True,403),('admin',False,401),('admin','invalid',403),('admin',auth['admin']['expired_nonce'],403)]:
        status, data, _ = request('POST',root,body,role,nonce)
        check(status==expected, 'native auth boundary '+str((role,nonce is True,expected)))
        if nonce=='invalid':
            check(data['code']=='rest_cookie_invalid_nonce', 'invalid cookie nonce rejected by WordPress core')
    check(cli('snapshot')==snap, 'failed native authentication performs no commerce mutation')
    query_status, query_data, _ = request('GET',root+'/'+identity,role='admin',query_route=True)
    check(query_status==404 and query_data.get('code')=='not_found', 'query-form native status reaches exact read-only service')
    check(cli('snapshot')==snap, 'query-form native status never changes commerce data')
    status, data, _ = request('GET',root+'/'+identity+'/',role='admin')
    check(status==404 and data.get('code')=='not_found', 'native legitimate trailing-slash route reaches exact status')
    for action, route, payload in [('GET',root+'/'+identity,None),('POST',root,body)]:
        status, data, _ = request(action,route,payload,role='admin',extra_query={'unexpected':'x'})
        check(status==400 and data.get('code')=='invalid_request', 'native routing still rejects unexpected query metadata '+action)
    check(cli('snapshot')==snap, 'native rejected query metadata and status probes make no commerce writes')

    status, _, _ = request('POST', root, {**body,'confirmation':True}, 'admin')
    check(status==400, 'native administrator still needs exact destructive confirmation')
    status, _, _ = request('POST', root, {**body,'expected_epoch_identity':'bad'}, 'admin')
    check(status==400, 'native route rejects malformed source identity')
    status, _, _ = request('GET', root+'/BAD', role='admin')
    check(status==400, 'native route rejects noncanonical operation identity')
    reset_page=f'http://127.0.0.1:{port}/wp-admin/admin.php?page=cpt2collection-commerce-reset'
    def reset_admin(role='admin'):
        req=urllib.request.Request(reset_page,headers={'Cookie':auth[role]['cookie']})
        try: response=urllib.request.urlopen(req,timeout=30)
        except urllib.error.HTTPError as error: response=error
        return response.status,response.read().decode()
    status,reset_html=reset_admin()
    check(status==200 and 'id="c2c-reset"' in reset_html and 'commerce-reset-admin.js' in reset_html,'native dedicated reset menu/asset renders')
    menu_slugs=[]
    sidebar=reset_html.split('id="adminmenumain"',1)[1].split('id="wpcontent"',1)[0]
    for href in re.findall(r'<a[^>]+href=[\'"]([^\'"]+)[\'"]',sidebar):
        slug=urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(href)).query).get('page',[''])[0]
        if slug in ['cpt2collection-orders','cpt2collection-commerce-reset','cpt2collection'] and slug not in menu_slugs: menu_slugs.append(slug)
    check(menu_slugs==['cpt2collection-orders','cpt2collection-commerce-reset','cpt2collection'],'native menu order is Orders, Full Commerce Reset, Settings')
    reset_config=json.loads(html.unescape(re.search(r'id="c2c-reset"[^>]*data-config="([^"]+)"',reset_html).group(1)))
    check(reset_config['source']==epoch and reset_config['nonce']==auth['admin']['nonce'],'native page obtains confirmed shared ready context and session nonce')
    check('commerce-reset-admin.js' not in admin_page(seed['orders'][0])[1],'reset asset never loads on Order detail')
    status,denied=reset_admin('subscriber')
    check(status==403 and 'id="c2c-reset"' not in denied,'native subscriber cannot render reset UI')
    check(cli('snapshot')==snap,'native reset page/menu/context GETs never mutate commerce')
    delete_route = '/cpt2collection/v1/orders/'+str(seed['orders'][0])
    delete_payload=deletion_body(seed['orders'][0],snap)
    for role, nonce, expected in [(None,False,401),('subscriber',True,403),('admin',False,401),('admin','invalid',403),('admin',auth['admin']['expired_nonce'],403)]:
        status, data, _=request('DELETE',delete_route,delete_payload,role,nonce)
        check(status==expected, 'native DELETE auth/nonce boundary '+str((role,nonce is True,expected)))
    invalid_payloads=[None,{}, {'confirmation':delete_payload['confirmation']}, {**delete_payload,'unexpected':'x'}]
    invalid_payloads += [{**delete_payload,'confirmation':v} for v in [False,'','DELETE ORDER C2C-999',' '+delete_payload['confirmation'],delete_payload['confirmation'].lower()]]
    invalid_payloads += [{**delete_payload,'expected_context':v} for v in [None,{}, {**delete_payload['expected_context'],'epoch_id':1}, {**delete_payload['expected_context'],'epoch_identity':'bad'}]]
    for payload in invalid_payloads:
        status,data,_=request('DELETE',delete_route,payload,'admin')
        check(status==400 and not data['success'],'native DELETE rejects missing/malformed confirmation or context')
    for value in ['0','01','-1','1.0','9999999999999999999999999999']:
        status,data,_=request('DELETE','/cpt2collection/v1/orders/'+value,delete_payload,'admin')
        check(status==400 and data['code']=='invalid_order_id','native DELETE canonical selector '+value)
    status,data,_=request('DELETE',delete_route,delete_payload,'admin',extra_query={'confirmation':delete_payload['confirmation']})
    check(status==400,'native DELETE rejects business parameters in query')
    status,data,_=request('DELETE',delete_route,{**delete_payload,'expected_context':{**delete_payload['expected_context'],'epoch_identity':'f'*32}},'admin')
    check(status==409 and data['code']=='stale_context','native wiring rejects wrong exact expected context')
    check(cli('snapshot')==snap,'native rejected DELETE requests preserve all commerce data and stock')
    status,detail=admin_page(seed['orders'][0])
    check(status==200 and 'id="c2c-delete-form" hidden' in detail and 'id="c2c-delete-submit" disabled' in detail,'real wp-admin renders deliberate hidden confirmation and disabled submit')
    check('order-deletion.js' in detail and 'order-detail.css' in detail and 'Permanently delete Order' in detail and 'may still complete' in detail,'real admin wiring enqueues detail assets and displays the financial warning')
    style_url=html.unescape(re.search(r"""<link[^>]+id=['"]cpt2collection-order-detail-css['"][^>]+href=['"]([^'"]+)""",detail).group(1))
    with urllib.request.urlopen(style_url,timeout=30) as stylesheet:
        check(stylesheet.status==200 and stylesheet.headers.get_content_type()=='text/css'
              and stylesheet.read()==(plugin/'assets/css/order-detail.css').read_bytes(),
              'detail stylesheet is served as CSS and matches the packaged asset')
    check('order-detail.css' not in reset_html,'unrelated Full Commerce Reset screen omits the detail stylesheet')
    config=json.loads(html.unescape(re.search(r'id="c2c-order-deletion"[^>]*data-config="([^"]+)"',detail).group(1)))
    check(config['expected_context']==delete_payload['expected_context'] and config['nonce']==auth['admin']['nonce'],'real admin issues exact displayed context and session REST nonce')
    check('redirect=' not in html.unescape(re.search(r'id="c2c-delete-inspect" href="([^"]+)"',detail).group(1)),'native navigation discards arbitrary return URL')
    status,inspection=admin_page(seed['orders'][0],inspection=True)
    check(status==200 and 'id="c2c-delete-form"' not in inspection and 'outcome remains unverified' in inspection,'native recovery is read-only even while Order exists')
    status,denied=admin_page(seed['orders'][0],'subscriber')
    check(status==403 and 'id="c2c-delete-form"' not in denied and 'order-detail.css' not in denied,'real wp-admin capability rejects subscriber without detail assets')
    check(cli('snapshot')==snap,'native detail, reload and recovery GETs never mutate commerce')
    for code in ['deleted','already_deleted']:
        status, data, _ = request('DELETE', delete_route, deletion_body(seed['orders'][0], snap), role='admin')
        check(status==200 and data['code']==code, 'native permanent DELETE '+code)
    status, data, headers = request('POST', root, body, 'admin')
    check(status==202 and data['code']=='started' and not data['operation']['completed'], 'valid native cookie nonce starts an incomplete durable reset')
    check('no-store' in headers.get('Cache-Control',''), 'native reset response is not cacheable')
    status, data, _ = request('POST', root, body, 'admin')
    check(status==200 and data['code']=='existing', 'native duplicate start retains the original operation')
    after_start = cli('snapshot')
    status,resetting_html=reset_admin()
    check(status==200 and 'ready commerce context could not be verified' in resetting_html,'native resetting page fails closed instead of inventing ready context')
    check(cli('snapshot')==after_start,'rendering during reset never changes progress')
    for app_nonce in [False, 'invalid']:
        status, data, _ = request('GET',root+'/'+identity,application=True,nonce=app_nonce)
        check(status==202 and data['operation']['operation_identity']==identity, 'core application password auth works without a cookie nonce '+str(app_nonce))
    check(cli('snapshot')==after_start, 'native status polling is commerce read-only')
    phases = set()
    calls = 0
    while True:
        status, data, _ = request('GET', root+'/'+identity, role='admin')
        op = data['operation']
        phases.add(op['phase'])
        if op['completed']:
            check(status==200, 'native completion is confirmed HTTP 200')
            break
        resume = {'expected_epoch_identity':epoch,'revision':op['revision'],'confirmation':'FULL COMMERCE RESET '+identity}
        if calls==0:
            status, _, _ = request('POST',root+'/'+identity+'/resume',{**resume,'revision':0},'admin')
            check(status==400, 'native revision requires canonical decimal string')
        status, data, _ = request('POST',root+'/'+identity+'/resume',resume,'admin')
        check(status in [200,202] and data['operation']['revision']==str(int(op['revision'])+1), 'native bounded resume advances one durable revision '+str(calls))
        status, _, _ = request('POST',root+'/'+identity+'/resume',resume,'admin')
        check(status==(200 if data['operation']['completed'] else 409), 'native repeated revision cannot duplicate work '+str(calls))
        calls += 1
        if calls>300:
            raise AssertionError('native lifecycle did not converge')
    check({'validating','retiring_claims','terminalizing_events','deleting'} <= phases, 'native HTTP lifecycle traverses all semantic stages')
    final = cli('snapshot')
    check(all(final[k]['count']==0 for k in ['carts','cart_items','orders','order_items','reservations','payment_sessions','payment_events']), 'native reset erases only operational commerce domains')
    check(final['commerce_epochs']['count']==2 and final['commerce_resets']['count']==1 and final['order_deletions']['count']==1, 'native final switch and genuine deletion proof persist')
    check(final['stock']==snap['stock'], 'native deletion and reset never restore physical stock')
    status,data,_=request('DELETE',delete_route,delete_payload,'admin')
    check(status==409 and data['code']=='stale_context' and cli('snapshot')==final,'native pre-reset displayed context cannot authorize post-transition DELETE')
    status,missing=admin_page(seed['orders'][1],inspection=True)
    check(status==200 and 'Order not found.' in missing and 'outcome remains unverified' in missing and 'id="c2c-delete-form"' not in missing,'native absent Order recovery cannot claim deletion proof')
    status, data, _ = request('DELETE',delete_route,deletion_body(seed['orders'][0],final),role='admin')
    check(status==200 and data['code']=='already_deleted' and cli('snapshot')==final, 'native historical deletion recognition is mutation-free')
    status, data, _ = request('DELETE','/cpt2collection/v1/orders/'+str(seed['orders'][1]),deletion_body(seed['orders'][1],final),role='admin')
    check(status==409 and data['code']=='absence_unprovable' and cli('snapshot')==final, 'native reset-erased Order is not fabricated individual deletion proof')
    check(not any(field in json.dumps(op) for field in ['installation_id','requested_by','target_epoch_id','request_snapshot','error_code']), 'native response omits sensitive internal fields')
    print('NATIVE_RESULT '+json.dumps({'permalinks':a.permalinks,'wordpress':auth['version'],'checks':checks,'resume_calls':calls,'database':database,'provider_http_calls':0,'query_route_http':query_status,'query_route_code':query_data.get('code')}), flush=True)
finally:
    server.terminate()
    server.wait(timeout=10)
