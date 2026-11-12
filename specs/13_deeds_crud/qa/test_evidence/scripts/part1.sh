#!/bin/bash
# Part 1: auth, GET detail, POST create, validations
source "$(dirname "$0")/lib.sh"
export LOG=/tmp/qa13/01_get_post.log; : > $LOG
TAB=$'\t'
echo "--- AUTH (12 routes without token)"
for r in "GET /deeds/1" "POST /deeds" "PUT /deeds/1" "POST /deeds/1/finish" "GET /deeds/1/participants" "POST /deeds/1/participants" "PUT /deeds/1/participants/1" "DELETE /deeds/1/participants/1" "GET /deeds/1/registrations" "POST /deeds/1/registrations" "PUT /deeds/1/registrations/1" "DELETE /deeds/1/registrations/1"; do
  set -- $r; call "noauth $r" $1 $2 "" '{}'; expect 401; done
call "bad token" GET /deeds/1 "garbage.token.x"; expect 401

echo "--- GET /deeds/{id}"
call "GET 19478 (n1)" GET /deeds/19478 $T1; expect 200
jqx '.act|keys' '["id","name"]'
jqx '(.participants|type)' '"array"'
jqx '(.registrations|type)' '"array"'
jq -c 'keys' $LAST
call "GET 1 (n3 own)" GET /deeds/1 $T3; expect 200
call "GET 999999 not found" GET /deeds/999999 $T1; expect 404; jqx '.code' '"deed_not_found"'
call "GET cross-tenant (n1 token, deed 8137 of n2)" GET /deeds/8137 $T1; expect 404; jqx '.code' '"deed_not_found"'
call "GET cross-tenant (n2 token, deed 19478 of n1)" GET /deeds/19478 $T2; expect 404
call "GET id=abc" GET /deeds/abc $T1; expect 400; jqx '.code' '"invalid_id"'
call "GET id=1.5" GET /deeds/1.5 $T1; expect 400; jqx '.code' '"invalid_id"'
call "GET id=0" GET /deeds/0 $T1; expect 404
call "GET id=-5" GET /deeds/-5 $T1; expect 404
call "GET 3904 (other-notary act)" GET /deeds/3904 $T3; expect 200; jqx '.act.name' 'null'
echo "--- sentinel data"
sql "SELECT id_escritura FROM escrituras WHERE id_notaria=1 AND id_fraccionamiento=-1 LIMIT 1" | tr -d '\r\n' > /tmp/qa13/d_m1.txt
sql "SELECT id_escritura FROM escrituras WHERE id_notaria=1 AND id_fraccionamiento=0 LIMIT 1" | tr -d '\r\n' > /tmp/qa13/d_0.txt
sql "SELECT id_escritura FROM escrituras WHERE id_notaria=1 AND id_fraccionamiento>0 LIMIT 1" | tr -d '\r\n' > /tmp/qa13/d_pos.txt
sql "SELECT id_escritura FROM escrituras WHERE fecha_escritura='0000-00-00' AND id_notaria=1 LIMIT 1" | tr -d '\r\n' > /tmp/qa13/d_zero.txt
for f in m1 0 pos zero; do echo "deed $f = $(cat /tmp/qa13/d_$f.txt)"; done
call "frac -1" GET /deeds/$(cat /tmp/qa13/d_m1.txt) $T1; expect 200; jqx '.subdivision' 'null'
call "frac 0" GET /deeds/$(cat /tmp/qa13/d_0.txt) $T1; expect 200; jqx '.subdivision' 'null'
call "frac >0" GET /deeds/$(cat /tmp/qa13/d_pos.txt) $T1; expect 200; jqx '.subdivision|type' '"object"'
call "zero date deed" GET /deeds/$(cat /tmp/qa13/d_zero.txt) $T1; expect 200; jqx '.deedDate' 'null'
echo "--- GET list regression"
call "GET /deeds list n1" GET "/deeds?limit=5" $T1; expect 200
jq -c 'keys' $LAST
ZN=$(sql "SELECT num_escritura FROM escrituras WHERE fecha_escritura='0000-00-00' AND id_notaria=1 LIMIT 1" | tr -d '\r\n')
call "list with zero-date deed ($ZN)" GET "/deeds?limit=200&search=$ZN" $T1; expect 200
echo "     occurrences of -0001-11-30: $(grep -c -- '-0001-11-30' $LAST)"

echo "--- POST /deeds"
FULL='{"deedNumber":"QA13-1","book":"10","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"subdivisionId":null,"property":"Lote 5, Manzana 3","deliveryDate":"2024-02-01","deliveredBy":"Juan Pérez","observations":"Sin observaciones","participants":[{"name":"María García","typeId":1},{"name":"Pedro López","typeId":2}],"registrations":[{"number":"12345","section":"I","volume":"100","date":"2024-01-20"}]}'
call "POST full (no subdivision)" POST /deeds $T1 "$FULL"; expect 201
jqx '.participants|length' 2; jqx '.registrations|length' 1; jqx '.isFinished' false; jqx '.participants[0].type' '"Enajenante"'; jqx '.participants[1].type' '"Adquiriente"'
jqx '.modifiedBy.id' 2; jqx '.subdivision' 'null'
ID1=$(jq -r .id $LAST); echo $ID1 > /tmp/qa13/id_full.txt
sqleq "SELECT terminada,id_fraccionamiento,id_usuario,id_notaria FROM escrituras WHERE id_escritura=$ID1" "0${TAB}-1${TAB}2${TAB}1"
call "POST with subdivision (type 4 allows)" POST /deeds $T1 '{"deedNumber":"QA13-2","book":"B","deedDate":"2024-01-15","actId":1,"deedTypeId":4,"subdivisionId":1,"registrations":[{"number":"1"}]}'; expect 201
jqx '.subdivision.id' 1; jq -r .id $LAST > /tmp/qa13/id_sub.txt
call "POST minimal" POST /deeds $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":5,"deedTypeId":3}'; expect 201
jqx '.participants' '[]'; jqx '.registrations' '[]'; jq -r .id $LAST > /tmp/qa13/id_min.txt
call "POST duplicate number (ADR-002 => 201)" POST /deeds $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":5,"deedTypeId":3}'; expect 201
jq -r .id $LAST > /tmp/qa13/id_dup.txt
call "POST body ignores notaryId/userId/isFinished" POST /deeds $T1 '{"deedNumber":"QA13-4","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"notaryId":3,"userId":99,"isFinished":true,"terminada":1}'; expect 201
ID=$(jq -r .id $LAST); echo $ID > /tmp/qa13/id_inj.txt
sqleq "SELECT id_notaria,id_usuario,terminada FROM escrituras WHERE id_escritura=$ID" "1${TAB}2${TAB}0"
call "POST trim + empty optionals -> NULL" POST /deeds $T1 '{"deedNumber":"  QA13-5  ","book":" 7 ","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"property":"   ","deliveredBy":"","observations":"  ","deliveryDate":null}'; expect 201
ID=$(jq -r .id $LAST); echo $ID > /tmp/qa13/id_trim.txt
jqx '.deedNumber' '"QA13-5"'; jqx '.book' '"7"'; jqx '.property' 'null'; jqx '.deliveredBy' 'null'
sqleq "SELECT predio IS NULL, entrego IS NULL, observaciones IS NULL FROM escrituras WHERE id_escritura=$ID" "1${TAB}1${TAB}1"

echo "--- POST reference validations"
B='"deedNumber":"QA13-V","book":"1","deedDate":"2024-01-15"'
call "act inactive (169)" POST /deeds $T1 "{$B,\"actId\":169,\"deedTypeId\":1}"; expect 422; jqx '.code' '"act_unavailable"'; jqx '.error' '"Act not found or inactive"'
call "act other notary (58)" POST /deeds $T1 "{$B,\"actId\":58,\"deedTypeId\":1}"; expect 422; jqx '.code' '"act_unavailable"'
call "act nonexistent" POST /deeds $T1 "{$B,\"actId\":99999,\"deedTypeId\":1}"; expect 422
call "type inactive (23)" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":23}"; expect 422; jqx '.code' '"deed_type_unavailable"'
call "type other notary (6)" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":6}"; expect 422; jqx '.code' '"deed_type_unavailable"'
call "subdivision inactive (67)" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":4,\"subdivisionId\":67}"; expect 422; jqx '.code' '"subdivision_unavailable"'
call "subdivision other notary (32)" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":4,\"subdivisionId\":32}"; expect 422; jqx '.code' '"subdivision_unavailable"'
call "subdivision not allowed by type 1" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":1,\"subdivisionId\":1}"; expect 422; jqx '.code' '"subdivision_not_allowed"'; jqx '.details.deedTypeId' 1
call "registration limit (type 2 max 1, 2 items)" POST /deeds $T1 "{$B,\"actId\":1,\"deedTypeId\":2,\"registrations\":[{\"number\":\"1\"},{\"number\":\"2\"}]}"; expect 422; jqx '.code' '"registration_limit_exceeded"'; jqx '.details.maxRegistrations' 1; jqx '.error' '"Deed type allows at most 1 registrations"'
call "act inactive + type inactive -> act first" POST /deeds $T1 "{$B,\"actId\":169,\"deedTypeId\":23}"; expect 422; jqx '.code' '"act_unavailable"'
sqleq "SELECT COUNT(*) FROM escrituras WHERE num_escritura='QA13-V'" 0

echo "--- POST body validation (400)"
call "missing deedNumber" POST /deeds $T1 '{"book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1}'; expect 400; jqx '.code' '"validation_failed"'; jqx '.errors.deedNumber|type' '"string"'
call "empty body {}" POST /deeds $T1 '{}'; expect 400; jq -c '.errors|keys' $LAST
for d in '"2024-02-30"' '"15/01/2024"' '20240115' '"2024-1-5"' '""' 'null'; do call "deedDate=$d" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":$d,\"actId\":1,\"deedTypeId\":1}"; expect 400; done
call "actId string '5'" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":"5","deedTypeId":1}'; expect 400; jqx '.errors.actId|type' '"string"'
call "actId 0" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":0,"deedTypeId":1}'; expect 400
call "actId float 1.5" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1.5,"deedTypeId":1}'; expect 400
call "subdivisionId 0" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"subdivisionId":0}'; expect 400
N50=$(printf 'a%.0s' $(seq 50)); N51=$(printf 'a%.0s' $(seq 51))
call "deedNumber 50 ok" POST /deeds $T1 "{\"deedNumber\":\"$N50\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 201; jq -r .id $LAST > /tmp/qa13/id_n50.txt
call "deedNumber 51 fail" POST /deeds $T1 "{\"deedNumber\":\"$N51\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 400
NM50=$(printf 'ñ%.0s' $(seq 50)); NM51=$(printf 'ñ%.0s' $(seq 51))
call "deedNumber 50 x n-tilde (multibyte) ok" POST /deeds $T1 "{\"deedNumber\":\"$NM50\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 201; jq -r .id $LAST > /tmp/qa13/id_nm50.txt
call "deedNumber 51 x n-tilde (multibyte) fail" POST /deeds $T1 "{\"deedNumber\":\"$NM51\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 400
B101=$(printf 'a%.0s' $(seq 101))
call "book 101 fail" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"$B101\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1}"; expect 400
D251=$(printf 'a%.0s' $(seq 251))
call "deliveredBy 251 fail" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"deliveredBy\":\"$D251\"}"; expect 400
call "deliveryDate invalid" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"deliveryDate":"2024-13-01"}'; expect 400
call "participants as object" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"participants":{"name":"a","typeId":1}}'; expect 400
call "participant item not object" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"participants":["abc"]}'; expect 400
call "participant typeId 99" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"participants":[{"name":"ok","typeId":1},{"name":"a","typeId":99}]}'; expect 400; jqx '.errors["participants.1.typeId"]' '"Invalid participant type"'
for t in 0 3 '"1"' null; do call "participant typeId=$t" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"participants\":[{\"name\":\"a\",\"typeId\":$t}]}"; expect 400; done
call "participant name blank" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"participants":[{"name":"  ","typeId":1}]}'; expect 400
PN251=$(printf 'ñ%.0s' $(seq 251))
call "participant name 251 (no truncate)" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"participants\":[{\"name\":\"$PN251\",\"typeId\":1}]}"; expect 400
PN250=$(printf 'ñ%.0s' $(seq 250))
call "participant name 250 ok" POST /deeds $T1 "{\"deedNumber\":\"QA13-PN\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"participants\":[{\"name\":\"$PN250\",\"typeId\":1}]}"; expect 201; jq -r .id $LAST > /tmp/qa13/id_pn250.txt
P51=$(jq -nc '[range(51)|{name:"p",typeId:1}]')
call "51 participants" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"participants\":$P51}"; expect 400
call "registrations as object" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"registrations":{"number":"1"}}'; expect 400
call "registration empty {}" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"registrations":[{}]}'; expect 400; jqx '.errors["registrations.0"]|type' '"string"'
call "registration only date ok" POST /deeds $T1 '{"deedNumber":"QA13-RD","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"registrations":[{"date":"2099-01-01"}]}'; expect 201; jq -r .id $LAST > /tmp/qa13/id_rd.txt
call "registration bad date" POST /deeds $T1 '{"deedNumber":"x","book":"1","deedDate":"2024-01-15","actId":1,"deedTypeId":1,"registrations":[{"number":"1","date":"2024-02-30"}]}'; expect 400
R101=$(printf 'a%.0s' $(seq 101))
call "registration number 101" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"registrations\":[{\"number\":\"$R101\"}]}"; expect 400
R21=$(jq -nc '[range(21)|{number:"1"}]')
call "21 registrations" POST /deeds $T1 "{\"deedNumber\":\"x\",\"book\":\"1\",\"deedDate\":\"2024-01-15\",\"actId\":1,\"deedTypeId\":1,\"registrations\":$R21}"; expect 400
call "malformed JSON" POST /deeds $T1 '{bad json'; echo "     (info) code=$CODE"
summary
