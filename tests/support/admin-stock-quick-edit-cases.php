<?php
/** Reuses the Phase 2 database, services, fault injection and fixtures. */
use RodolfoRizzo\Cpt2Collection\Admin\{StockQuickEdit, ListTable};
use RodolfoRizzo\Cpt2Collection\{AdminStockService, AdminStockResult, PostTypes, MetaKeys};
function quick_race(AdminStockService $service,string $target):AdminStockResult {
	quick_form('10',$target); $error=quick_save($service);
	if ($error==='') return AdminStockResult::success((int)$target);
	if (str_contains($error,'changed after')) return AdminStockResult::failure('stock_conflict');
	if (str_contains($error,'could not be saved or checked')) return AdminStockResult::failure('database_error');
	if (str_contains($error,'below the units')) return AdminStockResult::failure('below_reservations');
	throw new RuntimeException('Unexpected Quick Edit race outcome: '.$error);
}
fixture(); [$s,$box,,$sale]=components(); $quick=new StockQuickEdit(new PostTypes(),$s); $quick->register();
$GLOBALS['screen']='post'; unset($GLOBALS['enqueued']); $quick->enqueue(); check(!isset($GLOBALS['enqueued']),'Quick Edit assets stay off the normal editor');
$GLOBALS['screen']='edit'; $quick->enqueue(); check($GLOBALS['enqueued'][0]==='cpt2collection-stock-quick-edit' && in_array('inline-edit-post',$GLOBALS['enqueued'][2],true),'Quick Edit assets load after core inline editor on the catalog list'); $GLOBALS['screen']='post';
check(isset($GLOBALS['hooks']['quick_edit_custom_box']) && !isset($GLOBALS['hooks']['bulk_edit_custom_box']),'Quick Edit registers stock UI without Bulk Edit');
$list=new ListTable(new PostTypes(),$quick); $list->register();
check(isset($GLOBALS['hooks']['wp_ajax_inline-save']),'AJAX replacement rows register catalog columns');
ob_start(); $quick->render('cpt2collection_stock','collection_item'); $html=ob_get_clean();
check(str_contains($html,'>Stock</span>') && str_contains($html,'Includes units reserved for checkout.') && str_contains($html,'cpt2collection_quick_expected') && str_contains($html,'disabled'),'Quick Edit template has disabled-until-populated strict stock and baseline fields');
ob_start(); $quick->render('other','collection_item'); $quick->render('cpt2collection_stock','other'); check(ob_get_clean()==='','UI only appears for configured CPT and stock column');
ob_start(); $list->render_column('cpt2collection_stock',1); $html=ob_get_clean();
check(str_contains($html,'data-stock="10"') && str_contains($html,'>10<'),'list row carries uncached strict persisted baseline');
quick_form(); check(quick_save($s)==='' && stock()==='12' && $GLOBALS['other_writes']===0,'current Quick Edit commits through shared writer without other commerce changes');
ob_start(); $list->render_column('cpt2collection_stock',1); check(str_contains(ob_get_clean(),'data-stock="12"'),'AJAX replacement stock cell contains refreshed persisted baseline');
quick_form('10','8'); $error=quick_save($s);
check(str_contains($error,'changed after') && str_contains($error,'data-stock="12"') && stock()==='12','stale Quick Edit cannot overwrite newer admin stock; error carries current stock');
fixture(); [$s,$box,,$sale]=components(); check(convert_sale($sale)->is_success(),'Quick Edit regression uses actual committed sale');
quick_form('10','12'); $error=quick_save($s); check(str_contains($error,'changed after') && str_contains($error,'data-stock="9"') && stock()==='9','stale Quick Edit cannot restore sale-consumed stock');
quick_form('10','10'); $db->log=[]; check(quick_save($s)==='' && !in_array('START TRANSACTION',$db->log,true) && stock()==='9','unchanged stale Quick Edit is read-only and preserves sale');
fixture(); [$s,$box]=components(); quick_form(); unset($_POST['cpt2collection_quick_stock']); $db->log=[];
check(quick_save($s)==='' && $db->log===[],'absent Quick Edit stock performs no stock read or mutation');
quick_form(); $_POST+=['cpt2collection_meta_nonce'=>'valid','cpt2collection_stock_quantity'=>'99','cpt2collection_stock_expected'=>'10']; $box->save(1,new WP_Post());
check(stock()==='10' && $GLOBALS['other_writes']===0,'inline-save cannot invoke the full metabox commerce writer through extra fields');
foreach ([null,'',-1,'-1','1.2',1.2,'01',' 1','abc','<script>',[],new stdClass(),true,(string)PHP_INT_MAX.'0'] as $invalid) {
	fixture(); [$s]=components(); quick_form('10',$invalid); $error=quick_save($s);
	check(str_contains($error,'whole number') && stock()==='10' && !str_contains($error,'<script>'),'Quick Edit rejects invalid target '.json_encode($invalid));
	quick_form($invalid,'12'); check(str_contains(quick_save($s),'whole number') && stock()==='10','Quick Edit rejects invalid baseline '.json_encode($invalid));
}
fixture(); [$s]=components(); quick_form(); unset($_POST['cpt2collection_quick_expected']); check(str_contains(quick_save($s),'whole number'),'Quick Edit explicit target needs baseline');
sql("UPDATE {$t->reservations()} SET quantity=4"); quick_form('10','3'); $error=quick_save($s);
check(str_contains($error,'below the units') && stock()==='10','Quick Edit rejects below reservation floor');
$before=$db->get_results("SELECT * FROM {$t->reservations()}"); quick_form('10','4'); check(quick_save($s)==='' && stock()==='4' && $before==$db->get_results("SELECT * FROM {$t->reservations()}"),'Quick Edit accepts floor equality without reservation mutation');
quick_form('4','8'); check(quick_save($s)==='' && stock()==='8' && $before==$db->get_results("SELECT * FROM {$t->reservations()}"),'Quick Edit increases stock above the floor without changing reservations');
quick_form('8','5'); check(quick_save($s)==='' && stock()==='5' && $before==$db->get_results("SELECT * FROM {$t->reservations()}"),'Quick Edit decreases stock above the floor without changing reservations');
sql("DELETE FROM {$t->reservations()}"); quick_form('5','0'); check(quick_save($s)==='' && stock()==='0' && get_post_meta(1,MetaKeys::STATUS,true)==='available','Quick Edit zero succeeds without relabeling commercial status');
foreach ([null,'broken','duplicate'] as $bad) {
	fixture($bad==='duplicate'?'10':$bad); [$s]=components(); if ($bad==='duplicate') sql($db->prepare("INSERT INTO {$db->postmeta} (post_id,meta_key,meta_value) VALUES(1,%s,'10')",MetaKeys::STOCK_QUANTITY));
	$before=snapshot(); quick_form(); $error=quick_save($s);
	check(str_contains($error,'technical review') && str_contains($error,'data-stock=""') && snapshot()===$before,'Quick Edit rejects persistence anomaly without repair: '.json_encode($bad));
	ob_start(); (new StockQuickEdit(new PostTypes(),$s))->render_stock(1); $html=ob_get_clean(); check(str_contains($html,'data-stock=""') && !str_contains($html,'>1<'),'anomalous list row never invents usable stock');
}
foreach (['read','write','rollback','1205','1213','begin','commit','commit_accepted','commit_throw'] as $failure) {
	fixture(); [$s]=components(); $db->fail=$failure; quick_form(); $error=quick_save($s);
	$unknown=in_array($failure,['rollback','begin','commit','commit_accepted','commit_throw'],true);
	check($error!=='' && !str_contains($error,'PRIVATE') && $GLOBALS['cache']===[] && $GLOBALS['other_writes']===0,'Quick Edit safe failure feedback and no false cache success: '.$failure);
	check(count(array_filter($db->log,fn($q)=>$q==='START TRANSACTION'))===1,'Quick Edit never retries failed mutation: '.$failure);
	if ($unknown) {
		check(str_contains($error,'could not be confirmed') && str_contains($error,'data-stock=""'),'uncertain Quick Edit never reports an authoritative quantity');
		check(end($db->log)===($failure==='rollback'?'ROLLBACK':($failure==='begin'?'START TRANSACTION':'COMMIT')),'uncertain Quick Edit performs no follow-up persistence reads');
	}
	check(!str_contains($error,'<tr'),'rejected response cannot be mistaken for a successful WordPress row');
	$db->fail=''; $db->close(); $db=new StockDb($prefix); check(stock()===($failure==='commit_accepted'?'12':'10'),'Quick Edit failure persistence verified on new connection: '.$failure);
}
foreach (['capability','nonce','nonce_missing','nonce_array','wrong_cpt','post_id','post_id_overflow'] as $guard) {
	fixture(); [$s]=components(); quick_form();
	if ($guard==='capability') $GLOBALS['allowed']=false;
	if ($guard==='nonce') $_POST['_inline_edit']='invalid';
	if ($guard==='nonce_missing') unset($_POST['_inline_edit']);
	if ($guard==='nonce_array') $_POST['_inline_edit']=[];
	if ($guard==='wrong_cpt') $_POST['post_type']='other';
	if ($guard==='post_id') $_POST['post_ID']='2';
	if ($guard==='post_id_overflow') $_POST['post_ID']=(string)PHP_INT_MAX.'0';
	$db->log=[]; $error=quick_save($s); check(str_contains($error,'permission') && $db->log===[],'Quick Edit boundary rejects before stock access: '.$guard); $GLOBALS['allowed']=true;
}
fixture(); [$s]=components(); sql("UPDATE {$db->posts} SET post_type='other'"); quick_form(); check(quick_save($s)!=='' && stock()==='10','Quick Edit shared writer rejects persisted wrong CPT');
foreach (['ajax','autosave','revision'] as $guard) {
	fixture(); [$s]=components(); quick_form(); $GLOBALS[$guard]=$guard!=='ajax'; $db->log=[];
	check(quick_save($s)==='' && $db->log===[],'Quick Edit ignores non-lifecycle save: '.$guard); $GLOBALS[$guard]=$guard==='ajax';
}
$GLOBALS['ajax']=false;
