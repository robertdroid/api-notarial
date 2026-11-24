#!/bin/bash
# Part 2: PUT, finish, participants, registrations, audit, tenancy, concurrency
source "$(dirname "$0")/lib.sh"
export LOG=/tmp/qa13/02_put_finish_sub.log; : > $LOG
TAB=$'\t'
T8=$(cat /tmp/qa13/tok_u8_n1.txt)   # user 8, notary 1 (second user for audit checks)
FULLID=$(cat /tmp/qa13/id_full.txt)   # 2 participants, 1 registration, type 1 (max 5)
SUBID=$(cat /tmp/qa13/id_sub.txt)     # type 4 (max 1, subdivisions ok), 1 registration, subdivision 1
MINID=$(cat /tmp/qa13/id_min.txt)     # type 3 (max 2), no children
DUPID=$(cat /tmp/qa13/id_dup.txt)
PUTBODY='{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":1,"property":"Nuevo predio","deliveryDate":"2024-03-01","deliveredBy":"Ñandú","observations":"obs"}'

echo "--- PUT /deeds/{id}"
sql "UPDATE escrituras SET fecha_modificacion='2020-01-01 00:00:00', id_usuario=2 WHERE id_escritura=$FULLID"
call "PUT full (user 8)" PUT /deeds/$FULLID $T8 "$PUTBODY"; expect 200
jqx '.book' '"11"'; jqx '.act.id' 2; jqx '.participants|length' 2; jqx '.registrations|length' 1; jqx '.modifiedBy.id' 8; jqx '.isFinished' false
sqleq "SELECT id_usuario, fecha_modificacion>'2020-01-02' FROM escrituras WHERE id_escritura=$FULLID" "8${TAB}1"
call "PUT ignores participants/registrations/isFinished" PUT /deeds/$FULLID $T1 "{\"deedNumber\":\"QA13-1\",\"book\":\"11\",\"deedDate\":\"2024-01-16\",\"actId\":2,\"deedTypeId\":1,\"isFinished\":true,\"terminada\":1,\"participants\":[{\"name\":\"X\",\"typeId\":1}],\"registrations\":[],\"id\":1,\"modifiedBy\":{\"id\":99},\"foo\":\"bar\"}"; expect 200
jqx '.isFinished' false; jqx '.participants|length' 2; jqx '.registrations|length' 1
sqleq "SELECT terminada FROM escrituras WHERE id_escritura=$FULLID" 0
echo "     replacement semantics: optionals omitted -> null"
jqx '.property' 'null'; jqx '.deliveryDate' 'null'; jqx '.deliveredBy' 'null'; jqx '.observations' 'null'
call "PUT same deedNumber as other deed (ADR-002, no 409)" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-3","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":1}'; expect 200
call "PUT subdivision null stores -1" PUT /deeds/$SUBID $T1 '{"deedNumber":"QA13-2","book":"B","deedDate":"2024-01-15","actId":1,"deedTypeId":4,"subdivisionId":null}'; expect 200; jqx '.subdivision' 'null'
sqleq "SELECT id_fraccionamiento FROM escrituras WHERE id_escritura=$SUBID" "-1"
call "PUT set subdivision 2 on type 4" PUT /deeds/$SUBID $T1 '{"deedNumber":"QA13-2","book":"B","deedDate":"2024-01-15","actId":1,"deedTypeId":4,"subdivisionId":2}'; expect 200; jqx '.subdivision.id' 2
call "PUT 404 nonexistent" PUT /deeds/999999 $T1 "$PUTBODY"; expect 404; jqx '.code' '"deed_not_found"'
call "PUT cross-tenant (n2 token on n1 deed)" PUT /deeds/$FULLID $T2 "$PUTBODY"; expect 404
sqleq "SELECT libro FROM escrituras WHERE id_escritura=$FULLID" "11"
call "PUT invalid id" PUT /deeds/abc $T1 "$PUTBODY"; expect 400; jqx '.code' '"invalid_id"'
call "PUT id=0" PUT /deeds/0 $T1 "$PUTBODY"; expect 404
call "PUT invalid body on nonexistent id -> 400 first" PUT /deeds/999999 $T1 '{}'; expect 400
call "PUT missing required" PUT /deeds/$FULLID $T1 '{"book":"1"}'; expect 400; jqx '.code' '"validation_failed"'
call "PUT act inactive (changed)" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":169,"deedTypeId":1}'; expect 422; jqx '.code' '"act_unavailable"'
call "PUT type inactive (changed)" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":23}'; expect 422; jqx '.code' '"deed_type_unavailable"'
call "PUT subdivision inactive (changed)" PUT /deeds/$SUBID $T1 '{"deedNumber":"QA13-2","book":"B","deedDate":"2024-01-15","actId":1,"deedTypeId":4,"subdivisionId":67}'; expect 422; jqx '.code' '"subdivision_unavailable"'
call "PUT only subdivision changes on type w/o subdivisions" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":1,"subdivisionId":1}'; expect 422; jqx '.code' '"subdivision_not_allowed"'
echo "  legacy inactive act: unchanged act => OK; changed => validated"
sql "UPDATE escrituras SET id_acto=169 WHERE id_escritura=$MINID"
call "PUT legacy deed keeps inactive act (unchanged)" PUT /deeds/$MINID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":169,"deedTypeId":3,"observations":"edit legacy"}'; expect 200; jqx '.act.id' 169
call "PUT legacy deed: change act to 1 OK" PUT /deeds/$MINID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":1,"deedTypeId":3}'; expect 200
call "PUT then back to inactive 169 -> 422" PUT /deeds/$MINID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":169,"deedTypeId":3}'; expect 422
echo "  type change vs registrations limit"
call "POST reg #2 on FULL (type1 max5)" POST /deeds/$FULLID/registrations $T1 '{"number":"R2"}'; expect 201
call "PUT change type to 2 (max 1) with 2 regs" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":2}'; expect 422; jqx '.code' '"registration_limit_exceeded"'; jqx '.details.maxRegistrations' 1; jqx '.details.currentRegistrations' 2
sqleq "SELECT id_tipo_escitura FROM escrituras WHERE id_escritura=$FULLID" 1
call "PUT change type to 3 (max 2) with 2 regs OK" PUT /deeds/$FULLID $T1 '{"deedNumber":"QA13-1","book":"11","deedDate":"2024-01-16","actId":2,"deedTypeId":3}'; expect 200; jqx '.deedType.id' 3
call "PUT date/number validations" PUT /deeds/$FULLID $T1 '{"deedNumber":"","book":"1","deedDate":"2024-02-30","actId":1,"deedTypeId":1}'; expect 400

echo "--- POST /deeds/{id}/finish"
call "finish (user 8)" POST /deeds/$MINID/finish $T8; expect 200; jqx '.isFinished' true; jqx '.modifiedBy.id' 8
sqleq "SELECT terminada,id_usuario FROM escrituras WHERE id_escritura=$MINID" "1${TAB}8"
call "finish again -> 409" POST /deeds/$MINID/finish $T1; expect 409; jqx '.code' '"deed_already_finished"'; jqx '.error' '"Deed is already finished"'
call "PUT on finished deed -> 200 and stays finished" PUT /deeds/$MINID $T1 '{"deedNumber":"QA13-3","book":"10-edited","deedDate":"2024-01-15","actId":1,"deedTypeId":3}'; expect 200; jqx '.isFinished' true; jqx '.book' '"10-edited"'
sqleq "SELECT terminada FROM escrituras WHERE id_escritura=$MINID" 1
call "PUT finished with isFinished:false" PUT /deeds/$MINID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":1,"deedTypeId":3,"isFinished":false}'; expect 200; jqx '.isFinished' true
sqleq "SELECT terminada FROM escrituras WHERE id_escritura=$MINID" 1
call "finish nonexistent" POST /deeds/999999/finish $T1; expect 404
call "finish cross-tenant" POST /deeds/$DUPID/finish $T2; expect 404
sqleq "SELECT terminada FROM escrituras WHERE id_escritura=$DUPID" 0
call "finish invalid id" POST /deeds/x1/finish $T1; expect 400
call "participants editable on finished deed" POST /deeds/$MINID/participants $T1 '{"name":"Post-finish","typeId":1}'; expect 201
call "legacy finish with terminada NULL then finish" PUT /deeds/$DUPID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":5,"deedTypeId":3}'; expect 200
sql "UPDATE escrituras SET terminada=NULL WHERE id_escritura=$DUPID"
call "finish when terminada NULL" POST /deeds/$DUPID/finish $T1; expect 200; jqx '.isFinished' true

echo "--- participants"
sql "INSERT INTO participantes_escritura (id_escritura,nombre_participante,id_tipo_participante,id_estatus) VALUES ($FULLID,'QA13_legacy_0',1,0),($FULLID,'QA13_inactive_2',2,2)"
call "GET participants only active" GET /deeds/$FULLID/participants $T1; expect 200; jqx 'length' 2; jqx '[.[].name]|any(startswith("QA13_"))' false
jq -c '.[0]|keys' $LAST
call "GET participants 404" GET /deeds/999999/participants $T1; expect 404
call "GET participants cross-tenant" GET /deeds/$FULLID/participants $T2; expect 404
call "POST participant" POST /deeds/$FULLID/participants $T8 '{"name":"Carlos Ruiz","typeId":1}'; expect 201; jqx '.type' '"Enajenante"'; jqx '.name' '"Carlos Ruiz"'
PID=$(jq -r .id $LAST); echo $PID > /tmp/qa13/pid.txt
sqleq "SELECT id_usuario FROM escrituras WHERE id_escritura=$FULLID" 8
call "POST participant typeId 99" POST /deeds/$FULLID/participants $T1 '{"name":"X","typeId":99}'; expect 400; jqx '.errors.typeId' '"Invalid participant type"'
call "POST participant name missing" POST /deeds/$FULLID/participants $T1 '{"typeId":1}'; expect 400
call "POST participant 251 chars" POST /deeds/$FULLID/participants $T1 "{\"name\":\"$(printf 'a%.0s' $(seq 251))\",\"typeId\":1}"; expect 400
call "POST participant 404 deed" POST /deeds/999999/participants $T1 '{"name":"X","typeId":1}'; expect 404
call "POST participant cross-tenant" POST /deeds/$FULLID/participants $T2 '{"name":"X","typeId":1}'; expect 404
call "PUT participant" PUT /deeds/$FULLID/participants/$PID $T1 '{"name":"Carlos R. Ruiz","typeId":2}'; expect 200; jqx '.type' '"Adquiriente"'; jqx '.name' '"Carlos R. Ruiz"'
OTHERP=$(sql "SELECT id_participante FROM participantes_escritura WHERE id_escritura=$SUBID LIMIT 1" | tr -d '\r\n')
[ -z "$OTHERP" ] && { call "seed participant on other deed" POST /deeds/$SUBID/participants $T1 '{"name":"Other","typeId":1}'; OTHERP=$(jq -r .id $LAST); }
call "PUT participant of another deed -> 404" PUT /deeds/$FULLID/participants/$OTHERP $T1 '{"name":"Hack","typeId":1}'; expect 404; jqx '.code' '"participant_not_found"'
sqleq "SELECT nombre_participante FROM participantes_escritura WHERE id_participante=$OTHERP" "Other"
call "DELETE participant of another deed -> 404" DELETE /deeds/$FULLID/participants/$OTHERP $T1; expect 404
call "PUT participant nonexistent" PUT /deeds/$FULLID/participants/999999 $T1 '{"name":"X","typeId":1}'; expect 404
call "PUT participant cross-tenant" PUT /deeds/$FULLID/participants/$PID $T2 '{"name":"X","typeId":1}'; expect 404; jqx '.code' '"deed_not_found"'
call "PUT participant invalid ids" PUT /deeds/$FULLID/participants/abc $T1 '{"name":"X","typeId":1}'; expect 400
call "PUT participant id 0" PUT /deeds/$FULLID/participants/0 $T1 '{"name":"X","typeId":1}'; expect 404; jqx '.code' '"participant_not_found"'
LEG0=$(sql "SELECT id_participante FROM participantes_escritura WHERE nombre_participante='QA13_legacy_0'" | tr -d '\r\n')
INA2=$(sql "SELECT id_participante FROM participantes_escritura WHERE nombre_participante='QA13_inactive_2'" | tr -d '\r\n')
call "PUT inactive (2) -> 409" PUT /deeds/$FULLID/participants/$INA2 $T1 '{"name":"X","typeId":1}'; expect 409; jqx '.code' '"participant_inactive"'
call "PUT legacy status 0 -> 409" PUT /deeds/$FULLID/participants/$LEG0 $T1 '{"name":"X","typeId":1}'; expect 409
call "DELETE inactive (2) -> 409" DELETE /deeds/$FULLID/participants/$INA2 $T1; expect 409; jqx '.code' '"participant_already_inactive"'; jqx '.error' '"Participant is already inactive"'
call "DELETE legacy status 0 -> 409" DELETE /deeds/$FULLID/participants/$LEG0 $T1; expect 409
sql "UPDATE escrituras SET id_usuario=2, fecha_modificacion='2020-01-01 00:00:00' WHERE id_escritura=$FULLID"
call "DELETE participant (user 8)" DELETE /deeds/$FULLID/participants/$PID $T8; expect 204
sqleq "SELECT id_estatus FROM participantes_escritura WHERE id_participante=$PID" 2
sqleq "SELECT id_usuario, fecha_modificacion>'2020-01-02' FROM escrituras WHERE id_escritura=$FULLID" "8${TAB}1"
call "DELETE participant again -> 409" DELETE /deeds/$FULLID/participants/$PID $T1; expect 409
call "GET participants after delete" GET /deeds/$FULLID/participants $T1; jqx '[.[].id]|index('$PID')' 'null'
call "DELETE participant cross-tenant" DELETE /deeds/$FULLID/participants/$PID $T2; expect 404
call "DELETE participant 404 deed" DELETE /deeds/999999/participants/1 $T1; expect 404

echo "--- registrations"
call "GET registrations" GET /deeds/$FULLID/registrations $T1; expect 200; jqx 'length' 2; jq -c '.[0]|keys' $LAST
call "GET registrations empty deed" GET /deeds/$DUPID/registrations $T1; expect 200; jqx '.' '[]'
call "GET registrations cross-tenant" GET /deeds/$FULLID/registrations $T2; expect 404
call "GET registrations 404" GET /deeds/999999/registrations $T1; expect 404
call "POST registration" POST /deeds/$DUPID/registrations $T8 '{"number":"54321","section":"II","volume":"200","date":"2024-03-15"}'; expect 201; jqx '.number' '"54321"'; jqx '.date' '"2024-03-15"'
RID=$(jq -r .id $LAST); echo $RID > /tmp/qa13/rid.txt
sqleq "SELECT id_usuario FROM escrituras WHERE id_escritura=$DUPID" 8
call "POST registration empty body" POST /deeds/$DUPID/registrations $T1 '{}'; expect 400; jqx '.errors.registration' '"At least one registration field is required"'
call "POST registration bad date" POST /deeds/$DUPID/registrations $T1 '{"date":"2024-02-30"}'; expect 400
call "POST registration over limit (type 3 max 2): 2nd ok" POST /deeds/$DUPID/registrations $T1 '{"number":"2"}'; expect 201
call "POST registration 3rd -> 422" POST /deeds/$DUPID/registrations $T1 '{"number":"3"}'; expect 422; jqx '.code' '"registration_limit_exceeded"'; jqx '.details.currentRegistrations' 3; jqx '.details.maxRegistrations' 2
sqleq "SELECT COUNT(*) FROM inscripciones_escritura WHERE id_escritura=$DUPID" 2
call "POST registration cross-tenant" POST /deeds/$DUPID/registrations $T2 '{"number":"1"}'; expect 404
call "PUT registration" PUT /deeds/$DUPID/registrations/$RID $T1 '{"number":"99999","section":null,"volume":"1","date":null}'; expect 200; jqx '.number' '"99999"'; jqx '.section' 'null'; jqx '.date' 'null'
call "PUT registration -> empty body 400" PUT /deeds/$DUPID/registrations/$RID $T1 '{}'; expect 400
call "PUT registration other deed -> 404" PUT /deeds/$FULLID/registrations/$RID $T1 '{"number":"hack"}'; expect 404; jqx '.code' '"registration_not_found"'
sqleq "SELECT numero_inscripcion FROM inscripciones_escritura WHERE id_inscripcion=$RID" "99999"
call "PUT registration at limit allowed (no limit rule)" PUT /deeds/$DUPID/registrations/$RID $T1 '{"number":"edit-at-limit"}'; expect 200
call "PUT registration cross-tenant" PUT /deeds/$DUPID/registrations/$RID $T2 '{"number":"x"}'; expect 404; jqx '.code' '"deed_not_found"'
call "DELETE registration other deed" DELETE /deeds/$FULLID/registrations/$RID $T1; expect 404
call "DELETE registration cross-tenant" DELETE /deeds/$DUPID/registrations/$RID $T2; expect 404
call "DELETE registration" DELETE /deeds/$DUPID/registrations/$RID $T1; expect 204
sqleq "SELECT COUNT(*) FROM inscripciones_escritura WHERE id_inscripcion=$RID" 0
call "DELETE registration again -> 404" DELETE /deeds/$DUPID/registrations/$RID $T1; expect 404
call "DELETE registration invalid id" DELETE /deeds/$DUPID/registrations/zz $T1; expect 400
call "POST registration after delete (room again)" POST /deeds/$DUPID/registrations $T1 '{"number":"again"}'; expect 201
echo "  legacy deed above limit: can edit/delete, cannot add"
sql "INSERT INTO inscripciones_escritura (id_escritura,numero_inscripcion) VALUES ($DUPID,'legacy-extra')"
call "POST registration over limit (legacy)" POST /deeds/$DUPID/registrations $T1 '{"number":"x"}'; expect 422
call "PUT deed (type unchanged) with regs over limit OK" PUT /deeds/$DUPID $T1 '{"deedNumber":"QA13-3","book":"10","deedDate":"2024-01-15","actId":5,"deedTypeId":3}'; expect 200
echo "--- 405/route checks"
call "DELETE /deeds/{id} not exposed" DELETE /deeds/$DUPID $T1; echo "     (info) DELETE /deeds/id -> $CODE"; expect 405
summary
