# Sourced by bin/e2e (mode remote): drives the real plugin on site-a against the fake dosya service
# (tests/fake-dosya/server.php, compose service fake-dosya). Uses bin/e2e's dc, wpa, json and fail.
# Each assertion prints "ok N <what>"; a failure prints the fake's dump excerpt and the job status.

FAKE=http://fake-dosya:9000
DUMP=""
LAST_JOB=""

# the fake is not published on the host: it is reached from inside site-a
fake_get() { "${dc[@]}" exec -T site-a curl -sS -f --max-time 60 "$FAKE$1"; }
fake_post() { "${dc[@]}" exec -T site-a curl -sS -f --max-time 60 -X POST -H 'Content-Type: application/json' --data "$2" "$FAKE$1"; }
dump() { fake_get "/fake/dump?hash=1" > "$DUMP"; }
# runs python with d = the last dump; an AssertionError (or any error) makes it exit non-zero
py() { python3 -c "import json, os, sys, time
d = json.load(open(os.environ['DUMP']))
$1"; }

rfail() {
  echo "--- fake dosya dump (excerpt) ---" >&2
  fake_get "/fake/dump?hash=1" > "$DUMP" 2>/dev/null || true
  python3 - "$DUMP" >&2 <<'PY' || true
import json, sys
d = json.load(open(sys.argv[1]))
print("sites:", json.dumps(list(d["sites"].values()), indent=1)[:1500])
for b in d["backups"].values():
    print("backup", b["id"], b["kind"], b["state"], "vols", [(v["volume_no"], v["file_id"], v["size"]) for v in b["volumes"]], "refs", len(b["refs"]), "bytes", b["bytes_new"], b["bytes_total"])
for s in d["sessions"].values():
    print("session", s["id"], s["name"], s["status"], "parts", sorted(int(k) for k in s["parts"]), "of", s["total_parts"])
print("received:", [(r["session_id"], r["part"], r["already"]) for r in d["received"]][-40:])
print("forgotten:", [(r["session_id"], r["part"]) for r in d["forgotten"]])
print("log tail:", d["log"][-25:])
PY
  if [ -n "$LAST_JOB" ]; then
    echo "--- job $LAST_JOB ---" >&2
    wpa dosya status "$LAST_JOB" >&2 || true
  fi
  fail "remote: $*"
}

# Steps a job with $1 (a wp runner) until it is terminal; prints the final state.
step_to_end() {
  local w=$1 job=$2 state=""
  for _ in $(seq 1 600); do
    state=$($w dosya step "$job" | json 'd["state"]') || rfail "step $job"
    case "$state" in done|failed|skipped|cancelled) echo "$state"; return 0 ;; esac
  done
  rfail "job $job did not finish after 600 steps (last state $state)"
}

uploads_dir=/var/www/html/wp-content/uploads/2026/10
add_random_upload() {
  "${dc[@]}" exec -T site-a sh -c "mkdir -p $uploads_dir && head -c $2 /dev/urandom > $uploads_dir/$1 && chown -R www-data:www-data /var/www/html/wp-content/uploads"
}

remote() {
  local verifier challenge code out sdir id1 id2 id3 id4 id5 job state sid i
  DUMP=$(mktemp -t dosya-fake-dump.XXXXXX)
  export DUMP

  # 1. consent on the fake, then the plugin's own PKCE exchange
  read -r verifier challenge < <(python3 -c "import base64, hashlib, secrets
v = secrets.token_urlsafe(48)
print(v, base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).decode().rstrip('='))")
  code=$(fake_post /fake/consent "{\"code_challenge\":\"$challenge\",\"state\":\"e2e-state\",\"site_url\":\"http://site-a\",\"name\":\"E2E\"}" | json 'd["code"]') || rfail "consent"
  wpa dosya connect --code="$code" --verifier="$verifier" >/dev/null || rfail "wp dosya connect"
  out=$(wpa dosya status) || rfail "wp dosya status"
  grep -q '^connected: yes' <<<"$out" || rfail "status after connect: $out"
  # the code is single use: a replay is refused (410) and the saved connection stays
  if wpa dosya connect --code="$code" --verifier="$verifier" >/dev/null 2>&1; then rfail "a used code connected again"; fi
  out=$(wpa dosya status) || rfail "wp dosya status"
  grep -q '^connected: yes' <<<"$out" || rfail "a refused replay dropped the connection: $out"
  dump
  py 'sites = list(d["sites"].values()); assert len(sites) == 1 and sites[0]["status"] == "active", sites; assert len(d["keys"]) == 1' || rfail "fake site after connect"
  # the dosya tab renders the connected card (the script login is the same as admin-ajax.sh)
  "${dc[@]}" exec -T site-a bash -c 'j=/tmp/jr; b=http://site-a
    curl -s -c $j -b $j -o /dev/null $b/wp-login.php
    curl -s -c $j -b $j -o /dev/null -d "log=admin&pwd=admin-pass-123&wp-submit=Log+In&testcookie=1" $b/wp-login.php
    curl -s -c $j -b $j "$b/wp-admin/admin.php?page=dosya-backup&tab=dosya" | grep -q "Connected to dosya"' || rfail "dosya tab does not show the connected card"
  echo "ok 1 connect: consent + wp dosya connect --code --verifier, status connected, replayed code refused"

  # 2. a full backup goes to dosya; the local copy is kept
  wpa dosya backup >/dev/null || rfail "first backup"
  dump
  id1=$(py '
b = list(d["backups"].values())
assert len(b) == 1, [x["id"] for x in b]
b = b[0]
assert b["state"] == "uploaded" and b["kind"] == "full" and b["refs"] == [], (b["state"], b["kind"], b["refs"])
assert len(b["volumes"]) >= 1
for v in b["volumes"]:
    f = d["files"][v["file_id"]]
    assert f["folder_id"] == b["volumes_folder_id"], ("volume folder", v)
    assert f["disk_sha256"] == v["sha256"] and f["disk_size"] == v["size"], ("stored bytes", v, f)
m, p = d["files"][b["manifest_file_id"]], d["files"][b["public_manifest_file_id"]]
assert (m["name"], m["folder_id"]) == ("manifest.json.gz", b["folder_id"]), m
assert (p["name"], p["folder_id"]) == ("manifest.public.json", b["folder_id"]), p
assert d["locks"][b["folder_id"]]["locked_until"] > time.time() + 23 * 3600
print(b["id"])') || rfail "first backup on the fake"
  [ "$(wpa dosya list --format=json | json "[b['id'] for b in d].count('$id1')")" = "1" ] || rfail "first backup not listed locally"
  wpa dosya verify "$id1" >/dev/null || rfail "local volumes of $id1 are missing or damaged"
  [ "$(wpa dosya list --format=json | json "[b['volumes'] for b in d if b['id'] == '$id1'][0]")" = "$(py "print(len(d['backups']['$id1']['volumes']))")" ] || rfail "local and remote volume counts differ"
  sdir=$(wpa eval 'echo \Dosya\Backup\Wp\Plugin::store()->dir();') || rfail "store dir"
  "${dc[@]}" exec -T site-a sh -c "test -f '$sdir/$id1/manifest.json.gz' && test -f '$sdir/remote/$id1/manifest.json.gz' && test -f '$sdir/remote/last.json'" \
    || rfail "manifest or remote/ record missing for $id1"
  out=$("${dc[@]}" exec -T site-a cat "$sdir/remote/last.json") || rfail "read remote/last.json"
  grep -q "$id1" <<<"$out" || rfail "remote/last.json does not name $id1: $out"
  # the admin list shows it as stored in dosya; the fake answered complete with "uploaded", so it is not verified
  rowjson=$(wpa eval '$p = \Dosya\Backup\Wp\Admin\ListAssembler::build(1); foreach ($p["backups"] as $b) { if ($b["id"] === "'"$id1"'") { echo wp_json_encode($b); } }') || rfail "list payload"
  json 'd["remote"] is True and d["remote_state"] in ("uploaded", "verified")' <<<"$rowjson" | grep -qx True || rfail "list row of $id1 is not shown as stored in dosya: $rowjson"
  rjson=$("${dc[@]}" exec -T site-a cat "$sdir/remote/$id1/remote.json") || rfail "read remote.json"
  json 'd.get("verified") is False' <<<"$rjson" | grep -qx True || rfail "remote.json of $id1 does not record verified false: $rjson"
  echo "ok 2 full backup uploaded ($id1): digests match the stored bytes, manifests in place, refs [], lock set; local volumes and manifest kept, remote/ recorded, list row remote"

  # 3. one changed post and one new upload: an incremental that reads the first backup
  wpa post update 1 --post_title="Changed by the remote e2e" >/dev/null || rfail "post update"
  add_random_upload e2e-small.bin 1048576
  wpa dosya backup >/dev/null || rfail "second backup"
  dump
  id2=$(py "
b = [x for x in d['backups'].values() if x['id'] != '$id1']
assert len(b) == 1, [x['id'] for x in b]
b = b[0]
assert b['state'] == 'uploaded' and b['kind'] == 'incremental', (b['state'], b['kind'])
assert b['refs'] and all(r['backup_id'] == '$id1' for r in b['refs']), b['refs']
assert b['bytes_new'] < b['bytes_total'], (b['bytes_new'], b['bytes_total'])
print(b['id'])") || rfail "second backup is not a valid incremental"
  echo "ok 3 incremental backup ($id2): refs into $id1, bytes_new < bytes_total"

  # 4. a part the server loses mid-upload is sent again; no other part is sent twice.
  # Deterministic: part 1 answers after 27 s, longer than a step's whole 25 s budget, so the step that sent it
  # returns right after it; the armed forget drops part 1 on the next status call (the next step's resume),
  # so the uploader sees it missing and re-sends it.
  add_random_upload e2e-big.bin 31457280
  fake_post /fake/delay '{"route":"part","ms":27000,"count":1}' >/dev/null || rfail "delay knob"
  fake_post /fake/forget-part '{"part":1,"when":"status"}' >/dev/null || rfail "forget-part knob"
  job=$(wpa dosya backup --async) || rfail "async backup"
  LAST_JOB=$job
  state=$(step_to_end wpa "$job")
  [ "$state" = "done" ] || rfail "job $job ended $state"
  id3=$(wpa dosya status "$job" | json 'd["result"]["backup_id"]') || rfail "job result"
  dump
  sid=$(py "
assert d['armed_forget'] is None and d['delay'] in ([], {}), (d['armed_forget'], d['delay'])
f = [r['session_id'] for r in d['forgotten']]
assert len(f) == 1, d['forgotten']
print(f[0])") || rfail "the armed forget never fired"
  py "
s = d['sessions']['$sid']
assert s['status'] == 'complete', s['status']
counts = {}
for r in d['received']:
    if r['session_id'] == '$sid':
        counts[r['part']] = counts.get(r['part'], 0) + 1
assert counts == {n: 1 for n in range(1, s['total_parts'] + 1)}, counts
assert [r['part'] for r in d['forgotten'] if r['session_id'] == '$sid'] == [1], d['forgotten']
assert s['total_parts'] >= 2, s['total_parts']
b = d['backups']['$id3']
assert b['state'] == 'uploaded', b['state']
assert s['file_id'] in [v['file_id'] for v in b['volumes']]
f = d['files'][s['file_id']]
assert f['disk_size'] == s['size_bytes'] and f['disk_sha256'] == [v['sha256'] for v in b['volumes'] if v['file_id'] == s['file_id']][0]" \
    || rfail "resumed upload: a part is missing or was sent twice"
  echo "ok 4 resume ($id3): part 1 dropped at the next step's status call, re-sent once; every part received exactly once"
  LAST_JOB=""

  # 5. a 503 on complete fails the job but keeps the backup locally; the next backup succeeds
  fake_post /fake/fail-next '{"route":"complete"}' >/dev/null || rfail "fail-next knob"
  job=$(wpa dosya backup --async) || rfail "async backup"
  LAST_JOB=$job
  state=$(step_to_end wpa "$job")
  [ "$state" = "failed" ] || rfail "expected the job to fail on the 503, got $state"
  out=$(wpa dosya status "$job" | json 'd["error"]') || rfail "job status"
  grep -q 'kept locally' <<<"$out" || rfail "failure message does not say the backup is kept locally: $out"
  dump
  id4=$(py "
r = [b['id'] for b in d['backups'].values() if b['state'] == 'running']
assert len(r) == 1, r
print(r[0])") || rfail "the failed backup is not left running on the fake"
  [ "$(wpa dosya list --format=json | json "[b['id'] for b in d].count('$id4')")" = "1" ] || rfail "failed backup $id4 is not kept locally"
  wpa dosya verify "$id4" >/dev/null || rfail "local volumes of the failed backup $id4"
  LAST_JOB=""
  wpa dosya backup >/dev/null || rfail "backup after the failed one"
  dump
  id5=$(py "
known = {'$id1', '$id2', '$id3', '$id4'}
b = [x for x in d['backups'].values() if x['id'] not in known]
assert len(b) == 1, [x['id'] for x in b]
assert b[0]['state'] == 'uploaded', b[0]['state']
assert d['backups']['$id4']['state'] == 'running'
print(b[0]['id'], b[0]['kind'])") || rfail "the backup after the failure did not upload"
  echo "ok 5 complete answered 503 once: job failed (kept locally, $id4 still local), next backup uploaded ($id5)"

  # 6. disconnect: the plugin forgets the key and the fake marks the site disconnected
  wpa dosya disconnect >/dev/null || rfail "wp dosya disconnect"
  out=$(wpa dosya status) || rfail "wp dosya status"
  grep -q '^connected: no' <<<"$out" || rfail "status after disconnect: $out"
  dump
  py 'sites = list(d["sites"].values()); assert sites[0]["status"] == "disconnected", sites[0]["status"]; assert d["keys"] == [] or d["keys"] == {}, d["keys"]' \
    || rfail "fake site after disconnect"
  echo "ok 6 disconnect: status disconnected, site disconnected and its key deleted on the fake"

  rm -f "$DUMP"
  echo "E2E remote OK"
}
