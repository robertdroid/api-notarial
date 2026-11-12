#!/bin/bash
# Part 3: concurrency, query counts, EXPLAIN, security, regression smoke
source "$(dirname "$0")/lib.sh"
export LOG=/tmp/qa13/04_concurrency_perf.log; : > $LOG
TAB=$'\t'

par_call() { # label METHOD path token body outfile
  local out="$6"
  printf "%s" "$5" > "$out.req"
  curl -s -o "$out.body" -w '%{http_code}' -X "$2" "$BASE$3" -H "Authorization: Bearer $4" -H "Content-Type: application/json" --data-binary @"$out.req" > "$out.code"; echo >> "$out.code"
}

echo "--- concurrency: POST /registrations on deed whose type allows 1 (0 regs), 3 parallel, 5 rounds"
for r in 1 2 3 4 5; do
  call "create deed type 2 (max 1) round $r" POST /deeds $T1 '{"deedNumber":"QA13-CONC-R'$r'","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":2}'; expect 201
  D=$(jq -r .id $LAST)
  rm -f /tmp/qa13/par_*
  for i in 1 2 3; do par_call x POST /deeds/$D/registrations $T1 '{"number":"c'$i'"}' /tmp/qa13/par_$i & done; wait
  codes=$(cat /tmp/qa13/par_*.code | sort | tr '\n' ' ')
  cnt=$(sql "SELECT COUNT(*) FROM inscripciones_escritura WHERE id_escritura=$D" | tr -d '\r\n')
  echo "  round $r codes: $codes | rows in DB: $cnt"
  echo "round $r codes: $codes rows: $cnt" >> $LOG
  if [ "$codes" = "201 422 422 " ] && [ "$cnt" = "1" ]; then echo "     PASS"; PASSN=$((PASSN+1)); else echo "     **FAIL** concurrency round $r"; FAILN=$((FAILN+1)); fi
done

echo "--- concurrency: POST /finish, 3 parallel, 5 rounds"
for r in 1 2 3 4 5; do
  call "create deed for finish round $r" POST /deeds $T1 '{"deedNumber":"QA13-FIN-R'$r'","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1}'; expect 201
  D=$(jq -r .id $LAST)
  rm -f /tmp/qa13/par_*
  for i in 1 2 3; do par_call x POST /deeds/$D/finish $T1 '' /tmp/qa13/par_$i & done; wait
  codes=$(cat /tmp/qa13/par_*.code | sort | tr '\n' ' ')
  echo "  round $r codes: $codes"; echo "finish round $r codes: $codes" >> $LOG
  if [ "$codes" = "200 409 409 " ]; then echo "     PASS"; PASSN=$((PASSN+1)); else echo "     **FAIL** finish round $r"; FAILN=$((FAILN+1)); fi
done

echo "--- concurrency: parallel participant+registration writes on one deed keep audit consistent"
call "create deed" POST /deeds $T1 '{"deedNumber":"QA13-MIX","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1}'; D=$(jq -r .id $LAST)
rm -f /tmp/qa13/par_*
for i in 1 2 3 4 5 6; do par_call x POST /deeds/$D/participants $T1 '{"name":"P'$i'","typeId":1}' /tmp/qa13/par_$i & done; wait
codes=$(cat /tmp/qa13/par_*.code | sort | uniq -c | tr -s ' \n' ' ')
echo "  codes: $codes"; sqleq "SELECT COUNT(*) FROM participantes_escritura WHERE id_escritura=$D" 6

echo "--- query counts (Com_select delta; exclude status query itself)"
cs() { sql "SHOW GLOBAL STATUS LIKE 'Com_select'" | awk '{print $2}' | tr -d '\r\n'; }
curl -s -o /dev/null -H "Authorization: Bearer $T1" $BASE/deeds/19478   # warm
b=$(cs); curl -s -o /dev/null -H "Authorization: Bearer $T1" $BASE/deeds/19478; a=$(cs); echo "  GET /deeds/{id}: SELECTs = $((a-b))"
FULLID=$(cat /tmp/qa13/id_full.txt)
b=$(cs); curl -s -o /dev/null -H "Authorization: Bearer $T1" -H "Content-Type: application/json" -X POST $BASE/deeds --data-binary '{"deedNumber":"QA13-Q","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"participants":[{"name":"a","typeId":1}],"registrations":[{"number":"1"}]}'; a=$(cs); echo "  POST /deeds: SELECTs = $((a-b))"
sql "SHOW GLOBAL STATUS LIKE 'Com_insert'" | tr '\n' ' '; echo

echo "--- EXPLAIN (index usage)"
sql "EXPLAIN SELECT id_participante,id_escritura,nombre_participante,id_tipo_participante,id_estatus FROM participantes_escritura WHERE id_escritura=19478 AND id_estatus=1 ORDER BY id_participante" | tee -a $LOG
sql "EXPLAIN SELECT id_inscripcion FROM inscripciones_escritura WHERE id_escritura=19478 ORDER BY id_inscripcion" | tee -a $LOG
sql "EXPLAIN SELECT id_escritura FROM escrituras WHERE id_escritura=19478 AND id_notaria=1" | tee -a $LOG
sqleq "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='escrituras' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'" 0
sqleq "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inscripciones_escritura' AND INDEX_NAME='idx_inscripciones_escritura'" 1

echo "--- response times (n=20 GET detail / n=10 PUT)"
tt=0; for i in $(seq 20); do t=$(curl -s -o /dev/null -w '%{time_total}' -H "Authorization: Bearer $T1" $BASE/deeds/19478); echo $t; done | sort -n | awk '{a[NR]=$1} END{print "  GET detail  p50="a[int(NR/2)]" max="a[NR]}'
for i in $(seq 10); do curl -s -o /dev/null -w '%{time_total}\n' -X PUT -H "Authorization: Bearer $T1" -H "Content-Type: application/json" $BASE/deeds/$FULLID --data-binary '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":3}'; done | sort -n | awk '{a[NR]=$1} END{print "  PUT         p50="a[int(NR/2)]" max="a[NR]}'

echo "--- security"
call "SQLi in deedNumber stored literally" POST /deeds $T1 "{\"deedNumber\":\"x'; DROP TABLE escrituras;--\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 201
sqleq "SELECT COUNT(*) > 19000 FROM escrituras" 1
call "SQLi in path id" GET "/deeds/1%20OR%201=1" $T1; echo "     (info) $CODE"
call "SQLi in participantId" PUT "/deeds/$FULLID/participants/1;DROP" $T1 '{"name":"x","typeId":1}'; expect 400
call "error body has no stack/PDO text (404)" GET /deeds/999999 $T1; jqx 'keys' '["code","error"]'
call "XSS-ish payload stored verbatim, JSON-escaped" POST /deeds/$FULLID/participants $T1 '{"name":"<script>alert(1)</script>","typeId":1}'; expect 201
grep -c '<script>' $LAST | sed 's/^/     raw <script> in JSON body (1 line is expected as data): /'
call "expired/forged token (alg none)" GET /deeds/1 "eyJhbGciOiJub25lIn0.eyJ1c2VySWQiOjIsIm5vdGFyeUlkIjoxfQ."; expect 401

echo "--- regression smoke (other modules)"
for p in "/health" "/acts" "/deed-types" "/subdivisions" "/users" "/notary" "/payment-taxes" "/payment-procedures" "/payment-clients" "/payment-transactions" "/deeds?limit=2"; do
  call "GET $p" GET "$p" $T1; echo "     (info) $p -> $CODE"
done
summary
