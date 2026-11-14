#!/usr/bin/env python3
"""Disposable native WordPress HTTP/cron/PHPMailer capture. Uses an existing local archive."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import subprocess
import tarfile
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--wordpress-archive', required=True)
parser.add_argument('--socket', required=True, help='Socket of a disposable MariaDB instance')
parser.add_argument('--plugin-dir', type=Path, help='Use an extracted release instead of source')
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]
helper = repo / 'tests/support/payment-notification-native.php'
db = 'c2c_native_notify_' + secrets.token_hex(6)
sql = ['mariadb', '--no-defaults', '--socket=' + args.socket, '-uroot', '-e']

def check(condition, label):
    if not condition:
        raise AssertionError(label)
    print('PASS: ' + label, flush=True)

def await_state(read, predicate, timeout=8):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = read()
        if predicate(value):
            return value
        time.sleep(.03)
    raise AssertionError('Timed out waiting for fixture state')

with tempfile.TemporaryDirectory(prefix='c2c-notify-native-', dir='/tmp') as directory:
    root = Path(directory)
    with tarfile.open(args.wordpress_archive) as archive:
        archive.extractall(root, filter='data')
    wp = root / 'wordpress'
    if (wp / 'wp-config.php').exists():
        raise RuntimeError('Use an unconfigured local archive')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    site = f'http://127.0.0.1:{port}'
    signing_key = 'whsec_' + secrets.token_hex(24)
    env = os.environ | {'C2C_NOTIFY_NATIVE_ROOT': str(root), 'PHP_CLI_SERVER_WORKERS': '4'}
    config = {
        'DB_NAME': db, 'DB_USER': 'root', 'DB_PASSWORD': '', 'DB_HOST': 'localhost:' + args.socket,
        'DB_CHARSET': 'utf8mb4', 'DB_COLLATE': '', 'WP_HOME': site, 'WP_SITEURL': site,
        'CPT2COLLECTION_STRIPE_WEBHOOK_SECRET': signing_key,
    }
    php = '<?php\n' + '\n'.join(f"define('{key}', {json.dumps(value)});" for key, value in config.items())
    php += "\ndefine('AUTOMATIC_UPDATER_DISABLED', true); define('SAVEQUERIES', true);\n"
    php += "define('ALTERNATE_WP_CRON', file_exists(dirname(__DIR__).'/alternate-cron'));\n"
    php += "$table_prefix='notify_';\nrequire __DIR__.'/wp-settings.php';\n"
    (wp / 'wp-config.php').write_text(php)
    plugin = wp / 'wp-content/plugins/cpt2collection'
    if args.plugin_dir:
        shutil.copytree(args.plugin_dir, plugin)
    else:
        plugin.symlink_to(repo, target_is_directory=True)
    mu = wp / 'wp-content/mu-plugins'
    mu.mkdir()
    (mu / 'notify-fixture.php').symlink_to(helper)

    def cli(*arguments):
        result = subprocess.run(['php', str(helper), *map(str, arguments)], env=env, capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(result.stderr + result.stdout)
        return json.loads(result.stdout) if result.stdout else None

    def records(name):
        path = root / name
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def deliver(event, timeout=5):
        raw = json.dumps(event).encode()
        stamp = str(int(time.time()))
        digest = hmac.new(signing_key.encode(), stamp.encode() + b'.' + raw, hashlib.sha256).hexdigest()
        request = urllib.request.Request(
            site + '/?rest_route=/cpt2collection/v1/stripe/webhook', data=raw,
            headers={'Stripe-Signature': f't={stamp},v1={digest}', 'Content-Type': 'application/json'},
        )
        try:
            response = urllib.request.urlopen(request, timeout=timeout)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.load(response)

    subprocess.run(sql + ['CREATE DATABASE ' + db], check=True)
    server = None
    try:
        installed = cli('install')
        check(installed['schema'] == '0.21.0' and installed['plugin'] == '1.0.0',
              f"native WordPress {installed['wordpress']} fresh install reaches 0.21.0 at plugin version 1.0.0")
        sweep = cli('schedule')
        check(len(sweep) == 1 and sweep[0][0].endswith('_sweep') and sweep[0][2:] == ['hourly', []],
              'one native recurring fallback has empty arguments')
        with (root / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(wp)], env=env,
                                      stdout=log, stderr=log, start_new_session=True)
        def ready():
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.2):
                    return True
            except OSError:
                return False
        await_state(ready, bool)

        first = cli('seed', 'buyer@slow.example.test')
        (root / 'probe-plain').touch()
        check(deliver(first['event']) == (200, {'code': 'accepted'}),
              'native signed webhook returns while captured mail transport is blocked')
        captured = await_state(lambda: records('mail'), lambda rows: len(rows) >= 1)
        check(len(captured) == 1 and captured[0][0] == 'buyer@slow.example.test',
              'core nonblocking loopback begins customer mail before any ordinary sweep')
        (root / 'release-mail').touch()
        captured = await_state(lambda: records('mail'), lambda rows: len(rows) == 3)
        check([row[3:5] for row in captured] == [['text/html', 'UTF-8'], ['text/html', 'UTF-8'], ['text/plain', 'UTF-8']],
              'native PHPMailer sees HTML twice and plain mail in the same process stays plain')
        check(all(row[5] == 'configured@example.test' for row in captured), 'existing WordPress sender filter applies to all mail')
        check('Native &amp; &lt;print&gt;' in captured[0][2] and '€2.15' in captured[0][2] and 'EUR' not in captured[0][2]
              and 'wp-admin' not in captured[0][2] and 'wp-admin/admin.php?' in captured[1][2],
              'native HTML has escaped snapshots, formatted total and admin-only detail link')
        snapshot = cli('snapshot', first['order'])
        check(snapshot == {'customer_name': 'Snapshot 李𠮷 <script>alert(1)</script> & Co',
                           'customer_email': 'buyer@slow.example.test', 'customer_mail_to': None},
              'signed guest Checkout persists Unicode name and email after recipient consumption')
        detail = cli('customer-detail', first['order'], 'admin')
        check(detail.index('Order summary') < detail.index('<h2 id="c2c-order-customer">Customer</h2>') < detail.index('Order items')
              and 'Customer name' in detail and 'Customer email' in detail
              and 'Snapshot 李𠮷 &lt;script&gt;alert(1)&lt;/script&gt; &amp; Co' in detail
              and '>buyer@slow.example.test</td>' in detail and 'mailto:' not in detail
              and detail.count('€2.15') == 6 and '€2.15 EUR' not in detail,
              'English Order detail renders escaped snapshot text in the requested section')
        check(cli('customer-detail', first['order'], 'subscriber') == ''
              and cli('customer-detail', first['order'], 'guest') == '',
              'native detail renderer denies a subscriber and a guest without manage_options')
        first_reference = f"C2C-{first['order']}"
        check(captured[0][1] == f'Payment confirmed · {first_reference}'
              and captured[1][1] == f'New paid order · {first_reference}', 'English site keeps both original subjects')
        check('Thank you for your order. We have received your payment.' in captured[0][2]
              and 'An order has been paid.' in captured[1][2]
              and all('€2.15' in row[2] and 'EUR' not in row[2] for row in captured[:2]),
              'both English HTML bodies use source text and one currency marker')
        wakes = records('wakes')
        check(len(wakes) == 1 and wakes[0]['args']['blocking'] is False,
              'native wake uses a single nonblocking WordPress cron request')
        check(deliver(first['event'])[0] == 200 and len(records('wakes')) == 1 and len(records('mail')) == 3,
              'native paid webhook replay produces no extra wake or mail')

        cli('hold')
        second = cli('seed', 'second@example.test')
        check(deliver(second['event'])[0] == 200 and len(records('wakes')) == 1 and len(records('mail')) == 3,
              'held native cron lock preserves accepted webhook and suppresses the wake')
        pending = [event for event in cli('schedule') if event[0].endswith('_wake')]
        check(len(pending) == 1 and pending[0][1] <= time.time() and pending[0][3] == [],
              'held lock leaves a coalesced due-now event without payload arguments')
        cli('fallback')
        (root / 'fail-loopback').touch()
        third = cli('seed', 'third@example.test')
        check(deliver(third['event'])[0] == 200 and len(records('wakes')) == 2 and len(records('mail')) == 3,
              'failed native HTTP loopback preserves accepted webhook and leaves pending work')
        cli('age', second['order'])
        cli('fallback')
        with urllib.request.urlopen(site + '/wp-cron.php', timeout=5) as response:
            check(response.status == 200, 'core wp-cron.php accepts host-style invocation')
        captured = await_state(lambda: records('mail'), lambda rows: len(rows) == 7)
        check({row[0] for row in captured[3:]} == {'second@example.test', 'third@example.test', 'owner@example.test'},
              'recurring sweep handles failed and held wakes including work over seven days old')
        fourth = cli('seed', 'alternate@example.test')
        (root / 'alternate-cron').touch()
        check(deliver(fourth['event'])[0] == 200 and len(records('wakes')) == 2 and len(records('mail')) == 7,
              'alternate cron POST schedules work without running cron inline')
        check(any(event[0].endswith('_wake') for event in cli('schedule')),
              'alternate cron leaves the empty-argument wake scheduled')
        (root / 'alternate-cron').unlink()
        cli('fallback')
        with urllib.request.urlopen(site + '/wp-cron.php', timeout=5):
            pass
        await_state(lambda: records('mail'), lambda rows: len(rows) == 9)
        def paid_roles_observed():
            return all(cli('state', order['order']) == {'status': 'paid', 'customer_mail_state': '4', 'admin_mail_state': '4'}
                       for order in (first, second, third, fourth))
        check(await_state(paid_roles_observed, bool), 'native mail observations persist for all paid roles')
        cli('hold')
        fifth = cli('seed', 'rollback@slow.example.test')
        sixth = cli('seed', 'concurrent@example.test')
        (root / 'fail-paid-write').touch()
        check(deliver(fifth['event'])[0] == 500, 'failed recipient-bearing paid write preserves webhook retry status')
        check(cli('state', fifth['order']) == {'status': 'awaiting_payment', 'customer_mail_state': None, 'admin_mail_state': None},
              'failed paid write rolls back the paid transition and both outbox slots')
        check(cli('snapshot', fifth['order']) == {
            'customer_name': None, 'customer_email': None, 'customer_mail_to': None,
        }, 'failed paid write leaves both snapshot values NULL')
        (root / 'fail-paid-write').unlink()
        with ThreadPoolExecutor(2) as pool:
            responses = list(pool.map(lambda order: deliver(order['event']), (fifth, sixth)))
        check(all(status in (200, 500) for status, _ in responses), 'concurrent native sales acknowledge or return a retryable disposition')
        for order, (status, _) in zip((fifth, sixth), responses):
            if status == 500:
                check(deliver(order['event'])[0] == 200, 'retry converges after native commerce contention')
        check(all(cli('state', order['order'])['status'] == 'paid' for order in (fifth, sixth)),
              'both sales become durably paid with the cron lock held')
        events = cli('schedule')
        check(sum(event[0].endswith('_wake') for event in events) == 1 and sum(event[0].endswith('_sweep') for event in events) == 1,
              'concurrent native sales coalesce the due-now wake while the cron lock is held')
        diagnostics = records('sql-diagnostics')
        check(len(diagnostics) >= 7 and set(diagnostics) == {'[commerce private update]'},
              'native SAVEQUERIES and wpdb error diagnostics exclude snapshot and recipient values')
        server_log = (root / 'server.log').read_text()
        check(all(value not in server_log for value in ('Snapshot 李', 'buyer@slow.example.test', 'rollback@slow.example.test')),
              'webhook application and PHP server diagnostics contain neither synthetic name nor email')
        (root / 'release-mail').unlink()
        cli('fallback')
        def run_cron():
            with urllib.request.urlopen(site + '/wp-cron.php', timeout=10):
                pass
        with ThreadPoolExecutor(1) as pool:
            cron = pool.submit(run_cron)
            try:
                await_state(lambda: records('mail'), lambda rows: len(rows) == 10)
                cli('change-admin', 'replacement@example.test')
            finally:
                (root / 'release-mail').touch()
            cron.result()
        captured = await_state(lambda: records('mail'), lambda rows: len(rows) == 11)
        check({row[0] for row in captured[9:]} == {'rollback@slow.example.test', 'concurrent@example.test'},
              'administrator address change suppresses queued admin email without rerouting or affecting customers')
        check(await_state(lambda: [cli('state', order['order'])['admin_mail_state'] for order in (fifth, sixth)],
                          lambda states: states == ['2', '2']) == ['2', '2'],
              'suppressed administrator roles are terminal')

        # Queue the intents in English, then let the unauthenticated worker use the new site locale.
        cli('hold')
        localized = cli('seed', 'locale@example.test', 200)
        del localized['event']['data']['object']['customer_details']['name']
        check(deliver(localized['event']) == (200, {'code': 'accepted'}),
              'locale fixture queues both intents with the same signed webhook disposition')
        money_before = cli('money', localized['order'])
        english = cli('localization')
        check(english['locale'] == 'en_US' and 'Proceed to checkout' in english['frontend']
              and english['plurals'] == ['0 items in cart', '1 item in cart', '2 items in cart']
              and all(script is False for _, script in english['translations'].values()),
              'English WordPress retains the source frontend and plural text')
        def verify_js(translations, language):
            runtime = subprocess.run(
                ['node', str(repo / 'tests/support/italian-native-ui.js'), str(wp), str(plugin), language],
                input=json.dumps(translations), capture_output=True, text=True, check=False,
            )
            check(runtime.returncode == 0, f'WordPress JS runtime renders {language} cart, post-checkout and reset text'
                  + (': ' + runtime.stderr if runtime.stderr else ''))

        verify_js(english['translations'], 'en_US')
        languages = wp / 'wp-content/languages'
        languages.mkdir(parents=True, exist_ok=True)
        # Supply only the core number conventions. CPT2Collection catalogs come from the plugin.
        (languages / 'it_IT.l10n.php').write_text(
            "<?php return ['messages'=>['number_format_decimal_point'=>',','number_format_thousands_sep'=>'.']];"
        )
        cli('change-locale')
        locale = cli('localization')
        check(locale['locale'] == 'it_IT' and locale['amounts'] == [
            '€2,00', 'US$2,00', '1.200 JPY', '€92.233.720.368.547.758,07',
        ], 'WordPress decimal and grouping rules preserve exact EUR, USD, JPY and large amounts')
        check(all(text in locale['frontend'] for text in (
            '0 articoli nel carrello', 'Svuota il carrello', 'Procedi al pagamento', 'Procedura di acquisto',
        )) and locale['plurals'] == ['0 articoli nel carrello', '1 articolo nel carrello', '2 articoli nel carrello'],
              'shipped MO localizes native cart and checkout markup and Italian PHP plurals')
        check(locale['checkout']['code'] == 'empty_cart'
              and locale['checkout']['message'] == 'Il carrello deve contenere almeno un articolo.',
              'native cart REST returns localized validation with the original code')
        admin_view = cli('admin-localization', localized['order'])
        check(all(text in admin_view['order'] for text in (
            'Dettagli tecnici del pagamento', 'Informazioni sul pagamento', 'Tentativi di pagamento', 'Aggiornamento del carrello',
            'Eliminazione permanente', 'Esamina l&#039;eliminazione', 'Prezzo unitario', 'Qtà',
            'Date e orari sono espressi in UTC.', 'senza perdere eventuali modifiche successive.',
            'Stato dell’ordine', 'Pagato', 'Elimina definitivamente l’ordine', f"DELETE ORDER C2C-{localized['order']}",
            '<h2 id="c2c-order-customer">Cliente</h2>', 'Nome del cliente', 'Email del cliente', 'Non disponibile', '>locale@example.test</td>',
        )) and admin_view['order'].count('€2,00') == 6 and '€2,00 EUR' not in admin_view['order']
              and 'Azzeramento completo del commercio' in admin_view['reset'] and 'Dettagli avanzati' in admin_view['reset']
              and 'FULL COMMERCE RESET' in admin_view['reset'],
              'native administrator views use the shipped MO and preserve the exact deletion phrase')
        check(all(text in admin_view['settings'] for text in ('Impostazioni di CPT2Collection', 'Quantità minima d’ordine', 'Dollaro statunitense')),
              'native settings labels and currency choices use the Italian catalog')
        translations = locale['translations'] | admin_view['translations']
        for handle, (dependencies, script) in translations.items():
            check(dependencies and 'wp-i18n' in dependencies and script and 'wp.i18n.setLocaleData' in script and '"cpt2collection"' in script,
                  f'core loads the shipped Jed catalog for {handle}')
        verify_js(translations, 'it_IT')
        cli('fallback')
        run_cron()
        captured = await_state(lambda: records('mail'), lambda rows: len(rows) == 13)
        customer, admin = captured[-2:]
        reference = f"C2C-{localized['order']}"
        check(customer[1] == f'Pagamento confermato · {reference}' and admin[1] == f'Nuovo ordine pagato · {reference}',
              'both subjects use the shipped Italian catalog in the cron site locale')
        check('Grazie per il tuo ordine. Abbiamo ricevuto il pagamento.' in customer[2] and 'È stato pagato un ordine.' in admin[2]
              and all('Articolo' in row[2] and 'Qtà' in row[2] and 'Notifica automatica dell’ordine' in row[2]
                      and 'Totale pagato: €2,00' in row[2] and 'EUR' not in row[2] for row in (customer, admin))
              and 'Visualizza l’ordine in WordPress' in admin[2] and 'wp-admin' not in customer[2],
              'both HTML templates use the locale catalog and one currency marker')
        check(f'Riferimento dell’ordine: {reference}' in customer[2]
              and 'Native &amp; &lt;print&gt;' in customer[2],
              'Italian reference and escaped saved item name appear in the HTML')
        escaped = cli('email-escaping', localized['order'])
        check(f'{reference} &lt; &lt;b&gt;Riferimento&lt;/b&gt;' in escaped and '<b>Riferimento</b>' not in escaped,
              'reordered placeholders escape both translated format and translated label')
        check(money_before == cli('money', localized['order']) == {
            'currency': 'EUR', 'total_minor': '200', 'amount_minor': '200',
        }, 'localized formatting leaves stored order and payment amounts unchanged')
        check(deliver(localized['event']) == (200, {'code': 'accepted'}) and len(records('mail')) == 13,
              'locale change leaves paid replay acknowledgement and deduplication unchanged')
    except BaseException:
        if (root / 'server.log').exists():
            print((root / 'server.log').read_text()[-8000:])
        raise
    finally:
        if server:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)
        subprocess.run(sql + ['DROP DATABASE ' + db], check=True)
