#!/usr/bin/env bash
# Runs inside the site-a container: logs in, backs up through admin-ajax, downloads, uploads to site-b, restores via ajax.
set -euo pipefail
login() {
  local base=$1 jar=$2
  curl -s -c "$jar" -b "$jar" -o /dev/null "$base/wp-login.php"
  curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin-pass-123&wp-submit=Log+In&testcookie=1" "$base/wp-login.php"
  curl -s -c "$jar" -b "$jar" "$base/wp-admin/admin.php?page=dosya-backup" | sed -n 's/.*"ajaxUrl":"[^"]*","nonce":"\([a-f0-9]*\)".*/\1/p' | head -1
}
ajax() { local base=$1 jar=$2; shift 2; curl -s -c "$jar" -b "$jar" "$base/wp-admin/admin-ajax.php" "$@"; }
val() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".", $argv[1]); foreach($p as $k){$d=$d[$k];} echo is_array($d)?json_encode($d):$d;' "$1"; }
drive() {
  local base=$1 jar=$2 nonce=$3 job=$4 state="" resp=""
  for _ in $(seq 1 600); do
    resp=$(ajax "$base" "$jar" -F action=dosya_step -F "nonce=$nonce" -F "job_id=$job")
    if [ "$resp" = "-1" ] || [ "$resp" = "0" ] || echo "$resp" | grep -q '"Session expired"'; then
      # a restore replaced users and sessions: log in again, as the reloaded admin page would
      nonce=$(login "$base" "$jar"); continue
    fi
    state=$(echo "$resp" | val data.state)
    case "$state" in done) return 0 ;; failed|skipped|cancelled) echo "job $job ended $state: $resp" >&2; return 1 ;; esac
  done
  return 1
}

A=http://site-a; B=http://site-b; JA=/tmp/ja; JB=/tmp/jb
NA=$(login "$A" "$JA"); [ -n "$NA" ] || { echo "no nonce on A" >&2; exit 1; }
JOB=$(ajax "$A" "$JA" -F action=dosya_start_backup -F "nonce=$NA" -F scope=full | val data.job_id)
drive "$A" "$JA" "$NA" "$JOB"
LIST=$(ajax "$A" "$JA" -F action=dosya_list -F "nonce=$NA")
# the list payload carries the server's view models for the Backups tab script
echo "$LIST" | php -r '$d=json_decode(stream_get_contents(STDIN),true)["data"];
  $ok = is_array($d["rows_vm"]) && count($d["rows_vm"]) >= 1 && isset($d["rows_vm"][0]["created_text"], $d["rows_vm"][0]["badges"], $d["rows_vm"][0]["actions"])
    && is_int($d["total"]) && $d["total"] >= 1 && $d["page"] === 1
    && in_array($d["status_vm"]["tone"] ?? "", ["neutral","success","caution","error"], true) && ($d["status_vm"]["headline"] ?? "") !== ""
    && is_string($d["foot_text"]) && strpos($d["foot_text"], "on this server") !== false;
  if (!$ok) { fwrite(STDERR, "list payload missing view models: ".json_encode($d)."\n"); exit(1); }'
ST=$(ajax "$A" "$JA" -F action=dosya_status -F "nonce=$NA" -F "job_id=$JOB")
[ "$(echo "$ST" | val data.kind)" = "backup" ] || { echo "status lacks kind: $ST" >&2; exit 1; }
[ "$(echo "$ST" | val data.result_vm.tone)" = "success" ] || { echo "status lacks a success result_vm: $ST" >&2; exit 1; }
# the newest row can be a failed run; take the first stored backup
IDX=$(echo "$LIST" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["data"]["backups"] as $i=>$b){ if($b["failed_reason"]===null){ echo $i; break; } }')
ID=$(echo "$LIST" | val "data.backups.$IDX.id"); VOLS=$(echo "$LIST" | val "data.backups.$IDX.volumes")
DL=$(curl -s -c "$JA" -b "$JA" "$A/wp-admin/admin.php?page=dosya-backup" | sed -n 's/.*"downloadBase":"\([^"]*\)".*/\1/p' | sed 's#\\/#/#g')
# the Backups tab loads api.js + backups.js + restore.js (the list opens the restore dialog); Restore & migrate loads api.js +
# restore.js; Schedule loads api.js + schedule.js and dosya loads api.js + dosya.js; admin.js is gone
PAGE=$(curl -s -c "$JA" -b "$JA" "$A/wp-admin/admin.php?page=dosya-backup&tab=backups")
echo "$PAGE" | grep -q 'assets/js/api.js' && echo "$PAGE" | grep -q 'assets/js/backups.js' && echo "$PAGE" | grep -q 'assets/js/restore.js' \
  && ! echo "$PAGE" | grep -q 'assets/admin.js' || { echo "backups tab scripts wrong" >&2; exit 1; }
RPAGE=$(curl -s -c "$JA" -b "$JA" "$A/wp-admin/admin.php?page=dosya-backup&tab=restore")
echo "$RPAGE" | grep -q 'assets/js/api.js' && echo "$RPAGE" | grep -q 'assets/js/restore.js' && ! echo "$RPAGE" | grep -q 'assets/admin.js' \
  && ! echo "$RPAGE" | grep -q 'assets/js/backups.js' || { echo "restore tab scripts wrong" >&2; exit 1; }
SPAGE=$(curl -s -c "$JA" -b "$JA" "$A/wp-admin/admin.php?page=dosya-backup&tab=schedule")
echo "$SPAGE" | grep -q 'assets/js/api.js' && echo "$SPAGE" | grep -q 'assets/js/schedule.js' && ! echo "$SPAGE" | grep -q 'assets/admin.js' \
  && ! echo "$SPAGE" | grep -q 'assets/js/restore.js' && ! echo "$SPAGE" | grep -q 'assets/js/dosya.js' || { echo "schedule tab scripts wrong" >&2; exit 1; }
DPAGE=$(curl -s -c "$JA" -b "$JA" "$A/wp-admin/admin.php?page=dosya-backup&tab=dosya")
echo "$DPAGE" | grep -q 'assets/js/api.js' && echo "$DPAGE" | grep -q 'assets/js/dosya.js' && ! echo "$DPAGE" | grep -q 'assets/admin.js' \
  && ! echo "$DPAGE" | grep -q 'assets/js/schedule.js' || { echo "dosya tab scripts wrong" >&2; exit 1; }
# save_settings: the Schedule tab posts its seven controls; volume_mb is not one of them and must survive; an unchecked box arrives as 0
VOL_BEFORE=$(echo "$LIST" | val data.settings.volume_mb)
SAVED=$(ajax "$A" "$JA" -F action=dosya_save_settings -F "nonce=$NA" -F schedule=weekly -F time=04:30 -F keep=5 -F safety_copy=0 -F email_fail=1 -F email_ok=0 -F "excludes=wp-content/uploads/videos/**")
echo "$SAVED" | VOL="$VOL_BEFORE" php -r '$d=json_decode(stream_get_contents(STDIN),true); $s=$d["data"]["settings"] ?? [];
  $ok = ($d["success"] ?? false) === true && $s["safety_copy"] === false && $s["email_fail"] === true && $s["email_ok"] === false
    && $s["schedule"] === "weekly" && $s["time"] === "04:30" && $s["keep"] === 5 && $s["excludes"] === ["wp-content/uploads/videos/**"]
    && (string) $s["volume_mb"] === getenv("VOL") && array_key_exists("next_run", $d["data"]) && is_string($d["data"]["next_run_text"] ?? null);
  if (!$ok) { fwrite(STDERR, "save_settings round trip: ".json_encode($d)."\n"); exit(1); }'
# put the defaults back so later steps (undo, restores) see the usual settings
ajax "$A" "$JA" -F action=dosya_save_settings -F "nonce=$NA" -F schedule=daily -F time=03:00 -F keep=3 -F safety_copy=1 -F email_fail=1 -F email_ok=0 -F excludes= >/dev/null
# restore_dialog: the local backup is a same-site restore with a safety copy, so it needs no typed domain; an unknown id is refused
DLG=$(ajax "$A" "$JA" -F action=dosya_restore_dialog -F "nonce=$NA" -F "backup_id=$ID")
echo "$DLG" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
  $v=$d["data"] ?? [];
  $ok = ($d["success"] ?? false) === true && ($v["variant"] ?? "") === "same" && ($v["type_to_confirm"] ?? null) === false
    && ($v["show_address"] ?? null) === false && is_string($v["title"] ?? null) && ($v["consequence_parts"]["domain"] ?? "") === "site-a"
    && is_array($v["includes"] ?? null) && ($v["scope"] ?? "") === "full" && is_string($v["safety"] ?? null);
  if (!$ok) { fwrite(STDERR, "restore_dialog for a local backup: ".json_encode($d)."\n"); exit(1); }'
BAD=$(curl -s -o /dev/null -w '%{http_code}' -c "$JA" -b "$JA" "$A/wp-admin/admin-ajax.php" -F action=dosya_restore_dialog -F "nonce=$NA" -F "backup_id=00000000000000000000000000000000")
[ "$BAD" = "400" ] || { echo "restore_dialog for an unknown id answered $BAD" >&2; exit 1; }
mkdir -p /tmp/dl && cd /tmp/dl
fetch() { curl -s -f -c "$JA" -b "$JA" -o "$1" "$(echo "$DL" | sed "s/__ID__/$ID/; s/__FILE__/$1/")"; }
fetch manifest.json.gz
for v in $(seq 1 "$VOLS"); do fetch "$(printf 'v%04d.dosya' "$v")"; done

NB=$(login "$B" "$JB"); UP=$(php -r 'echo bin2hex(random_bytes(16));')
for f in manifest.json.gz v*.dosya; do
  size=$(stat -c %s "$f"); off=0
  while [ "$off" -lt "$size" ]; do
    dd if="$f" of=/tmp/chunk bs=262144 skip=$((off / 262144)) count=1 status=none
    off=$(ajax "$B" "$JB" -F action=dosya_upload_chunk -F "nonce=$NB" -F "upload_id=$UP" -F "name=$f" -F "offset=$off" -F "total=$size" -F "chunk=@/tmp/chunk" | val data.next)
  done
done
GOT=$(ajax "$B" "$JB" -F action=dosya_upload_finish -F "nonce=$NB" -F "upload_id=$UP" | val data.backup_id)
[ "$GOT" = "$ID" ] || { echo "upload_finish returned '$GOT', expected $ID" >&2; exit 1; }
# on site-b the upload is a migration: the dialog asks for the domain and the server refuses a restore without it
MDLG=$(ajax "$B" "$JB" -F action=dosya_restore_dialog -F "nonce=$NB" -F "backup_id=$ID")
[ "$(echo "$MDLG" | val data.variant)" = "migration" ] && [ "$(echo "$MDLG" | val data.confirm_domain)" = "site-b" ] \
  || { echo "restore_dialog on site-b: $MDLG" >&2; exit 1; }
NOCONF=$(ajax "$B" "$JB" -F action=dosya_start_restore -F "nonce=$NB" -F "backup_id=$ID" -F scope=full -F confirm=1)
echo "$NOCONF" | grep -q "Type the current site" || { echo "migration restore without the typed domain was not refused: $NOCONF" >&2; exit 1; }
WRONG=$(ajax "$B" "$JB" -F action=dosya_start_restore -F "nonce=$NB" -F "backup_id=$ID" -F scope=full -F confirm=1 -F confirm_domain=site-a)
echo "$WRONG" | grep -q "Type the current site" || { echo "migration restore with the wrong domain was not refused: $WRONG" >&2; exit 1; }
RJ=$(ajax "$B" "$JB" -F action=dosya_start_restore -F "nonce=$NB" -F "backup_id=$ID" -F scope=full -F confirm=1 -F "confirm_domain= Site-B " | val data.job_id)
[ -n "$RJ" ] || { echo "migration restore with the typed domain did not start" >&2; exit 1; }
drive "$B" "$JB" "$NB" "$RJ"
# a files-only restore has no safety copy (the copy holds the database only), so even on the same site it needs the typed domain
FCODE=$(curl -s -o /tmp/files-only.json -w '%{http_code}' -c "$JA" -b "$JA" "$A/wp-admin/admin-ajax.php" -F action=dosya_start_restore -F "nonce=$NA" -F "backup_id=$ID" -F scope=files -F confirm=1)
[ "$FCODE" = "400" ] && grep -q "Type the current site" /tmp/files-only.json || { echo "files-only restore without confirm_domain answered $FCODE: $(cat /tmp/files-only.json)" >&2; exit 1; }
# a same-site restore of the local backup on site-a starts without confirm_domain; its result banner offers Undo
SJ=$(ajax "$A" "$JA" -F action=dosya_start_restore -F "nonce=$NA" -F "backup_id=$ID" -F scope=full -F confirm=1 | val data.job_id)
[ -n "$SJ" ] || { echo "same-site restore without confirm_domain did not start" >&2; exit 1; }
drive "$A" "$JA" "$NA" "$SJ"
NA=$(login "$A" "$JA")
SST=$(ajax "$A" "$JA" -F action=dosya_status -F "nonce=$NA" -F "job_id=$SJ")
[ "$(echo "$SST" | val data.result_vm.action.kind)" = "undo" ] || { echo "same-site restore offers no undo: $SST" >&2; exit 1; }
# a manual backup with a note: the note is stored and shows in the list
# a "<" followed by a space is text, not a tag: it must come back unchanged (sanitize_text_field would turn it into &lt;)
NOTE="before theme update e2e, size < 5MB"
NJ=$(ajax "$A" "$JA" -F action=dosya_start_backup -F "nonce=$NA" -F scope=db -F "note=$NOTE" | val data.job_id)
drive "$A" "$JA" "$NA" "$NJ"
NL=$(ajax "$A" "$JA" -F action=dosya_list -F "nonce=$NA" -F page=1)
echo "$NL" | NOTE="$NOTE" php -r '$d=json_decode(stream_get_contents(STDIN),true)["data"]; $n=getenv("NOTE");
  $hit = false; foreach ($d["rows_vm"] as $r) { if ($r["note"] === $n) { $hit = true; } }
  if (!$hit) { fwrite(STDERR, "note missing from list: ".json_encode($d["rows_vm"])."\n"); exit(1); }'
echo "ADMIN AJAX OK $ID"
