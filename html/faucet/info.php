<?php
/**
 * Created by IntelliJ IDEA.
 * User: Cupmouse
 * Date: 2017/07/23
 * Time: 21:25
 */
//try {
    require_once './lib/faucet.php';
    require_once './lib/nekonium.php';

    $pdo=init_db();
    $stmt = $pdo->query("SELECT time, INET6_NTOA(ip) AS ip, HEX(address) AS address, HEX(amount) AS amount, HEX(tx_id) AS tx_id FROM send_log ORDER BY id DESC LIMIT 10");

    if ($stmt === false) {
        die("db error");
    }

    $history = array();
    for ($i = 0; ($dbres = $stmt->fetch(PDO::FETCH_ASSOC)); $i++) {
        $history[$i] = [$dbres['time'], $dbres['ip'], '0x' . $dbres['address'], bchexdec($dbres['amount']), '0x' . $dbres['tx_id']];
    }
    $dbres = null;
    $stmt = null;

    $gnek = new Nekonium($ipcpath);
    $faucet_balance = bchexdec($gnek->eth_getBalance($faucet_address));
    $current_distribution = calculateValue($faucet_balance, $pdo);
    $pdo = null;

    $res = array(
        'b' => [$current_distribution, $faucet_balance, $faucet_address],
        'h' => $history
    );

    print json_encode($res);

//} catch (Throwable $e) {
//    print('error occurred');
//}