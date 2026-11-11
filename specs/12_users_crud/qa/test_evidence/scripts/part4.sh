source /tmp/qa12/lib.sh
LOG=/tmp/qa12/evidence_part4.log; : > $LOG
echo "== Login for edge passwords =="
call "login pw72 (72-char password)" POST /auth/login "" "{\"username\":\"pw72\",\"password\":\"$(printf 'a%.0s' $(seq 72))\"}"; expect 200
call "login pwspaces (8 spaces)" POST /auth/login "" '{"username":"pwspaces","password":"        "}'; expect 200
call "login pw72 with 71 chars must fail" POST /auth/login "" "{\"username\":\"pw72\",\"password\":\"$(printf 'a%.0s' $(seq 71))\"}"; expect 401

echo "== Concurrency: 2 parallel POST same username =="
BODY='{"username":"race.user","password":"12345678","firstName":"R","paternalLastName":"U","access":["ESC"]}'
for i in 1 2 3 4 5 6; do
  curl -s -o /tmp/qa12/race_$i.json -w "%{http_code}\n" -X POST $BASE/users -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' --data-binary "$BODY" > /tmp/qa12/race_$i.code &
done
wait
echo "     codes: $(cat /tmp/qa12/race_*.code | sort | uniq -c | tr '\n' ' ')"
sql "SELECT COUNT(*) FROM usuarios_notaria WHERE usuario='race.user'" | sed 's/^/     rows with race.user: /'
for i in 1 2 3 4 5 6; do c=$(cat /tmp/qa12/race_$i.code); [ "$c" = 409 ] && { echo "     409 body: $(jq -c . /tmp/qa12/race_$i.json)"; break; }; done
grep -l "Internal" /tmp/qa12/race_*.json | wc -l | sed 's/^/     responses containing Internal Server Error: /'

echo "== Concurrency: case variants (documented risk ADR-003) =="
for v in case.race CASE.RACE Case.Race case.RACE; do
  curl -s -o /tmp/qa12/cv_$v.json -w "%{http_code}\n" -X POST $BASE/users -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' --data-binary "{\"username\":\"$v\",\"password\":\"12345678\",\"firstName\":\"R\",\"paternalLastName\":\"U\",\"access\":[\"ESC\"]}" > /tmp/qa12/cv_$v.code &
done
wait
echo "     codes: $(cat /tmp/qa12/cv_*.code | sort | uniq -c | tr '\n' ' ')"
sql "SELECT usuario FROM usuarios_notaria WHERE LOWER(usuario)='case.race'" | tr '\n' ' ' | sed 's/^/     rows: /'; echo

echo "== Concurrency: 2 parallel PUTs renaming different users to the same username =="
for id in 14 15; do
  curl -s -o /tmp/qa12/pr_$id.json -w "%{http_code}\n" -X PUT $BASE/users/$id -H "Authorization: Bearer $T1" -H 'Content-Type: application/json' --data-binary '{"username":"put.race"}' > /tmp/qa12/pr_$id.code &
done
wait
echo "     codes: $(cat /tmp/qa12/pr_*.code | sort | uniq -c | tr '\n' ' ')"

echo "== Logs: password leakage =="
(cd /c/Users/jrobe/WorkSpace/WebApps/api-notarial && docker-compose logs --no-color api 2>&1 | grep -c -E "SecurePass123|NewPass1234|12345678|password_hash|\\\$2y\\\$10" | sed 's/^/     apache/php log lines containing test passwords or hashes: /')
(cd /c/Users/jrobe/WorkSpace/WebApps/api-notarial && MSYS_NO_PATHCONV=1 docker-compose exec -T api sh -c 'ls /var/log/apache2 2>/dev/null; grep -rIl -E "SecurePass123|NewPass1234" /var/log /var/www/html/storage /var/www/html/logs 2>/dev/null | head')

echo "== EXPLAIN list query =="
sql "EXPLAIN SELECT id_usuario FROM usuarios_notaria WHERE id_notaria=1 AND id_estatus=1" | sed 's/^/     /'
sql "EXPLAIN SELECT id_usuario FROM usuarios_notaria WHERE id_notaria=1 AND (id_estatus IS NULL OR id_estatus <> 1)" | sed 's/^/     /'
