#!/usr/bin/env python3
"""Offline deploy-task boundary tests; fake Docker, no containers/network touched."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
MOCK = r'''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
p = Path(os.environ['MOCK_STATE'])
s = json.loads(p.read_text())
a = sys.argv[1:]
s['calls'].append(a)
if a[0] == 'compose':
    if 'ps' in a:
        print('\n'.join(s['ids']))
    elif 'up' in a:
        assert '--no-deps' in a, 'Rolling deploy must not touch dependencies'
        assert a[-1] in ['php', 'cron', 'nginx'], 'Explicit service required'
        if a[-1] == 'php':
            assert '--no-recreate' in a
            target = int(a[a.index('--scale') + 1].split('=')[1])
            while len(s['ids']) < target:
                s['sequence'] += 1
                s['ids'].append('new' + str(s['sequence']))
            assert len(s['ids']) == target, 'Unexpected automatic downscale'
    elif 'build' not in a:
        raise RuntimeError('Unexpected compose call: ' + repr(a))
elif a[0] == 'inspect':
    assert a[-1].startswith('new'), 'Must not gate on saturated old pools'
    print('unhealthy' if os.environ.get('MOCK_BAD') == '1' else 'healthy')
elif a[0] == 'stop':
    assert a[-1].startswith('old'), 'Must not retire a candidate'
elif a[0] == 'rm':
    s['ids'].remove(a[-1])
else:
    raise RuntimeError('Unexpected Docker call: ' + repr(a))
p.write_text(json.dumps(s))
'''

for first, bad in [(False, False), (True, False), (True, True)]:
    with tempfile.TemporaryDirectory(prefix='deploy-code-test-') as directory:
        root = Path(directory)
        (root / 'scripts').mkdir()
        (root / 'bin').mkdir()
        shutil.copyfile(ROOT / 'scripts/deploy.sh', root / 'scripts/deploy.sh')
        state = root / 'state.json'
        state.write_text(json.dumps({'ids': ['old1', 'old2'], 'sequence': 0, 'calls': []}))
        (root / 'bin/docker').write_text(MOCK)
        (root / 'bin/sleep').write_text('#!/bin/sh\nexit 0\n')
        for executable in (root / 'bin').iterdir():
            executable.chmod(0o700)
        env = dict(os.environ, PATH=str(root / 'bin') + ':' + os.environ['PATH'],
                   MOCK_STATE=str(state), MOCK_BAD=str(int(bad)), PHP_REPLICAS='2',
                   HEALTH_TIMEOUT='3', DEPLOY_NGINX_FIRST=str(int(first)))
        result = subprocess.run(['bash', str(root / 'scripts/deploy.sh'), 'code'],
                                env=env, capture_output=True, text=True, timeout=15)
        data = json.loads(state.read_text())
        calls = data['calls']
        assert result.returncode == (1 if bad else 0), result.stdout + result.stderr
        if bad:
            assert data['ids'] == ['old1', 'old2', 'new1']
            assert not any(c[0] in ['stop', 'rm'] for c in calls)
        else:
            assert data['ids'] == ['new1', 'new2']
            assert len([c for c in calls if c[0] == 'stop']) == 2
            ups = [c for c in calls if c[0] == 'compose' and 'up' in c]
            assert len([c for c in ups if c[-1] == 'nginx']) == 1
            assert len([c for c in ups if c[-1] == 'cron']) == 1
            assert (ups[0] if first else ups[-1])[-1] == 'nginx'
        print(f'PASS: rolling deploy nginx_first={first} candidate_failure={bad}')
