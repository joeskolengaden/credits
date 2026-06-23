#!/bin/bash
# End-to-end on-device functional test for the credits plugin.
# Drives the plugin via its settings file + HTTP API and checks observable state.
# Restores the caller's original config at the end.
CFG=/home/fpp/media/config/plugin.credits
ST=/dev/shm/credits_status.json
BLK=/dev/shm/credits_block
PASS=0; FAIL=0
rem(){ python3 -c "import json;v=json.load(open('$ST'))['$1'];print(str(v).lower() if isinstance(v,bool) else v)" 2>/dev/null; }
chk(){ if [ "$2" = "$3" ]; then echo "  PASS: $1 ($2)"; PASS=$((PASS+1)); else echo "  FAIL: $1 (got '$2' want '$3')"; FAIL=$((FAIL+1)); fi; }
chk_true(){ if [ "$2" = "true" ] || [ "$2" = "1" ]; then echo "  PASS: $1"; PASS=$((PASS+1)); else echo "  FAIL: $1 (got '$2')"; FAIL=$((FAIL+1)); fi; }

HASH=$(grep "^admin_password_hash" "$CFG" | sed "s/^admin_password_hash = //")
write_cfg(){ # enabled spc mode recharge token
  printf 'enabled = "%s"\nseconds_per_credit = "%s"\ncount_mode = "%s"\nblank_channels = "524288"\nrecharge_value = "%s"\nrecharge_token = "%s"\nadmin_password_hash = %s\n' "$1" "$2" "$3" "$4" "$5" "$HASH" > "$CFG"; }

echo "=== T1: plugin loaded & status fresh ==="
age=$(( $(date +%s) - $(stat -c %Y "$ST" 2>/dev/null || echo 0) ))
[ "$age" -le 3 ] && chk "status file fresh (<=3s)" "ok" "ok" || chk "status file fresh" "stale:${age}s" "ok"

echo "=== T2: set balance (recharge token applied once) ==="
write_cfg 1 2 running 3 "t2-$RANDOM"; sleep 1.5
r=$(rem remaining); echo "  remaining=$r"; python3 -c "exit(0 if 1.5 < $r <= 3.2 else 1)" && chk "balance set ~3" ok ok || chk "balance set (1.5-3.2)" "$r" "~3"

echo "=== T3: meters down while running ==="
a=$(rem remaining); sleep 2; b=$(rem remaining); echo "  $a -> $b"
python3 -c "exit(0 if $b < $a else 1)" && chk "remaining decreased" ok ok || chk "remaining decreased" "$a->$b" "decrease"

echo "=== T4: hits zero -> blocking + flag=1 ==="
sleep 3
chk_true "blocking flag(status)" "$(rem blocking)"
chk "credits_block file" "$(cat $BLK 2>/dev/null)" "1"

echo "=== T5: recharge restores (blocking clears, flag=0) ==="
write_cfg 1 2 running 5 "t5-$RANDOM"; sleep 1.5
chk "blocking cleared" "$(rem blocking)" "false"
chk "credits_block file" "$(cat $BLK 2>/dev/null)" "0"

echo "=== T6: disable -> passthrough (flag=0 even at low credits) ==="
write_cfg 0 2 running 5 "t6-$RANDOM"; sleep 1.5
chk "blocking false when disabled" "$(rem blocking)" "false"
chk "credits_block file" "$(cat $BLK 2>/dev/null)" "0"

echo "=== T7: count_mode=playing, idle -> no drain ==="
curl -s -m5 "http://localhost/api/playlists/stop" >/dev/null 2>&1; sleep 1
write_cfg 1 2 playing 5 "t7-$RANDOM"; sleep 1.5
a=$(rem remaining); sleep 3; b=$(rem remaining); echo "  idle $a -> $b (playing=$(rem playing))"
python3 -c "exit(0 if abs($a-$b)<0.05 else 1)" && chk "no drain while idle" ok ok || chk "no drain while idle" "$a->$b" "steady"

echo "=== T8: persistence across fppd restart ==="
write_cfg 1 2 running 9 "t8-$RANDOM"; sleep 2
pre=$(rem remaining); echo "  pre-restart remaining=$pre"
curl -s -m10 "http://localhost/api/system/fppd/restart" >/dev/null 2>&1
for i in $(seq 1 30); do [ -f "$ST" ] && break; sleep 1; done; sleep 2
post=$(rem remaining); echo "  post-restart remaining=$post"
# Resumed from disk = not reset to 0, not re-applied to recharge_value(9); may be a
# bit lower than pre due to continued (fast-test) draining after restart.
python3 -c "exit(0 if (0 < $post <= $pre+0.1 and $post > $pre-2.5 and $post < 8.6) else 1)" && chk "balance resumed from disk (no reset/reapply)" ok ok || chk "balance resumed" "$pre->$post" "resume"

echo "=== T9: password gate (HTTP) ==="
u=$(curl -s -m6 "http://localhost/plugin.php?plugin=credits&page=action.php&nopage=1" -d "action=recharge&value=99999")
echo "  unauth recharge -> $u"
echo "$u" | grep -q '"ok":false' && chk "unauthorized recharge rejected" ok ok || chk "unauthorized recharge rejected" "$u" "rejected"
bal=$(rem remaining); python3 -c "exit(0 if $bal < 100 else 1)" && chk "balance not changed by bogus recharge" ok ok || chk "balance unchanged" "$bal" "<100"
w=$(curl -s -m6 "http://localhost/plugin.php?plugin=credits&page=action.php&nopage=1" -d "action=login&pw=wrongpw")
echo "$w" | grep -q '"ok":false' && chk "wrong password rejected" ok ok || chk "wrong password rejected" "$w" "rejected"

echo
echo "=== RESTORE original config (10 credits, 1/hr, password kept) + playback ==="
write_cfg 1 3600 running 10 "restore-$RANDOM"
curl -s -m6 "http://localhost/api/playlist/newplaylist/start" >/dev/null 2>&1
echo "restored."
echo
echo "================ RESULT: $PASS passed, $FAIL failed ================"
