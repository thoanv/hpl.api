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
require __DIR__.'/classes/JwtHandler.php';
require __DIR__.'/classes/Database.php';
$db_connection = new Database();
$conn = $db_connection->dbConnection();

$data = json_decode(file_get_contents('php://input'), true);

// IF REQUEST METHOD IS NOT EQUAL TO POST
if($_SERVER["REQUEST_METHOD"] != "POST"):
    $returnData = msg(0,404,'Page Not Found!');

// CHECKING EMPTY FIELDS
elseif(!isset($data['email'])
    || !isset($data['username'])
    || empty(trim($data['email']))
    || empty(trim($data['username']))
):
    $fields = ['fields' => ['email','username']];

    $returnData = msg(0,422,'Please Fills in all Required Fields!',$fields);

// IF THERE ARE NO EMPTY FIELDS THEN-
else:

    $email = trim($data['email']);
    $username = trim($data['username']);

    // CHECKING THE EMAIL FORMAT (IF INVALID FORMAT)
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)):
        $returnData = msg(0,422,'Invalid Email Address!');

    // THE USER IS ABLE TO PERFORM THE LOGIN ACTION
    else:
        try{

            $fetch_user_by_email = "SELECT * FROM `b_user` WHERE `EMAIL`=:email";
            $query_stmt = $conn->prepare($fetch_user_by_email);
            $query_stmt->bindValue(':email', $email,PDO::PARAM_STR);
            $query_stmt->execute();
            // IF THE USER IS FOUNDED BY EMAIL
            if($query_stmt->rowCount()):
                $row = $query_stmt->fetch(PDO::FETCH_ASSOC);

                $jwt = new JwtHandler();
                $token = $jwt->_jwt_encode_data(
                    'https://eoffice.haiphatland.com.vn/local/components/hpl.api/',
                    array("user_id"=> $row['ID'])
                );

                $returnData = [
                    'success' => 1,
                    'message' => 'You have successfully logged in.',
                    'token' => $token
                ];



            // IF THE USER IS NOT FOUNDED BY EMAIL THEN SHOW THE FOLLOWING ERROR
            else:
                $returnData = msg(0,422,'Invalid Email Address!');
            endif;
        }
        catch(PDOException $e){
            $returnData = msg(0,500,$e->getMessage());
        }
    endif;
endif;
echo json_encode($returnData);