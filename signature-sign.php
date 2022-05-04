<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS',true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB', true);
define('STOP_STATISTICS', true);
define('NO_AGENT_STATISTIC', 'Y');
define('DisableEventsCheck', true);
define('NO_AGENT_CHECK', true);

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__.'/../../../');
}
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: access");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
require_once($_SERVER['DOCUMENT_ROOT'].'/local/components/hpl.procedure/vendor/autoload.php');

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
function msg($success,$status,$message,$extra = []){
    return array_merge([
        'success' => $success,
        'status' => $status,
        'message' => $message
    ],$extra);
}

require __DIR__.'/classes/Database.php';
require __DIR__.'/middlewares/Auth.php';
require __DIR__.'/vnpt/OAuth2Config.php';
require __DIR__.'/vnpt/HelperVnpt.php';
//require __DIR__.'/classes/CFile.php';

$allHeaders = getallheaders();

$db_connection = new Database();
$conn = $db_connection->dbConnection();
$config = new OAuth2Config();
$helper = new HelperVnpt();
//$cfile = new CFile();

//$data = $_REQUEST;
$data = json_decode(file_get_contents('php://input'), true);
$rpa_id = (int)trim($data['rpa']);
$task_id = (int)trim($data['task']);
$file_id = (int)trim($data['file_id']);
$visibleType = isset($data['visibleType']) ? (int)$data['visibleType'] : 0;
$tran_id = isset($data['tran_id']) ? $data['tran_id'] : '';
$access_token_vnpt = isset($data['access_token_vnpt']) ? $data['access_token_vnpt'] : '';
$auth = new Auth($conn,$allHeaders);
$link_s3_amazon = 'https://haiphatland-bitrix24.s3.ap-southeast-1.amazonaws.com/';
$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized"
];
function getFile($conn, $file_id){
    $fetch= "SELECT * FROM `b_file` WHERE `ID`=:file_id";
    $query = $conn->prepare($fetch);
    $query->bindValue(':file_id', $file_id, PDO::PARAM_STR);
    $query->execute();
    if($query->rowCount()){
        $file = $query->fetch(PDO::FETCH_ASSOC);
        return $file;
    }
    return false;
}
function generateRandomString($length = 5) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}
if($auth->isAuth()){
    try{
        $data_user = $auth->isAuth();
        $user_id = (int)$data_user['user']['ID'];
        $store = [];
        $signature = '';
        $file = [];
        $sql = "SELECT * FROM `hpl_procedure_histories` 
                            WHERE `rpa_type_id`=:rpa_id AND `rpa_stage_id`=:task_id AND `file_id`=:file_id AND `user_sig`=:user_id;";
        $query = $conn->prepare($sql);
        $query->bindValue(':rpa_id', $rpa_id, PDO::PARAM_STR);
        $query->bindValue(':task_id', $task_id, PDO::PARAM_STR);
        $query->bindValue(':file_id', $file_id, PDO::PARAM_STR);
        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
        $query->execute();
        if($query->rowCount()){
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if($row['location_sig'])
                $store = json_decode($row['location_sig'], true);
        }
        $sql_s = "SELECT * FROM `hpl_procedure_accounts` 
                            WHERE `user_id`=:user_id;";
        $query_s = $conn->prepare($sql_s);
        $query_s->bindValue(':user_id', $user_id, PDO::PARAM_STR);
        $query_s->execute();
        if($query_s->rowCount()){
            $row_sign = $query_s->fetch(PDO::FETCH_ASSOC);
            $signature = 'https://eoffice.haiphatland.com.vn/'.$row_sign['img_signature'];
        }
        $file = getFile($conn, $file_id);
        if($file) {
            $name_file = str_replace(' ', '+', $file['FILE_NAME']);
            $file['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
        }else{
            $file = [];
        }
        $returnData = [
            "success" => 1,
            "status" => 2000,
            "message" => "success",
            "data"   => $store,
            "signature" => $signature,
            "file"  => $file,
        ];

        if($visibleType){
            $list_signature = [];
            $list_but_phe = [];
            $k_ = 0;
            if($row && $row['location_sig']){
                $list_location_sigs = json_decode($row['location_sig'], true);
                $height_cavan = 841;
                $width_cavan = 595;
                $scale = 1;
                foreach ($list_location_sigs as $k_s => $v_s){
                    $x_ratio = $width_cavan / $scale / $width_cavan;
                    $y_ratio = $height_cavan / $scale / $height_cavan;
                    foreach ($v_s as $v){
                        $x = $v['x']/100*$width_cavan;
                        $y = $v['y']/100*$height_cavan;
                        $x_pdf = $x * $x_ratio;
                        $y_pdf =  ($height_cavan / $scale) - ($y * $y_ratio) - ((int)$v['h'] * $y_ratio);
                        $w_pdf = $x_pdf + (int)$v['w'];
                        $h_pdf = $y_pdf + (int)$v['h'];
                        $list_signature[$k_]['page'] = (int)$v['page'];
                        $list_signature[$k_]['rectangle'] =  "".round($x_pdf).",".round($y_pdf-5).",".round($w_pdf).",".round($h_pdf)."";
                        $k_++;
                    }
                }
            }
            $returnData['list_signature'] = $list_signature;
            if($list_signature && !empty($list_signature)){
                $payload = [
                    'client_id' => $config->client_id,
                    'client_secret' => $config->client_secret,
                    'username' => $row_sign['login'],
                    'password' => $row_sign['password'],
                    'grant_type' => 'password'
                ];
                $curl = curl_init();
                curl_setopt_array($curl,[
                    CURLOPT_URL => $config->token_url,
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/x-www-form-urlencoded",
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => http_build_query($payload)
                ]);
                $response = curl_exec($curl);
                curl_close($curl);
                $response = json_decode($response);
                if(isset($response->error)){
                    $returnData['note'] = "Vui lòng kiểm tra lại mật khẩu";
                }else{
                    $access_token_vnpt = $response->access_token;
                    $refresh_token_vnpt = $response->refresh_token;
                    $msg = $helper->api_get_credentical_curl($access_token_vnpt);
                    $credentials = $msg->content[0];
                    $msg = $helper->api_get_certBase64([
                        "credentialId" => $credentials,
                        "certificates" => "chain",
                        "certInfo" => true,
                        "authInfo" => true,
                        "access_token_vnpt" => $access_token_vnpt,
                    ]);

                    $certBase64 = $msg->cert->certificates[0];
                    $certBase64 = str_replace("\r\n","",$certBase64);
                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                    $unsignDataBase64 = chunk_split(base64_encode($helper->curl_get_contents($link_s3_amazon.$file['SUBDIR'].'/'.$name_file)));
                    //echo json_encode($unsignDataBase64);die;
                    //$img_signatre = chunk_split(base64_encode($helper->curl_get_contents($signature)));
                    $img_signatre = base64_encode(file_get_contents($_SERVER['DOCUMENT_ROOT'].$row_sign['img_signature']));
                    $options = [
                        "fontColor" => "3333ff",
                        "fontName" => "Roboto ", // 3 option : Time/Roboto/Arial
                        "fontSize" => 8,
                        "fontStyle"=> 0, //0:Normal,1:Bold,2:Italic,3:Bold&Italic,4:Underline
                        "imageSrc" => $img_signatre,
                        "visibleType" => $visibleType, //1:TextOnly, 2:TEXT_WITH_LOGO_LEFT, 3:LOGO_ONLY, 4:TEXT_WITH_LOGO_TOP, 5:TEXT_WITH_BACKGROUND
                        "comment" => $list_but_phe,
                        "signatures" => $list_signature
                    ];
                    $returnData['options'] = $options;
                    $data_sign = [
                        'credentialId' => $credentials,
                        'refTranId' => $helper->getGUID(),
                        'description' => 'Ký văn bản',
                        'datas' => [
                            [
                                "name" => $file['FILE_NAME'],
                                "dataBase64" => $unsignDataBase64,
                                "options" => json_encode($options),
                            ]
                        ]
                    ];
                    $msg = $helper->api_sign_curl($data_sign, $access_token_vnpt);
                    $tranId = isset($msg->content->tranId) ? $msg->content->tranId : "";
                    if($tranId != ""){
                        $data = [
                            'status' => 0,
                            'id' => $row['id'],
                        ];
                        $sql = "UPDATE hpl_procedure_histories SET status=:status WHERE id=:id";
                        $conn->prepare($sql)->execute($data);
                        $returnData['access_token_vnpt'] =$access_token_vnpt;
                        $returnData['tranId'] =$tranId;
                        $returnData['note'] = 'Gửi xác nhận ký thành công vui lòng xác nhận tại App VNPT';
                        $returnData['zile_base'] = $unsignDataBase64;
                    }else{
                        $returnData['note'] = "Ký số thất bại";
                    }
                }
            }
        }
        if($tran_id && $access_token_vnpt){
            $data_getTranInfo = [
                "tranId" => $tran_id
            ];

            $msg = $helper->api_get_tranInfo_curl($data_getTranInfo, $access_token_vnpt);
            $status_vnpt = $msg->content->tranStatus;
            $returnData['status_vnpt'] = $status_vnpt;
            $returnData['check_status'] = $msg;

            if($status_vnpt == 1){
                $dataSigned = $msg->content->documents[0]->dataSigned;

                $bin = base64_decode($dataSigned, true);
                if (strpos($bin, '%PDF') !== 0) {
                    throw new Exception('Missing the PDF file signature');
                }

                //Lưu file amazon
                $s3 = S3Client::factory([
                    'version' => 'latest',
                    'region'  => 'ap-southeast-1',
                    'credentials' => array(
                        'key' => 'AKIA32PBU75HW4B7RW5L',
                        'secret'  => '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'
                    )
                ]);

                $bucket = 'haiphatland-bitrix24'; //haiphatland-bitrix24

                $path_aws = 'hplSign/'.generateRandomString(5);

                $key = $path_aws.'/'.$file['FILE_NAME'];
                $key_current = $file['SUBDIR'].'/'.$file['FILE_NAME'];

                try {
                    //Xóa file Aws
                    $result = $s3->deleteObject([
                        'Bucket' => $bucket,
                        'Key'    => $key_current
                    ]);
                    //Thêm mới file Aws
                    $result_push = $s3->putObject([
                        'ACL' => 'public-read-write',
                        'Body' => $bin,
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'ContentType' => 'application/pdf',
                        'visibility' => 'public',
    //                                'ContentEncoding' =>  'base64',
                    ]);

                    $data_file = [
                        'SUBDIR' => $path_aws,
                        'id' => $file['ID'],
                    ];
                    $sql = "UPDATE b_file SET SUBDIR=:SUBDIR WHERE ID=:id";
                    $conn->prepare($sql)->execute($data_file);
                    //$cfile->CleanCache($file['ID']);

                    CFile::CleanCache($file['ID']);
                    $data_history = [
                        'status' => 1,
                        'location_sig' => NULL,
                        'id' => $row['id'],
                    ];
                    $sql = "UPDATE hpl_procedure_histories SET location_sig=:location_sig, status=:status WHERE id=:id";
                    $conn->prepare($sql)->execute($data_history);
                    $returnData['note'] = 'Ký thành công';
                    $file = getFile($conn, $file_id);
                    if($file) {
                        $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                        $file['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                        //clearstatcache();
                    }else{
                        $file = [];
                    }
                    $returnData["file"]  = $file;
                    $returnData['note'] = "Ký thành công";
                   // clearstatcache();
                } catch (S3Exception $e) {
                    echo 'Lỗi '.$e->getMessage() . "\n";
                }
            }
            else{
                $returnData['note'] = "Bạn chưa thực hiện đầy đủ các thao tác trên App. Mời thực hiện lại";
                $returnData['access_token_vnpt'] =$access_token_vnpt;
                $returnData['tranId'] =$tran_id;
                $returnData['success'] =3;
            }
        }
    }catch(PDOException $e){
        $returnData = msg(0,500,$e->getMessage());
    }
}else{
    $returnData = [
        "success" => 2,
        "status" => 2000,
        "message" => "success",
    ];
}
echo json_encode($returnData);