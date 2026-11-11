source /tmp/qa12/lib.sh
LOG=/tmp/qa12/evidence_part1.log; : > $LOG
echo "== AUTH =="
call "no token GET /users" GET /users ""; expect 401
call "no token GET /users/2" GET /users/2 ""; expect 401
call "no token POST /users" POST /users "" '{"username":"zz.noauth","password":"Password123","firstName":"A","paternalLastName":"B","access":["ESC"]}'; expect 401
call "no token PUT /users/2" PUT /users/2 "" '{"firstName":"X"}'; expect 401
call "garbage token GET /users" GET /users "abc.def.ghi"; expect 401
RT=$(cd /c/Users/jrobe/WorkSpace/WebApps/api-notarial && docker-compose exec -T api php -r 'require "/var/www/html/vendor/autoload.php"; Dotenv\Dotenv::createImmutable("/var/www/html")->load(); echo \Firebase\JWT\JWT::encode(["iss"=>"security@devrob","aud"=>"apps@devrob","iat"=>time(),"exp"=>time()+3600,"userId"=>2,"notaryId"=>1,"type"=>"refresh"],$_ENV["JWT_SECRET"],"HS256");' | tr -d '\r\n')
call "refresh token used as access GET /users" GET /users "$RT"; expect 401
echo "== LIST =="
call "list n1" GET /users "$T1"; expect 200
jq -r 'length as $n | "     count=\($n) order=" + ([.[].paternalLastName]|join(","))' $LAST_BODY
jq -r '"     keys=" + ([.[]|keys|join("/")]|unique|join(" | "))' $LAST_BODY
grep -ciE 'password|contrasenia|notaryId' $LAST_BODY | sed 's/^/     password-like matches: /'
call "list n1 status=1" GET "/users?status=1" "$T1"; expect 200
jq -r '"     count=\(length) statuses=" + ([.[].status]|unique|join(","))' $LAST_BODY
call "list n1 status=2" GET "/users?status=2" "$T1"; expect 200
jq -r '"     count=\(length) ids=" + ([.[].id|tostring]|join(",")) + " statuses=" + ([.[].status]|unique|join(","))' $LAST_BODY
call "list status=0" GET "/users?status=0" "$T1"; expect 400
call "list status=3" GET "/users?status=3" "$T1"; expect 400
call "list status=abc" GET "/users?status=abc" "$T1"; expect 400
call "list status= (empty)" GET "/users?status=" "$T1"; expect 400
call "list status=1%20OR%201=1 (sqli)" GET "/users?status=1%20OR%201=1" "$T1"; expect 400
call "list status[]=1" GET "/users?status%5B%5D=1" "$T1"; echo "     (info) code $CODE"
call "list n3" GET /users "$T3"; expect 200
jq -r '"     count=\(length) ids=" + ([.[].id|tostring]|join(","))' $LAST_BODY
call "list n2" GET /users "$T2"; expect 200
jq -r '"     count=\(length) ids=" + ([.[].id|tostring]|join(","))' $LAST_BODY
echo "== SHOW =="
call "show own-notary user 2" GET /users/2 "$T1"; expect 200
jq -r '"     keys=" + (keys|join(","))' $LAST_BODY
call "show user 7 (id_estatus=0)" GET /users/7 "$T1"; expect 200
jq -r '"     status=\(.status)"' $LAST_BODY
call "show other-notary user 4 from n1" GET /users/4 "$T1"; expect 404
call "show n1 user 2 from n3" GET /users/2 "$T3"; expect 404
call "show nonexistent 99999" GET /users/99999 "$T1"; expect 404
call "show id=0" GET /users/0 "$T1"; expect 404
call "show id=-1" GET /users/-1 "$T1"; expect 404
call "show id=abc" GET /users/abc "$T1"; expect 400
call "show id=1.5" GET /users/1.5 "$T1"; expect 400
call "show id=2abc" GET /users/2abc "$T1"; expect 400
call "show id=99999999999999999999" GET /users/99999999999999999999 "$T1"; echo "     (info) code $CODE"
call "show id=1%20OR%201=1" GET "/users/1%20OR%201=1" "$T1"; echo "     (info) code $CODE"
