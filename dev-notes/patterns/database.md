# Database Patterns for WordPress Plugins

**Purpose:** Custom table creation, migrations, and versioning  
**Last Updated:** 10 January 2026

---

## Custom Tables

### When to use:
- Complex data structures
- High-performance requirements
- Data not fitting WordPress core tables

---

## Migration Pattern

```php
class Database {
    private const DB_VERSION = 1;
    
    public function create_tables(): bool {
        global $wpdb;
        
        $table_name      = $wpdb->prefix . 'plugin_data';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            data_value varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        update_option( 'example_plugin_db_version', self::DB_VERSION );
        
        return true;
    }
}
```

---

## Version Tracking

```php
function check_database_version(): void {
    $current_version = get_option( 'example_plugin_db_version', 0 );
    
    if ( $current_version < self::DB_VERSION ) {
        $this->run_migrations( $current_version );
    }
}
```
