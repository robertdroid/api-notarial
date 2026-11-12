#!/bin/bash
BASE=http://localhost:8080/api/v3
T1=$(cat /tmp/qa13/tok_u2_n1.txt)   # user 2, notary 1
T2=$(cat /tmp/qa13/tok_u3_n2.txt)   # user 3, notary 2
T3=$(cat /tmp/qa13/tok_u4_n3.txt)   # user 4, notary 3
LOG=${LOG:-/tmp/qa13/evidence.log}
LAST=/tmp/qa13/last_body.json
PASSN=0; FAILN=0
call() { # label METHOD path token [body]
  local label="$1" method="$2" path="$3" tok="$4" body="$5"
  local args=(-s -o "$LAST" -w '%{http_code} %{time_total}' -X "$method" "$BASE$path")
  [ -n "$tok" ] && args+=(-H "Authorization: Bearer $tok")
  if [ -n "$body" ]; then printf "%s" "$body" > /tmp/qa13/req_body.json; args+=(-H "Content-Type: application/json" --data-binary @/tmp/qa13/req_body.json); fi
  local res; res=$(curl "${args[@]}"); CODE=${res%% *}; TIME=${res##* }
  { echo "### $label"; echo "> $method $path   body: ${body:-<none>}"; echo "< HTTP $CODE (${TIME}s)";
    if jq -e . "$LAST" >/dev/null 2>&1; then jq -c . "$LAST" | head -c 2000; else head -c 600 "$LAST"; fi; echo; echo; } >> "$LOG"
  printf '%-64s -> %s (%ss)\n' "$label" "$CODE" "$TIME"
}
expect() { if [ "$CODE" = "$1" ]; then echo "     PASS ($1)"; PASSN=$((PASSN+1)); else echo "     **FAIL** expected $1 got $CODE: $(head -c 300 $LAST | tr -d '\n')"; FAILN=$((FAILN+1)); fi; }
# jqx 'expr' expected  -> compares jq output of last body
jqx() { local v; v=$(jq -c "$1" "$LAST" 2>/dev/null); if [ "$v" = "$2" ]; then echo "     PASS  $1 == $2"; PASSN=$((PASSN+1)); else echo "     **FAIL** $1 expected $2 got $v"; FAILN=$((FAILN+1)); fi; }
sql() { (cd /c/Users/jrobe/WorkSpace/WebApps/api-notarial && MSYS_NO_PATHCONV=1 docker-compose exec -T db sh -c "mariadb -unotarial_user -p\"\$MYSQL_PASSWORD\" notarial_db -N -e \"$1\"" 2>/dev/null); }
sqleq() { local v; v=$(sql "$1" | tr -d '\r'); if [ "$v" = "$2" ]; then echo "     PASS  DB[$1] == $2"; PASSN=$((PASSN+1)); else echo "     **FAIL** DB[$1] expected '$2' got '$v'"; FAILN=$((FAILN+1)); fi; }
summary() { echo "=== PASS=$PASSN FAIL=$FAILN ==="; }
