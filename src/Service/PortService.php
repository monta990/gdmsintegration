<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PortService
{
    public static function status(Request $request): JsonResponse
    {
        if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) return new JsonResponse(['error'=>'Access denied'],403);
        $entities_id=AccessService::entityId($request); AccessService::requireEntityAccess($entities_id);
        if ((string)$request->query->get('action','status') !== 'status') return new JsonResponse(['error'=>'Unknown action']);
        $config=(new \GlpiPlugin\Gdmsintegration\Config())->getConfigByEntity($entities_id); if(empty($config['gwn_client_id'])) return new JsonResponse([]);
        $all=(new \GlpiPlugin\Gdmsintegration\Device())->find(); $result=[];
        foreach($all as $row){
            $mac=strtolower(trim($row['mac']??'')); $network_id=(int)($row['network_id']??0); if(!$mac||!$network_id||($row['status']??'')!=='online') continue;
            $stored=$row['wan_ports_json']??''; if(!empty($stored)){ $result[$mac]=json_decode($stored,true)??[]; continue; }
            $apiMac=strtoupper(str_replace(':','',$mac)); $model=$row['model']??''; $is_switch=preg_match('/^GWN78|^GSS/i',$model);
            if($is_switch){
                $raw=\GlpiPlugin\Gdmsintegration\API::gwnGetSwitchPortInfo($config,$apiMac,$network_id); if(empty($raw)) continue; $ports=[];
                foreach($raw as $port){$ports[]=['id'=>$port['portId']??$port['silkScreenPort']??'','name'=>$port['portName']??'','silk'=>$port['silkScreenPort']??'','role'=>0,'link'=>(int)($port['linkStatus']??0),'speed'=>(int)($port['portSpeed']??0),'type'=>($port['type']??0)==1?'SFP':'GE','customName'=>$port['portCustomName']??'','desc'=>$port['portDesc']??'','txBytes'=>(int)($port['aggregate']['txBytes']??0),'rxBytes'=>(int)($port['aggregate']['rxBytes']??0),'vlan'=>(int)($port['vlan']??0)];}
                usort($ports,static fn($a,$b)=>strcmp($a['silk']?:$a['id'],$b['silk']?:$b['id'])); $result[$mac]=$ports; continue;
            }
            $port_data=\GlpiPlugin\Gdmsintegration\API::gwnGetRouterPortInfo($config,$apiMac,$network_id); if(empty($port_data['portInfo'])) continue; $ports=[];
            foreach($port_data['portInfo'] as $port){$role=(int)($port['role']??0);$ipv4=$port['ipv4Info']??[];$ports[]=['id'=>$port['portId']??$port['silkScreenPort']??'','name'=>$port['portName']??'','silk'=>$port['silkScreenPort']??'','silkNum'=>$port['silkNum']??'','role'=>$role,'link'=>(int)($port['linkStatus']??0),'speed'=>(int)($port['portSpeed']??0),'type'=>($port['type']??0)==1?'SFP':'GE','desc'=>$port['portDesc']??'','customName'=>$port['portCustomName']??'','wanName'=>$port['wanName']??'','connectDuration'=>(int)($port['connectDuration']??0),'ip'=>$ipv4['ip4Address']??'','connectStatus'=>isset($ipv4['connectStatus'])?(int)$ipv4['connectStatus']:-1,'wanType'=>isset($ipv4['type'])?(int)$ipv4['type']:-1,'gateway'=>$ipv4['gateway']??'','txBytes'=>(int)($port['aggregate']['txBytes']??0),'rxBytes'=>(int)($port['aggregate']['rxBytes']??0)];}
            usort($ports,static fn($a,$b)=>(($b['role']-$a['role'])?:strcmp($a['silk']?:$a['id'],$b['silk']?:$b['id']))); $result[$mac]=$ports;
        }
        return new JsonResponse($result);
    }
}
