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
$page = (int)trim($data['page'])  ? $data['page']* 5 : 0;
$limit = 5;

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
        $fetch_rpa_type= "SELECT * FROM `b_rpa_type` ORDER BY ID DESC LIMIT ".$page.", ".$limit;
        $query_stmt = $conn->prepare($fetch_rpa_type);
        $query_stmt->execute();
        if($query_stmt->rowCount()){
            $rows = $query_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stt_row = 0;
            foreach ($rows as $row){
                switch ($row['TABLE_NAME']) {
                    //Xác nhận công ban công nghệ
                    case 'b_rpa_items_dpjcodapov':
                        $sql = "SELECT * FROM `b_rpa_items_dpjcodapov` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_43_1642473322`=:user_id  OR `UF_RPA_43_1642473344`=:user_id  OR `UF_RPA_43_1642473354`=:user_id OR `UF_RPA_43_1646193110`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_43_1641866971']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_43_1641867502'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_43_1646300201']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_43_1646300201'] ? true : false;
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
                                //List User ký
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //1.Đề xuất Vinh danh
                    case 'b_rpa_items_waeqonmfci':
                        $sql = "SELECT * FROM `b_rpa_items_waeqonmfci` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_50_1651044915`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_50_1651045759']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_50_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_50_1651044962']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_50_1651044962'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_50_1651044915']) array_push($user_signs, $data['UF_RPA_50_1651044915']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //2.Phê duyệt Vinh danh
                    case 'b_rpa_items_tkqlqlpugi':
                        $sql = "SELECT * FROM `b_rpa_items_tkqlqlpugi` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_51_1650599497`=:user_id OR `UF_RPA_51_1650599520`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_51_1651045792']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_50_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_51_1651045447']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_51_1651045447'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_51_1650599497']) array_push($user_signs, $data['UF_RPA_51_1650599497']);
                                if($data['UF_RPA_51_1650599520']) array_push($user_signs, $data['UF_RPA_51_1650599520']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //3.[Khối KD] Xin nghỉ việc
                    case 'b_rpa_items_hahljvcncl':
                        $sql = "SELECT * FROM `b_rpa_items_hahljvcncl` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_52_1651054407`=:user_id OR `UF_RPA_52_1651054440`=:user_id OR `UF_RPA_52_1651054577`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_52_1651050537']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_52_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_52_1651053068']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_52_1651053068'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_52_1651054407']) array_push($user_signs, $data['UF_RPA_52_1651054407']);
                                if($data['UF_RPA_52_1651054440']) array_push($user_signs, $data['UF_RPA_52_1651054440']);
                                if($data['UF_RPA_52_1651054577']) array_push($user_signs, $data['UF_RPA_52_1651054577']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //4.[Khối VP] Xin nghỉ việc
                    case 'b_rpa_items_mtpjsbhack':
                        $sql = "SELECT * FROM `b_rpa_items_mtpjsbhack` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_53_1651054750`=:user_id OR `UF_RPA_53_1651054758`=:user_id OR `UF_RPA_53_1651054771`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_53_1651051043']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_53_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_53_1651053116']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_53_1651053116'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_53_1651054750']) array_push($user_signs, $data['UF_RPA_53_1651054750']);
                                if($data['UF_RPA_53_1651054758']) array_push($user_signs, $data['UF_RPA_53_1651054758']);
                                if($data['UF_RPA_53_1651054771']) array_push($user_signs, $data['UF_RPA_53_1651054771']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //5.Cấp phát Văn phòng phẩm
                    case 'b_rpa_items_zrneigpcdn':
                        $sql = "SELECT * FROM `b_rpa_items_zrneigpcdn` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_54_1651113935`=:user_id OR `UF_RPA_54_1651113954`=:user_id OR `UF_RPA_54_1651113965`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_54_1651054180']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_54_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_54_1651054275']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_54_1651054275'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_54_1651113935']) array_push($user_signs, $data['UF_RPA_54_1651113935']);
                                if($data['UF_RPA_54_1651113954']) array_push($user_signs, $data['UF_RPA_54_1651113954']);
                                if($data['UF_RPA_54_1651113965']) array_push($user_signs, $data['UF_RPA_54_1651113965']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //6.Đăng ký đi công tác
                    case 'b_rpa_items_vhgqokzymt':
                        $sql = "SELECT * FROM `b_rpa_items_vhgqokzymt` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_54_1651113935`=:user_id OR `UF_RPA_54_1651113954`=:user_id OR `UF_RPA_54_1651113965`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_54_1651054180']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_54_NAME'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_54_1651054275']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_54_1651054275'] ? true : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_54_1651113935']) array_push($user_signs, $data['UF_RPA_54_1651113935']);
                                if($data['UF_RPA_54_1651113954']) array_push($user_signs, $data['UF_RPA_54_1651113954']);
                                if($data['UF_RPA_54_1651113965']) array_push($user_signs, $data['UF_RPA_54_1651113965']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                    //7.Đăng ký xe ô tô
                    case 'b_rpa_items_zvctwovhcl':
                        $sql = "SELECT * FROM `b_rpa_items_zvctwovhcl` 
                            WHERE (`CREATED_BY`=:user_id  OR `UF_RPA_14_1651054978`=:user_id OR `UF_RPA_14_1651055111`=:user_id OR `UF_RPA_14_1651055131`=:user_id OR `UF_RPA_14_1651198584`=:user_id)
                            ORDER BY ID DESC LIMIT ".$limit;
                        $query = $conn->prepare($sql);
                        $query->bindValue(':user_id', $user_id, PDO::PARAM_STR);
                        $query->execute();
                        if($query->rowCount()){
                            $datas = $query->fetchAll(PDO::FETCH_ASSOC);
                            $stt_ = 0;
                            $store = [];
                            foreach ($datas as $k_data => $data){
                                $checkStageCurrent = [];
                                $checkCreatedByAndUserSig = false;
                                $fileSigned = 0;
                                $count_files = 0;
                                $files = unserialize($data['UF_RPA_54_1651054180']);
                                $total_files = count($files);

                                $store[$stt_]['COUNT_FILE'] = $total_files;
                                $store[$stt_]['FILE_SIGN'] = $fileSigned;
                                $created_by = getInformationMember($conn, $data['CREATED_BY']);
                                $store[$stt_]['ID_TASK'] = $data['ID'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['NAME_TABLE_RPA'] = $row['NAME'];
                                $store[$stt_]['CREATED_BY'] = $created_by;
                                $store[$stt_]['CREATED_AT'] = date('H:i d/m/Y', strtotime($data['CREATED_TIME']));
                                $store[$stt_]['NAME_TASK'] = $data['UF_RPA_14_1630117592'];
                                $store[$stt_]['NAME_RPA'] = $row['TITLE'];
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                $store[$stt_]['STAGE'] = $data['STAGE_ID'] ? getStage($conn, $data['STAGE_ID']) : '';
                                $store[$stt_]['DOCUMENT_SIGN'] = false;
                                $store[$stt_]['ID_RPA'] = $row['ID'];
                                if(isset($data['UF_RPA_14_1651053415']))
                                    $store[$stt_]['DOCUMENT_SIGN'] = $data['UF_RPA_14_1651053415'] ? true : false;

                                if(isset($data['UF_RPA_14_1651198584']))
                                    $store[$stt_]['DRIVE'] = $data['UF_RPA_14_1651198584'] ? getInformationMember($conn, (int)$data['UF_RPA_14_1651198584']) : false;
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
                                //List User ký
                                $user_signs = [];
                                if($data['UF_RPA_14_1651054978']) array_push($user_signs, $data['UF_RPA_14_1651054978']);
                                if($data['UF_RPA_14_1651055111']) array_push($user_signs, $data['UF_RPA_14_1651055111']);
                                if($data['UF_RPA_14_1651055131']) array_push($user_signs, $data['UF_RPA_14_1651055131']);
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
                                    $store[$stt_]['USERS'][$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                                    $store[$stt_]['USERS'][$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$v_us);
                                    $stt_user++;
                                    $stt_user_sign++;
                                endforeach;
                                $stt_++;
                            }
                        }
                        $stores[$stt_row]['STORES'] = $store;
                        $stores[$stt_row]['TITLE'] = $row['TITLE'];
                        $stores[$stt_row]['ID_RPA'] = $row['ID'];
                        $stt_row++;
                        break;
                }
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