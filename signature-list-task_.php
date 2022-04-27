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
//$data = $_REQUEST;
$data = json_decode(file_get_contents('php://input'), true);
$rpa_id = (int)trim($data['rpa']);
$limit = (int)trim($data['limit']);
$auth = new Auth($conn,$allHeaders);
$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized"
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
function checkFileSignature($conn, $id_rpa, $id_task, $id_file){
    $fetch = "SELECT * FROM `hpl_procedure_histories` 
        WHERE `rpa_type_id`=:rpa_id AND `rpa_stage_id`=:task_id AND `file_id`=:file_id AND `status`=1";
    $query = $conn->prepare($fetch);
    $query->bindValue(':rpa_id', $id_rpa, PDO::PARAM_STR);
    $query->bindValue(':task_id', $id_task, PDO::PARAM_STR);
    $query->bindValue(':file_id', $id_file, PDO::PARAM_STR);
    $query->execute();
    if($query->rowCount()){
        $checkSig = $query->fetch();
        if($checkSig)
            return true;
    }
    return false;
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
if($auth->isAuth()){
    try{
        $data_user = $auth->isAuth();
        $user_id = (int)$data_user['user']['ID'];
        $name_user = $data_user['user']['LAST_NAME'] .' '.$data_user['user']['NAME'];
        $stores = [];
        $task = '';
        $fetch_rpa= "SELECT * FROM `b_rpa_type` WHERE `ID`=:id_rpa";
        $query_stmt = $conn->prepare($fetch_rpa);
        $query_stmt->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
        $query_stmt->execute();
        if($query_stmt->rowCount()){
            $rpa = $query_stmt->fetch(PDO::FETCH_ASSOC);
            $table = $rpa['TABLE_NAME'];
            if($table):
                switch ($table) {
                    case 'b_rpa_items_dpjcodapov':
                        $fetch_task = "SELECT * FROM $table 
                                WHERE `CREATED_BY`=:user_id OR `UF_RPA_43_1642473322`=:user_id OR `UF_RPA_43_1642473344`=:user_id OR `UF_RPA_43_1642473354`=:user_id
                                ORDER BY ID DESC LIMIT ".$limit.", 15";
                        $query = $conn->prepare($fetch_task);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if ($query->rowCount()):
                            $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            foreach ($tasks as $task):
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $store['ID'] = $task['ID'];
                                $store['NAME'] = $task['UF_RPA_43_1641867502'];
                                $store['CREATED_TIME'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                                $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);
                                $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                                $data_files = [];
                                if ($task['UF_RPA_43_1641866971']):
                                    $data_files = unserialize($task['UF_RPA_43_1641866971']);
                                    $count_files = count($data_files);
                                endif;
                                $store['COUNT_FILE'] = $count_files;

                                $stt_user = 0;
                                if($task['UF_RPA_43_1642473322']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_43_1642473322']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_43_1642473322']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473322']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_43_1642473344']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_43_1642473344']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_43_1642473344']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473344']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_43_1642473354']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_43_1642473354']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_43_1642473354']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473354']);
                                    $stt_user++;
                                endif;
                                $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                                $store['FILE_SIGNED'] = $fileSigned;
                                $stores[$stt_] = $store;
                                $stt_++;

                            endforeach;
                        endif;
                        break;
                    case 'b_rpa_items_keshervtxj':
                        $fetch_task = "SELECT * FROM $table 
                                WHERE `CREATED_BY`=:user_id OR `UF_RPA_44_1645691505`=:user_id OR `UF_RPA_44_1645691512`=:user_id
                                ORDER BY ID DESC LIMIT ".$limit.", 15";
                        $query = $conn->prepare($fetch_task);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if ($query->rowCount()):
                            $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            foreach ($tasks as $task):
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $store['ID'] = $task['ID'];
                                $store['NAME'] = $task['UF_RPA_44_NAME'];
                                $store['CREATED_TIME'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                                $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);
                                $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';;
                                $data_files = [];
                                if ($task['UF_RPA_44_1645849477']):
                                    $data_files = unserialize($task['UF_RPA_44_1645849477']);
                                    $count_files = count($data_files);
                                endif;
                                $store['COUNT_FILE'] = $count_files;

                                $stt_user = 0;
                                if($task['UF_RPA_44_1645691505']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_44_1645691505']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_44_1645691505']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_44_1645691505']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_44_1645691512']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_44_1645691512']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_44_1645691512']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_44_1645691512']);
                                    $stt_user++;
                                endif;

                                $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                                $store['FILE_SIGNED'] = $fileSigned;
                                $stores[$stt_] = $store;
                                $stt_++;

                            endforeach;
                        endif;
                        break;
                    case 'b_rpa_items_xgxqvhhhun':
                        $fetch_task = "SELECT * FROM $table 
                                WHERE `CREATED_BY`=:user_id OR `UF_RPA_47_1645688431`=:user_id OR `UF_RPA_47_1645688495`=:user_id OR `UF_RPA_47_1645688504`=:user_id
                                ORDER BY ID DESC LIMIT ".$limit.", 15";
                        $query = $conn->prepare($fetch_task);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if ($query->rowCount()):
                            $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            foreach ($tasks as $task):
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $store['ID'] = $task['ID'];
                                $store['NAME'] = $task['UF_RPA_47_NAME'];
                                $store['CREATED_TIME'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                                $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);
                                $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';;
                                $data_files = [];
                                if ($task['UF_RPA_47_1645849511']):
                                    $data_files = unserialize($task['UF_RPA_47_1645849511']);
                                    $count_files = count($data_files);
                                endif;
                                $store['COUNT_FILE'] = $count_files;

                                $stt_user = 0;
                                if($task['UF_RPA_47_1645688431']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_47_1645688431']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_47_1645688431']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_47_1645688431']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_47_1645688495']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_47_1645688495']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_47_1645688495']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_47_1645688495']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_47_1645688504']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_47_1645688504']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_47_1645688495']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_47_1645688504']);
                                    $stt_user++;
                                endif;
                                $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                                $store['FILE_SIGNED'] = $fileSigned;
                                $stores[$stt_] = $store;
                                $stt_++;

                            endforeach;
                        endif;
                        break;
                    case 'b_rpa_items_czhlytiouo':
                        $fetch_task = "SELECT * FROM $table 
                                WHERE `CREATED_BY`=:user_id OR `UF_RPA_48_1645689085`=:user_id OR `UF_RPA_48_1645689097`=:user_id
                                ORDER BY ID DESC LIMIT ".$limit.", 15";
                        $query = $conn->prepare($fetch_task);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if ($query->rowCount()):
                            $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            foreach ($tasks as $task):
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $store['ID'] = $task['ID'];
                                $store['NAME'] = $task['UF_RPA_48_NAME'];
                                $store['CREATED_TIME'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                                $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);
                                $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';;
                                $data_files = [];
                                if ($task['UF_RPA_48_1645688750']):
                                    $data_files = unserialize($task['UF_RPA_48_1645688750']);
                                    $count_files = count($data_files);
                                endif;
                                $store['COUNT_FILE'] = $count_files;

                                $stt_user = 0;
                                if($task['UF_RPA_48_1645689085']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_48_1645689085']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_48_1645689085']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_48_1645689085']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_48_1645689097']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_48_1645689097']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_48_1645689097']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_48_1645689097']);
                                    $stt_user++;
                                endif;

                                $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                                $store['FILE_SIGNED'] = $fileSigned;
                                $stores[$stt_] = $store;
                                $stt_++;

                            endforeach;
                        endif;
                        break;
                    case 'b_rpa_items_dbpjbvoesh':
                        $fetch_task = "SELECT * FROM $table 
                                WHERE `CREATED_BY`=:user_id OR `UF_RPA_49_1645691068`=:user_id OR `UF_RPA_49_1645691094`=:user_id OR `UF_RPA_49_1645691108`=:user_id OR `UF_RPA_49_1645691121`=:user_id
                                ORDER BY ID DESC LIMIT ".$limit.", 15";
                        $query = $conn->prepare($fetch_task);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if ($query->rowCount()):
                            $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            foreach ($tasks as $task):
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $store['ID'] = $task['ID'];
                                $store['NAME'] = $task['UF_RPA_49_NAME'];
                                $store['CREATED_TIME'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                                $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);
                                $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';;
                                $data_files = [];
                                if ($task['UF_RPA_49_1645690474']):
                                    $data_files = unserialize($task['UF_RPA_49_1645690474']);
                                    $count_files = count($data_files);
                                endif;
                                $store['COUNT_FILE'] = $count_files;

                                $stt_user = 0;
                                if($task['UF_RPA_49_1645691068']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_49_1645691068']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_49_1645691068']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_49_1645691068']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_49_1645691094']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_49_1645691094']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_49_1645691094']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_49_1645691094']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_49_1645691108']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_49_1645691108']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_49_1645691108']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_49_1645691108']);
                                    $stt_user++;
                                endif;
                                if($task['UF_RPA_49_1645691121']):
                                    $number_signed_files = 0;
                                    if(!empty($data_files)):
                                        foreach ($data_files as $v_file):
                                            $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task["ID"], $v_file, $task['UF_RPA_49_1645691121']);
                                            if($checkSig):
                                                $number_signed_files++;
                                            endif;
                                        endforeach;
                                    endif;
                                    if($user_id == $task['UF_RPA_49_1645691121']):
                                        $checkCreatedByAndUserSig = true;
                                        $fileSigned = $number_signed_files;
                                    endif;
                                    $store['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_49_1645691121']);
                                    $stt_user++;
                                endif;
                                $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                                $store['FILE_SIGNED'] = $fileSigned;
                                $stores[$stt_] = $store;
                                $stt_++;

                            endforeach;
                        endif;
                        break;
                }
            endif;
        }
        $returnData = [
            "success" => 1,
            "status" => 2000,
            "message" => "success",
            "data"   => $stores,
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