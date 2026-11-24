source /tmp/qa12/lib.sh
LOG=/tmp/qa12/evidence_part2.log; : > $LOG
VALID='"password":"SecurePass123","firstName":"Maria","paternalLastName":"Lopez","access":["ESC","PAG"]'
echo "== POST happy path =="
call "POST full, access unordered+dups" POST /users "$T1" '@/tmp/qa12/b_maria.json'; expect 201
cat $LAST_BODY | jq -c .
ID1=$(jq -r .id $LAST_BODY); echo "     new id=$ID1 keys=$(jq -r 'keys|join(",")' $LAST_BODY)"
sql "SELECT id_usuario,id_notaria,usuario,nombre,apellido_paterno,apellido_materno,user_access,id_estatus,ultimo_acceso,IFNULL(contrasenia,'<NULL>'),LEFT(password_hash,7),LENGTH(password_hash) FROM usuarios_notaria WHERE id_usuario=$ID1"
call "GET new user" GET /users/$ID1 "$T1"; expect 200
call "POST minimal (no maternal/status)" POST /users "$T1" '{"username":"min.user","password":"12345678","firstName":"Min","paternalLastName":"User","access":["PAG"]}'; expect 201
ID2=$(jq -r .id $LAST_BODY); jq -c . $LAST_BODY
call "POST with status 2" POST /users "$T1" '{"username":"inactive.user","password":"12345678","firstName":"In","paternalLastName":"Active","access":["ESC"],"status":2}'; expect 201
ID3=$(jq -r .id $LAST_BODY); jq -c '[.status]' $LAST_BODY
call "POST ignores notaryId/id/lastAccess in body" POST /users "$T1" '{"username":"tenant.test","password":"12345678","firstName":"T","paternalLastName":"T","access":["ESC"],"notaryId":3,"id":999,"lastAccess":"2020-01-01T00:00:00","contrasenia":"x","password_hash":"y"}'; expect 201
ID4=$(jq -r .id $LAST_BODY)
sql "SELECT id_usuario,id_notaria,ultimo_acceso,IFNULL(contrasenia,'<NULL>'),LEFT(password_hash,4) FROM usuarios_notaria WHERE id_usuario=$ID4"
echo "$ID1 $ID2 $ID3 $ID4" > /tmp/qa12/created_ids.txt
echo "== login with created user =="
call "login new user maria.lopez" POST /auth/login "" '{"username":"maria.lopez","password":"SecurePass123"}'; expect 200
jq -c 'del(.accessToken,.refreshToken)' $LAST_BODY | head -c 300; echo
call "login wrong case Maria.Lopez" POST /auth/login "" '{"username":"Maria.Lopez","password":"SecurePass123"}'; echo "     (info) code $CODE (login is exact-match per ADR-003)"
call "login wrong password" POST /auth/login "" '{"username":"maria.lopez","password":"WrongPass123"}'; expect 401
echo "== POST duplicates =="
call "dup exact" POST /users "$T1" "{\"username\":\"maria.lopez\",$VALID}"; expect 409
call "dup case-insens Maria.Lopez" POST /users "$T1" "{\"username\":\"Maria.Lopez\",$VALID}"; expect 409
call "dup vs legacy 'devrob' (case: DEVROB)" POST /users "$T1" "{\"username\":\"DEVROB\",$VALID}"; expect 409
call "dup cross-notary: n3 token, username of n1 user" POST /users "$T3" "{\"username\":\"maria.lopez\",$VALID}"; expect 409
call "dup cross-notary: n1 token, username of n3 user" POST /users "$T1" "{\"username\":\"devrob15\",$VALID}"; expect 409
echo "== POST username validation =="
for u in 'ab' 'abcdefghijklmnopqrstu' 'FANNY RECEPCION' 'a-b-c' ' abc' 'abc ' 'abc\n' 'a@b.com'; do
  call "username '$u'" POST /users "$T1" "{\"username\":\"$u\",$VALID}"; expect 400; jq -c '.errors' $LAST_BODY
done
call "username 20 chars ok" POST /users "$T1" "{\"username\":\"abcdefghij0123456789\",$VALID}"; expect 201; ID5=$(jq -r .id $LAST_BODY)
call "username with accent (UTF-8 body file)" POST /users "$T1" "@/tmp/qa12/b_utf8user.json"; expect 400; jq -c .errors $LAST_BODY
call "username 3 chars a_b ok" POST /users "$T1" "{\"username\":\"a_b\",$VALID}"; expect 201; ID6=$(jq -r .id $LAST_BODY)
call "username number" POST /users "$T1" "{\"username\":123,$VALID}"; expect 400
call "username null" POST /users "$T1" "{\"username\":null,$VALID}"; expect 400
call "username array" POST /users "$T1" "{\"username\":[\"x\"],$VALID}"; expect 400
call "username missing" POST /users "$T1" "{$VALID}"; expect 400; jq -c .errors $LAST_BODY
echo "$ID5 $ID6" >> /tmp/qa12/created_ids.txt
U='"username":"val.test"'
echo "== POST password validation =="
call "password 7 chars" POST /users "$T1" "{$U,\"password\":\"1234567\",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400; jq -c .errors $LAST_BODY; grep -c 1234567 $LAST_BODY | sed 's/^/     echo of password in response: /'
call "password 73 bytes" POST /users "$T1" "{$U,\"password\":\"$(printf 'a%.0s' $(seq 73))\",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400; jq -c .errors $LAST_BODY
call "password NUL byte" POST /users "$T1" "{$U,\"password\":\"abcd\\u0000efgh\",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400; jq -c .errors $LAST_BODY
call "password number" POST /users "$T1" "{$U,\"password\":12345678,\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400
call "password null" POST /users "$T1" "{$U,\"password\":null,\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400
call "password missing" POST /users "$T1" "{$U,\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 400
call "password 72 bytes ok" POST /users "$T1" "{\"username\":\"pw72\",\"password\":\"$(printf 'a%.0s' $(seq 72))\",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 201; ID7=$(jq -r .id $LAST_BODY)
call "password 8 spaces ok" POST /users "$T1" "{\"username\":\"pwspaces\",\"password\":\"        \",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"ESC\"]}"; expect 201; ID8=$(jq -r .id $LAST_BODY)
echo "$ID7 $ID8" >> /tmp/qa12/created_ids.txt
echo "== POST names =="
P='"username":"name.test","password":"12345678","access":["ESC"]'
call "firstName missing" POST /users "$T1" "{$P,\"paternalLastName\":\"B\"}"; expect 400; jq -c .errors $LAST_BODY
call "paternalLastName missing" POST /users "$T1" "{$P,\"firstName\":\"A\"}"; expect 400; jq -c .errors $LAST_BODY
call "firstName empty" POST /users "$T1" "{$P,\"firstName\":\"\",\"paternalLastName\":\"B\"}"; expect 400
call "firstName spaces" POST /users "$T1" "{$P,\"firstName\":\"   \",\"paternalLastName\":\"B\"}"; expect 400
call "firstName number" POST /users "$T1" "{$P,\"firstName\":5,\"paternalLastName\":\"B\"}"; expect 400
call "firstName 151 chars" POST /users "$T1" "{$P,\"firstName\":\"$(printf 'a%.0s' $(seq 151))\",\"paternalLastName\":\"B\"}"; expect 400; jq -c .errors $LAST_BODY
call "firstName emoji (4-byte)" POST /users "$T1" "{$P,\"firstName\":\"Ana \\ud83d\\ude00\",\"paternalLastName\":\"B\"}"; expect 400; jq -c .errors $LAST_BODY
call "maternalLastName 151" POST /users "$T1" "{$P,\"firstName\":\"A\",\"paternalLastName\":\"B\",\"maternalLastName\":\"$(printf 'a%.0s' $(seq 151))\"}"; expect 400
call "maternalLastName number" POST /users "$T1" "{$P,\"firstName\":\"A\",\"paternalLastName\":\"B\",\"maternalLastName\":7}"; expect 400
call "maternalLastName null ok" POST /users "$T1" "{\"username\":\"mat.null\",\"password\":\"12345678\",\"access\":[\"ESC\"],\"firstName\":\"A\",\"paternalLastName\":\"B\",\"maternalLastName\":null}"; expect 201; ID9=$(jq -r .id $LAST_BODY); jq -c .maternalLastName $LAST_BODY
call "150 accented chars ok" POST /users "$T1" "{\"username\":\"accent150\",\"password\":\"12345678\",\"access\":[\"ESC\"],\"firstName\":\"$(printf '\\u00e1%.0s' $(seq 150))\",\"paternalLastName\":\"B\"}"; expect 201; ID10=$(jq -r .id $LAST_BODY)
call "name with apostrophe / SQL chars" POST /users "$T1" "{\"username\":\"sqli.name\",\"password\":\"12345678\",\"access\":[\"ESC\"],\"firstName\":\"O'Brien\\\"); DROP TABLE usuarios_notaria;--\",\"paternalLastName\":\"B\"}"; expect 201; ID11=$(jq -r .id $LAST_BODY); jq -c .firstName $LAST_BODY
echo "$ID9 $ID10 $ID11" >> /tmp/qa12/created_ids.txt
echo "== POST access (scenarios 10/11) =="
B='"username":"acc.test","password":"12345678","firstName":"A","paternalLastName":"B"'
for a in '[]' '["XYZ"]' '"ESC"' '[1]' '{"a":"ESC"}' 'null' '["esc"]' '["ESC","XYZ"]' '[null]' '[["ESC"]]' 'true' '{"0":"ESC"}'; do
  call "access $a" POST /users "$T1" "{$B,\"access\":$a}"; expect 400; jq -c .errors $LAST_BODY
done
call "access missing" POST /users "$T1" "{$B}"; expect 400; jq -c .errors $LAST_BODY
call "access [PAG] only ok" POST /users "$T1" "{\"username\":\"pag.only\",\"password\":\"12345678\",\"firstName\":\"A\",\"paternalLastName\":\"B\",\"access\":[\"PAG\"]}"; expect 201; ID12=$(jq -r .id $LAST_BODY); jq -c .access $LAST_BODY
echo "$ID12" >> /tmp/qa12/created_ids.txt
echo "== POST status =="
C='"username":"st.test","password":"12345678","firstName":"A","paternalLastName":"B","access":["ESC"]'
for s in 0 3 '"1"' true 1.0 null '[]' -1; do
  call "status $s" POST /users "$T1" "{$C,\"status\":$s}"; expect 400; jq -c .errors $LAST_BODY
done
echo "== POST malformed bodies =="
call "empty body" POST /users "$T1" ''; echo "     code $CODE $(head -c 200 $LAST_BODY|tr -d '\n ')"
call "invalid JSON" POST /users "$T1" '{bad json'; echo "     code $CODE $(head -c 200 $LAST_BODY|tr -d '\n ')"
call "empty object" POST /users "$T1" '{}'; expect 400; jq -c .errors $LAST_BODY
call "JSON array body" POST /users "$T1" '[]'; echo "     code $CODE $(head -c 200 $LAST_BODY|tr -d '\n ')"
call "JSON scalar body" POST /users "$T1" '"x"'; echo "     code $CODE $(head -c 200 $LAST_BODY|tr -d '\n ')"
echo "== state check =="
sql "SELECT COUNT(*) FROM usuarios_notaria"
