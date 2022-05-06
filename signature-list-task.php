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
$rpa_id = (int)trim($data['rpa']);
$limit = (int)trim($data['limit']);
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
        $fetch_rpa= "SELECT * FROM `b_rpa_type` WHERE `ID`=:id_rpa";
        $query_stmt = $conn->prepare($fetch_rpa);
        $query_stmt->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
        $query_stmt->execute();
        if($query_stmt->rowCount()){
            $row = $query_stmt->fetch(PDO::FETCH_ASSOC);
            $stt_ = 0;
            switch ($row['TABLE_NAME']) {
                //Xác nhận công
                case 'b_rpa_items_dpjcodapov':
                    $sql = "SELECT * FROM `b_rpa_items_dpjcodapov` 
                        WHERE `CREATED_BY`=:user_id OR `UF_RPA_43_1642473322`=:user_id OR `UF_RPA_43_1642473344`=:user_id OR `UF_RPA_43_1642473354`=:user_id OR `UF_RPA_43_1646193110`=:user_id
                        ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll();
                        foreach ($datas as $k_data => $data){
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
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            if(isset($data['UF_RPA_43_1646300201']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_43_1646300201'] ? true : false;

                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_43_1642473322']) array_push($user_signs, $data['UF_RPA_43_1642473322']);
                            if($data['UF_RPA_43_1642473344']) array_push($user_signs, $data['UF_RPA_43_1642473344']);
                            if($data['UF_RPA_43_1642473354']) array_push($user_signs, $data['UF_RPA_43_1642473354']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //1.Đề xuất Vinh danh - 50
                case 'b_rpa_items_waeqonmfci':
                    $sql = "SELECT * FROM `b_rpa_items_waeqonmfci` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_50_1651044915`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_50_1651045759']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_50_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_50_1651044962']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_50_1651044962'] ? true : false;
                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_50_1651044915']) array_push($user_signs, $data['UF_RPA_50_1651044915']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //2.Phê duyệt Vinh danh - 51
                case 'b_rpa_items_tkqlqlpugi':
                    $sql = "SELECT * FROM `b_rpa_items_tkqlqlpugi` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_51_1650599497`=:user_id OR `UF_RPA_51_1650599520`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_51_1651045792']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_51_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_51_1651045447']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_51_1651045447'] ? true : false;
                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_51_1650599497']) array_push($user_signs, $data['UF_RPA_51_1650599497']);
                            if($data['UF_RPA_51_1650599520']) array_push($user_signs, $data['UF_RPA_51_1650599520']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //3.[Khối KD] Xin nghỉ việc - 52
                case 'b_rpa_items_hahljvcncl':
                    $sql = "SELECT * FROM `b_rpa_items_hahljvcncl` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_52_1651054407`=:user_id OR `UF_RPA_52_1651054440`=:user_id OR `UF_RPA_52_1651054577`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_52_1651050537']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_52_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_52_1651053068']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_52_1651053068'] ? true : false;

                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_52_1651054407']) array_push($user_signs, $data['UF_RPA_52_1651054407']);
                            if($data['UF_RPA_52_1651054440']) array_push($user_signs, $data['UF_RPA_52_1651054440']);
                            if($data['UF_RPA_52_1651054577']) array_push($user_signs, $data['UF_RPA_52_1651054577']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //4.[Khối VP] Xin nghỉ việc - 53
                case 'b_rpa_items_mtpjsbhack':
                    $sql = "SELECT * FROM `b_rpa_items_mtpjsbhack` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_53_1651054750`=:user_id OR `UF_RPA_53_1651054758`=:user_id OR `UF_RPA_53_1651054771`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_53_1651051043']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_53_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_53_1651053116']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_53_1651053116'] ? true : false;

                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_53_1651054750']) array_push($user_signs, $data['UF_RPA_53_1651054750']);
                            if($data['UF_RPA_53_1651054758']) array_push($user_signs, $data['UF_RPA_53_1651054758']);
                            if($data['UF_RPA_53_1651054771']) array_push($user_signs, $data['UF_RPA_53_1651054771']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //5.Cấp phát Văn phòng phẩm - 54
                case 'b_rpa_items_zrneigpcdn':
                    $sql = "SELECT * FROM `b_rpa_items_zrneigpcdn` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_54_1651113935`=:user_id OR `UF_RPA_54_1651113954`=:user_id OR `UF_RPA_54_1651113965`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_54_1651054180']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_54_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_54_1651054275']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_54_1651054275'] ? true : false;

                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_54_1651113935']) array_push($user_signs, $data['UF_RPA_54_1651113935']);
                            if($data['UF_RPA_54_1651113954']) array_push($user_signs, $data['UF_RPA_54_1651113954']);
                            if($data['UF_RPA_54_1651113965']) array_push($user_signs, $data['UF_RPA_54_1651113965']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //6.Đăng ký đi công tác - 55
                case 'b_rpa_items_vhgqokzymt':
                    $sql = "SELECT * FROM `b_rpa_items_vhgqokzymt` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_55_1651130809`=:user_id OR `UF_RPA_55_1651130823`=:user_id OR `UF_RPA_55_1651130837`=:user_id 
                                       OR `UF_RPA_55_1651130846`=:user_id OR `UF_RPA_55_1651130846`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_55_1651130107']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_55_NAME'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_55_1651130145']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_55_1651130145'] ? true : false;

                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_55_1651130809']) array_push($user_signs, $data['UF_RPA_55_1651130809']);
                            if($data['UF_RPA_55_1651130823']) array_push($user_signs, $data['UF_RPA_55_1651130823']);
                            if($data['UF_RPA_55_1651130837']) array_push($user_signs, $data['UF_RPA_55_1651130837']);
                            if($data['UF_RPA_55_1651130846']) array_push($user_signs, $data['UF_RPA_55_1651130846']);
                            if($data['UF_RPA_55_1651130858']) array_push($user_signs, $data['UF_RPA_55_1651130858']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
                //7.Đăng ký xe ô tô
                case 'b_rpa_items_zvctwovhcl':
                    $sql = "SELECT * FROM `b_rpa_items_zvctwovhcl` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_14_1651054978`=:user_id OR `UF_RPA_14_1651055111`=:user_id OR `UF_RPA_14_1651055131`=:user_id OR `UF_RPA_14_1651198584`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit.", 15";
                    $query = $conn->prepare($sql);
                    $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                    $query->execute();
                    if($query->rowCount()){
                        $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($datas as $k_data => $data){
                            $checkStageCurrent = [];
                            $checkCreatedByAndUserSig = false;
                            $fileSigned = 0;
                            $count_files = 0;
                            $files = unserialize($data['UF_RPA_54_1651054180']);
                            $total_files = count($files);

                            $stores[$stt_]['COUNT_FILE'] = $total_files;
                            $stores[$stt_]['FILE_SIGN'] = $fileSigned;
                            $created_by = getInformationMember($conn, $data['CREATED_BY']);
                            $stores[$stt_]['ID_TASK'] = $data['ID'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                            $stores[$stt_]['CREATED_BY'] = $created_by;
                            $stores[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                            $stores[$stt_]['NAME_TASK'] = $data['UF_RPA_14_1630117592'];
                            $stores[$stt_]['NAME_RPA'] = $row['TITLE'];
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            $stores[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                            $stores[$stt_]['DOCUMENT_SIGN'] = false;
                            $stores[$stt_]['ID_RPA'] = $row['ID'];
                            if(isset($data['UF_RPA_14_1651053415']))
                                $stores[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_14_1651053415'] ? true : false;

                            if(isset($data['UF_RPA_14_1651198584']))
                                $store[$stt_]['DRIVE'] = $data['UF_RPA_14_1651198584'] ? getInformationMember($conn, (int)$data['UF_RPA_14_1651198584']) : false;
                            //List User ký
                            $user_signs = [];
                            if($data['UF_RPA_14_1651054978']) array_push($user_signs, $data['UF_RPA_14_1651054978']);
                            if($data['UF_RPA_14_1651055111']) array_push($user_signs, $data['UF_RPA_14_1651055111']);
                            if($data['UF_RPA_14_1651055131']) array_push($user_signs, $data['UF_RPA_14_1651055131']);
                            $stt_user = 0;
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
                                endif;
                                $stores[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                $stores[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                $stt_user++;
                            endforeach;
                            $stt_++;
                        }
                    }
                    break;
            }
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