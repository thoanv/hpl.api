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
$data = json_decode(file_get_contents('php://input'), true);
//$data = $_REQUEST;
$type = trim($data['type']);
$page = (int)trim($data['page'])  ? $data['page']* 10 : 0;
$limit = 10;

$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized",
];

function getInformationMember($conn, $user_id){
    $link_s3_amazon = 'https://haiphatland-bitrix24.s3.ap-southeast-1.amazonaws.com/';
    $fetch= "SELECT LOGIN, EMAIL, b_user.ID as user_id, LAST_NAME, NAME, WORK_DEPARTMENT, WORK_POSITION, b_file.ID as file_id, b_file.SUBDIR, b_file.FILE_NAME
            FROM b_user 
            LEFT OUTER JOIN b_file ON b_file.ID = b_user.PERSONAL_PHOTO
            WHERE b_user.ID=:user_id";
    $query = $conn->prepare($fetch);
    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
    $query->execute();
    if($query->rowCount()){
        $user = $query->fetch(PDO::FETCH_ASSOC);
        $user['PATH'] = '';
        if($user['SUBDIR'] && $user['FILE_NAME']):
            $name_file = str_replace(' ', '+', $user['FILE_NAME']);
            $user['PATH'] = $link_s3_amazon.$user['SUBDIR'].'/'.$name_file;

        endif;
        $user['FULLNAME'] = $user['LAST_NAME'].' '.$user['NAME'];
        return $user;
    }
}
function checkFileSignatureByUser($conn, $id_rpa, $id_task, $id_file, $user_id){
    $fetch = "SELECT * FROM `hpl_procedure_histories` 
        WHERE `rpa_type_id`=:rpa_id AND `rpa_stage_id`=:task_id AND `file_id`=:file_id AND `user_sig`=:user_id AND `status`=1";
    $query = $conn->prepare($fetch);
    $query->bindValue(':rpa_id', $id_rpa, PDO::PARAM_STR);
    $query->bindValue(':task_id', $id_task, PDO::PARAM_STR);
    $query->bindValue(':file_id', $id_file, PDO::PARAM_STR);
    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
    $query->execute();
    if($query->rowCount()){
        $checkSig = $query->fetch();
        if($checkSig)
            return true;
    }
    return false;
}
function getStage($conn, $stage_id){
    $fetch= "SELECT * FROM `b_rpa_stage` WHERE `ID`=:stage_id";
    $query = $conn->prepare($fetch);
    $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
    $query->execute();
    if($query->rowCount()){
        $stage = $query->fetch(PDO::FETCH_ASSOC);
        return $stage;
    }
    return false;
}
if($auth->isAuth()){
    try{
        $data_user = $auth->isAuth();
        $user_id = (int)$data_user['user']['ID'];
        $stores = [];
        $fetch_rpa_type= "SELECT * FROM `b_rpa_type` ORDER BY ID DESC";
        $query_stmt = $conn->prepare($fetch_rpa_type);
        $query_stmt->execute();
        if($query_stmt->rowCount()){
            $rows = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stt_ = 0;
            foreach ($rows as $row){
                switch ($row['TABLE_NAME']) {
                    case 'b_rpa_items_dpjcodapov':
                        if($type === 'approved'){
                            $stage_id = 109;
                        }else{
                            $stage_id = 110;
                        }
                        $sql = "SELECT * FROM `b_rpa_items_dpjcodapov` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_43_1642473322`=:user_id  OR `UF_RPA_43_1642473344`=:user_id  OR `UF_RPA_43_1642473354`=:user_id)
                            AND (`STAGE_ID`=:stage_id) 
                            ORDER BY ID DESC LIMIT ".$page.",".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll();
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_43_1641866971']);
                                $total_files = count($files);

                                $stores[$stt_]['COUNT_FILE'] = $total_files;
                                $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $stores[$stt_]['ID_TASK'] = $data['ID'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $stores[$stt_]['CREATED_BY'] = $created_by;
                                $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_43_1641867502'];
                                $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $stores[$stt_]['DOCUMENT_SIGN'] = false;
                                if(isset($data['UF_RPA_43_1646300201']))
                                    $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_43_1646300201'] ? true : false;
                                //List Stage
                                $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                                $query_stage = $conn->prepare($sql);
                                $query_stage->bindValue(':id_rpa', $row['ID'], PDO::PARAM_STR);
                                $query_stage->execute();
                                $arrIdStage = [];

                                if($query_stage->rowCount()){
                                    $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($stages as $sta):
                                        array_push( $arrIdStage, $sta['ID']);
                                    endforeach;
                                }
                                if($data['CREATED_BY'] == $user_id):
                                    array_push($checkStageCurrent, $arrIdStage[0]);
                                endif;
                                //List User k??
                                $user_signs = [];
                                if($data['UF_RPA_43_1642473322']) array_push($user_signs, $data['UF_RPA_43_1642473322']);
                                if($data['UF_RPA_43_1642473344']) array_push($user_signs, $data['UF_RPA_43_1642473344']);
                                if($data['UF_RPA_43_1642473354']) array_push($user_signs, $data['UF_RPA_43_1642473354']);
                                $stt_user = 0;
                                $stt_user_sign = 1;
                                foreach ($user_signs as $v_us):
                                    $number_signed_files = 0;
                                    if(!empty($files)):
                                        foreach ($files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $row['ID'], $data["ID"], $v_file, $v_us);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $v_us):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                        array_push($checkStageCurrent, $arrIdStage[$stt_user_sign]);
                                    endif;
                                    $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                if($type === 'pending' && in_array($data['STAGE_ID'], $checkStageCurrent)):
                                    unset($stores[$stt_]);
                                endif;
                                $stt_++;
                            }
                        }
                        break;
                    case 'b_rpa_items_keshervtxj':
                        if($type === 'approved'){
                            $stage_id = 116;
                        }else{
                            $stage_id = 117;
                        }
                        $sql = "SELECT * FROM `b_rpa_items_keshervtxj` 
                            WHERE (`CREATED_BY`=:user_id OR `UF_RPA_44_1645691505`=:user_id OR `UF_RPA_44_1645691512`=:user_id)
                            AND (`STAGE_ID`=:stage_id) 
                            ORDER BY ID DESC LIMIT ".$page.",".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll();
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_44_1645849477']);
                                $total_files = count($files);

                                $stores[$stt_]['COUNT_FILE'] = $total_files;
                                $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $stores[$stt_]['ID_TASK'] = $data['ID'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $stores[$stt_]['CREATED_BY'] = $created_by;
                                $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_44_NAME'];
                                $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $stores[$stt_]['DOCUMENT_SIGN'] = false;
                                if(isset($data['UF_RPA_43_1646300201']))
                                    $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_43_1646300201'] ? true : false;
                                //List Stage
                                $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                                $query_stage = $conn->prepare($sql);
                                $query_stage->bindValue(':id_rpa', $row['ID'], PDO::PARAM_STR);
                                $query_stage->execute();
                                $arrIdStage = [];

                                if($query_stage->rowCount()){
                                    $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($stages as $sta):
                                        array_push( $arrIdStage, $sta['ID']);
                                    endforeach;
                                }
                                if($data['CREATED_BY'] == $user_id):
                                    array_push($checkStageCurrent, $arrIdStage[0]);
                                endif;
                                //List User k??
                                $user_signs = [];
                                if($data['UF_RPA_44_1645691505']) array_push($user_signs, $data['UF_RPA_44_1645691505']);
                                if($data['UF_RPA_44_1645691512']) array_push($user_signs, $data['UF_RPA_44_1645691512']);
                                $stt_user = 0;
                                $stt_user_sign = 1;
                                foreach ($user_signs as $v_us):
                                    $number_signed_files = 0;
                                    if(!empty($files)):
                                        foreach ($files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $row['ID'], $data["ID"], $v_file, $v_us);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $v_us):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                        array_push($checkStageCurrent, $arrIdStage[$stt_user_sign]);
                                    endif;
                                    $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                if($type === 'pending' && in_array($data['STAGE_ID'], $checkStageCurrent)):
                                    unset($stores[$stt_]);
                                endif;
                                $stt_++;
                            }
                        }
                        break;
                    case 'b_rpa_items_xgxqvhhhun':
                        if($type === 'approved'){
                            $stage_id = 121;
                        }else{
                            $stage_id = 122;
                        }
                        $sql = "SELECT * FROM `b_rpa_items_xgxqvhhhun`
                            WHERE (`CREATED_BY`=:user_id OR `UF_RPA_47_1645688431`=:user_id OR `UF_RPA_47_1645688495`=:user_id OR `UF_RPA_47_1645688504`=:user_id)
                            AND (`STAGE_ID`=:stage_id)
                            ORDER BY ID DESC LIMIT ".$page.",".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll();
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $number_signed_files = 0;
                                $files = unserialize($data['UF_RPA_47_1645849511']);
                                $total_files = count($files);
                                $stores[$stt_]['COUNT_FILE'] = $total_files;
                                $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $stores[$stt_]['ID_TASK'] = $data['ID'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $stores[$stt_]['CREATED_BY'] = $created_by;
                                $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_47_NAME'];
                                $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $stores[$stt_]['DOCUMENT_SIGN'] = false;
                                //List Stage
                                $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                                $query_stage = $conn->prepare($sql);
                                $query_stage->bindValue(':id_rpa', $row['ID'], PDO::PARAM_STR);
                                $query_stage->execute();
                                $arrIdStage = [];

                                if($query_stage->rowCount()){
                                    $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($stages as $sta):
                                        array_push( $arrIdStage, $sta['ID']);
                                    endforeach;
                                }
                                //List User k??
                                $user_signs = [];
                                if($data['UF_RPA_47_1645688431']) array_push($user_signs, $data['UF_RPA_47_1645688431']);
                                if($data['UF_RPA_47_1645688495']) array_push($user_signs, $data['UF_RPA_47_1645688495']);
                                if($data['UF_RPA_47_1645688504']) array_push($user_signs, $data['UF_RPA_47_1645688504']);
                                $stt_user = 0;
                                $stt_user_sign = 1;
                                foreach ($user_signs as $v_us):
                                    $number_signed_files = 0;
                                    if(!empty($files)):
                                        foreach ($files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $row['ID'], $data["ID"], $v_file, $v_us);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $v_us):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                        array_push($checkStageCurrent, $arrIdStage[$stt_user_sign]);
                                    endif;
                                    $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                endforeach;
                                if($type === 'pending' && in_array($data['STAGE_ID'], $checkStageCurrent)):
                                    unset($stores[$stt_]);
                                endif;
                                $stt_++;
                            }
                        }
                        break;
                    case 'b_rpa_items_czhlytiouo':
                        if($type === 'approved'){
                            $stage_id = 127;
                        }else{
                            $stage_id = 128;
                        }
                        $sql = "SELECT * FROM `b_rpa_items_czhlytiouo`
                            WHERE (`CREATED_BY`=:user_id OR `UF_RPA_48_1645689085`=:user_id OR `UF_RPA_48_1645689097`=:user_id)
                             AND (`STAGE_ID`=:stage_id)
                            ORDER BY ID DESC LIMIT ".$page.",".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll();
                            $stt_ = 0;
                            foreach ($datas as $k_data => $data){
                                $number_signed_files = 0;
                                $files = unserialize($data['UF_RPA_48_1645688750']);
                                $total_files = count($files);

                                $stores[$stt_]['COUNT_FILE'] = $total_files;
                                $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $stores[$stt_]['ID_TASK'] = $data['ID'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $stores[$stt_]['CREATED_BY'] = $created_by;
                                $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_48_NAME'];
                                $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $stores[$stt_]['DOCUMENT_SIGN'] = false;
                                //List Stage
                                $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                                $query_stage = $conn->prepare($sql);
                                $query_stage->bindValue(':id_rpa', $row['ID'], PDO::PARAM_STR);
                                $query_stage->execute();
                                $arrIdStage = [];

                                if($query_stage->rowCount()){
                                    $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($stages as $sta):
                                        array_push( $arrIdStage, $sta['ID']);
                                    endforeach;
                                }
                                //List User k??
                                $user_signs = [];
                                if($data['UF_RPA_48_1645689085']) array_push($user_signs, $data['UF_RPA_48_1645689085']);
                                if($data['UF_RPA_48_1645689097']) array_push($user_signs, $data['UF_RPA_48_1645689097']);
                                $stt_user = 0;
                                $stt_user_sign = 1;
                                foreach ($user_signs as $v_us):
                                    $number_signed_files = 0;
                                    if(!empty($files)):
                                        foreach ($files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $row['ID'], $data["ID"], $v_file, $v_us);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $v_us):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                        array_push($checkStageCurrent, $arrIdStage[$stt_user_sign]);
                                    endif;
                                    $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                endforeach;
                                if($type === 'pending' && in_array($data['STAGE_ID'], $checkStageCurrent)):
                                    unset($stores[$stt_]);
                                endif;
                                $stt_++;
                            }
                        }
                        break;
                    case 'b_rpa_items_dbpjbvoesh':
                        if($type === 'approved'){
                            $stage_id = 133;
                        }else{
                            $stage_id = 134;
                        }
                        $sql = "SELECT * FROM `b_rpa_items_dbpjbvoesh`
                            WHERE (`CREATED_BY`=:user_id OR `UF_RPA_49_1645691068`=:user_id OR `UF_RPA_49_1645691094`=:user_id OR `UF_RPA_49_1645691108`=:user_id OR `UF_RPA_49_1645691121`=:user_id) 
                            AND (`STAGE_ID`=:stage_id)
                            ORDER BY ID DESC LIMIT ".$page.",".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->bindValue(':stage_id', $stage_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll();
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $number_signed_files = 0;
                                $files = unserialize($data['UF_RPA_49_1645690474']);
                                $total_files = count($files);

                                $stores[$stt_]['COUNT_FILE'] = $total_files;
                                $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $stores[$stt_]['ID_TASK'] = $data['ID'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $stores[$stt_]['CREATED_BY'] = $created_by;
                                $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_49_NAME'];
                                $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $stores[$stt_]['ID_RPA'] = $row['ID'];
                                $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $stores[$stt_]['DOCUMENT_SIGN'] = false;
                                //List Stage
                                $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                                $query_stage = $conn->prepare($sql);
                                $query_stage->bindValue(':id_rpa', $row['ID'], PDO::PARAM_STR);
                                $query_stage->execute();
                                $arrIdStage = [];

                                if($query_stage->rowCount()){
                                    $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($stages as $sta):
                                        array_push( $arrIdStage, $sta['ID']);
                                    endforeach;
                                }
                                //List User k??
                                $user_signs = [];
                                if($data['UF_RPA_49_1645691068']) array_push($user_signs, $data['UF_RPA_49_1645691068']);
                                if($data['UF_RPA_49_1645691094']) array_push($user_signs, $data['UF_RPA_49_1645691094']);
                                if($data['UF_RPA_49_1645691108']) array_push($user_signs, $data['UF_RPA_49_1645691108']);
                                if($data['UF_RPA_49_1645691121']) array_push($user_signs, $data['UF_RPA_49_1645691121']);
                                $stt_user = 0;
                                $stt_user_sign = 1;
                                foreach ($user_signs as $v_us):
                                    $number_signed_files = 0;
                                    if(!empty($files)):
                                        foreach ($files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $row['ID'], $data["ID"], $v_file, $v_us);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $v_us):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                        array_push($checkStageCurrent, $arrIdStage[$stt_user_sign]);
                                    endif;
                                    $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                endforeach;
                                if($type === 'pending' && in_array($data['STAGE_ID'], $checkStageCurrent)):
                                    unset($stores[$stt_]);
                                endif;
                                $stt_++;
                            }
                        }
                        break;
                }
            }
        }
        $data = $stores;
        usort($data, "compareDate");
        $returnData = [
            "success" => 1,
            "status" => 2000,
            "message" => "success",
            "data"   => $data,
            "dateEnd"   => $dateEnd,
            "dateStart"   => $dateStart,
        ];

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