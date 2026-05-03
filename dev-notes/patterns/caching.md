# Caching Patterns for WordPress Plugins

**Purpose:** WordPress Transients API patterns for performance optimization  
**Last Updated:** 10 January 2026

---

## Basic Caching Pattern

Use WordPress Transients API for caching to improve performance:

```php
/**
 * Get user's websites with caching.
 *
 * @param int $user_id User ID.
 * @return array Website list.
 */
function get_user_websites( int $user_id ): array {
    $cache_key = 'example_plugin_websites_' . $user_id;
    $websites  = get_transient( $cache_key );
    
    if ( false !== $websites ) {
        return $websites;
    }
    
    // Fetch from API or database
    $websites = fetch_websites_from_api( $user_id );
    
    // Cache for 5 minutes
    set_transient( $cache_key, $websites, 5 * MINUTE_IN_SECONDS );
    
    return $websites;
}

/**
 * Invalidate cache when data changes.
 *
 * @param int $user_id User ID.
 */
function invalidate_websites_cache( int $user_id ): void {
    delete_transient( 'example_plugin_websites_' . $user_id );
}
```

---

## Best Practices

1. **Use descriptive cache keys** with unique identifiers (user ID, post ID, etc.)
2. **Set appropriate expiration times** (use WordPress time constants)
3. **Invalidate cache** when underlying data changes
4. **Consider object caching** for high-traffic sites (but don't rely on it)
5. **Avoid caching user-specific data** without user ID in key

---

## WordPress Time Constants

```php
MINUTE_IN_SECONDS  // 60
HOUR_IN_SECONDS    // 3600
DAY_IN_SECONDS     // 86400
WEEK_IN_SECONDS    // 604800
MONTH_IN_SECONDS   // 2592000
YEAR_IN_SECONDS    // 31536000
```

---

## Rate Limiting for Public Endpoints

```php
public function ajax_public_endpoint(): void {
    $ip_address   = $_SERVER['REMOTE_ADDR'] ?? '';
    $throttle_key = 'plugin_throttle_' . md5( $ip_address );
    $count        = get_transient( $throttle_key );
    
    if ( false !== $count && $count > 20 ) {
        wp_send_json_error( array( 'message' => __( 'Rate limit exceeded.', 'example-plugin' ) ) );
    }
    
    set_transient( $throttle_key, ( $count ? $count + 1 : 1 ), MINUTE_IN_SECONDS );
    
    // Process request...
}
```
