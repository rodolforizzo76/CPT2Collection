<?php
/** Authenticated browser projection of the existing cart identity. Never transaction authority. */
declare(strict_types=1);
namespace RodolfoRizzo\Cpt2Collection;
defined('ABSPATH') || exit;

final class CheckoutContextToken {
 public const LIFETIME = 3600;
 private static function material(CartRecord $cart,CommerceContext $context):string {
  return json_encode(['checkout-context-v1',$context->installation_id(),$context->epoch_id(),$context->epoch_identity(),$cart->id(),$cart->token_hash(),$cart->created_at(),$cart->content_revision(),$cart->checkout_generation()],JSON_THROW_ON_ERROR);
 }
 public static function same_owner(CartRecord $a,CartRecord $b):bool {
  return $a->id()===$b->id()&&$a->epoch_id()===$b->epoch_id()&&hash_equals($a->token_hash(),$b->token_hash())&&$a->created_at()===$b->created_at();
 }
 public static function same_cart(CartRecord $a,CartRecord $b):bool {
  return self::same_owner($a,$b)&&$a->content_revision()===$b->content_revision()&&$a->checkout_generation()===$b->checkout_generation();
 }
 /** Stable version scopes browser attempt keys; expiry refresh alone does not change it. */
 public static function issue(CartRecord $cart,CommerceContext $context,?int $now=null):array {
  $now??=time();$until=$now+self::LIFETIME;
  $version=hash_hmac('sha256',self::material($cart,$context),wp_salt('auth'));
  return ['version'=>$version,'token'=>'v1.'.$until.'.'.hash_hmac('sha256',$version.'|'.$until,wp_salt('auth'))];
 }
 /** A lost renewal response may retry only that exact already-committed generation. */
 public static function previous_generation(string $token,CartRecord $cart,CommerceContext $context):bool {
  if($cart->checkout_generation()<1)return false;
  $before=new CartRecord($cart->id(),$cart->token_hash(),$cart->created_at(),$cart->updated_at(),$cart->last_activity_at(),$cart->expires_at(),$cart->content_revision(),$cart->checkout_generation()-1,$cart->epoch_id());
  return self::verify($token,$before,$context);
 }
 public static function verify(string $token,CartRecord $cart,CommerceContext $context,?int $now=null):bool {
  $now??=time();
  if($cart->epoch_id()!==$context->epoch_id()||$cart->expires_at()<=gmdate('Y-m-d H:i:s',$now)||!preg_match('/\Av1\.([1-9][0-9]{0,10})\.([0-9a-f]{64})\z/',$token,$parts))return false;
  $until=(int)$parts[1];if($until<=$now||$until>$now+self::LIFETIME)return false;
  $version=hash_hmac('sha256',self::material($cart,$context),wp_salt('auth'));
  return hash_equals(hash_hmac('sha256',$version.'|'.$parts[1],wp_salt('auth')),$parts[2]);
 }
}
