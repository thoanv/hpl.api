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

$db_connection = new Database();
$conn = $db_connection->dbConnection();
//$data = $_REQUEST;
$data = json_decode(file_get_contents('php://input'), true);
$ID = (int)trim($data['ID']);
$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized"
];

try{
    $store = [];
    if($ID) {
        $sql = 'DELETE FROM b_crm_field_multi
        WHERE ID = :ID';
        $statement = $conn->prepare($sql);
        $statement->bindParam(':ID', $ID, PDO::PARAM_INT);
        if ($statement->execute()) {
            $returnData = [
                "success" => 1,
                "status" => 2000,
                "message" => "success",
            ];
        }
    }else {
        $returnData = [
            "success" => 2,
            "status" => 4000,
            "message" => "error",
        ];
    }


}catch(PDOException $e){
    $returnData = msg(0,500,$e->getMessage());
}
echo json_encode($returnData);