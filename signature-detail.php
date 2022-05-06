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
require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
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
require __DIR__.'/classes/AssetNotify.php';
$allHeaders = getallheaders();
$db_connection = new Database();
$conn = $db_connection->dbConnection();
//$data = $_REQUEST;
$data = json_decode(file_get_contents('php://input'), true);
$rpa_id = (int)trim($data['rpa']);
$task_id = (int)trim($data['task']);
$stage_current = isset($data['stage_current']) ? $data['stage_current'] : '';
$stage_status = (int)trim($data['stage_status']) ? $data['stage_status'] : 0;
$stage_id_next = (int)trim($data['stage_id_next']) ? $data['stage_id_next'] : 0;
$auth = new Auth($conn,$allHeaders);
$link_s3_amazon = 'https://haiphatland-bitrix24.s3.ap-southeast-1.amazonaws.com/';
$date_current = date("Y-m-d H:i:s");
$returnData = [
    "success" => 0,
    "status" => 401,
    "message" => "Unauthorized"
];
$arrayCar = [
    504=>'Xe Innova 7 chỗ - Biển số: 30F-987.67',
    505=>'Xe Innova 7 chỗ - Biển số: 30H-015.66',
    512=>'Thuê xe ngoài'
];
$typeCar = [
    502=>'Xe công ty',
    503=>'Xe thuê ngoài'
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
function formatSizeUnits($bytes)
{
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }

    return $bytes;
}
function changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, $table, $stage_fail){
    $statge_q = '';
    $date_current = date("Y-m-d H:i:s");
    if($stage_current):
        $sort = 0;
        if($stage_status == 1)
            $sort = $stage_current['SORT'] + 1000;
        if($sort):
            $fetch_stage = "SELECT * FROM `b_rpa_stage` WHERE `SORT`=:sort AND `TYPE_ID`=:type_id";
            $query_sta = $conn->prepare($fetch_stage);
            $query_sta->bindValue(':sort', $sort, PDO::PARAM_STR);
            $query_sta->bindValue(':type_id', $rpa_id, PDO::PARAM_STR);
            $query_sta->execute();
            if ($query_sta->rowCount()) {
                $statge_q = $query_sta->fetch(PDO::FETCH_ASSOC);
                $stage_id_next = $statge_q['ID'];
            }
        endif;
        if($stage_status == 2){
            $sort = $stage_fail;
            $stage_id_next = $stage_fail;
        }
        $data_stage = [
            'stage_id_next' => $stage_id_next,
            'stage_id_current' => $stage_current['ID'],
            'id' => $task_id,
            'moved_by' => $user_id,
            'updated_by' => $user_id,
            'updated_time' => $date_current,
            'moved_time' => $date_current,
        ];
        $sql = "UPDATE ".$table." 
                            SET STAGE_ID=:stage_id_next, 
                                PREVIOUS_STAGE_ID=:stage_id_current, 
                                MOVED_BY=:moved_by, 
                                UPDATED_BY=:updated_by,
                                MOVED_TIME=:moved_time,
                                UPDATED_TIME=:updated_time
                            WHERE ID=:id";
        $conn->prepare($sql)->execute($data_stage);

        $date_item_history = [ $stage_id_next, $stage_current['ID'], $task_id, $rpa_id, $date_current, $user_id, 'MOVE', 'manual', ''];

        $sql = "INSERT INTO b_rpa_item_history (`NEW_STAGE_ID`, `STAGE_ID`, `ITEM_ID`, `TYPE_ID`, `CREATED_TIME`, `USER_ID`, `ACTION`, `SCOPE`, `TASK_ID`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $conn->prepare($sql)->execute($date_item_history);
        return true;
    endif;
}
if($auth->isAuth()){
    try{
        $data_user = $auth->isAuth();
        $user_id = (int)$data_user['user']['ID'];
        $name_user = $data_user['user']['LAST_NAME'] .' '.$data_user['user']['NAME'];
        $store = [];
        $task = '';
        $stages = [];
        $checkStageCurrent = [];
        $fetch_rpa= "SELECT * FROM `b_rpa_type` WHERE `ID`=:id_rpa";
        $query_stmt = $conn->prepare($fetch_rpa);
        $query_stmt->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
        $query_stmt->execute();
        $date_item_history = [];
        if($query_stmt->rowCount()){
            $rpa = $query_stmt->fetch(PDO::FETCH_ASSOC);
            switch ($rpa['TABLE_NAME']) {
                //[BCN] Xác nhận Công
                case 'b_rpa_items_dpjcodapov':
                    //Chuyển trạng thái stage
                    $statge_q = '';
                    if($stage_current):
                        $sort = 0;
                        if($stage_status == 1)
                            $sort = $stage_current['SORT'] + 1000;

                        if($sort):
                            $fetch_stage = "SELECT * FROM `b_rpa_stage` WHERE `SORT`=:sort AND `TYPE_ID`=:type_id";
                            $query_sta = $conn->prepare($fetch_stage);
                            $query_sta->bindValue(':sort', $sort, PDO::PARAM_STR);
                            $query_sta->bindValue(':type_id', $rpa_id, PDO::PARAM_STR);
                            $query_sta->execute();
                            if ($query_sta->rowCount()) {
                                $statge_q = $query_sta->fetch(PDO::FETCH_ASSOC);
                                $stage_id_next = $statge_q['ID'];
                            }
                        endif;
                        if($stage_status == 2){
                            $sort = 110;
                            $stage_id_next = 110;
                        }
                        $data_stage = [
                            'stage_id_next' => $stage_id_next,
                            'stage_id_current' => $stage_current['ID'],
                            'id' => $task_id,
                            'moved_by' => $user_id,
                            'updated_by' => $user_id,
                            'updated_time' => $date_current,
                            'moved_time' => $date_current,
                        ];
                        $sql = "UPDATE b_rpa_items_dpjcodapov 
                            SET STAGE_ID=:stage_id_next, 
                                PREVIOUS_STAGE_ID=:stage_id_current, 
                                MOVED_BY=:moved_by, 
                                UPDATED_BY=:updated_by,
                                MOVED_TIME=:moved_time,
                                UPDATED_TIME=:updated_time
                            WHERE ID=:id";
                        $conn->prepare($sql)->execute($data_stage);

                        $date_item_history = [ $stage_id_next, $stage_current['ID'], $task_id, $rpa_id, $date_current, $user_id, 'MOVE', 'manual', ''];

                        $sql = "INSERT INTO b_rpa_item_history (`NEW_STAGE_ID`, `STAGE_ID`, `ITEM_ID`, `TYPE_ID`, `CREATED_TIME`, `USER_ID`, `ACTION`, `SCOPE`, `TASK_ID`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $conn->prepare($sql)->execute($date_item_history);
                    endif;

                    $fetch_task = "SELECT * FROM `b_rpa_items_dpjcodapov`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_43_1641867502'];
                        $store['NOTE'] = $task['UF_RPA_43_1641866991'];
                        $store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_43_1646300201'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_43_1641866971']):
                            $data_files = unserialize($task['UF_RPA_43_1641866971']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_43_1646704879'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        if($task['UF_RPA_43_1642473322']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_43_1641866971']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_43_1642473322']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473322']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_43_1642473322']):
                                $checkCreatedByAndUserSig = true;
                            endif;
                            if($task['UF_RPA_43_1642473322'] == $user_id)
                                array_push($checkStageCurrent, $arrIdStage[1]);
                        endif;

                        if($task['UF_RPA_43_1642473344']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_43_1641866971']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_43_1642473344']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473344']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_43_1642473344']):
                                $checkCreatedByAndUserSig = true;
                            endif;
                            if($task['UF_RPA_43_1642473344'] == $user_id)
                                array_push($checkStageCurrent, $arrIdStage[2]);
                        endif;
                        if($task['UF_RPA_43_1642473354']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_43_1641866971']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_43_1642473354']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_43_1642473354']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_43_1642473354']):
                                $checkCreatedByAndUserSig = true;
                            endif;
                            if($task['UF_RPA_43_1642473354'] == $user_id)
                                array_push($checkStageCurrent, $arrIdStage[3]);
                        endif;
                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //1.Đề xuất Vinh danh - 50 - STAGE_ID=144 Trạng thái không Đạt - STAGE_ID=141 Trạng thái Đạt
                case 'b_rpa_items_waeqonmfci':
                    //Chuyển trạng thái stage
                    $changeStage = changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_waeqonmfci', 144);
                    $fetch_task = "SELECT * FROM `b_rpa_items_waeqonmfci`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_50_NAME'];
                        $store['NOTE'] = $task['UF_RPA_50_1650595411'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_50_1651044962'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_50_1651045759']):
                            $data_files = unserialize($task['UF_RPA_50_1651045759']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_50_1651050342'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        if($task['UF_RPA_50_1651044915']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_50_1651045759']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_50_1651044915']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_50_1651044915']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_50_1651044915']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;

                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //2.Phê duyệt Vinh danh - 51 - STAGE_ID=150 Trạng thái không Đạt - STAGE_ID=149 Trạng thái Đạt
                case 'b_rpa_items_tkqlqlpugi':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_tkqlqlpugi', 150);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_tkqlqlpugi`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_51_NAME'];
                        $store['NOTE'] = $task['UF_RPA_51_1650597833'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_51_1651045447'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_51_1651045792']):
                            $data_files = unserialize($task['UF_RPA_51_1651045792']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_51_1651050471'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        if($task['UF_RPA_51_1650599497']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_51_1651045792']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_51_1650599497']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_51_1650599497']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_51_1650599497']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_51_1650599520']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_51_1651045792']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_51_1650599520']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_51_1650599520']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_51_1650599497']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //3.[Khối KD] Xin nghỉ việc - 52 - STAGE_ID=158 Trạng thái không Đạt - STAGE_ID=157 Trạng thái Đạt
                case 'b_rpa_items_hahljvcncl':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_hahljvcncl', 158);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_hahljvcncl`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_52_NAME'];
                        $store['NOTE'] = $task['UF_RPA_52_1650856223'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_52_1651053068'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_52_1651050537']):
                            $data_files = unserialize($task['UF_RPA_52_1651050537']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_52_1651050546'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        //NGười ký
                        if($task['UF_RPA_52_1651054407']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_52_1651050537']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_52_1651054407']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_52_1651054407']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_52_1651054407']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_52_1651054440']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_52_1651050537']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_52_1651054440']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_52_1651054440']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_52_1651054440']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($task['UF_RPA_52_1651054577']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_52_1651050537']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_52_1651054577']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_52_1651054577']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_52_1651054577']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[3]);
                            endif;
                        endif;
                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //4.[Khối VP] Xin nghỉ việc - 53 - STAGE_ID=164 Trạng thái không Đạt - STAGE_ID=163 Trạng thái Đạt
                case 'b_rpa_items_mtpjsbhack':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_mtpjsbhack', 164);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_mtpjsbhack`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_53_NAME'];
                        $store['NOTE'] = $task['UF_RPA_53_1650859105'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_53_1651053116'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_53_1651051043']):
                            $data_files = unserialize($task['UF_RPA_53_1651051043']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_53_1651051052'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        //NGười ký
                        if($task['UF_RPA_53_1651054750']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_53_1651051043']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_53_1651054750']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_53_1651054750']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_53_1651054750']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_53_1651054758']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_53_1651051043']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_53_1651054758']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_53_1651054758']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_53_1651054758']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($task['UF_RPA_53_1651054771']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_53_1651051043']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_53_1651054771']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_52_1651054577']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_53_1651054771']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[3]);
                            endif;
                        endif;
                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //5.Cấp phát Văn phòng phẩm - 54 - STAGE_ID=170 Trạng thái không Đạt - STAGE_ID=172 Trạng thái Đạt
                case 'b_rpa_items_zrneigpcdn':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_zrneigpcdn', 173);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_zrneigpcdn`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_54_NAME'];
                        $store['NOTE'] = $task['UF_RPA_54_1651054249'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_54_1651054275'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_54_1651054180']):
                            $data_files = unserialize($task['UF_RPA_54_1651054180']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_54_1651054195'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        //NGười ký
                        if($task['UF_RPA_54_1651113935']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_54_1651054180']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_54_1651113935']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_54_1651113935']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_54_1651113935']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_54_1651113954']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_54_1651054180']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_54_1651113954']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_54_1651113954']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_54_1651113954']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($task['UF_RPA_54_1651113965']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_54_1651054180']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_54_1651113965']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_54_1651113965']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_54_1651113965']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[3]);
                            endif;
                        endif;

                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //6.Đăng ký đi công tác - 55 - STAGE_ID=179 Trạng thái không Đạt - STAGE_ID=178 Trạng thái Đạt
                case 'b_rpa_items_vhgqokzymt ':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_vhgqokzymt', 179);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_vhgqokzymt`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_55_NAME'];
                        $store['NOTE'] = $task['UF_RPA_55_1651130132'];
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_55_1651130145'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_55_1651130107']):
                            $data_files = unserialize($task['UF_RPA_55_1651130107']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_55_1651130120'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        //NGười ký
                        if($task['UF_RPA_55_1651130809']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_55_1651130107']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_55_1651130809']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130809']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_55_1651130809']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_55_1651130823']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_55_1651130107']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_55_1651130823']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130823']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_55_1651130823']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($task['UF_RPA_55_1651130837']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_55_1651130107']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_55_1651130837']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130837']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_55_1651130837']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[3]);
                            endif;
                        endif;
                        if($task['UF_RPA_55_1651130846']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_55_1651130107']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_55_1651130846']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130846']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_55_1651130846']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[4]);
                            endif;
                        endif;
                        if($task['UF_RPA_55_1651130858']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_55_1651130107']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_55_1651130858']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130858']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_55_1651130858']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[5]);
                            endif;
                        endif;
                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
                //7.Đăng ký xe ô tô - 14 - STAGE_ID=43 Trạng thái không Đạt - STAGE_ID=42 Trạng thái Đạt
                case 'b_rpa_items_zvctwovhcl ':
                    //Chuyển trạng thái stage
                    changeStage($conn, $stage_current, $task_id, $rpa_id, $user_id, $stage_status, 'b_rpa_items_zvctwovhcl', 43);
                    $statge_q = '';
                    $fetch_task = "SELECT * FROM `b_rpa_items_zvctwovhcl`  WHERE `ID`=:id_task";
                    $query = $conn->prepare($fetch_task);
                    $query->bindValue(':id_task', $task_id, PDO::PARAM_STR);
                    $query->execute();
                    if ($query->rowCount()) {
                        $checkCreatedByAndUserSig = false;
                        $task = $query->fetch(PDO::FETCH_ASSOC);
                        $store['NAME_RPA'] = $rpa['TITLE'];
                        $store['ID_RPA'] = $rpa_id;
                        $store['ID_TASK'] = $task_id;
                        $store['NAME_TASK'] = $task['UF_RPA_14_1630117592'];
                        $store['NOTE'] = $task['UF_RPA_14_1630123906'];
                        //Mở rộng đăng ký ô tô
                        //Mục đích
                        $store['PURPOSE'] = '';
                        if(isset($task['UF_RPA_14_1630122555']))
                            $store['PURPOSE'] = $task['UF_RPA_14_1630122555'];
                        //Thời gian đi
                        $store['TIME_START'] = '';
                        if(isset($task['UF_RPA_14_1630123833']))
                            $store['TIME_START'] = date('H:i d/m/Y', strtotime($task['UF_RPA_14_1630123833']));
                        //Thời gian về
                        $store['TIME_END'] = '';
                        if(isset($task['UF_RPA_14_1630123881']))
                            $store['TIME_END'] = date('H:i d/m/Y', strtotime($task['UF_RPA_14_1630123881']));
                        // Nơi xuất phát
                        $store['ADDRESS_START'] = '';
                        if(isset($task['UF_RPA_14_1630123052']))
                            $store['ADDRESS_START'] = $task['UF_RPA_14_1630123052'];

                        $store['ADDRESS_END'] = '';
                        if(isset($task['UF_RPA_14_1630123083']))
                            $store['ADDRESS_END'] = $task['UF_RPA_14_1630123083'];
                        // Thông tin LOẠI xe
                        $store['TYPE_CAR'] = '';
                        if(isset($task['UF_RPA_14_1630122496']))
                            $store['TYPE_CAR'] = $typeCar[$task['UF_RPA_14_1630122496']];
                        // Thông tin xe
                        $store['CAR'] = '';
                        if(isset($task['UF_RPA_14_1631522705']))
                            $store['CAR'] = $arrayCar[$task['UF_RPA_14_1631522705']];
                        // Thông tin DRIVE
                        $store['DRIVE'] = '';
                        if(isset($task['UF_RPA_14_1651198584']))
                            $store['DRIVE'] = getInformationMember($conn, (int)$task['UF_RPA_14_1651198584']);
                        //$store['FOLLOW'] = $task['UF_RPA_43_1646193110'] ? getInformationMember($conn, $task['UF_RPA_43_1646193110']) : '';
                        $store['STAGE'] = $task['STAGE_ID'] ? getStage($conn, $task['STAGE_ID']) : '';
                        if($task['CREATED_BY'])
                            $store['CREATED_BY'] = getInformationMember($conn, $task['CREATED_BY']);

                        $store['CREATED_AT'] = date('H:i d/m/Y', strtotime($task['CREATED_TIME']));
                        $store['DOCUMENT_SIGN'] = false;
                        if($task['UF_RPA_55_1651130145'])
                            $store['DOCUMENT_SIGN'] = true;
                        $files = [];
                        $user_signatures = [];
                        $count_files = 0;
                        $data_files = [];
                        if($task['UF_RPA_14_1651654944']):
                            $data_files = unserialize($task['UF_RPA_14_1651654944']);
                            $count_files = count($data_files);
                            $stt_file = 0;
                            foreach ($data_files as $v_file):
                                $file = getFile($conn, (int)$v_file);
                                if($file):
                                    $files[$stt_file]['ID'] = (int)$v_file;
                                    $files[$stt_file]['NAME'] = $file['ORIGINAL_NAME'];
                                    $files[$stt_file]['SIZE'] = formatSizeUnits($file['FILE_SIZE']);
                                    $files[$stt_file]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file['TIMESTAMP_X']));
                                    $files[$stt_file]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $v_file);
                                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                                    $files[$stt_file]['PATH'] = $link_s3_amazon.$file['SUBDIR'].'/'.$name_file;
                                    $stt_file++;
                                endif;

                            endforeach;
                        endif;

                        // File đính kèm
                        $fileAttachs = [];
                        $count_files_attachs = 0;
                        $store['TOTAL_FILE'] = $count_files;
                        $row_file_attachs = $task['UF_RPA_14_1651052994'];
                        if(isset($row_file_attachs) && $row_file_attachs):
                            $data_file_attachs = unserialize($row_file_attachs);
                            $count_files_attachs = count($data_file_attachs);
                            $stt_file_attach = 0;
                            foreach ($data_file_attachs as $a_file):
                                $file_a = getFile($conn, (int)$a_file);
                                if($file_a):
                                    $fileAttachs[$stt_file_attach]['ID'] = (int)$a_file;
                                    $fileAttachs[$stt_file_attach]['NAME'] = $file_a['ORIGINAL_NAME'];
                                    $fileAttachs[$stt_file_attach]['SIZE'] = formatSizeUnits($file_a['FILE_SIZE']);
                                    $fileAttachs[$stt_file_attach]['CREATED_TIME'] = date('H:i d/m/Y', strtotime($file_a['TIMESTAMP_X']));
                                    $fileAttachs[$stt_file_attach]['CHECK'] = checkFileSignature($conn, $rpa_id, $task_id, $a_file);
                                    $name_file = str_replace(' ', '+', $file_a['FILE_NAME']);
                                    $fileAttachs[$stt_file_attach]['PATH'] = $link_s3_amazon.$file_a['SUBDIR'].'/'.$name_file;
                                    $stt_file_attach++;
                                endif;

                            endforeach;
                        endif;
                        $store['FILEATTACHS'] = $fileAttachs;
                        $store['COUNTFILEATTACHS'] = $count_files_attachs;

                        $stt_user = 0;
                        $sql = "SELECT `ID`, `NAME`, `COLOR`, `SORT` FROM `b_rpa_stage` WHERE `TYPE_ID`=:id_rpa ORDER BY SORT ASC";
                        $query_stage = $conn->prepare($sql);
                        $query_stage->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_stage->execute();
                        $arrIdStage = [];

                        if($query_stage->rowCount()){
                            $stages = $query_stage->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($stages as $sta):
                                array_push( $arrIdStage, $sta['ID']);
                            endforeach;
                        }
                        if($task['CREATED_BY'] == $user_id):
                            array_push($checkStageCurrent, $arrIdStage[0]);
                        endif;
                        //NGười ký
                        if($task['UF_RPA_14_1651054978']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_14_1651654944']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_14_1651054978']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_14_1651054978']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_14_1651054978']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[1]);
                            endif;
                        endif;
                        if($task['UF_RPA_14_1651055111']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_14_1651654944']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_14_1651055111']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_14_1651055111']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_14_1651055111']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[2]);
                            endif;
                        endif;
                        if($task['UF_RPA_14_1651055131']):
                            $number_signed_files = 0;
                            if($task['UF_RPA_14_1651654944']):
                                if(!empty($data_files)):
                                    foreach ($data_files as $v_file):
                                        $checkSig = checkFileSignatureByUser($conn, $rpa_id, $task_id, $v_file, $task['UF_RPA_14_1651055131']);
                                        if($checkSig):
                                            $number_signed_files++;
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            $user_signatures[$stt_user]['FILE_SIGNATURE'] = $number_signed_files;
                            $user_signatures[$stt_user]['INFORMATION'] = getInformationMember($conn, (int)$task['UF_RPA_55_1651130837']);
                            $stt_user++;
                            if($user_id == $task['UF_RPA_14_1651055131']):
                                $checkCreatedByAndUserSig = true;
                                array_push($checkStageCurrent, $arrIdStage[3]);
                            endif;
                        endif;

                        if($stage_current):
                            $dataTimeLine = [];
                            $arrStageFrom = [];
                            $arrStageTo = [];
                            if($store['PREVIOUS_STAGE']):
                                $arrStageFrom = [
                                    'id' => $store['PREVIOUS_STAGE']['ID'],
                                    'name' => $store['PREVIOUS_STAGE']['NAME'],
                                ];
                            endif;
                            if($store['STAGE']):
                                $arrStageTo = [
                                    'id' => $store['STAGE']['ID'],
                                    'name' => $store['STAGE']['NAME'],
                                ];
                            endif;
                            $stageFrom = (object)$arrStageFrom;
                            $stageTo = (object)$arrStageTo;
                            $arrItem = (object)['name'=> $rpa['TITLE']];
                            $dataTimeLine['item'] = $arrItem;
                            $dataTimeLine['scope'] ="manual";
                            $dataTimeLine['stageFrom'] =  $stageFrom;
                            $dataTimeLine['stageTo'] =  $stageTo;
                            $date_time_line = [ $rpa_id, $task_id, $date_current, $user_id, '', '', 'stage_change', 'N', json_encode($dataTimeLine, JSON_UNESCAPED_UNICODE)];

                            $sql = "INSERT INTO b_rpa_timeline (`TYPE_ID`, `ITEM_ID`,`CREATED_TIME`, `USER_ID`, `TITLE`, `DESCRIPTION`, `ACTION`, `IS_FIXED`, `DATA`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $conn->prepare($sql)->execute($date_time_line);
                        endif;
                        //Lấy thay đổi trạng thái
                        $sql_timeline = "SELECT * FROM `b_rpa_timeline` WHERE `TYPE_ID`=:id_rpa AND `ITEM_ID`=:item_id";
                        $query_timeline = $conn->prepare($sql_timeline);
                        $query_timeline->bindValue(':id_rpa', $rpa_id, PDO::PARAM_STR);
                        $query_timeline->bindValue(':item_id', $task_id, PDO::PARAM_STR);
                        $query_timeline->execute();
                        $stageOfChanges = [];
                        if($query_timeline->rowCount()){
                            $query_stages = $query_timeline->fetchAll(PDO::FETCH_ASSOC);
                            $stt_stage = 0;
                            foreach ($query_stages as $val):
                                $s_tage = json_decode($val['DATA'], true);
                                $stageOfChanges[$stt_stage] = isset($s_tage['stageTo']['id']) ? getStage($conn, $s_tage['stageTo']['id']) : '';
                                $stt_stage++;
                            endforeach;
                        }
                        $store['stageOfChanges'] = $stageOfChanges;
                        $store['FILES'] = $files;
                        $store['USER_SIGS'] = $user_signatures;
                        $store['checkCreatedByAndUserSig'] = $checkCreatedByAndUserSig;
                    }
                    break;
            }
        }
        $returnData = [
            "success" => 1,
            "status" => 2000,
            "message" => "success",
            "data"   => $store,
            "stages" => $stages,
            "checkStageCurrent" => $checkStageCurrent,
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