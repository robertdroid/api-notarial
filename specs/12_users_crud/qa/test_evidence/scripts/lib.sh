#!/bin/bash

BASE=http://localhost:8080/api/v3
T1=$(cat /tmp/qa12/tok_u2_n1.txt)   # user 2, notary 1
T3=$(cat /tmp/qa12/tok_u4_n3.txt)   # user 4, notary 3
T2=$(cat /tmp/qa12/tok_u3_n2.txt)   # user 3, notary 2
LOG=${LOG:-/tmp/qa12/evidence.log}
LAST_BODY=/tmp/qa12/last_body.json
# call "label" METHOD path token [body]
call() {
  local label="$1" method="$2" path="$3" tok="$4" body="$5"
  local args=(-s -o "$LAST_BODY" -w '%{http_code} %{time_total}' -X "$method" "$BASE$path")
  [ -n "$tok" ] && args+=(-H "Authorization: Bearer $tok")
  if [ -n "$body" ]; then args+=(-H "Content-Type: application/json" --data-binary "$body"); fi
  local res; res=$(curl "${args[@]}")
  CODE=${res%% *}; TIME=${res##* }
  {
    echo "### $label"
    echo "> $method $path   body: ${body:-<none>}"
    echo "< HTTP $CODE (${TIME}s)"
    if jq -e . "$LAST_BODY" >/dev/null 2>&1; then jq -c . "$LAST_BODY" | head -c 1800; else head -c 600 "$LAST_BODY"; fi
    echo; echo
  } >> "$LOG"
  printf '%-58s -> %s (%ss)\n' "$label" "$CODE" "$TIME"
}
# expect CODE  (prints PASS/FAIL against last call)
expect() { if [ "$CODE" = "$1" ]; then echo "     PASS (expected $1)"; else echo "     **FAIL** expected $1 got $CODE: $(head -c 300 $LAST_BODY | tr -d '\n')"; fi; }
sql() { (cd /c/Users/jrobe/WorkSpace/WebApps/api-notarial && MSYS_NO_PATHCONV=1 docker-compose exec -T db sh -c "mariadb -unotarial_user -p\"\$MYSQL_PASSWORD\" notarial_db -N -e \"$1\"" 2>/dev/null); }
