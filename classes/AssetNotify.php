<?
/** var CMain $APPLICATION */

use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

IncludeModuleLangFile(__FILE__);

class AssetNotify
{
    /**
     * @param $userSend
     * @param $userReceiver
     * @param $messages
     * @param $data
     * @return bool
     */
    public function sendNotify($userSend, $userReceiver, $messages, $data)
    {

        $arMess = array(
            "TO_USER_ID" => $userReceiver,
            "FROM_USER_ID" => $userSend,
            "NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
            "MESSAGE" => $messages,
            "MESSAGE_OUT" => "",
            "ATTACH" => Array($data)
        );
        try {
            if (!(ModuleManager::isModuleInstalled('im') && Loader::includeModule('im'))) {
                return false;
            }
            $arMess['NOTIFY_MODULE'] = 'hpl.request';
            switch ($arMess['NOTIFY_TYPE']) {
                case 'IM_NOTIFY_FROM':
                    $arMess['NOTIFY_TYPE'] = IM_NOTIFY_FROM;
                    break;
                case 'IM_NOTIFY_CONFIRM':
                    $arMess['NOTIFY_TYPE'] = IM_NOTIFY_CONFIRM;
                    break;
                default:
                    $arMess['NOTIFY_TYPE'] = IM_NOTIFY_FROM;
            };
            return (bool)\CIMNotify::add($arMess);
        } catch (LoaderException $e) {
        }
    }
}

?>
