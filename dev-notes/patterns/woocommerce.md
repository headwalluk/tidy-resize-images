# WooCommerce Integration Patterns

**Purpose:** WooCommerce-specific patterns for WordPress plugins  
**Last Updated:** 10 January 2026

---

## Declare HPOS Compatibility

```php
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
);
```

---

## HPOS Data Access Rule

**Never use `get_post_meta()`, `update_post_meta()`, or direct SQL queries for Order data.**  
**Always use the CRUD getters and setters on the `WC_Order` object.**

```php
// ❌ Bad - Breaks in HPOS
$email = get_post_meta( $order_id, '_billing_email', true );

// ✅ Good - HPOS Compatible
$order = wc_get_order( $order_id );
$email = $order->get_billing_email();
$order->update_meta_data( 'custom_key', $value );
$order->save();
```

**Why:**
- WooCommerce High-Performance Order Storage (HPOS) moves order data from `wp_posts` to custom tables
- Direct post meta access fails silently when HPOS is enabled
- WooCommerce CRUD methods abstract the storage layer
- Always declare compatibility with `custom_order_tables`

---

## Product Data Tabs

```php
add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );

public function add_product_tab( array $tabs ): array {
    $tabs['example_plugin'] = array(
        'label'    => __( 'Plugin Data', 'example-plugin' ),
        'target'   => 'example_plugin_data',
        'class'    => array( 'show_if_simple', 'show_if_variable' ),
        'priority' => 60,
    );
    return $tabs;
}
```
