# Template System Patterns

**Purpose:** WordPress template loading with theme override support  
**Last Updated:** 10 January 2026

---

## Template Loading with Overrides

Allow theme customization:

```php
/**
 * Load a template file with theme override support.
 *
 * @param string $template_name Template filename.
 * @param array  $args          Variables to pass to template.
 */
function load_template( string $template_name, array $args = array() ): void {
    // Extract args to variables
    extract( $args );
    
    // Check theme override
    $theme_template = locate_template( "example-plugin/{$template_name}" );
    
    if ( $theme_template ) {
        include $theme_template;
    } else {
        include PLUGIN_DIR . "templates/{$template_name}";
    }
}
```

---

## Template Files

```php
<?php
/**
 * Template description.
 *
 * @package Example_Plugin
 */

defined( 'ABSPATH' ) || die();

// Template variables available:
// $variable1, $variable2, etc.
?>

<div class="plugin-wrapper">
    <h2><?php echo esc_html( $title ); ?></h2>
    <!-- Template content -->
</div>
```
