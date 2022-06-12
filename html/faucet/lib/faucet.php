<?php
/**
 * Created by IntelliJ IDEA.
 * User: Cupmouse
 * Date: 2017/07/24
 * Time: 6:59
 */

$faucet_address = "???";
$faucet_password = "???";
$captcha_secret = "???";
$ipcpath = '/run/gnekonium/gnekonium.ipc';

function bchexdec($hex)
{
    if (is_null($hex)) {
        return null;
    }

    $result = '0';
    $digits = strlen($hex);

    for ($i = 0; $i < $digits; $i++) {
        switch ($hex{$i}) {
            case 'A':
            case 'a': $num = 10; break;
            case 'B':
            case 'b': $num = 11; break;
            case 'C':
            case 'c': $num = 12; break;
            case 'D':
            case 'd': $num = 13; break;
            case 'E':
            case 'e': $num = 14; break;
            case 'F':
            case 'f': $num = 15; break;
            default:
                // 0~9までは同じ
                $num = $hex{$i};
                break;
        }

        $result = bcadd($result, bcmul($num, bcpow(16, $digits - $i - 1)));
    }

    return $result;
}

function bcdechex($dec) {
    $last = bcmod($dec, 16);
    $remain = bcdiv(bcsub($dec, $last), 16);

    if($remain == 0) {
        return dechex($last);
    } else {
        return bcdechex($remain).dechex($last);
    }
}

function calculateValue($faucet_balance, PDO $pdo) {
    $stmt = @$pdo->query("SELECT COUNT(*) FROM send_log WHERE time BETWEEN NOW() - INTERVAL 1 DAY AND NOW()");

    if ($stmt === false) {
        return false;
    }

    $count = bcadd($stmt->fetch(PDO::FETCH_NUM)[0], 1);

    $one_nuko = bcpow(10, 18);

    $faucet_balance = bcmul(500, $one_nuko);

    // ここから配布料計算

    if (bccomp($faucet_balance, bcmul(1000, $one_nuko))) {
        // 残量が1000以上のときは、一日あたり100枚(ぐらい)配布する
        $daily_supply = bcmul(100, $one_nuko);
    } else {
        // それ以下のときは残高/10を一日あたり配布する
        $daily_supply = bcdiv($faucet_balance, 10);
    }

    // 汚い数字にするためにノイズをかけています。
    $daily_supply = bcsub($daily_supply, bcdiv($faucet_balance, bcmul(98, pow(10, 4))));

    $value = bcdiv($daily_supply, $count);

    // 一人あたり一回クレイム0.01枚配布が限度！
    $max_value = bcmul(1, bcdiv($one_nuko, 100));
    // valueがmaxよりも大きいときはmaxになる
    if (bccomp($value, $max_value) === 1)
        $value = $max_value;

    // ここまで

    return $value;
}

function init_db()
{
    return new PDO('mysql:host=localhost;dbname=nekonium_faucet;autocommit=false', "nuko_faucet", "dZcakUME8mZ1XQa1UfRq", array(
        PDO::ATTR_PERSISTENT => true
    ));
}