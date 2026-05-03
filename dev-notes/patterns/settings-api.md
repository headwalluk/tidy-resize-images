# Settings API Patterns

**Purpose:** WordPress Settings API implementation patterns  
**Last Updated:** 10 January 2026

---

## Settings Registration

```php
class Settings {
    public function register_settings(): void {
        register_setting(
            'example_plugin_options',
            'example_plugin_setting',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_setting' ),
                'default'           => '',
            )
        );
        
        add_settings_section(
            'example_plugin_section',
            __( 'General Settings', 'example-plugin' ),
            array( $this, 'render_section' ),
            'example_plugin_settings'
        );
        
        add_settings_field(
            'example_plugin_field',
            __( 'Setting Label', 'example-plugin' ),
            array( $this, 'render_field' ),
            'example_plugin_settings',
            'example_plugin_section'
        );
    }
}
```

---

## Meta Box Pattern

```php
public function add_meta_box(): void {
    add_meta_box(
        'example_plugin_meta',
        __( 'Plugin Settings', 'example-plugin' ),
        array( $this, 'render_meta_box' ),
        'post',
        'normal',
        'default'
    );
}

public function save_meta_box( int $post_id ): void {
    // Verify nonce
    if ( ! isset( $_POST['example_plugin_nonce'] ) 
         || ! wp_verify_nonce( $_POST['example_plugin_nonce'], 'example_plugin_save' ) ) {
        return;
    }
    
    // Check autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    
    // Check permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    
    // Save data
    $value = isset( $_POST['plugin_field'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_field'] ) ) : '';
    update_post_meta( $post_id, '_example_plugin_field', $value );
}
```
