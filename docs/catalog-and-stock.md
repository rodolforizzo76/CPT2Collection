# Catalog, cart and stock

## Overview

CPT2Collection 1.0.0 adds product fields and stock management to one existing
WordPress Custom Post Type. You keep the posts you already use for your
catalog. For example, if each artwork is already a post in your Artworks
catalog, you can add a price and stock quantity to each of those posts.

In WordPress, choose the post type under **CPT2Collection → Settings →
Managed post type**. If you still need to select your catalog or add purchase
controls, start with the [configuration guide](configuration.md).

## Product settings

Open an item in your managed post type and find the **CPT2Collection** panel
in the WordPress editor. Set the fields below and save the post. Publish the
item when you want visitors to see it.

| Field | What to enter |
| --- | --- |
| **Price** | Enter a positive amount to sell the item. The field starts blank when no price is set. |
| **Commercial status** | Choose whether customers can buy the item. The default is **Not for sale**. |
| **Stock** | Enter the total units physically on hand, including any held for checkout. Use a whole number of zero or more. A new item started with **Add New** is normally initialized with 1 unit. |
| **Rating** | Choose **No rating** (the default) or a rating from **0.5 out of 5** to **5 out of 5** in half-point steps. |
| **Can be purchased without reaching the minimum order quantity** | Leave this unchecked by default. Check it when a cart containing this eligible product should be allowed below the normal minimum. |

### Enter a price

Enter the amount in the shop's selected **Currency**. The choices are **EUR**
(the default) and **USD**. Use a whole amount such as `12` or up to two decimal
places, such as `12.90` or `12,90`. Leave out currency symbols and thousands
separators.

The plugin stores amounts as whole cents. For example, `12.90` is stored as
1,290 cents. Changing **Currency** leaves the number the same, so review
prices before changing it.

### Choose a commercial status

| Commercial status | What it means for purchases |
| --- | --- |
| **Available** | The item can be purchased if its price, stock, and the rest of the cart meet the checkout rules. |
| **Reserved** | The item is manually marked as reserved. Customers cannot buy it. |
| **Sold** | The item is manually marked as sold. Customers cannot buy it. |
| **Not for sale** | The item is kept out of checkout. This is the default for a new item. |

When you save the full product editor with **Available** selected, the item
needs a valid price greater than zero and physical stock greater than zero.
If either is missing, CPT2Collection saves the item as **Not for sale** and
shows a warning.

The **Reserved** status is a catalog setting. Temporary stock holds created
during checkout leave that setting alone. A sale or a stock-only edit can
bring **Stock** to zero while the item still shows **Available**. Checkout
blocks purchases at zero stock, and **Sold** is never set automatically.

### Set a rating or allow a smaller order

**Rating** is set by the editor. If you use the `[cpt2collection_rating]`
shortcode, it displays that rating as stars. **No rating** leaves the rating
display empty.

**Minimum order quantity** counts units across the cart. The default is
`1`. If your minimum is 3, a cart with two units of one product and one unit
of another qualifies. A cart with one eligible product that has the exemption
checked also meets the rule. The exemption applies to the whole cart, and
all items must still meet the other checkout rules.

## Guest cart

Visitors can shop without a WordPress account. The site stores the cart and
uses a browser cookie to find it again. A visitor can return to the same cart
in the same browser while the cookie and cart are still valid. Clearing the
cookie or opening the site in a different browser gives them a separate cart.

1. On the product page, choose a whole-number **Quantity** of at least 1 and
   click **Add to cart**. Adding the same product again increases its quantity.
2. On the cart page, change **Quantity** and click **Update**. To take a product
   out of the cart, use its remove control.
3. Review the items and total, then click **Proceed to checkout**.

Adding an item to the cart does not reserve it. At checkout, CPT2Collection
checks current prices, commercial statuses, quantities, and the minimum order
quantity. It then checks whether enough stock can be reserved. Another shopper
may have reserved or bought the last units while the item was in the cart.

## Understanding stock

- **Physical stock** is the number saved in the product's **Stock** field.
  It includes units currently held for checkout.
- **Reserved stock** is the number of units held by active checkout
  reservations whose time has yet to run out.
- **Available stock** is physical stock minus those active reservations.
  It tells you how many units another checkout can still reserve.

Suppose you have 10 units and one customer checks out with 3.

| What happens | Physical stock | Reserved stock | Available stock |
| --- | ---: | ---: | ---: |
| Before checkout | 10 | 0 | 10 |
| Checkout reserves 3 units | 10 | 3 | 7 |
| WordPress confirms the payment and records the sale | 7 | 0 | 7 |

The sale deducts three units from physical stock and uses up their reservation.
If the customer leaves without paying and the hold expires, physical stock
stays at 10 and all 10 units can be reserved again.

The cart currently labels its displayed count **Available stock**, but that
count comes from the physical **Stock** field. Checkout accounts for existing
reservations before deciding whether it can hold the requested quantity.

## Stock reservations

A reservation begins after the customer starts checkout and CPT2Collection
successfully holds the requested units, before it requests a Stripe Checkout
Session. The hold gives the customer time to pay and prevents another checkout
from reserving the same units during that time.

**Reservation duration** is under **CPT2Collection → Settings**. It defaults to
**35 minutes** and accepts whole minutes from **5 to 120**. Stripe Checkout
needs at least **30 minutes remaining** when the plugin requests the session.
A setting below 30 minutes cannot work for Stripe, and exactly 30 minutes
leaves no time for processing. Keep the default or use a longer duration.

When an unpaid reservation reaches its deadline, it stops counting against
availability. The order may still show **Awaiting payment** until WordPress
processes Stripe's session expiration notification and marks it **Expired**.
Returning through the cancellation page does not release a hold immediately.
Physical stock is deducted when WordPress verifies the payment and records the
sale. See [Orders and notifications](orders.md) for more on order status.

## Editing stock

Enter the total physical quantity you have now, including any units held for
checkout. For example, if you physically have 10 units, receive 5 more, and
still have 3 units held for checkout, enter `15` as **Stock**.

### Use the product editor

1. Open the product and find the **CPT2Collection** panel.
2. Check the current **Stock** and enter the new physical count.
3. Review **Price** and **Commercial status** if you are also changing whether
   the product is for sale. Save the post and check the saved values and any
   notices.

### Use Stock Quick Edit

1. Open the list of posts for your managed post type and find the product.
2. Open **Quick Edit** on that row and enter the new value in **Stock**.
3. Click **Update** and check the **Stock** column.

In Quick Edit, **Stock** is the only CPT2Collection field you can change. Use
the product editor for **Price**, **Commercial status**, **Rating**, and the
minimum order exemption. Both stock editors reject a quantity below the
number of units currently held for checkout.

## Conflicts and limitations

If two people edit the same item, or a sale updates stock while a form is
open, CPT2Collection checks the original quantity before applying a change.
For example, if you opened an item with 10 units and a sale reduced it to 9,
an edit based on 10 is rejected. Saving the form with the **Stock** field
untouched leaves the newer stock value in place.

If you see a conflict, a busy message, or a notice that the save could not be
confirmed, reload the product editor or the list before trying again. Check
the current stock against your physical count and any recent orders. The
change may already have been saved if the result could not be confirmed.
Enter a new quantity only after checking. WordPress may have saved other post
fields, such as the title, even when CPT2Collection reports a stock error.

**Bulk Edit** does not provide CPT2Collection stock editing. Changes made by
imports, scripts, or plugins that write directly to the product's stock
metadata can bypass the checks in the two stock editors. Use the supported
editors for routine adjustments.

If **Stock** stays unavailable after a reload, ask a technical maintainer to
review the item. Missing, duplicate, or malformed stock data on an existing
item cannot be repaired through either stock editor. The starting quantity for
new items does not restore existing stock data. Check your inventory records
so the maintainer can verify the intended quantity.
