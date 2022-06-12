<?php
/**
 * Created by IntelliJ IDEA.
 * User: Cupmouse
 * Date: 2017/07/23
 * Time: 20:23
 */

require_once 'lib/faucet.php';
require_once 'lib/nekonium.php';

if(!empty($_SERVER['REMOTE_ADDR']) ){
    $remoteip = $_SERVER['REMOTE_ADDR'];
}
else{
    $remoteip = empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? '' : $_SERVER['HTTP_X_FORWARDED_FOR'];
}

$remoteip = htmlspecialchars($remoteip);

// captcha check

if (empty($_POST['t'])) {
    print json_encode(array('s'=>false, 'c'=>0));   // code 0 tなし
    die();
}
if (empty($_POST['a'])) {
    print json_encode(array('s'=>false, 'c'=>1));   // code 1 aなし
    die();
}
 /* アドレスクイック正当性チェック */

$to_address = $_POST['a'];

if (preg_match("/^0x[a-fA-F\d]{40}$/", $to_address) !== 1) {
    print json_encode(array('s'=>false, 'c'=>2));   // code 2 アドレス不正
    die();
}

/* ロボットチェックのためのトークンをGoogleのサーバーに送信し、認証 */

$g_response = $_POST['t'];
$params= array(
    'secret' => $captcha_secret,
    'response' => $g_response,
    'remoteip' => $remoteip
);

$options = array(
    CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3
);


$ch = curl_init();
@curl_setopt_array($ch, $options);

$capres = @curl_exec($ch);
curl_close($ch);

/* プロキシチェック */

if (isset($_SERVER['HTTP_CLIENT_IP']) || isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_VIA'])) {
    $proxiness = 10000;
} else {
    $proxiness = 0;
}
//else {
//    /* グローバルでBANされているIPか確認 */
//
//    $curl = curl_init();
//    $option = array(
//        CURLOPT_URL => 'http://check.getipintel.net/check.php?ip='.$remoteip.'&contact=cupnmouse@gmail.com',
//        CURLOPT_RETURNTRANSFER => true,
//        CURLOPT_TIMEOUT => 3
//    );
//    @curl_setopt_array($curl, $option);
//    $return = @curl_exec($curl);
//    @curl_close($curl);
//
//    if (!is_numeric($return) || !(0<= $return && $return <= 1)) {
//        print json_encode(array('s'=>false, 'c'=>400));  // code 400 プロキシチェック失敗
//        die();
//    }
//    $proxiness = $return * 10000;
//}

if ($capres === false) {
    print json_encode(array('s'=>false, 'c'=>10));  // code 10 ロボットチェック問い合わせ失敗
    die();
}

$capres = @json_decode($capres, true);

if ($capres === false || $capres['success'] === false) {
    // ロボット認証に失敗
    print json_encode(array('s'=>false, 'c'=>11));  // code 11 ロボットチェック失敗、99%クライアント側の失敗
    die();
}

/* データベース通信 */

$subbedaddr = substr($to_address, 2);

try {
    $pdo = init_db();

    /* データベースを照合して重複していないか不正チェック */

    $prepStmt = $pdo->prepare("SELECT COUNT(*), MAX(time) FROM send_log WHERE (address = UNHEX(?) OR ip = INET6_ATON(?)) AND time BETWEEN NOW() - INTERVAL 3 HOUR AND NOW()");
    $prepStmt->bindParam(1, $subbedaddr, PDO::PARAM_STR);
    $prepStmt->bindParam(2, $remoteip, PDO::PARAM_STR);

    if (!$prepStmt->execute()) {
        print json_encode(array('s'=>false, 'c'=>101)); // code 101 重複チェック失敗
        die();
    }

    $dbres = $prepStmt->fetch(PDO::FETCH_NUM);

    if (intval($dbres[0]) > 0) {
        // 期間以内に受け取っているのでさようならです

        print json_encode(array('s'=>false, 'c'=>102, 't'=>$dbres[1])); // code 102 期間内に受け取っているので追い返す
        die();
    }

    // 送信する量の計算
    $gnek = new Nekonium($ipcpath);
    $faucet_balance = bchexdec(substr($gnek->eth_getBalance($faucet_address), 2));
    $value = calculateValue($faucet_balance, $pdo);
    $hexamount = bcdechex($value);

    // ここでデータベースに記録

    $pdo->beginTransaction();

    $prepStmt = $pdo->prepare("INSERT INTO send_log VALUES (NULL, UNHEX(?), UNHEX(?), NULL, INET6_ATON(?), NOW(), ?)");
    $prepStmt->bindParam(1, $subbedaddr, PDO::PARAM_STR);
    $prepStmt->bindParam(2, $hexamount, PDO::PARAM_STR);
    $prepStmt->bindParam(3, $remoteip, PDO::PARAM_STR);
    $prepStmt->bindParam(4, $proxiness, PDO::PARAM_INT);

    if (!$prepStmt->execute()) {
        print json_encode(array('s'=>false, 'c'=>103)); // code 103 データベース記録時クエリ失敗(文法エラー？)
        die();
    }


    // まだコミットしない

    $prepStmt = null;

    $recid = $pdo->lastInsertId();
} catch (PDOException $e) {
    print json_encode(array('s'=>false, 'c'=>100)); // code 100 重複チェック時接続エラー
    if ($pdo !== null) {
        try {
            $pdo->rollBack();
        } catch (PDOException $e2) {}
    }
    die();
} catch (RPCException $e) {
    print json_encode(array('s'=>false, 'c'=>200)); // code 200 gnek接続エラー
    if ($pdo !== null) {
        try {
            $pdo->rollBack();
        } catch (PDOException $e2) {}
    }
    die();
}

// プロクシ死すべし
if ($proxiness >= 8000) {
    $pdo->commit();
    print json_encode(array('s'=>false, 'c'=>401)); // code 401 プロクシ厨爆発
    die();
}

try {
    // ここから実際に送る

    $gnekres = $gnek->personal_sendTransaction(new Ethereum_Transaction($faucet_address, $to_address, null, null, '0x' . $hexamount), $faucet_password);
//
//    if (isset($gethres['error'])) {
//        // maybe not be called...
//        // DBの内容をロールバックする
//        $pdo->rollBack();
//        print json_encode(array('s' => false, 'c' => 301, 'd' => $gnekres['error']));
//        die();
//    }
} catch (RPCException $e) {
    print json_encode(array('s'=>false, 'c'=>300, 'd' => $e->getMessage()));
    try {
        $pdo->rollBack();
    } catch (PDOException $e2) {}
    die();
}

try {
    $tx_id = $gnekres;

    $pdo->commit();

    // DBに最終登録

    $subbedtx = substr($tx_id, 2);

    $prepStmt = $pdo->prepare("UPDATE send_log SET tx_id = UNHEX(?) WHERE id = ?");
    $prepStmt->bindParam(1, $subbedtx, PDO::PARAM_STR);
    $prepStmt->bindParam(2, $recid, PDO::PARAM_INT);

    if (!$prepStmt->execute()) {
        print json_encode(array('s'=>true, 'v'=>$value, 'tx'=>$tx_id, 'c' => 101));
        die();
    }
} catch (PDOException $e) {
    print json_encode(array('s'=>true, 'v'=>$value, 'tx'=>$tx_id, 'c' => 100));
    die();
} finally {
    try {
        $pdo->commit();
    } catch (PDOException $e) {}

    $prepStmt = null;
    $pdo = null;
}

print json_encode(array('s'=>true, 'amount'=>$value, 'tx'=>$tx_id, 'c'=>0));