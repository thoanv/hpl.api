<?php
/** Following line for just debugging the errors if any,
 * but you can omit it out */
require_once($_SERVER['DOCUMENT_ROOT'].'/local/components/hpl.procedure/vendor/autoload.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/local/modules/hpl.procedure/lib/helper/OAuth2Config.php');
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Hpl\Producare\Helper\Helper;
use Hpl\Procedure\Entity\HplProcedureAccountsTable;
use Hpl\Procedure\Entity\BRpaItemsDpjcodapovTable;
use Hpl\Procedure\Entity\BRpaTypeTable;
use Hpl\Procedure\Entity\BFileTable;
use Hpl\Procedure\Entity\HplProcedureHistoriesTable;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class ItemSignatureComponents extends CBitrixComponent
{
    const FORM_ID = 'DOCUMENT_DETAIL';
    private $errors, $user;

    public function __construct(CBitrixComponent $component = null)
    {
        global $USER;
        $this->user = $USER->GetID();
        parent::__construct($component);
        $this->errors = new ErrorCollection();
        if(!Loader::includeModule('hpl.procedure')){
            ShowError('Not found module');
            return;
        }
    }
    public function executeComponent()
    {
        global $APPLICATION;
        $config = new OAuth2Config();
        $APPLICATION->SetTitle('Chi tiết');
        $type_id = $this->arParams['ID'];
        $rpa_type = BRpaTypeTable::getById($type_id)->fetch();
        if (empty($rpa_type)) {
            ShowError('Page not found');
            return;
        }
        $stage_id = $this->arParams['STAGE_ID'];
        $store = [];
        $file = [];
        $signer_users = [];
        $array_user_sigs = [];
        switch ($rpa_type['TABLE_NAME']) {
            case 'b_rpa_items_dpjcodapov':
                $store = BRpaItemsDpjcodapovTable::getById($stage_id)->fetch();
                $APPLICATION->SetTitle($store['UF_RPA_43_1641867502']);
                if (empty($rpa_type)) {
                    ShowError('Page not found');
                    return;
                }
                $store['TITLE'] = $store['UF_RPA_43_1641866991'];
                $store['NOTE'] = $store['UF_RPA_43_1641867502'];
                $store['CREATED_USER'] = Helper::getMember($store['CREATED_BY']);
                $file_id = $this->arParams['FILE_ID'];
                if (!$store['UF_RPA_43_1641866971']) {
                    ShowError('Page not found');
                    return;
                }
                $files = unserialize($store['UF_RPA_43_1641866971']);
                if (empty($files)) {
                    ShowError('Page not found');
                    return;
                }
                $checkExistFileId = Helper::checkExistFileId($files, $file_id);
                if(!$checkExistFileId){
                    ShowError('Page not found');
                    return;
                }
                $file = BFileTable::getById($file_id)->fetch();
                if($store['UF_RPA_43_1642473322']) {
                    $signer_users[] = Helper::getMember($store['UF_RPA_43_1642473322']);
                    $array_user_sigs[] = $store['UF_RPA_43_1642473322'];
                }
                if($store['UF_RPA_43_1642473344']) {
                    $signer_users[] = Helper::getMember($store['UF_RPA_43_1642473344']);
                    $array_user_sigs[] = $store['UF_RPA_43_1642473344'];
                }
                if($store['UF_RPA_43_1642473354']) {
                    $signer_users[] = Helper::getMember($store['UF_RPA_43_1642473354']);
                    $array_user_sigs[] = $store['UF_RPA_43_1642473354'];
                }
                break;
            case 'b_rpa_items_keshervtxj':

                break;
        }

        if (empty($store)) {
            ShowError(Loc::getMessage('NOT_FOUND'));
            return;
        }
        $signer_account = HplProcedureAccountsTable::getList([
            'filter' => ['user_id' => $this->user]
        ])->fetch();

        $location_all_sigs = [];
        $location_sigs=[];
        if($this->user === $store['CREATED_BY']){
            $location_all_sigs = HplProcedureHistoriesTable::getList([
                'filter' => [
                    'rpa_type_id' => $rpa_type['ID'],
                    'rpa_stage_id'=> $store['ID'],
                    'file_id'     => $file['ID'],
                    'created_by'  => $this->user,
                    '!=location_sig' => NULL
                ]
            ])->fetchAll();
            foreach ($location_all_sigs as $k_sig => $v_sig){
                $u_sig = Helper::getMember($v_sig['user_sig']);
                $location_all_sigs[$k_sig]['name_sig'] = $u_sig['NAME'];
            }
        }else{
            $location_sigs = HplProcedureHistoriesTable::getList([
                'filter' => [
                    'rpa_type_id' => $rpa_type['ID'],
                    'rpa_stage_id'=> $store['ID'],
                    'file_id'     => $file['ID'],
                    'user_sig'    => $this->user
                ]
            ])->fetch();
        }
        $errors = new ErrorCollection();
        if (self::isFormSubmitted()) {
            $context = Context::getCurrent();
            $request = $context->getRequest();
            $data = $request->getValues();
            $date_current = new \Bitrix\Main\Type\DateTime();
            if(isset($data['access_token'])){
                $list_signature = [];
                $list_but_phe = [];
                $k_ = 0;
                foreach ($data['sig_page'] as $key => $val){

                    foreach ($val as $k => $v){
                        $w = $v['x'] + $v['w'];
                        $h = $v['y'] + $v['h'];

                        $list_signature[$k_]['page'] = $key;
                        $list_signature[$k_]['rectangle'] =  "".round($v['x']+20).",".round($v['y']+20).",".round($w).",".round($h)."";
                        $k_++;
                    }
                }
                $q_uer = HplProcedureHistoriesTable::getList([
                    'filter' => [
                        'rpa_type_id' => $rpa_type['ID'],
                        'rpa_stage_id'=> $store['ID'],
                        'file_id'     => $file['ID'],
                        'user_sig'    => $this->user,
                        '!=location_sig' => NULL
                    ]
                ])->fetch();
                if($q_uer && $q_uer['location_sig']){
                    $list_location_sigs = json_decode($q_uer['location_sig'], true);
                    $height_cavan = $data['height_cavan'];
                    $width_cavan = $data['width_cavan'];
                    $scale = $data['scale'];
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
                            $list_signature[$k_]['rectangle'] =  "".round($x_pdf).",".round($y_pdf).",".round($w_pdf).",".round($h_pdf)."";
                            $k_++;
                        }
                    }
                }
//				echo '<pre>'; print_r($list_signature);die;
                $img_signatre = base64_encode(file_get_contents($_SERVER['DOCUMENT_ROOT'].$signer_account['img_signature']));

                foreach ($data['but_page'] as $k_b => $val_b){
                    $k__ = 0;
                    foreach ($val_b as $k_ => $v_){
                        $w = $v_['x'] + $v_['w'];
                        $h = $v_['y'] + $v_['h'];
                        $list_but_phe[$k__]['fontColor'] = "#0000FF";
                        $list_but_phe[$k__]['fontName'] = "Time";
                        $list_but_phe[$k__]['fontSize'] = 14;
                        $list_but_phe[$k__]['fontStyle'] = 2;
                        $list_but_phe[$k__]['page'] = $k_b;
                        $list_but_phe[$k__]['rectangle'] =  "".round($v_['x']).",".round($v_['y']).",".round($w).",".round($h)."";
                        $list_but_phe[$k__]['text'] =  $v_['text'];
                        $list_but_phe[$k__]['type'] =  2;
                        $k__++;
                    }
                }

                $payload = [
                    'client_id' => $config->client_id,
                    'client_secret' => $config->client_secret,
                    'username' => $signer_account['login'],
                    'password' => $signer_account['password'],
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
                    echo ('ERROR : '.$response->error_description);
                    exit();
                }else{
                    $_SESSION['access_token_vnpt'] = $response->access_token;
                    $_SESSION['refresh_token_vnpt'] = $response->refresh_token;

                    $data_getCredentical = '{}';
                    $msg = Helper::api_get_credentical_curl();

                    $credentials = $msg->content[0];
                    //3 get certBase64
                    $msg = Helper::api_get_certBase64([
                        "credentialId" => $credentials,
                        "certificates" => "chain",
                        "certInfo" => true,
                        "authInfo" => true
                    ]);

                    $certBase64 = $msg->cert->certificates[0];
                    $certBase64 = str_replace("\r\n","",$certBase64);
                    $link_s3_amazom = 'https://haiphatland-bitrix24.s3.ap-southeast-1.amazonaws.com/';
                    $name_file = str_replace(' ', '+', $file['FILE_NAME']);
                    $unsignDataBase64 = chunk_split(base64_encode(Helper::curl_get_contents($link_s3_amazom.$file['SUBDIR'].'/'.$name_file)));

                    $options = [
                        "fontColor" => "3333ff",
                        "fontName" => "Roboto ", // 3 option : Time/Roboto/Arial
                        "fontSize" => 8,
                        "fontStyle"=> 0, //0:Normal,1:Bold,2:Italic,3:Bold&Italic,4:Underline
                        "imageSrc" => $img_signatre,
                        "visibleType" => (int)$data['visibleType'], //1:TextOnly, 2:TEXT_WITH_LOGO_LEFT, 3:LOGO_ONLY, 4:TEXT_WITH_LOGO_TOP, 5:TEXT_WITH_BACKGROUND
                        "comment" => $list_but_phe,
                        "signatures" => $list_signature
                    ];
//                    echo '<pre>'; print_r($options);die;

                    $data_sign = [
                        'credentialId' => $credentials,
                        'refTranId' => Helper::getGUID(),
                        'description' => 'Test php signer',
                        'datas' => [
                            [
                                "name" => $store['name'].".pdf",
                                "dataBase64" => $unsignDataBase64,
                                "options" => json_encode($options),
                            ]
                        ]
                    ];
                    if(empty($list_signature)){
                        $errors->setError(new Error('Chữ ký trống'));
                    }else{
                        $msg = Helper::api_sign_curl($data_sign);
                        $tranId = isset($msg->content->tranId) ? $msg->content->tranId : "";

                        if($tranId != ""){
                            $q_history = HplProcedureHistoriesTable::getList([
                                'filter' => [
                                    'rpa_type_id' => $rpa_type['ID'],
                                    'rpa_stage_id'=> $store['ID'],
                                    'file_id'     => $file['ID'],
                                    'user_sig'    => $this->user
                                ]
                            ])->fetch();
                            if($q_history){
                                $history['tran_id']       = $tranId;
                                $history['status']        = 2;
                                $result = HplProcedureHistoriesTable::update($q_history['id'], $history);
                                if($result->isSuccess()){
                                    $errors->setError(new Error('Gửi xác nhận ký thành công vui lòng vào App để xác nhận'));
                                }
                            }else{
                                $history['rpa_type_id']   = $rpa_type['ID'];
                                $history['rpa_stage_id']  = $store['ID'];
                                $history['file_id']       = $file['ID'];
                                $history['tran_id']       = $tranId;
                                $history['status']        = 2;
                                $history['created_at']    = $date_current;
                                $history['created_by']    = $store['CREATED_BY'];
                                $history['user_sig']      = $this->user;
                                $result = HplProcedureHistoriesTable::add($history);
                                if($result->isSuccess()){
                                    $errors->setError(new Error('Gửi xác nhận ký thành công vui lòng vào App để xác nhận'));
                                }
                            }

                        }else{
                            echo("Ký số thất bại");
                            exit();
                        }
                    }
                }
            }
            if(isset($data['complete'])){
                $q_history = HplProcedureHistoriesTable::getList([
                    'filter' => [
                        'rpa_type_id' => $rpa_type['ID'],
                        'rpa_stage_id'=> $store['ID'],
                        'file_id'     => $file['ID'],
                        'user_sig'    => $this->user,
                    ]
                ])->fetch();
                if($q_history){
                    $data_getTranInfo = [
                        "tranId" => $q_history['tran_id']
                    ];

                    $msg = Helper::api_get_tranInfo_curl($data_getTranInfo);
                    $status = $msg->content->tranStatus;
                    if($status != 1){
                        $history['status'] = 3;// Lỗi xác thực
                        $history['tran_id'] = NULL;
                        $result = HplProcedureHistoriesTable::update($q_history['id'], $history);
                        if($result->isSuccess()){
                            $errors->setError(new Error('Bạn chưa thực hiện đầy đủ các thao tác trên App. Mời thực hiện lại'));
                        }
                    }else{

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
                        $path_aws = 'hplSign/'.$this->generateRandomString(5);
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
                            $b_file['SUBDIR'] = $path_aws;

                            BFileTable::update($file['ID'], $b_file);
                            CFile::CleanCache($file['ID']);

                        } catch (S3Exception $e) {
                            echo $e->getMessage() . "\n";
                        }
                        $history['status'] = 1;// Thành công
                        $history['tran_id'] = NULL;
                        $history['location_sig'] = NULL;
                        $result = HplProcedureHistoriesTable::update($q_history['id'], $history);
                        if($result->isSuccess()){
                            LocalRedirect($this->getRedirectUrl($result->getId()));
                        }
                    }
                }else{
                    $errors->setError(new Error('Đã xuất hiện lỗi. Vui lòng thao tác lại'));
                }
            }
            if(isset($data['location'])){
                $location_signature = [];
                foreach ($data['sig_page_location'] as $ke => $va){
                    foreach ($va as $k1 => $v1){
                        if($location_signature['u']){
                            if($location_signature[$v1['u']][$ke]){
                                $location_signature[$v1['u']][$ke][$k1]['x'] = $v1['x']/$v1['w_c']*100;
                                $location_signature[$v1['u']][$ke][$k1]['y'] = $v1['y']/$v1['h_c']*100;
                                $location_signature[$v1['u']][$ke][$k1]['w'] = $v1['w'];
                                $location_signature[$v1['u']][$ke][$k1]['h'] = $v1['h'];
                                $location_signature[$v1['u']][$ke][$k1]['t'] = $v1['t'];
                                $location_signature[$v1['u']][$ke][$k1]['page'] = $v1['page'];
                            }else{
                                $location_signature[$v1['u']][$ke][$k1]['x'] = $v1['x']/$v1['w_c']*100;
                                $location_signature[$v1['u']][$ke][$k1]['y'] = $v1['y']/$v1['h_c']*100;
                                $location_signature[$v1['u']][$ke][$k1]['w'] = $v1['w'];
                                $location_signature[$v1['u']][$ke][$k1]['h'] = $v1['h'];
                                $location_signature[$v1['u']][$ke][$k1]['page'] = $v1['page'];
                            }

                        }else{
                            $location_signature[$v1['u']][$ke][$k1]['x'] = $v1['x']/$v1['w_c']*100;
                            $location_signature[$v1['u']][$ke][$k1]['y'] = $v1['y']/$v1['h_c']*100;
                            $location_signature[$v1['u']][$ke][$k1]['w'] = $v1['w'];
                            $location_signature[$v1['u']][$ke][$k1]['h'] = $v1['h'];
                            $location_signature[$v1['u']][$ke][$k1]['t'] = $v1['t'];
                            $location_signature[$v1['u']][$ke][$k1]['page'] = $v1['page'];
                        }
                    }
                }
                foreach ($location_signature as $k_si => $v_si){

                    $q_u = HplProcedureHistoriesTable::getList([
                        'filter' => [
                            'rpa_type_id' => $rpa_type['ID'],
                            'rpa_stage_id'=> $store['ID'],
                            'file_id'     => $file['ID'],
                            'user_sig'    => $k_si,
                        ]
                    ])->fetch();
                    if(!$q_u){
                        $history['rpa_type_id']   = $rpa_type['ID'];
                        $history['rpa_stage_id']  = $store['ID'];
                        $history['file_id']       = $file['ID'];
                        $history['user_sig']      = $k_si;
                        $history['location_sig']  = json_encode($v_si);
                        $history['created_at']    = $date_current;
                        $history['created_by']    = $this->user;
                        $result = HplProcedureHistoriesTable::add($history);
                    }else{
                        $list_sig_users = json_decode($q_u['location_sig'], true);
                        $page_key = array_keys($v_si);
                        if($page_key)
                            $list_sig_users[$page_key[0]] = $v_si[$page_key[0]];
                        ksort($list_sig_users);
                        $history['location_sig']  = json_encode($list_sig_users);
                        $history['created_at']    = $date_current;
                        $result = HplProcedureHistoriesTable::update($q_u['id'], $history);
                    }
                }
                if(isset($result) && $result->isSuccess()){
                    $errors->setError(new Error('Định vị chữ ký thành công'));
                }
            }
        }
        $this->errors = $errors;
        $this->arResult = array(
            'FORM_ID'       => self::FORM_ID,
            'STORE'         => $store,
            'RPA_TYPE'      => $rpa_type,
            'SIGNER_ACCOUNT'=> $signer_account,
            'SIGNER_USERS'  => $signer_users,
            'FILE'          => $file,
            'ERRORS'        => $this->errors,
            'USER_LOGIN'    => $this->user,
            'LOCATION_SIGS' => $location_sigs,
            'LOCATION_ALL_SIGS' => $location_all_sigs,
            'ARRAY_USER_SIGS'=> $array_user_sigs
        );

        $this->includeComponentTemplate();
    }
    private function processSave($initialStore)
    {
        $submittedStore = $this->getSubmittedStore();

        $store = array_merge($initialStore, $submittedStore);
        $this->errors = self::validate($store);
        if (!$this->errors->isEmpty()) {
            return false;
        }
        $date = new \Bitrix\Main\Type\DateTime();
        $user = $this->user;
        $data['category_id'] = $store['category_id'];
        $data['classify_id'] = $store['classify_id'];
        $data['note'] = $store['note'];
        $data['updated_at'] = $date;
        $data['updated_by'] = $user;
        $data['money'] = str_replace(',', '', $store['money']);
        $store['time_only'] = $this->convertDate($store['time_only']);
        $data['time_only'] = new Type\Date($store['time_only'], 'Y-m-d');

        if (!empty($store['id'])) {
            $result = HplCostItemsTable::update($store['id'], $data);
            if (!$result->isSuccess()) {
                $this->errors->add($result->getErrors());
            }
        }

        return $result->isSuccess() ? $result->getId() : false;
    }

    private function getSubmittedStore()
    {
        $context = Context::getCurrent();
        $request = $context->getRequest();

        $submittedStore = array(
            'category_id'   => $request->get('category_id'),
            'time_only'     => $request->get('time_only'),
            'money'         => $request->get('money'),
            'classify_id'   => $request->get('classify_id'),
            'note'          => $request->get('note'),
        );
        return $submittedStore;
    }

    private static function validate($store)
    {
        $errors = new ErrorCollection();
        if (empty($store['category_id'])) {
            $errors->setError(new Error('Tên danh mục không được để trống'));
        }
        if (empty($store['time_only'])) {
            $errors->setError(new Error('Ngày áp dụng không được để trống'));
        }
        if (empty($store['money'])) {
            $errors->setError(new Error('Số tiền không được để trống'));
        }
        if (empty($store['classify_id'])) {
            $errors->setError(new Error('Phân loại không được để trống'));
        }
        return $errors;
    }

    private static function isFormSubmitted()
    {
        $context = Context::getCurrent();
        $request = $context->getRequest();
        $accessToken = $request->get('access_token');
        $saveAndAdd = $request->get('complete');
        $saveLocation = $request->get('location');
        $apply = $request->get('apply');
        return !empty($accessToken) || !empty($saveAndAdd) || !empty($apply) || !empty($saveLocation);
    }

    private function getRedirectUrl($savedStoreId = null)
    {
        $context = Context::getCurrent();
        $request = $context->getRequest();

        if (!empty($savedStoreId) && $request->offsetExists('apply')) {
            return CComponentEngine::makePathFromTemplate(
                $this->arParams['URL_TEMPLATES']['EDIT'],
                array('CLASSIFY_ID' => $savedStoreId)
            );
        } elseif (!empty($savedStoreId) && $request->offsetExists('saveAndAdd')) {
            return CComponentEngine::makePathFromTemplate(
                $this->arParams['URL_TEMPLATES']['EDIT'],
                array('CLASSIFY_ID' => 0)
            );
        }

        $backUrl = $request->get('backurl');
        if (!empty($backUrl)) {
            return $backUrl;
        }

        if (!empty($savedStoreId) && $request->offsetExists('complete')) {
            if(isset($_GET['IFRAME']) && $_GET['IFRAME']==='Y'){
                echo '<script>parent.BX.SidePanel.Instance.close();
                    </script>';
                die;

            }else{
                return CComponentEngine::makePathFromTemplate(
                    $this->arParams['URL_TEMPLATES']
                );
            }
        } else {
            return $this->arParams['SEF_FOLDER'];
        }
    }
    private function generateRandomString($length = 5) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}

