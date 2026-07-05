<?php
define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);
require 'services/gateway/server.php';

function buildServerHeaders($requestHeaders){
  foreach(array_keys($_SERVER) as $key){
    if(strpos($key,'HTTP_')===0) unset($_SERVER[$key]);
  }
  foreach($requestHeaders as $name=>$value){
    $serverKey = 'HTTP_'.str_replace('-','_',strtoupper($name));
    $_SERVER[$serverKey] = $value;
  }
}

function dispatchGatewayRequest($request){
  foreach(array('REQUEST_METHOD','REQUEST_URI','QUERY_STRING','REMOTE_ADDR','GDWB_RAW_REQUEST_BODY') as $key) {
    unset($_SERVER[$key]);
  }
  buildServerHeaders(isset($request['headers'])?$request['headers']:array());
  $_SERVER['REQUEST_METHOD']=$request['method'];
  $_SERVER['REQUEST_URI']=$request['uri'];
  $_SERVER['QUERY_STRING']=isset($request['query'])?$request['query']:'';
  $_SERVER['REMOTE_ADDR']=isset($request['remote_addr'])?$request['remote_addr']:'127.0.0.1';
  $_SERVER['GDWB_RAW_REQUEST_BODY']=isset($request['body'])?$request['body']:'';
  
  error_log('dispatchGatewayRequest: ob_level='.(int)ob_get_level().' before loop');
  while(ob_get_level()>0) {
    error_log('dispatchGatewayRequest: calling ob_end_clean at level '.(int)ob_get_level());
    ob_end_clean();
  }
  error_log('dispatchGatewayRequest: ob_level='.(int)ob_get_level().' after cleanup, about to call runGatewayServer');
  
  try {
    error_log('dispatchGatewayRequest: calling runGatewayServer');
    $result = runGatewayServer();
    error_log('dispatchGatewayRequest: runGatewayServer returned '.gettype($result));
    return $result;
  } catch (ServiceHelpersTestResponseException $ex) {
    error_log('dispatchGatewayRequest: caught ServiceHelpersTestResponseException');
    return $ex->response;
  } catch (Throwable $ex) {
    error_log('dispatchGatewayRequest: caught '.get_class($ex).': '.$ex->getMessage());
    throw $ex;
  }
}

setGatewayProxyHandler(function($targetUrl,$method,$headers,$body=null){
  return array('status'=>200,'headers'=>array('Content-Type: application/json'),'body'=>"{}\n");
});

$scenarios=array(
  array('name'=>'valid','headers'=>array('X-API-Key'=>'valid-key-1','X-Tenant-Id'=>'tenant-allowed')),
  array('name'=>'tenant','headers'=>array('X-API-Key'=>'valid-key-1','X-Tenant-Id'=>'other-tenant'))
);

foreach($scenarios as $sc){
  error_log('===== Starting scenario: '.$sc['name']);
  try{
    $res=dispatchGatewayRequest(array('method'=>'POST','uri'=>'/api/v1/assistant/sessions/test-session/message','headers'=>$sc['headers'],'body'=>'{}'));
    error_log('===== Scenario '.$sc['name'].' result: '.gettype($res).' status='.(isset($res['status'])?$res['status']:'?'));
    echo "Scenario {$sc['name']}: ".json_encode(array('status'=>$res['status'],'headers_count'=>count($res['headers'])))."\n";
  }catch(Throwable $e){
    error_log('===== Scenario '.$sc['name'].' exception: '.get_class($e).': '.$e->getMessage());
    echo "Scenario {$sc['name']}: EXCEPTION ".get_class($e)."\n";
  }
}
echo "Done\n";
