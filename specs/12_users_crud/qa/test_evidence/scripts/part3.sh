source /tmp/qa12/lib.sh
LOG=/tmp/qa12/evidence_part3.log; : > $LOG
U() { sql "SELECT id_usuario,id_notaria,usuario,nombre,apellido_paterno,apellido_materno,user_access,IFNULL(id_estatus,'NULL'),IFNULL(ultimo_acceso,'NULL'),MD5(IFNULL(contrasenia,'')),MD5(IFNULL(password_hash,'')) FROM usuarios_notaria WHERE id_usuario=$1"; }
M=13   # maria.lopez, notary 1 (created in part2)
echo "== PUT self (actor user 2) =="
B2=$(U 2)
call "self: unchanged firstName" PUT /users/2 "$T1" '{"firstName":"Roberto"}'; expect 200
[ "$(U 2)" = "$B2" ] && echo "     DB row (incl. pwd hashes, ultimo_acceso) unchanged: PASS" || echo "     **FAIL** DB row changed: $(U 2)"
call "self: status 2 -> 403 (scenario 9)" PUT /users/2 "$T1" '{"status":2}'; expect 403; jq -c . $LAST_BODY
call "self: status 2 + firstName -> 403, nothing written" PUT /users/2 "$T1" '{"status":2,"firstName":"Hack"}'; expect 403
[ "$(U 2)" = "$B2" ] && echo "     DB row unchanged after 403: PASS" || echo "     **FAIL** DB row changed after 403"
call "self: status 2 + invalid username -> 403 first" PUT /users/2 "$T1" '{"status":2,"username":"a b"}'; expect 403
call "self: status 1 -> 200" PUT /users/2 "$T1" '{"status":1}'; expect 200
call "self: access [ESC] allowed" PUT /users/2 "$T1" '{"access":["ESC"]}'; expect 200; jq -c .access $LAST_BODY
call "self: restore access" PUT /users/2 "$T1" '{"access":["PAG","ESC"]}'; expect 200; jq -c .access $LAST_BODY
call "self: status '2' string -> 400 not 403" PUT /users/2 "$T1" '{"status":"2"}'; expect 400
echo "== PUT other user: deactivate / reactivate (scenario 8) =="
call "login before deactivation" POST /auth/login "" '{"username":"maria.lopez","password":"SecurePass123"}'; expect 200
RTOK=$(jq -r .refreshToken $LAST_BODY)
HASH0=$(U $M)
call "deactivate user 13" PUT /users/$M "$T1" '{"status":2}'; expect 200; jq -c .status $LAST_BODY
sql "SELECT id_estatus FROM usuarios_notaria WHERE id_usuario=$M" | sed 's/^/     DB id_estatus=/'
call "login while inactive" POST /auth/login "" '{"username":"maria.lopez","password":"SecurePass123"}'; echo "     (info) login inactive -> $CODE"
call "refresh while inactive" POST /auth/refresh "" "{\"refreshToken\":\"$RTOK\"}"; echo "     (info) refresh inactive -> $CODE"
call "list status=2 includes 13" GET "/users?status=2" "$T1"; jq -r '"     ids=" + ([.[].id|tostring]|join(","))' $LAST_BODY
call "reactivate user 13" PUT /users/$M "$T1" '{"status":1}'; expect 200; jq -c .status $LAST_BODY
echo "== PUT partial semantics =="
call "partial firstName only" PUT /users/$M "$T1" '{"firstName":"  Maria Elena  "}'; expect 200; jq -c . $LAST_BODY
call "maternalLastName null -> ''" PUT /users/$M "$T1" '{"maternalLastName":null}'; expect 200; jq -c .maternalLastName $LAST_BODY
call "maternalLastName '' " PUT /users/$M "$T1" '{"maternalLastName":""}'; expect 200
call "maternalLastName set" PUT /users/$M "$T1" '{"maternalLastName":"Garcia"}'; expect 200; jq -c .maternalLastName $LAST_BODY
call "access unordered+dups" PUT /users/$M "$T1" '{"access":["PAG","ESC","PAG"]}'; expect 200; jq -c .access $LAST_BODY
call "access [] (scenario 11)" PUT /users/$M "$T1" '{"access":[]}'; expect 400; jq -c . $LAST_BODY
call "access [XYZ] (scenario 10)" PUT /users/$M "$T1" '{"access":["XYZ"]}'; expect 400; jq -c . $LAST_BODY
call "access 'ESC' string" PUT /users/$M "$T1" '{"access":"ESC"}'; expect 400
call "access null" PUT /users/$M "$T1" '{"access":null}'; expect 400
call "status '2' string" PUT /users/$M "$T1" '{"status":"2"}'; expect 400
call "status 0" PUT /users/$M "$T1" '{"status":0}'; expect 400
call "status 3" PUT /users/$M "$T1" '{"status":3}'; expect 400
call "firstName ''" PUT /users/$M "$T1" '{"firstName":""}'; expect 400
call "firstName null" PUT /users/$M "$T1" '{"firstName":null}'; expect 400
call "firstName 151" PUT /users/$M "$T1" "{\"firstName\":\"$(printf 'a%.0s' $(seq 151))\"}"; expect 400
call "paternalLastName spaces" PUT /users/$M "$T1" '{"paternalLastName":"   "}'; expect 400
call "idempotent same values" PUT /users/$M "$T1" '{"firstName":"Maria Elena","access":["ESC","PAG"]}'; expect 200
call "ignores notaryId/id/lastAccess" PUT /users/$M "$T1" '{"notaryId":3,"id":999,"lastAccess":"2000-01-01T00:00:00","firstName":"Maria Elena"}'; expect 200
U $M | cut -f1,2,3,4,9 | sed 's/^/     DB: /'
[ "$(U $M | cut -f10,11)" = "$(echo "$HASH0" | cut -f10,11)" ] && echo "     password columns unchanged after all PUTs: PASS" || echo "     **FAIL** password columns changed"
echo "== PUT body validation =="
call "empty object" PUT /users/$M "$T1" '{}'; expect 400; jq -c .errors $LAST_BODY
call "only unknown field" PUT /users/$M "$T1" '{"foo":"bar"}'; expect 400; jq -c .errors $LAST_BODY
call "password only" PUT /users/$M "$T1" '{"password":"NewPass1234"}'; expect 400; jq -c .errors $LAST_BODY
call "password + firstName (ADR-005)" PUT /users/$M "$T1" '{"password":"NewPass1234","firstName":"Zed"}'; expect 400; jq -c .errors $LAST_BODY
grep -c NewPass1234 $LAST_BODY | sed 's/^/     password echoed in response: /'
sql "SELECT nombre FROM usuarios_notaria WHERE id_usuario=$M" | sed 's/^/     firstName still: /'
call "login still works with ORIGINAL password" POST /auth/login "" '{"username":"maria.lopez","password":"SecurePass123"}'; expect 200
call "login with attempted new password fails" POST /auth/login "" '{"username":"maria.lopez","password":"NewPass1234"}'; expect 401
echo "== PUT username =="
call "valid new username" PUT /users/$M "$T1" '{"username":"maria.l"}'; expect 200; jq -c .username $LAST_BODY
call "back to original" PUT /users/$M "$T1" '{"username":"maria.lopez"}'; expect 200
call "case-only change (own, allowed)" PUT /users/$M "$T1" '{"username":"Maria.Lopez"}'; expect 200; jq -c .username $LAST_BODY
call "login Maria.Lopez after change" POST /auth/login "" '{"username":"Maria.Lopez","password":"SecurePass123"}'; expect 200
call "login old-case maria.lopez now fails" POST /auth/login "" '{"username":"maria.lopez","password":"SecurePass123"}'; echo "     (info) old-case login -> $CODE"
call "back to lower-case" PUT /users/$M "$T1" '{"username":"maria.lopez"}'; expect 200
call "taken (exact) devrob" PUT /users/$M "$T1" '{"username":"devrob"}'; expect 409
call "taken (case) DEVROB" PUT /users/$M "$T1" '{"username":"DEVROB"}'; expect 409
call "taken cross-notary devrob15" PUT /users/$M "$T1" '{"username":"devrob15"}'; expect 409
call "taken by created user min.user" PUT /users/$M "$T1" '{"username":"MIN.USER"}'; expect 409
call "invalid 'a b' -> 400 errors.username" PUT /users/$M "$T1" '{"username":"a b"}'; expect 400; jq -c . $LAST_BODY
call "invalid 'ab'" PUT /users/$M "$T1" '{"username":"ab"}'; expect 400
call "invalid number" PUT /users/$M "$T1" '{"username":5}'; expect 400; jq -c .errors $LAST_BODY
call "invalid null" PUT /users/$M "$T1" '{"username":null}'; echo "     (info) username null -> $CODE $(jq -c .errors $LAST_BODY)"
call "precedence: invalid username + taken -> 400" PUT /users/$M "$T1" '{"username":"dev rob"}'; expect 400
call "user not found beats username checks" PUT /users/99999 "$T1" '{"username":"devrob"}'; expect 404
echo "== PUT ids / tenancy =="
call "id abc" PUT /users/abc "$T1" '{"firstName":"X"}'; expect 400
call "id 0" PUT /users/0 "$T1" '{"firstName":"X"}'; expect 404
call "id -5" PUT /users/-5 "$T1" '{"firstName":"X"}'; expect 404
call "id 99999" PUT /users/99999 "$T1" '{"firstName":"X"}'; expect 404
call "invalid id + invalid body: id first" PUT /users/abc "$T1" '{}'; expect 400; jq -c .error $LAST_BODY
call "other-notary user 4 from n1" PUT /users/4 "$T1" '{"firstName":"Hacked"}'; expect 404
call "other-notary user 4 from n1 + status 2 (404 not 403)" PUT /users/4 "$T1" '{"status":2}'; expect 404
call "n3 -> n1 user 13" PUT /users/$M "$T3" '{"firstName":"Hacked","status":2}'; expect 404
sql "SELECT nombre,id_estatus FROM usuarios_notaria WHERE id_usuario IN (4,$M)" | sed 's/^/     DB after cross-tenant attempts: /'
call "n3 self deactivate (user 4)" PUT /users/4 "$T3" '{"status":2}'; expect 403
call "invalid body on other-notary user: 400 before 404" PUT /users/4 "$T1" '{}'; expect 400
echo "== Legacy data =="
call "legacy user 6 (n3): same invalid username + other field" PUT /users/6 "$T3" '{"username":"FANNY RECEPCION","firstName":"RUBI STEPHANIE"}'; expect 200; jq -c '[.username,.firstName]' $LAST_BODY
call "legacy user 6: only same username" PUT /users/6 "$T3" '{"username":"FANNY RECEPCION"}'; expect 200
call "legacy user 6: case-variant of invalid username" PUT /users/6 "$T3" '{"username":"fanny recepcion"}'; expect 400
call "legacy user 6: new valid username" PUT /users/6 "$T3" '{"username":"Fanny.Recepcion"}'; expect 200
call "legacy user 6: GET before restore" GET /users/6 "$T3"; jq -c '[.username,.status]' $LAST_BODY
call "legacy user 7 (id_estatus=0): PUT firstName" PUT /users/7 "$T1" '{"firstName":"Fatima F"}'; expect 200; jq -c '[.firstName,.status]' $LAST_BODY
sql "SELECT id_estatus FROM usuarios_notaria WHERE id_usuario=7" | sed 's/^/     DB id_estatus preserved (expect 0): /'
call "legacy user 7: PUT status 2" PUT /users/7 "$T1" '{"status":2}'; expect 200
sql "SELECT id_estatus FROM usuarios_notaria WHERE id_usuario=7" | sed 's/^/     DB id_estatus (expect 2): /'
call "legacy user 7: PUT status 1 (reactivate)" PUT /users/7 "$T1" '{"status":1}'; expect 200; jq -c .status $LAST_BODY
echo "== SQLi probes =="
call "username sqli on PUT" PUT /users/$M "$T1" "{\"username\":\"x' OR '1'='1\"}"; expect 400
call "id sqli on PUT" PUT "/users/1%20OR%201=1" "$T1" '{"firstName":"X"}'; expect 400
sql "SELECT COUNT(*) FROM usuarios_notaria" | sed 's/^/     rows in table: /'
