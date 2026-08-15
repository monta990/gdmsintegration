<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ClientService
{
    public static function list(Request $request): JsonResponse
    {
        if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) return new JsonResponse(['error'=>'Access denied'],403);
        $entities_id=AccessService::entityId($request); AccessService::requireEntityAccess($entities_id); $network_id=(int)$request->query->get('network_id',0); $mac=trim((string)$request->query->get('mac',''));
        if(!$network_id) return new JsonResponse(['error'=>'Missing network_id']);
        $config=(new \GlpiPlugin\Gdmsintegration\Config())->getConfigByEntity($entities_id); if(empty($config['gwn_client_id'])) return new JsonResponse([]);
        $ap_mac=$mac!==''?strtoupper(str_replace(':','',$mac)):''; $complete=false; $raw=\GlpiPlugin\Gdmsintegration\API::gwnGetClientList($config,$network_id,$ap_mac,200,$complete);
        $assetByMac=[]; foreach(['Computer','NetworkEquipment','Phone','Peripheral','Printer'] as $it){$o=new $it();foreach($o->find(['entities_id'=>$entities_id]) as $a){$am=strtolower(trim((string)($a['uuid']??'')));if($am!=='')$assetByMac[str_replace([':','-'],'',$am)]=['itemtype'=>$it,'id'=>(int)$a['id'],'name'=>(string)($a['name']??'')];}}
        $clients=[];$historyRows=[];
        foreach($raw as $c){$cmac=strtolower((string)($c['mac']??$c['clientMac']??''));$asset=$assetByMac[str_replace([':','-'],'',$cmac)]??null;$clients[]=['mac'=>$cmac,'hostname'=>$c['hostname']??$c['clientName']??'','ip'=>$c['ip']??$c['ipAddress']??'','rssi'=>(int)($c['rssi']??$c['signal']??0),'band'=>$c['band']??$c['frequency']??'','ssid'=>$c['ssid']??$c['wifiName']??'','apMac'=>strtolower($c['apMac']??$c['connectedApMac']??''),'txRate'=>(int)($c['txRate']??$c['uploadRate']??0),'rxRate'=>(int)($c['rxRate']??$c['downloadRate']??0),'connectTime'=>(int)($c['connectTime']??$c['onlineTime']??0),'glpi_itemtype'=>$asset['itemtype']??'','glpi_id'=>$asset['id']??0,'glpi_name'=>$asset['name']??''];if($cmac!=='')$historyRows[]=['entities_id'=>$entities_id,'client_mac'=>$cmac,'ap_mac'=>strtolower((string)($c['apMac']??$c['connectedApMac']??'')),'network_id'=>$network_id,'hostname'=>(string)($c['hostname']??$c['clientName']??''),'ip'=>(string)($c['ip']??$c['ipAddress']??''),'ssid'=>(string)($c['ssid']??$c['wifiName']??''),'rssi'=>(int)($c['rssi']??$c['signal']??0),'seen_at'=>gmdate('Y-m-d H:i:s')];}
        if($historyRows){global $DB;foreach($historyRows as $hr){try{$it=$DB->request(['FROM'=>'glpi_plugin_gdmsintegration_client_history','WHERE'=>['entities_id'=>$entities_id,'client_mac'=>$hr['client_mac']],'ORDER'=>['seen_at DESC'],'LIMIT'=>1]);$last=null;foreach($it as $r){$last=$r;break;}if($last&&strtolower((string)$last['ap_mac'])===strtolower((string)$hr['ap_mac'])&&(int)$last['network_id']===(int)$hr['network_id']&&(string)$last['ssid']===(string)$hr['ssid'])continue;$DB->insert('glpi_plugin_gdmsintegration_client_history',$hr);}catch(\Throwable $e){\GlpiPlugin\Gdmsintegration\Utils::debug('Client history write skipped: '.$e->getMessage());}}}
        return new JsonResponse($clients,200,['X-GDMS-Result-Complete'=>$complete?'1':'0']);
    }
}
