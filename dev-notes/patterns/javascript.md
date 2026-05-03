# JavaScript Patterns for WordPress Plugins

**Purpose:** Modern JavaScript patterns for WordPress plugin development  
**Last Updated:** 10 January 2026

---

## Class-Based Selectors

Never use IDs for JavaScript selectors (except for unique admin elements):

```javascript
// Good - reusable, multiple instances safe
document.querySelector('.plugin-calendar');
document.querySelectorAll('.plugin-item');

// Avoid - unless truly unique admin element
document.getElementById('unique-admin-element');
```

---

## Container-Scoped Initialization

```javascript
class PluginWidget {
    constructor(container) {
        this.container = container;
        this.init();
    }
    
    init() {
        // Use delegated events for dynamic content
        this.container.addEventListener('click', (e) => {
            if (e.target.matches('.plugin-button')) {
                this.handleClick(e);
            }
        });
    }
}

// Initialize all instances
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.plugin-widget').forEach(container => {
        new PluginWidget(container);
    });
});
```

---

## AJAX in JavaScript

```javascript
async function sendRequest(action, data) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', pluginData.nonce);
    
    for (const [key, value] of Object.entries(data)) {
        formData.append(key, value);
    }
    
    try {
        const response = await fetch(pluginData.ajaxUrl, {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    } catch (error) {
        console.error('Request failed:', error);
        throw error;
    }
}
```

---

## No Inline JavaScript

**Rule:** Never include inline JavaScript in template files.

```php
<!-- ❌ Bad - inline JavaScript -->
<div id="my-element"></div>
<script>
jQuery(document).ready(function($) {
    $('#my-element').on('click', function() { /* handler */ });
});
</script>

<!-- ✅ Good - all JS in separate files -->
<div class="my-widget"></div>
<!-- JavaScript loaded via wp_enqueue_script() from assets/ directory -->
```

**Why:**
- Cleaner templates, better caching
- Easier debugging and testing
- Follows WordPress best practices
- Separates concerns (HTML vs JS)

---

## Button Elements Must Include `button` Class

**Rule:** All `<button>` elements in templates and JavaScript must include the `button` CSS class.

```php
<!-- ❌ Bad - missing button class -->
<button type="button" class="plugin-action">Click Me</button>

<!-- ✅ Good - includes button class -->
<button type="button" class="plugin-action button">Click Me</button>
```

```javascript
// ❌ Bad - missing button class
html += `<button type="button" class="plugin-slot" data-id="${id}">${label}</button>`;

// ✅ Good - includes button class
html += `<button type="button" class="plugin-slot button" data-id="${id}">${label}</button>`;
```

**Why:**
- Ensures consistent WordPress button styling across the plugin
- Leverages WordPress core button styles automatically
- Maintains visual consistency with WordPress admin and themes
