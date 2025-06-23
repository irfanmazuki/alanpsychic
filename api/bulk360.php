<?php

class bulk360{
    var $test = true;
    var $user   = 'k8mepW2dMy';
    var $pass   = 'TTcwUqkX7j89Axt3G0YtEGniFOhsCo4b1Hb9GlGw';
    var $from   = '66688';
    var $to;
    var $text;

    var $url            = 'https://sms.360.my/gw/bulk360/v3_0/send.php';
    var $balance_url    = 'https://sms.360.my/api/balance/v3_0/getBalance';

    function __construct() {
        $this->user = urlencode($this->user);
        $this->pass = urlencode($this->pass);

        $this->url = $this->url . "?user=$this->user&pass=$this->pass&from=$this->from";
    }

    function sendsms($to, $text) {
        if ($this->test) {
            echo 'sentResult = ';
            return;
        }
        $this->url = $this->url . "&to=" . $to . "&text=" . rawurlencode($text);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $sentResult = curl_exec($ch);
        if ($sentResult == FALSE) {
            echo 'Curl failed for sending sms to bulk360.. '.curl_error($ch);
        }
        curl_close($ch);

        echo 'sentResult = ' . $sentResult;
    }

    function checkBalance($country = "") {
        $country = ($country) ? "?country="  . $country : "";
        $this->balance_url = $this->balance_url . $country;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $balanceResult = curl_exec($ch);
        if ($balanceResult == FALSE) {
            echo 'Curl failed for balance checking in bulk360.. '.curl_error($ch);
        }
        curl_close($ch);

        echo 'balanceResult = ' . $balanceResult;
    }
}

?>