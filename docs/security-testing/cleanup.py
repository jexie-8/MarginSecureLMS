"""Remove the data a test run leaves in the local demo database.

The WSTG run and the deployment smoke test talk to the real demo stack, so the
announcements, submissions, grades, files and test accounts they create would
otherwise stay visible to anyone using the demo (attack payloads included).

How it works: a run calls `db_now()` before its first request and
`cleanup(since)` at the end. Everything created in those tables from that
moment on is removed, together with the uploaded files. The audit log is
append-only (a trigger forbids deleting it) and is kept as evidence.

Caveat: the cleanup goes by time, so content that someone else creates in the
demo while a test run is in progress is removed too. Run the tests when nobody
is using the demo. Pass --keep-data to run_wstg.py or deploy_smoke.py to look
at what a run created before removing it by hand:

  python cleanup.py --since "2026-11-27 10:00:00+00" [--dry-run]
"""
import os
import re
import subprocess
import sys

COMPOSE_DIR = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'deploy', 'local'))
DB = 'margin_db'
# Accounts the WSTG run tries to create. The seeded demo and second-university
# accounts are never removed.
TEST_EMAILS = ('cross-uni@margin.test', 'formula@margin.test', 'evil-pa@margin.test', 'evil-pa2@margin.test', 'x@y.test')
SAFE_PATH = re.compile(r'^(submissions|materials)/\d+/[A-Za-z0-9_-]+\.[A-Za-z0-9]{1,10}$')
STAMP = re.compile(r'^\d{4}-\d{2}-\d{2}[ T][\d:.]+([+-]\d{2}(:?\d{2})?|Z)?$')


def _compose(args, stdin=None):
    return subprocess.run(['docker', 'compose', *args], cwd=COMPOSE_DIR, input=stdin,
                          capture_output=True, text=True, timeout=120)


def _psql(sql):
    r = _compose(['exec', '-T', 'db', 'psql', '-U', 'postgres', '-d', DB, '-v', 'ON_ERROR_STOP=1', '-At', '-F', '\t'], stdin=sql)
    if r.returncode != 0:
        raise RuntimeError(f'psql failed: {r.stderr.strip()}')
    return r.stdout


def db_now():
    """The database clock, so the window does not depend on this PC's clock."""
    return _psql('select now();').strip()


def cleanup(since, dry_run=False):
    if not STAMP.match(since):
        raise ValueError(f'not a timestamp: {since!r}')
    s = f"'{since}'::timestamptz"
    emails = ', '.join(f"'{e}'" for e in TEST_EMAILS)
    window = {
        'subs': f'select id from submissions where created_at >= {s}',
        'users': f'select id from users where created_at >= {s} and email in ({emails}) '
                 'and not exists (select 1 from audit_logs a where a.actor_id = users.id)',
    }
    paths = _psql(f"""
        select path from submission_files where submission_id in ({window['subs']})
        union all select path from course_materials where created_at >= {s};""").split()

    steps = [
        ('notifications', f'delete from lms_notifications where created_at >= {s}'),
        ('grades', f"delete from grades where created_at >= {s} or submission_id in ({window['subs']})"),
        ('submissions', f'delete from submissions where created_at >= {s}'),
        ('materials', f'delete from course_materials where created_at >= {s}'),
        ('announcements', f'delete from announcements where created_at >= {s}'),
        ('calendar events', f'delete from calendar_events where created_at >= {s}'),
        ('attendance records', f'delete from attendance_records where created_at >= {s}'),
        ('attendance sessions', f'delete from attendance_sessions s where s.created_at >= {s} '
                                'and not exists (select 1 from attendance_records r where r.attendance_session_id = s.id)'),
        ('test enrollments', f"delete from enrollments where student_id in ({window['users']})"),
        ('test accounts', f"delete from users where id in ({window['users']})"),
    ]
    sql = ['begin;']
    for label, statement in steps:
        sql.append(f"with d as ({statement} returning 1) select '{label}', count(*) from d;")
    sql.append('rollback;' if dry_run else 'commit;')
    counts = {}
    for line in _psql('\n'.join(sql)).splitlines():
        parts = line.split('\t')
        if len(parts) == 2 and parts[1].isdigit():
            counts[parts[0]] = int(parts[1])

    safe = [p for p in paths if SAFE_PATH.match(p)]
    counts['files'] = len(safe)
    if safe and not dry_run:
        r = _compose(['exec', '-T', 'app', 'rm', '-f', '--', *[f'storage/app/private/{p}' for p in safe]])
        if r.returncode != 0:
            counts['files'] = f'not removed: {r.stderr.strip()[:200]}'
    return counts


def report(counts):
    removed = ', '.join(f'{v} {k}' for k, v in counts.items() if v)
    print(f'Test data removed: {removed or "nothing"}')


if __name__ == '__main__':
    if '--since' not in sys.argv:
        sys.exit(__doc__)
    since = sys.argv[sys.argv.index('--since') + 1]
    dry = '--dry-run' in sys.argv
    counts = cleanup(since, dry_run=dry)
    print(('Would remove: ' if dry else '') + str(counts))
