<?php
/** Standalone REST harness. WP dispatcher/authentication semantics are SIMULATED, not a WordPress boot. */
declare(strict_types=1);
if (!function_exists('__')) {
 function __(string $text,string $domain):string{return $text;}
}
function is_user_logged_in():bool{return ($GLOBALS['reset_user']??7)>0;}
function get_current_user_id():int{return $GLOBALS['reset_user']??7;}
function current_user_can(string $cap):bool{return $cap==='manage_options'&&($GLOBALS['reset_capable']??true)&&is_user_logged_in();}
function register_rest_route(string $ns,string $route,array $args):void{$GLOBALS['reset_routes'][$route]=[$ns,$args];}
class WP_Error {public function __construct(public string $code,public string $message='',public array $data=[]){} }
class WP_REST_Request {
 public function __construct(private array $url=[],private mixed $json=null,private array $query=[],private ?string $raw=null,private string $type='application/json',private array $headers=[]){}
 public function get_body_params():array{return [];}
 public function get_file_params():array{return [];}
 public function get_url_params():array{return $this->url;}
 public function get_query_params():array{return $this->query;}
 public function get_json_params():mixed{return $this->raw===null?$this->json:json_decode($this->raw,true);}
 public function get_body():string{return $this->raw??($this->json===null?'':json_encode($this->json,JSON_THROW_ON_ERROR));}
 public function get_header(string $key):?string{return $this->headers[$key]??null;}
 public function is_json_content_type():bool{return $this->type==='application/json';}
}
class WP_REST_Response {
 public array $headers=[];
 public function __construct(private mixed $data,private int $status){}
 public function get_data():mixed{return $this->data;}
 public function get_status():int{return $this->status;}
 public function header(string $key,string $value):void{$this->headers[$key]=$value;}
}
function rest_request(string $action,string $identity,string $epoch='',string $revision='0',array $changes=[]):WP_REST_Request {
 $body=$action==='status'?null:array_merge(['expected_epoch_identity'=>$epoch,'confirmation'=>'FULL COMMERCE RESET'.($action==='resume'?' '.$identity:'')],$action==='start'?['operation_identity'=>$identity]:['revision'=>$revision],$changes);
 return new WP_REST_Request($action==='start'?[]:['operation_identity'=>$identity],$body);
}
/** Simulates core cookie behavior: no nonce sets user 0, invalid nonce rejects before permissions;
 * an already authenticated alternate context bypasses cookie checks, never capability checks.
 */
function rest_dispatch(object $controller,string $action,WP_REST_Request $request,string $auth='valid'):WP_REST_Response|WP_Error {
 $original=$GLOBALS['reset_user']??7;
 if(in_array($auth,['invalid','expired','malformed'],true))return new WP_Error('rest_cookie_invalid_nonce','Cookie check failed',['status'=>403]);
 if($auth==='missing')$GLOBALS['reset_user']=0;
 try{$allowed=$controller->authorize();return $allowed===true?$controller->$action($request):$allowed;}finally{$GLOBALS['reset_user']=$original;}
}
