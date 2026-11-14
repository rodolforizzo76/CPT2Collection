<?php
/** Restartable migration. The committed control fence survives every implicit DDL commit. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-foundation.php';
final class CommercePhaseTwoMigration {
	public static function run( \wpdb $db ): bool {
		if(!$db instanceof CommerceConnection){return false;}
		$t=new TableNames($db);$tx=new TransactionManager($db,$t);
		$name=substr('c2c_phase2_'.hash('sha256',(string)$db->get_var('SELECT DATABASE()').':'.$db->prefix),0,64);
		if('1'!==(string)$db->get_var($db->prepare('SELECT GET_LOCK(%s, 10)',$name))){return false;}
		try {
			if(!CommerceFoundation::verify_structure($db,$t)||!$tx->begin()){return false;}
			$rows=$db->get_results('SELECT * FROM '.$t->commerce_control().' ORDER BY id FOR UPDATE');
			if(!is_array($rows)||count($rows)!==1||(int)$rows[0]->id!==1||!in_array($rows[0]->state,array('ready','migrating_v11'),true)||(int)$rows[0]->current_epoch_id!==1){$tx->rollback();return false;}
			if(property_exists($rows[0],'baseline_contract')){$tx->rollback();return false;}
			$control=$rows[0];$epochs=$db->get_results('SELECT * FROM '.$t->commerce_epochs().' ORDER BY id FOR UPDATE');
			if(!is_array($epochs)||count($epochs)!==1||(int)$epochs[0]->id!==1||null!==$epochs[0]->retired_at||!preg_match('/\A[0-9a-f]{32}\z/',$control->installation_id)||!preg_match('/\A[0-9a-f]{32}\z/',$epochs[0]->identity)){ $tx->rollback();return false; }
			if(false===$db->query("UPDATE {$t->commerce_control()} SET state='migrating_v11' WHERE id=1")||!$tx->commit()){return false;}
			foreach(self::ddl($t) as $sql){if(false===$db->query($sql)){return false;}}
			// Validate existing partial tables before any backfill can write durable evidence.
			if(!self::verify_registry_structure($db,$t)){return false;}
			foreach(array('carts','orders','reservations','payment_sessions','payment_events') as $method){
				$columns=$db->get_col('SHOW COLUMNS FROM '.$t->$method());
				if(!is_array($columns)){return false;}
				if(!in_array('epoch_id',$columns,true)&&false===$db->query('ALTER TABLE '.$t->$method().' ADD epoch_id BIGINT UNSIGNED NULL')){return false;}
			}
			$columns=$db->get_col('SHOW COLUMNS FROM '.$t->payment_events());
			if(!in_array('ownership_state',$columns,true)&&false===$db->query("ALTER TABLE {$t->payment_events()} ADD ownership_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unresolved'")){return false;}
			if(!$tx->begin()){return false;}
			if(!self::backfill($db,$t,$control,$epochs[0])){$tx->rollback();return false;}
			if(!$tx->commit()){return false;}
			foreach(array('carts','orders','reservations','payment_sessions') as $method){
				if(false===$db->query('ALTER TABLE '.$t->$method().' MODIFY epoch_id BIGINT UNSIGNED NOT NULL')){return false;}
			}
			$indexes=array('carts'=>array('epoch_expiry'=>'epoch_id, expires_at, id'),'orders'=>array('epoch_created'=>'epoch_id, created_at, id','epoch_status'=>'epoch_id, status, created_at, id'),'reservations'=>array('epoch_stock'=>'epoch_id, post_id, status, expires_at','epoch_status'=>'epoch_id, status, expires_at'),'payment_sessions'=>array('epoch_order'=>'epoch_id, order_id, status','epoch_status'=>'epoch_id, status, expires_at'),'payment_events'=>array('epoch_status'=>'epoch_id, processing_status, received_at'));
			foreach($indexes as $method=>$keys){$found=$db->get_results('SHOW INDEX FROM '.$t->$method());if(!is_array($found)){return false;}$names=array_map(fn($r)=>$r->Key_name,$found);foreach($keys as $key=>$columns){if(!in_array($key,$names,true)){if(false===$db->query('ALTER TABLE '.$t->$method().' ADD KEY '.$key.' ('.$columns.')')){return false;}}else{$actual=array();foreach($found as $part){if($part->Key_name===$key){if(null!==$part->Sub_part){return false;}$actual[(int)$part->Seq_in_index]=$part->Column_name;}}ksort($actual);if(array_values($actual)!==explode(', ',$columns)){return false;}}}}
			if(!self::verify($db,$t)||!$tx->begin()){return false;}
			if(false===$db->query("UPDATE {$t->commerce_control()} SET state='ready' WHERE id=1 AND state='migrating_v11'")){ $tx->rollback();return false; }
			return $tx->commit();
		} catch(\Throwable $e){if($tx->is_active()){$tx->rollback();}return false;}
		finally {if(!$tx->is_outcome_uncertain()){$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$name));}}
	}
	public static function ddl(TableNames $t):array {
		return array(
			"CREATE TABLE IF NOT EXISTS {$t->provider_claims()} (
			 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			 epoch_id BIGINT UNSIGNED NOT NULL, epoch_identity CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			 provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, reference CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			 order_id BIGINT UNSIGNED NOT NULL, payment_session_id BIGINT UNSIGNED NOT NULL,
			 creation_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			 request_version INT UNSIGNED NOT NULL, request_snapshot LONGTEXT NULL, environment VARCHAR(16) NULL,
			 evidence_state VARCHAR(32) NOT NULL, disposition VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, tombstoned_at DATETIME NULL,
			 PRIMARY KEY(id), UNIQUE KEY reference(reference), UNIQUE KEY provider_idempotency(provider,idempotency_key),
			 UNIQUE KEY attempt(installation_id,epoch_id,order_id,creation_key_hash), KEY epoch_order(epoch_id,order_id)
			) ENGINE=InnoDB",
			"CREATE TABLE IF NOT EXISTS {$t->provider_identities()} (
			 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			 identity_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, identity_value VARBINARY(255) NOT NULL,
			 claim_id BIGINT UNSIGNED NULL, binding_state VARCHAR(16) NOT NULL, evidence_state VARCHAR(32) NOT NULL,
			 observed_epoch_id BIGINT UNSIGNED NOT NULL, first_seen_at DATETIME NOT NULL,
			 payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, event_type VARCHAR(128) NULL, environment VARCHAR(16) NULL,
			 disposition VARCHAR(64) NOT NULL, conflict_code VARCHAR(64) NULL,
			 PRIMARY KEY(id), UNIQUE KEY identity(provider,identity_kind,identity_value), KEY claim_id(claim_id)
			) ENGINE=InnoDB"
		);
	}
	private static function backfill(\wpdb $db,TableNames $t,object $control,object $epoch):bool {
		foreach(array('carts','orders','reservations','payment_sessions') as $method){
			if(false===$db->query('UPDATE '.$t->$method().' SET epoch_id=1 WHERE epoch_id IS NULL')){return false;}
		}
		// Missing historical carts/source cart items are allowed. Required operational parents are not.
		$invalid=array(
			"SELECT i.id FROM {$t->cart_items()} i LEFT JOIN {$t->carts()} c ON c.id=i.cart_id WHERE c.id IS NULL LIMIT 1",
			"SELECT i.id FROM {$t->order_items()} i LEFT JOIN {$t->orders()} o ON o.id=i.order_id WHERE o.id IS NULL LIMIT 1",
			"SELECT r.id FROM {$t->reservations()} r LEFT JOIN {$t->orders()} o ON o.id=r.order_id LEFT JOIN {$t->order_items()} i ON i.id=r.order_item_id WHERE o.id IS NULL OR i.id IS NULL OR i.order_id<>r.order_id OR i.post_id<>r.post_id OR r.epoch_id<>o.epoch_id LIMIT 1",
			"SELECT s.id FROM {$t->payment_sessions()} s LEFT JOIN {$t->orders()} o ON o.id=s.order_id WHERE o.id IS NULL OR s.epoch_id<>o.epoch_id LIMIT 1",
			"SELECT o.id FROM {$t->orders()} o JOIN {$t->carts()} c ON c.id=o.cart_id WHERE o.epoch_id<>c.epoch_id LIMIT 1"
		);
		foreach($invalid as $sql){$db->last_error='';if(null!==$db->get_var($sql)||''!==$db->last_error){return false;}}
		foreach(array('carts','orders','reservations','payment_sessions') as $method){if(null!==$db->get_var('SELECT id FROM '.$t->$method().' WHERE epoch_id<>1 LIMIT 1')){return false;}}
		$sessions=$db->get_results('SELECT * FROM '.$t->payment_sessions().' ORDER BY id');if(!is_array($sessions)){return false;}
		$by_id=array();$by_ref=array();$by_identity=array();$session_rows=array();
		foreach($sessions as $s){
			if(!preg_match('/\A[0-9a-f]{32}\z/',$s->reference)||!preg_match('/\A[0-9a-f]{64}\z/',$s->creation_key_hash)){return false;}
			$claim=$db->get_row($db->prepare('SELECT * FROM '.$t->provider_claims().' WHERE reference=%s',$s->reference));
			if(null===$claim){
				$data=array('installation_id'=>$control->installation_id,'epoch_id'=>1,'epoch_identity'=>$epoch->identity,'provider'=>$s->provider,'reference'=>$s->reference,'order_id'=>$s->order_id,'payment_session_id'=>$s->id,'creation_key_hash'=>$s->creation_key_hash,'idempotency_key'=>'c2c_ps_'.$s->reference,'request_version'=>1,'request_snapshot'=>null,'environment'=>null,'evidence_state'=>'legacy_incomplete','disposition'=>'open','created_at'=>$s->created_at,'tombstoned_at'=>null);
				if(false===$db->insert($t->provider_claims(),$data,array('%s','%d','%s','%s','%s','%d','%d','%s','%s','%d',null,null,'%s','%s','%s',null))){return false;}
				$claim=$db->get_row($db->prepare('SELECT * FROM '.$t->provider_claims().' WHERE reference=%s',$s->reference));
			}
			if(!is_object($claim)||$claim->installation_id!==$control->installation_id||(int)$claim->epoch_id!==1||$claim->epoch_identity!==$epoch->identity||(int)$claim->order_id!==(int)$s->order_id||(int)$claim->payment_session_id!==(int)$s->id||$claim->provider!==$s->provider||$claim->creation_key_hash!==$s->creation_key_hash){return false;}
			$session_rows[(int)$s->id]=$s;$by_id[(int)$s->id]=$claim;$by_ref[$s->reference]=$claim;
			foreach(array('session'=>'provider_session_id','payment'=>'provider_payment_id','event'=>'provider_event_id') as $kind=>$field){if(null!==$s->$field&&''!==$s->$field){
				if(!self::identity($db,$t,$s->provider,$kind,$s->$field,(int)$claim->id,$s->created_at,null,null,null,'legacy_incomplete','pending')){return false;}
				$by_identity[$s->provider.':'.$kind.':'.$s->$field]=$claim;
			}}
		}
		$events=$db->get_results('SELECT * FROM '.$t->payment_events().' ORDER BY id');if(!is_array($events)){return false;}
		foreach($events as $e){
			$candidates=array();if(null!==$e->payment_session_id){if(!isset($by_id[(int)$e->payment_session_id])){return false;}$candidates[]=$by_id[(int)$e->payment_session_id];}
			if(null!==$e->local_reference&&isset($by_ref[$e->local_reference])){$candidates[]=$by_ref[$e->local_reference];}
			$checkout=in_array($e->event_type,array('checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'),true);
			if($checkout){foreach(array('session'=>'provider_session_id','payment'=>'provider_payment_id') as $kind=>$field){if(null!==$e->$field&&isset($by_identity[$e->provider.':'.$kind.':'.$e->$field])){$candidates[]=$by_identity[$e->provider.':'.$kind.':'.$e->$field];}}}
			$ids=array_unique(array_map(fn($c)=>(int)$c->id,$candidates));if(count($ids)>1){return false;}
			$claim=$candidates[0]??null;
			if(null!==$claim&&($claim->provider!==$e->provider||(null!==$e->local_reference&&$claim->reference!==$e->local_reference))){return false;}
			if(null!==$claim&&$checkout){$owner=$session_rows[(int)$claim->payment_session_id];foreach(array('provider_session_id','provider_payment_id') as $field){if(null!==$owner->$field&&null!==$e->$field&&$owner->$field!==$e->$field){return false;}}}
			$environment=$e->livemode?'live':'test';$claim_id=null===$claim?null:(int)$claim->id;
			if(null!==$claim){
				$existing=$db->get_var($db->prepare('SELECT environment FROM '.$t->provider_claims().' WHERE id=%d',$claim_id));if(null!==$existing&&$existing!==$environment){return false;}
				if(false===$db->update($t->provider_claims(),array('environment'=>$environment),array('id'=>$claim_id),array('%s'),array('%d'))){return false;}
			}
			if(!self::identity($db,$t,$e->provider,'event',$e->provider_event_id,$claim_id,$e->received_at,$e->payload_sha256,$e->event_type,$environment,null===$claim?'legacy_unresolved':'legacy_verified',$e->processing_status)){return false;}
			if($checkout){foreach(array('session'=>'provider_session_id','payment'=>'provider_payment_id') as $kind=>$field){if(null!==$e->$field&&!self::identity($db,$t,$e->provider,$kind,$e->$field,$claim_id,$e->received_at,null,null,$environment,null===$claim?'legacy_unresolved':'legacy_verified','pending')){return false;}}}
			if(false===$db->update($t->payment_events(),array('epoch_id'=>null===$claim?null:1,'ownership_state'=>null===$claim?'unresolved':'bound'),array('id'=>(int)$e->id),array('%d','%s'),array('%d'))){return false;}
		}
		return true;
	}
	private static function identity(\wpdb $db,TableNames $t,string $provider,string $kind,string $value,?int $claim,string $at,?string $hash,?string $type,?string $env,string $evidence,string $disposition):bool {
		$row=$db->get_row($db->prepare('SELECT * FROM '.$t->provider_identities().' WHERE provider=%s AND identity_kind=%s AND identity_value=%s',$provider,$kind,$value));
		if(null!==$row){
			if('quarantined'===$row->binding_state || null!==$row->conflict_code || (null!==$type&&null!==$row->event_type&&$type!==$row->event_type) || (null!==$row->claim_id&&null!==$claim&&(int)$row->claim_id!==$claim)||(null!==$hash&&null!==$row->payload_sha256&&$hash!==$row->payload_sha256)||(null!==$env&&null!==$row->environment&&$env!==$row->environment)){return false;}
			$data=array();foreach(array('claim_id'=>$claim,'payload_sha256'=>$hash,'event_type'=>$type,'environment'=>$env) as $key=>$val){if(null===$row->$key&&null!==$val){$data[$key]=$val;}}
			if(null!==$claim){$data['binding_state']='bound';}if('event'===$kind){$data['disposition']=$disposition;}
			return !$data||false!==$db->update($t->provider_identities(),$data,array('id'=>(int)$row->id),array_map(fn($k)=>'claim_id'===$k?'%d':'%s',array_keys($data)),array('%d'));
		}
		return false!==$db->insert($t->provider_identities(),array('provider'=>$provider,'identity_kind'=>$kind,'identity_value'=>$value,'claim_id'=>$claim,'binding_state'=>null===$claim?'unresolved':'bound','evidence_state'=>$evidence,'observed_epoch_id'=>1,'first_seen_at'=>$at,'payload_sha256'=>$hash,'event_type'=>$type,'environment'=>$env,'disposition'=>$disposition,'conflict_code'=>null),array('%s','%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s',null));
	}
	public static function verify(\wpdb $db,TableNames $t):bool {
		// Historical completion proof only: never use this backfill invariant for later schemas.
		foreach ( array( 'carts', 'orders', 'reservations', 'payment_sessions' ) as $method ) {
			$db->last_error = '';
			if ( null !== $db->get_var( 'SELECT id FROM ' . $t->$method() . ' WHERE epoch_id IS NULL OR epoch_id<>1 LIMIT 1' ) || '' !== $db->last_error ) { return false; }
		}
		$db->last_error = '';
		$invalid = $db->get_var("SELECT id FROM {$t->payment_events()} WHERE ownership_state NOT IN ('unresolved','bound','quarantined') OR (ownership_state='bound' AND (epoch_id IS NULL OR epoch_id<>1)) OR (ownership_state='unresolved' AND epoch_id IS NOT NULL) LIMIT 1");
		return null === $invalid && '' === $db->last_error && self::verify_structure( $db, $t );
	}
	/** Read-only schema verification, independent of the historical epoch-1 backfill. */
	public static function verify_structure(\wpdb $db,TableNames $t):bool {
		foreach(array('carts','orders','reservations','payment_sessions') as $method){
			$columns=$db->get_results('SHOW FULL COLUMNS FROM '.$t->$method());if(!is_array($columns)){return false;}
			$valid=false;foreach($columns as $c){if('epoch_id'===$c->Field){$valid='bigint unsigned'===preg_replace('/bigint\(\d+\)/','bigint',strtolower($c->Type))&&'NO'===$c->Null&&null===$c->Default;}}if(!$valid){return false;}
		}
		$columns=$db->get_results('SHOW FULL COLUMNS FROM '.$t->payment_events());if(!is_array($columns)){return false;}$fields=array_column($columns,null,'Field');
		if(!isset($fields['epoch_id'],$fields['ownership_state'])||'YES'!==$fields['epoch_id']->Null||'bigint unsigned'!==preg_replace('/bigint\(\d+\)/','bigint',strtolower($fields['epoch_id']->Type))||'ascii_bin'!==$fields['ownership_state']->Collation){return false;}
		return self::verify_registry_structure($db,$t);
	}
	private static function verify_registry_structure(\wpdb $db,TableNames $t):bool {
		foreach(array('provider_claims','provider_identities') as $method){
			$engine=$db->get_var($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t->$method()));if(!is_string($engine)||strtoupper($engine)!=='INNODB'){return false;}
			$indexes=$db->get_results('SHOW INDEX FROM '.$t->$method());if(!is_array($indexes)){return false;}$unique=array();foreach($indexes as $i){if((int)$i->Non_unique===0){$unique[$i->Key_name][(int)$i->Seq_in_index]=$i->Column_name;}}
			$expected='provider_claims'===$method?array(array('id'),array('reference'),array('provider','idempotency_key'),array('installation_id','epoch_id','order_id','creation_key_hash')):array(array('id'),array('provider','identity_kind','identity_value'));
			$actual=array();foreach($unique as $index){ksort($index);$actual[]=array_values($index);}foreach($expected as $index){if(!in_array($index,$actual,true)){return false;}}
			$columns=$db->get_results('SHOW FULL COLUMNS FROM '.$t->$method());if(!is_array($columns)){return false;}
			$fields=array_column($columns,null,'Field');
			$required='provider_claims'===$method?array('id','installation_id','epoch_id','epoch_identity','provider','reference','order_id','payment_session_id','creation_key_hash','idempotency_key','request_version','request_snapshot','environment','evidence_state','disposition','created_at','tombstoned_at'):array('id','provider','identity_kind','identity_value','claim_id','binding_state','evidence_state','observed_epoch_id','first_seen_at','payload_sha256','event_type','environment','disposition','conflict_code');
			if(array_diff($required,array_keys($fields))){return false;}
			foreach(array('provider','reference','idempotency_key','installation_id','epoch_identity','creation_key_hash','identity_kind','payload_sha256') as $key){if(isset($fields[$key])&&$fields[$key]->Collation!=='ascii_bin'){return false;}}
			if('provider_identities'===$method&&strtolower($fields['identity_value']->Type)!=='varbinary(255)'){return false;}
		}
		return true;
	}
}
