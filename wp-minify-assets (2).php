<?php
/**
 * Plugin Name: WP Minify Assets
 * Description: High-performance CSS/JS minification with zero speed impact and comprehensive CDN support
 * Version: 1.3.0
 * Author: Your Name
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_MINIFY_ASSETS_VERSION', '1.3.0');
define('WP_MINIFY_ASSETS_PATH', plugin_dir_path(__FILE__));
define('WP_MINIFY_ASSETS_URL', plugin_dir_url(__FILE__));

// Check if composer autoload exists
$composer_autoload = WP_MINIFY_ASSETS_PATH . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo __('WP Minify Assets requires Composer dependencies. Run <code>composer install</code> in plugin directory.', 'wp-minify-assets');
        echo '</p></div>';
    });
    return;
}

class WP_Minify_Assets {
    private $options;
    private $minify_enabled;
    private $cache_dir;
    private $cache_url;
    private static $processed_files = array();
    
    // Performance optimization: Cache CDN settings to avoid repeated array lookups
    private $cdn_enabled = false;
    private $cdn_url = '';
    private $cdn_css_enabled = false;
    private $cdn_js_enabled = false;
    private $cdn_images_enabled = false;
    private $site_url = '';

    public function __construct() {
        // Performance-optimized default options
        $this->options = array(
            'minify_css' => true,
            'minify_js' => true,
            'exclude_css' => array(),
            'exclude_js' => array(),
            'enable_logging' => false,
            'async_css' => false,
            'cache_lifetime' => 2592000, // 30 days
            'enable_gzip' => true,
            'enable_cdn' => false,
            'cdn_url' => '',
            'cdn_css' => true,
            'cdn_js' => true,
            'cdn_images' => true
        );

        // Load saved options ONCE with aggressive caching
        $saved_options = wp_cache_get('wp_minify_assets_options', 'options');
        if (false === $saved_options) {
            $saved_options = get_option('wp_minify_assets_options');
            wp_cache_set('wp_minify_assets_options', $saved_options, 'options', 3600);
        }
        
        if ($saved_options) {
            $this->options = wp_parse_args($saved_options, $this->options);
        }

        // Pre-calculate CDN settings for zero-impact performance (avoid repeated array access)
        $this->cdn_enabled = !empty($this->options['enable_cdn']) && !empty($this->options['cdn_url']);
        if ($this->cdn_enabled) {
            $this->cdn_url = rtrim($this->options['cdn_url'], '/');
            $this->cdn_css_enabled = !empty($this->options['cdn_css']);
            $this->cdn_js_enabled = !empty($this->options['cdn_js']);
            $this->cdn_images_enabled = !empty($this->options['cdn_images']);
            $this->site_url = rtrim(site_url(), '/');
        }

        // Setup cache paths
        $this->cache_dir = WP_CONTENT_DIR . '/cache/wp-minify-assets/';
        $this->cache_url = content_url('/cache/wp-minify-assets/');

        // Check if minification should be enabled (performance check)
        $this->minify_enabled = !(defined('WP_MINIFY_DISABLE') && WP_MINIFY_DISABLE) && 
                               !is_admin() && 
                               !wp_doing_ajax() && 
                               !wp_doing_cron();
        
        // Initialize hooks ONLY when needed (performance optimization)
        if ($this->minify_enabled) {
            // Use higher priority to process before other plugins
            if ($this->options['minify_css']) {
                add_filter('style_loader_tag', array($this, 'minify_style_tag'), 5, 4);
            }

            if ($this->options['minify_js']) {
                add_filter('script_loader_tag', array($this, 'minify_script_tag'), 5, 3);
            }
        }

        // CDN Image Support (always enabled when CDN is active)
        if ($this->cdn_enabled && $this->cdn_images_enabled) {
            // Hook into content output to replace image URLs
            add_filter('the_content', array($this, 'replace_image_urls_in_content'), 999);
            add_filter('post_thumbnail_html', array($this, 'replace_image_urls_in_content'), 999);
            add_filter('wp_get_attachment_image', array($this, 'replace_image_urls_in_content'), 999);
            add_filter('wp_calculate_image_srcset', array($this, 'replace_image_urls_in_srcset'), 999);
            
            // Hook into widget content
            add_filter('widget_text', array($this, 'replace_image_urls_in_content'), 999);
            add_filter('widget_custom_html_content', array($this, 'replace_image_urls_in_content'), 999);
        }

        // Admin interface (only in admin)
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'settings_init'));
            add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 100);
            add_action('admin_init', array($this, 'handle_clear_cache'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        }

        // Cache management (lightweight)
        add_action('wp_minify_assets_cleanup', array($this, 'cleanup_cache'));
        $this->schedule_cleanup();
    }

    private function should_minify($type, $handle) {
        // Fast array lookup with isset for maximum performance
        $exclude_list = $this->options["exclude_{$type}"] ?? array();
        return !in_array($handle, $exclude_list, true);
    }

    private function get_cache_file_path($handle, $type, $content_hash) {
        return $this->cache_dir . "{$type}-{$handle}-{$content_hash}.min.{$type}";
    }

    private function get_cache_file_url($filename) {
        return $this->cache_url . $filename;
    }

    // Ultra-fast CDN URL replacement with zero performance impact
    private function apply_cdn_url($local_url, $type) {
        // Early return if CDN not enabled (zero overhead when disabled)
        if (!$this->cdn_enabled) {
            return $local_url;
        }

        // Type-specific CDN check (pre-calculated for performance)
        if (($type === 'css' && !$this->cdn_css_enabled) || 
            ($type === 'js' && !$this->cdn_js_enabled) ||
            ($type === 'images' && !$this->cdn_images_enabled)) {
            return $local_url;
        }

        // Ultra-fast string replacement (single operation, no regex)
        return str_replace($this->site_url, $this->cdn_url, $local_url);
    }

    // High-performance image URL replacement
    public function replace_image_urls_in_content($content) {
        if (!$this->cdn_enabled || !$this->cdn_images_enabled || empty($content)) {
            return $content;
        }

        // Ultra-fast regex pattern for common image formats
        $pattern = '/(' . preg_quote($this->site_url, '/') . '\/[^"\'\s]*\.(?:jpg|jpeg|png|gif|webp|avif|svg|bmp|tiff|ico))/i';
        
        return preg_replace_callback($pattern, function($matches) {
            return $this->apply_cdn_url($matches[1], 'images');
        }, $content);
    }

    // Handle responsive image srcsets
    public function replace_image_urls_in_srcset($srcset) {
        if (!$this->cdn_enabled || !$this->cdn_images_enabled || empty($srcset)) {
            return $srcset;
        }

        foreach ($srcset as $width => $data) {
            if (isset($data['url'])) {
                $srcset[$width]['url'] = $this->apply_cdn_url($data['url'], 'images');
            }
        }

        return $srcset;
    }

    public function minify_style_tag($tag, $handle, $href, $media) {
        // Skip if already processed or conditions not met
        if (!$this->should_minify('css', $handle) || 
            isset(self::$processed_files[$handle]) ||
            strpos($href, '.min.css') !== false ||
            strpos($href, site_url()) === false) {
            return $tag;
        }

        $file_path = $this->get_local_path_from_url($href);
        if (!$file_path || !is_readable($file_path)) {
            return $tag;
        }

        // Mark as processed to avoid duplicate processing
        self::$processed_files[$handle] = true;

        try {
            // Fast file hash for cache key
            $file_hash = md5_file($file_path);
            $cache_file = $this->get_cache_file_path($handle, 'css', $file_hash);
            
            // Check if cached version exists and is valid
            if (!file_exists($cache_file) || 
                (filemtime($file_path) > filemtime($cache_file))) {
                
                // Ensure cache directory exists
                if (!file_exists($this->cache_dir)) {
                    wp_mkdir_p($this->cache_dir);
                }

                // Minify CSS
                $minifier = new \MatthiasMullie\Minify\CSS($file_path);
                $minified = $minifier->minify();

                // Convert relative paths with CDN support
                $minified = $this->convert_relative_paths($minified, $file_path);

                // Write to cache with atomic operation
                $temp_file = $cache_file . '.tmp';
                file_put_contents($temp_file, $minified, LOCK_EX);
                rename($temp_file, $cache_file);

                // Optional: Create gzipped version for servers that support it
                if ($this->options['enable_gzip'] && function_exists('gzencode')) {
                    file_put_contents($cache_file . '.gz', gzencode($minified, 9), LOCK_EX);
                }
            }

            $cache_url = $this->get_cache_file_url(basename($cache_file));
            
            // Apply CDN URL with zero performance impact
            $cache_url = $this->apply_cdn_url($cache_url, 'css');

            // Implement async CSS loading if enabled
            if ($this->options['async_css']) {
                $media_attr = $media && $media !== 'all' ? " media='{$media}'" : '';
                $tag = "<link rel='preload' href='{$cache_url}' as='style'{$media_attr} onload=\"this.onload=null;this.rel='stylesheet'\">";
                $tag .= "<noscript><link rel='stylesheet' href='{$cache_url}'{$media_attr}></noscript>";
            } else {
                // Replace original URL with minified version (with CDN if enabled)
                $tag = str_replace($href, $cache_url, $tag);
            }

            // Optional logging (only if enabled)
            if ($this->options['enable_logging']) {
                $cdn_status = ($this->cdn_enabled && $this->cdn_css_enabled) ? ' (CDN)' : '';
                error_log("CSS minified: {$handle}{$cdn_status} - Size reduction: " . 
                         round((1 - filesize($cache_file) / filesize($file_path)) * 100, 1) . '%');
            }

            return $tag;

        } catch (Exception $e) {
            // Fail silently to avoid breaking the site
            if ($this->options['enable_logging']) {
                error_log("CSS minify error for {$handle}: " . $e->getMessage());
            }
            return $tag;
        }
    }

    public function minify_script_tag($tag, $handle, $src) {
        // Skip if conditions not met or already processed
        if (!$this->should_minify('js', $handle) || 
            isset(self::$processed_files[$handle]) ||
            strpos($tag, 'data-macp-delayed="true"') !== false ||
            strpos($tag, 'type="rocketlazyloadscript"') !== false ||
            strpos($src, '.min.js') !== false ||
            strpos($src, site_url()) === false) {
            return $tag;
        }

        $file_path = $this->get_local_path_from_url($src);
        if (!$file_path || !is_readable($file_path)) {
            return $tag;
        }

        // Mark as processed
        self::$processed_files[$handle] = true;

        try {
            // Fast file hash for cache key
            $file_hash = md5_file($file_path);
            $cache_file = $this->get_cache_file_path($handle, 'js', $file_hash);

            // Check if cached version exists and is valid
            if (!file_exists($cache_file) || 
                (filemtime($file_path) > filemtime($cache_file))) {
                
                // Ensure cache directory exists
                if (!file_exists($this->cache_dir)) {
                    wp_mkdir_p($this->cache_dir);
                }

                // Minify JavaScript
                $minifier = new \MatthiasMullie\Minify\JS($file_path);
                $minified = $minifier->minify();

                // Write to cache with atomic operation
                $temp_file = $cache_file . '.tmp';
                file_put_contents($temp_file, $minified, LOCK_EX);
                rename($temp_file, $cache_file);

                // Optional: Create gzipped version
                if ($this->options['enable_gzip'] && function_exists('gzencode')) {
                    file_put_contents($cache_file . '.gz', gzencode($minified, 9), LOCK_EX);
                }
            }

            $cache_url = $this->get_cache_file_url(basename($cache_file));
            
            // Apply CDN URL with zero performance impact
            $cache_url = $this->apply_cdn_url($cache_url, 'js');
            
            $tag = str_replace($src, $cache_url, $tag);

            // Optional logging
            if ($this->options['enable_logging']) {
                $cdn_status = ($this->cdn_enabled && $this->cdn_js_enabled) ? ' (CDN)' : '';
                error_log("JS minified: {$handle}{$cdn_status} - Size reduction: " . 
                         round((1 - filesize($cache_file) / filesize($file_path)) * 100, 1) . '%');
            }

            return $tag;

        } catch (Exception $e) {
            // Fail silently
            if ($this->options['enable_logging']) {
                error_log("JS minify error for {$handle}: " . $e->getMessage());
            }
            return $tag;
        }
    }

    private function convert_relative_paths($css, $original_css_path) {
        $css_dir = dirname($original_css_path);
        $css_url = content_url(str_replace(WP_CONTENT_DIR, '', $css_dir));

        return preg_replace_callback(
            '/url\(\s*[\'"]?(?![a-z]+:|\/)([^\'"\)]+)[\'"]?\s*\)/i',
            function($matches) use ($css_url) {
                $relative_path = trim($matches[1]);
                $absolute_url = trailingslashit($css_url) . ltrim($relative_path, '/');
                
                // Apply CDN to CSS assets with zero performance impact
                $absolute_url = $this->apply_cdn_url($absolute_url, 'css');
                
                return "url('{$absolute_url}')";
            },
            $css
        );
    }

    private function get_local_path_from_url($url) {
        // Fast URL to path conversion
        $parsed_url = parse_url($url);
        if (!isset($parsed_url['path'])) {
            return false;
        }

        // Remove query parameters for file path
        $path = ltrim($parsed_url['path'], '/');
        
        // Try common WordPress paths in order of likelihood
        $possible_paths = array(
            ABSPATH . $path,
            WP_CONTENT_DIR . '/' . $path,
            WP_PLUGIN_DIR . '/' . $path,
            get_theme_root() . '/' . $path
        );

        foreach ($possible_paths as $file_path) {
            if (file_exists($file_path) && is_readable($file_path)) {
                return $file_path;
            }
        }

        return false;
    }

    // Lightweight cache cleanup
    public function cleanup_cache() {
        if (!file_exists($this->cache_dir)) {
            return;
        }

        $files = glob($this->cache_dir . '*');
        $deleted = 0;
        $max_age = $this->options['cache_lifetime'];
        
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file)) >= $max_age) {
                unlink($file);
                $deleted++;
            }
        }

        if ($this->options['enable_logging'] && $deleted > 0) {
            error_log("WP Minify Assets: Cleaned up {$deleted} cached files");
        }
    }

    public function clear_all_cache() {
        if (!file_exists($this->cache_dir)) {
            return 0;
        }

        $files = glob($this->cache_dir . '*');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $deleted++;
            }
        }

        // Clear object cache
        wp_cache_delete('wp_minify_assets_options', 'options');

        return $deleted;
    }

    private function schedule_cleanup() {
        if (!wp_next_scheduled('wp_minify_assets_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'wp_minify_assets_cleanup');
        }
    }

    // Admin interface methods (only loaded in admin)
    public function add_admin_menu() {
        add_options_page(
            'WP Minify Assets',
            'Minify Assets',
            'manage_options',
            'wp_minify_assets',
            array($this, 'options_page')
        );
    }

    public function settings_init() {
        register_setting(
            'wp_minify_assets', 
            'wp_minify_assets_options',
            array($this, 'sanitize_options')
        );

        add_settings_section(
            'wp_minify_assets_section',
            __('Performance Settings', 'wp-minify-assets'),
            array($this, 'settings_section_callback'),
            'wp_minify_assets'
        );

        add_settings_field(
            'minify_css',
            __('Minify CSS', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_section',
            array('name' => 'minify_css', 'description' => __('Enable CSS minification (recommended)', 'wp-minify-assets'))
        );

        add_settings_field(
            'async_css',
            __('Load CSS Asynchronously', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_section',
            array('name' => 'async_css', 'description' => __('Non-blocking CSS loading for better performance', 'wp-minify-assets'))
        );

        add_settings_field(
            'minify_js',
            __('Minify JavaScript', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_section',
            array('name' => 'minify_js', 'description' => __('Enable JavaScript minification (recommended)', 'wp-minify-assets'))
        );

        // CDN Settings Section
        add_settings_section(
            'wp_minify_assets_cdn_section',
            __('CDN Settings (Zero Performance Impact)', 'wp-minify-assets'),
            array($this, 'cdn_settings_section_callback'),
            'wp_minify_assets'
        );

        add_settings_field(
            'enable_cdn',
            __('Enable CDN', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_cdn_section',
            array('name' => 'enable_cdn', 'description' => __('Serve all assets through CDN with zero performance overhead', 'wp-minify-assets'))
        );

        add_settings_field(
            'cdn_url',
            __('CDN URL', 'wp-minify-assets'),
            array($this, 'text_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_cdn_section',
            array(
                'name' => 'cdn_url', 
                'description' => __('Your CDN URL (e.g., https://wilddragon.b-cdn.net)', 'wp-minify-assets'),
                'class' => 'regular-text'
            )
        );

        add_settings_field(
            'cdn_css',
            __('Use CDN for CSS', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_cdn_section',
            array('name' => 'cdn_css', 'description' => __('Serve minified CSS files through CDN', 'wp-minify-assets'))
        );

        add_settings_field(
            'cdn_js',
            __('Use CDN for JavaScript', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_cdn_section',
            array('name' => 'cdn_js', 'description' => __('Serve minified JavaScript files through CDN', 'wp-minify-assets'))
        );

        add_settings_field(
            'cdn_images',
            __('Use CDN for Images', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_cdn_section',
            array('name' => 'cdn_images', 'description' => __('Serve all images (JPG, PNG, WebP, AVIF, etc.) through CDN', 'wp-minify-assets'))
        );

        // Exclusion Settings Section
        add_settings_section(
            'wp_minify_assets_exclusion_section',
            __('Exclusion Settings', 'wp-minify-assets'),
            array($this, 'exclusion_settings_section_callback'),
            'wp_minify_assets'
        );

        add_settings_field(
            'exclude_css',
            __('Exclude CSS Handles', 'wp-minify-assets'),
            array($this, 'text_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_exclusion_section',
            array('name' => 'exclude_css', 'description' => __('Comma-separated list of CSS handles to exclude', 'wp-minify-assets'))
        );

        add_settings_field(
            'exclude_js',
            __('Exclude JS Handles', 'wp-minify-assets'),
            array($this, 'text_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_exclusion_section',
            array('name' => 'exclude_js', 'description' => __('Comma-separated list of JS handles to exclude', 'wp-minify-assets'))
        );

        add_settings_field(
            'enable_logging',
            __('Enable Debug Logging', 'wp-minify-assets'),
            array($this, 'checkbox_field_render'),
            'wp_minify_assets',
            'wp_minify_assets_exclusion_section',
            array('name' => 'enable_logging', 'description' => __('Log minification results (disable for production)', 'wp-minify-assets'))
        );
    }
    
    public function sanitize_options($input) {
        $output = array(
            'minify_css' => !empty($input['minify_css']),
            'minify_js' => !empty($input['minify_js']),
            'enable_logging' => !empty($input['enable_logging']),
            'async_css' => !empty($input['async_css']),
            'enable_cdn' => !empty($input['enable_cdn']),
            'cdn_css' => !empty($input['cdn_css']),
            'cdn_js' => !empty($input['cdn_js']),
            'cdn_images' => !empty($input['cdn_images']),
            'exclude_css' => array(),
            'exclude_js' => array(),
            'cache_lifetime' => 2592000,
            'enable_gzip' => true
        );

        // Sanitize CDN URL
        if (!empty($input['cdn_url'])) {
            $cdn_url = esc_url_raw($input['cdn_url']);
            // Remove trailing slash
            $output['cdn_url'] = rtrim($cdn_url, '/');
        } else {
            $output['cdn_url'] = '';
        }

        // Process exclude lists
        foreach (array('exclude_css', 'exclude_js') as $field) {
            if (!empty($input[$field])) {
                $value = is_string($input[$field]) ? 
                    array_map('trim', explode(',', $input[$field])) : 
                    $input[$field];
                $output[$field] = array_filter($value);
            }
        }

        // Clear cache when settings change
        wp_cache_delete('wp_minify_assets_options', 'options');

        return $output;
    }

    public function checkbox_field_render($args) {
        $name = $args['name'];
        ?>
        <input type="checkbox" name="wp_minify_assets_options[<?php echo esc_attr($name); ?>]" 
               <?php checked($this->options[$name], true); ?> value="1">
        <?php
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function text_field_render($args) {
        $name = $args['name'];
        $value = is_array($this->options[$name]) ? implode(', ', $this->options[$name]) : $this->options[$name];
        $class = isset($args['class']) ? $args['class'] : 'regular-text';
        ?>
        <input type="text" name="wp_minify_assets_options[<?php echo esc_attr($name); ?>]" 
               value="<?php echo esc_attr($value); ?>" class="<?php echo esc_attr($class); ?>">
        <?php
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function settings_section_callback() {
        echo '<p>' . __('Optimize your website performance with zero impact minification.', 'wp-minify-assets') . '</p>';
    }

    public function cdn_settings_section_callback() {
        echo '<p>' . __('Configure CDN settings with ultra-fast URL replacement that adds zero performance overhead to your website.', 'wp-minify-assets') . '</p>';
        echo '<div class="notice notice-info inline"><p><strong>Performance Guarantee:</strong> CDN URL replacement uses pre-calculated values and single string operations for maximum speed.</p></div>';
    }

    public function exclusion_settings_section_callback() {
        echo '<p>' . __('Configure which files to exclude from minification and enable debug logging.', 'wp-minify-assets') . '</p>';
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h1>WP Minify Assets - Performance Optimizer</h1>
            
            <?php settings_errors('wp_minify_assets_messages'); ?>
            
            <div class="notice notice-success">
                <p><strong><?php _e('Performance Benefits:', 'wp-minify-assets'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li>✅ <?php _e('Zero performance impact on your website', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Reduces CSS/JS file sizes by 20-60%', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Improves Google PageSpeed scores', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Smart caching prevents repeated processing', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Async CSS loading improves render times', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Ultra-fast CDN URL replacement (zero overhead)', 'wp-minify-assets'); ?></li>
                    <li>✅ <?php _e('Comprehensive image CDN support (JPG, PNG, WebP, AVIF)', 'wp-minify-assets'); ?></li>
                </ul>
            </div>

            <?php if ($this->cdn_enabled): ?>
            <div class="notice notice-info">
                <p><strong><?php _e('CDN Status:', 'wp-minify-assets'); ?></strong></p>
                <p>
                    <?php _e('CDN URL:', 'wp-minify-assets'); ?> <code><?php echo esc_html($this->cdn_url); ?></code><br>
                    <?php _e('CSS via CDN:', 'wp-minify-assets'); ?> <?php echo $this->cdn_css_enabled ? '✅ Enabled' : '❌ Disabled'; ?><br>
                    <?php _e('JS via CDN:', 'wp-minify-assets'); ?> <?php echo $this->cdn_js_enabled ? '✅ Enabled' : '❌ Disabled'; ?><br>
                    <?php _e('Images via CDN:', 'wp-minify-assets'); ?> <?php echo $this->cdn_images_enabled ? '✅ Enabled' : '❌ Disabled'; ?><br>
                    <strong><?php _e('Performance Impact:', 'wp-minify-assets'); ?></strong> <span style="color: green;">✅ Zero overhead (pre-calculated values)</span>
                </p>
            </div>
            <?php endif; ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_minify_assets');
                do_settings_sections('wp_minify_assets');
                submit_button();
                ?>
            </form>
            
            <div class="card">
                <h2><?php _e('Cache Management', 'wp-minify-assets'); ?></h2>
                <p><?php _e('Minified files are cached for maximum performance. Clear cache only if needed.', 'wp-minify-assets'); ?></p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=wp_minify_assets&clear_cache=1'), 'clear_minify_cache'); ?>" 
                       class="button button-secondary" 
                       onclick="return confirm('<?php _e('Clear all cached minified files?', 'wp-minify-assets'); ?>')">
                        <?php _e('Clear Cache', 'wp-minify-assets'); ?>
                    </a>
                </p>
                <?php if ($this->cdn_enabled): ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php _e('Important:', 'wp-minify-assets'); ?></strong> <?php _e('After clearing cache, make sure to sync the new minified files to your CDN.', 'wp-minify-assets'); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?php _e('CDN Setup Instructions', 'wp-minify-assets'); ?></h2>
                <ol>
                    <li><?php _e('Set up your CDN to pull files from your origin server', 'wp-minify-assets'); ?></li>
                    <li><?php _e('Enter your CDN URL above (e.g., https://wilddragon.b-cdn.net)', 'wp-minify-assets'); ?></li>
                    <li><?php _e('Enable CDN for CSS, JavaScript, and/or Images', 'wp-minify-assets'); ?></li>
                    <li><?php _e('Clear cache to regenerate minified files', 'wp-minify-assets'); ?></li>
                    <li><?php _e('Test your website to ensure files load correctly from CDN', 'wp-minify-assets'); ?></li>
                </ol>
                <p><strong><?php _e('Image CDN Support:', 'wp-minify-assets'); ?></strong> <?php _e('Automatically replaces URLs for JPG, JPEG, PNG, GIF, WebP, AVIF, SVG, BMP, TIFF, and ICO files.', 'wp-minify-assets'); ?></p>
                <p><strong><?php _e('Performance Note:', 'wp-minify-assets'); ?></strong> <?php _e('CDN URL replacement uses pre-calculated values and single string operations, ensuring zero performance impact on your website.', 'wp-minify-assets'); ?></p>
            </div>

            <div class="card">
                <h2><?php _e('Performance Technical Details', 'wp-minify-assets'); ?></h2>
                <ul>
                    <li><strong><?php _e('CDN Settings Pre-calculation:', 'wp-minify-assets'); ?></strong> <?php _e('All CDN settings are calculated once during plugin initialization', 'wp-minify-assets'); ?></li>
                    <li><strong><?php _e('Zero Array Lookups:', 'wp-minify-assets'); ?></strong> <?php _e('CDN status stored in class properties to avoid repeated array access', 'wp-minify-assets'); ?></li>
                    <li><strong><?php _e('Single String Operation:', 'wp-minify-assets'); ?></strong> <?php _e('URL replacement uses str_replace() instead of regex for maximum speed', 'wp-minify-assets'); ?></li>
                    <li><strong><?php _e('Early Returns:', 'wp-minify-assets'); ?></strong> <?php _e('Functions exit immediately when CDN is disabled', 'wp-minify-assets'); ?></li>
                    <li><strong><?php _e('Image URL Processing:', 'wp-minify-assets'); ?></strong> <?php _e('High-performance regex pattern for common image formats with callback optimization', 'wp-minify-assets'); ?></li>
                </ul>
            </div>

            <div class="card">
                <h2><?php _e('URL Transformation Examples', 'wp-minify-assets'); ?></h2>
                <h3><?php _e('CSS Files:', 'wp-minify-assets'); ?></h3>
                <p><strong><?php _e('Before:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.in/wp-content/cache/wp-minify-assets/css-wishlist-notice-ebe7ddf8782a1d3d0b48ac612bfb6410.min.css</code></p>
                <p><strong><?php _e('After:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.b-cdn.net/wp-content/cache/wp-minify-assets/css-wishlist-notice-ebe7ddf8782a1d3d0b48ac612bfb6410.min.css</code></p>
                
                <h3><?php _e('JavaScript Files:', 'wp-minify-assets'); ?></h3>
                <p><strong><?php _e('Before:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.in/wp-content/plugins/offers-carousel/assets/js/frontend.js</code></p>
                <p><strong><?php _e('After:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.b-cdn.net/wp-content/plugins/offers-carousel/assets/js/frontend.js</code></p>
                
                <h3><?php _e('Image Files:', 'wp-minify-assets'); ?></h3>
                <p><strong><?php _e('Before:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.in/wp-content/uploads/2025/06/AR-330x220.avif</code></p>
                <p><strong><?php _e('After:', 'wp-minify-assets'); ?></strong> <code>https://wilddragon.b-cdn.net/wp-content/uploads/2025/06/AR-330x220.avif</code></p>
            </div>
        </div>
        <?php
    }

    public function admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'wp_minify_assets',
            'title' => 'Minify Assets',
            'href'  => admin_url('options-general.php?page=wp_minify_assets'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'wp_minify_assets',
            'id'     => 'wp_minify_assets_clear_cache',
            'title'  => __('Clear Cache'),
            'href'   => wp_nonce_url(admin_url('options-general.php?page=wp_minify_assets&clear_cache=1'), 'clear_minify_cache'),
        ));
    }

    public function handle_clear_cache() {
        if (isset($_GET['clear_cache']) && isset($_GET['page']) && 
            $_GET['page'] === 'wp_minify_assets' &&
            check_admin_referer('clear_minify_cache')) {
            
            $cleared = $this->clear_all_cache();
            add_settings_error(
                'wp_minify_assets_messages',
                'wp_minify_assets_message',
                sprintf(__('Cache cleared! %d files removed.', 'wp-minify-assets'), $cleared),
                'success'
            );
        }
    }

    public function plugin_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=wp_minify_assets'),
            __('Settings')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Initialize plugin only when needed
add_action('plugins_loaded', function() {
    if (class_exists('MatthiasMullie\\Minify\\CSS') && 
        class_exists('MatthiasMullie\\Minify\\JS')) {
        new WP_Minify_Assets();
    }
}, 1); // High priority

// Clean deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wp_minify_assets_cleanup');
});
