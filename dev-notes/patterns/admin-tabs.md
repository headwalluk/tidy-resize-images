# Tabbed Admin Interfaces Pattern

**Purpose:** Implementing hash-based tabbed navigation in WordPress admin pages  
**Last Updated:** 10 January 2026

---

## When to Use Tabs

Use tabbed interfaces for admin pages with distinct functional areas:
- Different operational contexts (e.g., quick tasks vs. advanced features)
- Grouping related settings
- Separating frequently-accessed features from configuration

---

## Implementation Pattern

### URL Hash-Based Navigation

```
/wp-admin/admin.php?page=example-plugin#tab-name
```

**Benefits:**
- Tab state persists on page reload
- Browser back/forward button support
- Deep linking to specific tabs
- Shareable URLs to specific sections

---

## HTML Structure

```php
<!-- admin-views/settings-page.php -->
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper wp-clearfix">
        <a href="#quick-export" class="nav-tab nav-tab-active" data-tab="quick-export">
            <?php esc_html_e( 'Quick Export', 'example-plugin' ); ?>
        </a>
        <a href="#advanced" class="nav-tab" data-tab="advanced">
            <?php esc_html_e( 'Advanced Reporting', 'example-plugin' ); ?>
        </a>
        <a href="#settings" class="nav-tab" data-tab="settings">
            <?php esc_html_e( 'Settings', 'example-plugin' ); ?>
        </a>
        <a href="#status" class="nav-tab" data-tab="status">
            <?php esc_html_e( 'Status & Help', 'example-plugin' ); ?>
        </a>
    </nav>
    
    <!-- Tab Content Panels -->
    <div class="tab-content">
        <div id="quick-export-panel" class="tab-panel active">
            <!-- Quick export content -->
        </div>
        
        <div id="advanced-panel" class="tab-panel" style="display:none;">
            <!-- Advanced reporting content -->
        </div>
        
        <div id="settings-panel" class="tab-panel" style="display:none;">
            <!-- Settings form -->
        </div>
        
        <div id="status-panel" class="tab-panel" style="display:none;">
            <!-- Status & help content -->
        </div>
    </div>
</div>
```

---

## JavaScript Implementation

```javascript
// assets/admin/va-admin.js
document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.nav-tab');
    const panels = document.querySelectorAll('.tab-panel');
    
    // Activate tab from URL hash on page load
    const activeTab = window.location.hash.substring(1) || 'quick-export';
    activateTab(activeTab);
    
    // Tab click handlers
    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const tabName = tab.dataset.tab;
            window.location.hash = tabName;
            activateTab(tabName);
        });
    });
    
    // Handle browser back/forward
    window.addEventListener('hashchange', () => {
        const tabName = window.location.hash.substring(1) || 'quick-export';
        activateTab(tabName);
    });
    
    function activateTab(tabName) {
        // Update nav tabs
        tabs.forEach(t => t.classList.remove('nav-tab-active'));
        document.querySelector(`[data-tab="${tabName}"]`)?.classList.add('nav-tab-active');
        
        // Show/hide panels
        panels.forEach(panel => {
            panel.style.display = 'none';
            panel.classList.remove('active');
        });
        const activePanel = document.getElementById(`${tabName}-panel`);
        if (activePanel) {
            activePanel.style.display = 'block';
            activePanel.classList.add('active');
        }
    }
});
```

---

## CSS Styling

```css
/* assets/admin/va-admin.css */
/* WordPress provides .nav-tab styling, we just need panel styles */

.tab-panel {
    margin-top: 20px;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.tab-panel.active {
    /* Additional active styling if needed */
}
```

---

## Conditional Asset Loading

**Only load admin assets on plugin pages:**

```php
// includes/class-admin-hooks.php
public function enqueue_admin_assets( string $hook_suffix ): void {
    // Only load on our settings page
    if ( 'toplevel_page_example-plugin' !== $hook_suffix ) {
        return;
    }
    
    wp_enqueue_style(
        'example-plugin-admin',
        plugins_url( 'assets/admin/admin-styles.css', dirname( __FILE__ ) ),
        array(),
        $this->version
    );
    
    wp_enqueue_script(
        'example-plugin-admin',
        plugins_url( 'assets/admin/admin-scripts.js', dirname( __FILE__ ) ),
        array(),
        $this->version,
        true
    );
    
    // Localize script if needed
    wp_localize_script(
        'example-plugin-admin',
        'examplePluginAdmin',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'example_plugin_admin' ),
        )
    );
}
```

---

## Best Practices

1. **Default tab:** Use hash or default to most frequently accessed tab
2. **Native styling:** Use WordPress `.nav-tab` classes for consistency
3. **Accessibility:** Ensure keyboard navigation works properly
4. **Performance:** Only load JavaScript/CSS on relevant admin pages
5. **State preservation:** URL hash preserves state across page reloads
6. **Progressive enhancement:** Basic functionality works without JavaScript
