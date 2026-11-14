<?php
/** Fixed HTML paid-Order templates, shared by the two recipient roles. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use RodolfoRizzo\Cpt2Collection\Admin\OrdersPage;
defined( 'ABSPATH' ) || exit;

final class PaymentNotificationEmail {
	/** @param OrderItem[] $items @return array{string,string} */
	public static function render( string $role, OrderRecord $order, array $items ): array {
		$site = esc_html( get_bloginfo( 'name' ) );
		$admin = 'admin' === $role;
		$heading = $admin ? __( 'New paid order', 'cpt2collection' ) : __( 'Payment confirmed', 'cpt2collection' );
		$intro = $admin ? __( 'An order has been paid.', 'cpt2collection' ) : __( 'Thank you for your order. We have received your payment.', 'cpt2collection' );
		$reference = 'C2C-' . $order->id();
		/* translators: 1: translated payment notification heading, 2: order reference such as C2C-123. */
		$subject = sprintf( _x( '%1$s · %2$s', 'paid order email subject', 'cpt2collection' ), $heading, $reference );
		/* translators: 1: order reference label, 2: order reference such as C2C-123. */
		$order_reference = sprintf( esc_html_x( '%1$s %2$s', 'email order reference line', 'cpt2collection' ), esc_html__( 'Order reference', 'cpt2collection' ), esc_html( $reference ) );
		/* translators: 1: paid total label, 2: amount including the currency marker. */
		$paid_total = sprintf( esc_html_x( '%1$s %2$s', 'email paid total line', 'cpt2collection' ), esc_html__( 'Paid total', 'cpt2collection' ), esc_html( Money::format_paid_total( $order->total_minor(), $order->currency() ) ) );
		$link = '';
		if ( $admin ) {
			$url = esc_url( add_query_arg( array( 'page' => OrdersPage::MENU_SLUG, 'order_id' => $order->id() ), admin_url( 'admin.php' ) ), array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$link = '<p style="margin:24px 0 0"><a style="display:inline-block;padding:12px 18px;background:#174c56;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold" href="' . $url . '">' . esc_html__( 'View order in WordPress', 'cpt2collection' ) . '</a></p>';
			}
		}

		$rows = '';
		foreach ( $items as $item ) {
			$rows .= '<tr><td style="padding:12px 8px;border-bottom:1px solid #dee8e9;overflow-wrap:anywhere">' . esc_html( $item->item_name() ) . '</td>'
				. '<td align="right" style="padding:12px 8px;border-bottom:1px solid #dee8e9">' . esc_html( (string) $item->quantity() ) . '</td></tr>';
		}
		$body = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
			. '<body style="margin:0;background:#eef4f4;color:#17363d;font:16px/1.5 Arial,sans-serif">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef4f4"><tr><td align="center" style="padding:20px 12px">'
			. '<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff">'
			. '<tr><td style="background:#174c56;color:#fff;padding:18px 24px;font-weight:bold">' . $site . '</td></tr>'
			. '<tr><td style="padding:24px"><h1 style="margin:0 0 8px;font-size:26px;line-height:1.2">' . esc_html( $heading ) . '</h1>'
			. '<p style="margin:0 0 20px">' . esc_html( $intro ) . '</p><p>' . $order_reference . '</p>'
			. '<table width="100%" cellpadding="0" cellspacing="0"><thead><tr>'
			. '<th scope="col" align="left" style="padding:8px;background:#eef4f4">' . esc_html__( 'Item', 'cpt2collection' ) . '</th>'
			. '<th scope="col" align="right" style="padding:8px;background:#eef4f4">' . esc_html__( 'Qty', 'cpt2collection' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<p style="margin:20px 0 0;font-size:18px"><strong>' . $paid_total . '</strong></p>' . $link . '</td></tr>'
			. '<tr><td style="padding:14px 24px;border-top:1px solid #dee8e9;color:#49676d;font-size:13px">' . esc_html__( 'Automated order notification', 'cpt2collection' ) . '</td></tr>'
			. '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';

		return array( $subject, $body );
	}
}
