<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: access");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
function msg($success,$status,$message,$extra = []){
    return array_merge([
        'success' => $success,
        'status' => $status,
        'message' => $message
    ],$extra);
}
require __DIR__.'/classes/Database.php';
require __DIR__.'/middlewares/Auth.php';
$allHeaders = getallheaders();
$db_connection = new Database();
$conn = $db_connection->dbConnection();
$auth = new Auth($conn,$allHeaders);
$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized"
];

if($auth->isAuth()){
    $user = $auth->isAuth();
    try{
        echo 1; die;
    }
    catch(PDOException $e){
        $returnData = msg(0,500,$e->getMessage());
    }
}

echo json_encode($returnData);

