<?php
class HelperVnpt{
//    public $link_product = 'https://rmgateway.vnptit.vn/csc/';
    public $link_product = "https://gwsca.vnpt.vn/csc/";
    public function api_get_credentical_curl($access_token){
        $curl = curl_init();
        curl_setopt_array($curl,[
            CURLOPT_URL => $this->link_product."credentials/list",
//            CURLOPT_URL => "https://gwsca.vnpt.vn/csc/credentials/list",
//            CURLOPT_URL => "https://rmgateway.vnptit.vn/csc/credentials/list",
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{}'
        ]);
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $msg = json_decode($response);
        curl_close($curl);
        if($httpcode != 200){
            print_r('<pre>');
            print_r($response);
            print_r('</pre>');
            exit();
        }
        return $msg;
    }
    public function api_get_certBase64($data){
        $access_token = $data['access_token_vnpt'];
        $curl = curl_init();
        curl_setopt_array($curl,[
            CURLOPT_URL => $this->link_product."credentials/info",
//            CURLOPT_URL => "https://gwsca.vnpt.vn/csc/credentials/info",
//            CURLOPT_URL => "https://rmgateway.vnptit.vn/csc/credentials/info",
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $msg = json_decode($response);
        curl_close($curl);
        if($httpcode != 200){
            print_r('<pre>');
            print_r($response);
            print_r('</pre>');
            exit();
        }
        return $msg;
    }
    public function api_sign_curl($data, $access_token_vnpt){
        $access_token = $access_token_vnpt;
        $curl = curl_init();
        curl_setopt_array($curl,[
            CURLOPT_URL => $this->link_product."signature/sign",
//            CURLOPT_URL => "https://gwsca.vnpt.vn/csc/signature/sign",
//            CURLOPT_URL => "https://rmgateway.vnptit.vn/csc/signature/sign",
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $msg = json_decode($response);
        curl_close($curl);
        if($httpcode != 200){
            print_r('<pre>');
            print_r($response);
            print_r('</pre>');
            exit();
        }
        return $msg;
    }
    public function api_get_tranInfo_curl($data, $access_token_vnpt){
        $access_token = $access_token_vnpt;
        $curl = curl_init();
        curl_setopt_array($curl,[
//            CURLOPT_URL => "https://gwsca.vnpt.vn/csc/credentials/gettraninfo",
            CURLOPT_URL => "https://rmgateway.vnptit.vn/csc/credentials/gettraninfo",
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $msg = json_decode($response);
        curl_close($curl);
        if($httpcode != 200){
            print_r('<pre>');
            print_r($response);
            print_r('</pre>');
            exit();
        }
        return $msg;
    }
    public function getGUID(){
        mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
        $charid = strtolower(md5(uniqid(rand(), true)));
        $hyphen = chr(45);// "-"
        $uuid = substr($charid, 0, 8).$hyphen
            .substr($charid, 8, 4).$hyphen
            .substr($charid,12, 4).$hyphen
            .substr($charid,16, 4).$hyphen
            .substr($charid,20,12);
        return $uuid;
    }
    public function curl_get_contents($url)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);

        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }
}
?>