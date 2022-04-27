<?php

class OAuth2Config {
    public $client_id = '4185-637127995547330633.apps.signserviceapi.com'; // Định danh ứng dụng client 'Nafosted Sample 01' trên hệ thống VNPT SignService
    public $client_secret = 'NGNhMzdmOGE-OGM2Mi00MTg0';// Mật khẩu riêng cho ứng dụng client hiện tại
    public $authorize_url = 'https://gwsca.vnpt.vn/auth/authorize';  // Địa chỉ authorization service
    public $token_url = 'https://gwsca.vnpt.vn/auth/token';          // Địa chỉ get access_token
//    public $authorize_url = 'https://rmgateway.vnptit.vn/auth/authorize';  // Địa chỉ authorization service
//    public $token_url = 'https://rmgateway.vnptit.vn/auth/token';          // Địa chỉ get access_token
    //public $api_url = 'https://demo-gateway.vnpt-ca.vn/signservice/v4/api_gateway'; 
}
?>