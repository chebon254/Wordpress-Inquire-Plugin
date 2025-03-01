<?php
/**
 * Plugin Name: Corporate Gift Inquiry System
 * Description: Replaces buy buttons with inquiry buttons for the corporate-gifts category and handles inquiries
 * Version: 1.2
 * Author: Kelvin Chebon
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Main plugin class
class Corporate_Gift_Inquiry {

    public function __construct() {
        // Initialize plugin
        add_action('init', array($this, 'init'));
    }

    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Replace buy button with inquiry button for corporate-gifts category
        add_action('woocommerce_before_single_product', array($this, 'modify_buttons_on_product_page'));
        
        // We're removing this action since we only want inquiry buttons on product detail pages
        // add_action('woocommerce_after_shop_loop_item', array($this, 'modify_buttons_in_loop'), 9);
        
        // Completely remove Add to Cart buttons for corporate gift products
        add_filter('woocommerce_is_purchasable', array($this, 'make_corporate_gifts_not_purchasable'), 10, 2);

        // Add inquiry form modal to footer
        add_action('wp_footer', array($this, 'add_inquiry_modal'));

        // Handle AJAX submission
        add_action('wp_ajax_submit_gift_inquiry', array($this, 'handle_inquiry_submission'));
        add_action('wp_ajax_nopriv_submit_gift_inquiry', array($this, 'handle_inquiry_submission'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add admin menu for inquiries
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register post type for inquiries
        $this->register_inquiry_post_type();
        
        // Add inquiry count to admin menu
        add_action('admin_menu', array($this, 'add_inquiry_bubble_count'));
        
        // Add meta boxes for inquiry details
        add_action('add_meta_boxes', array($this, 'add_inquiry_meta_boxes'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Make corporate gifts not purchasable
     */
    public function make_corporate_gifts_not_purchasable($purchasable, $product) {
        if ($this->is_corporate_gift($product->get_id())) {
            return false;
        }
        return $purchasable;
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Corporate Gift Inquiry System requires WooCommerce to be installed and active.', 'corporate-gift-inquiry'); ?></p>
        </div>
        <?php
    }

    /**
     * Register custom post type for inquiries
     */
    public function register_inquiry_post_type() {
        register_post_type('gift_inquiry', array(
            'labels' => array(
                'name' => 'Gift Inquiries',
                'singular_name' => 'Gift Inquiry',
                'menu_name' => 'Gift Inquiries',
                'all_items' => 'All Inquiries',
                'view_item' => 'View Inquiry',
                'search_items' => 'Search Inquiries',
                'not_found' => 'No inquiries found',
            ),
            'public' => false,
            'show_ui' => true,
            'capability_type' => 'post',
            'capabilities' => array(
                'create_posts' => false,
            ),
            'map_meta_cap' => true,
            'supports' => array('title'),
            'menu_icon' => 'dashicons-email',
        ));
    }

    /**
     * Modify buttons on single product page
     */
    public function modify_buttons_on_product_page() {
        global $product;

        // Check if product belongs to corporate-gifts category
        if ($this->is_corporate_gift($product->get_id())) {
            // Remove add to cart button and price
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
            
            // Add inquiry button instead
            add_action('woocommerce_single_product_summary', array($this, 'add_inquiry_button'), 30);
        }
    }

    /**
     * Check if product belongs to corporate-gifts category
     */
    public function is_corporate_gift($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ($term->slug === 'corporate-gifts') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Add inquiry button
     */
    public function add_inquiry_button() {
        global $product;
        
        $product_id = $product->get_id();
        $product_name = $product->get_name();
        $product_image = wp_get_attachment_url($product->get_image_id());
        
        echo '<button type="button" class="button alt inquire-button" 
            data-product-id="' . esc_attr($product_id) . '" 
            data-product-name="' . esc_attr($product_name) . '" 
            data-modal-product-image="' . esc_attr($product_image) . '"
            data-product-description="' . esc_attr(wp_strip_all_tags($product->get_short_description())) . '">
            Inquire About This Gift
        </button>';
    }

    /**
     * Add inquiry modal to footer
     */
    public function add_inquiry_modal() {
        ?>
        <div id="gift-inquiry-modal" style="display:none;">
            <div class="modal-content">
                <span class="close-modal">&times;</span>
                <h2>Gift Inquiry</h2>
                
                <div class="product-details">
                    <div class="modal-product-image">
                        <img src="" id="inquiry-modal-product-image" alt="Gift Image">
                    </div>
                    <div class="product-info">
                        <h3 id="inquiry-product-name"></h3>
                        <p id="inquiry-product-description"></p>
                    </div>
                </div>
                
                <form id="gift-inquiry-form">
                    <input type="hidden" id="inquiry-product-id" name="product_id">
                    
                    <div class="form-row">
                        <label for="inquiry-name">Your Name *</label>
                        <input type="text" id="inquiry-name" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="inquiry-email">Your Email *</label>
                        <input type="email" id="inquiry-email" name="email" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="inquiry-phone">Phone Number</label>
                        <input type="tel" id="inquiry-phone" name="phone">
                    </div>
                    
                    <div class="form-row">
                        <label for="inquiry-message">Message</label>
                        <textarea id="inquiry-message" name="message" rows="4"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <button type="submit" class="submit-inquiry">Submit Inquiry</button>
                    </div>
                    
                    <div id="inquiry-message-response"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Enqueue styles
        wp_enqueue_style(
            'gift-inquiry-styles', 
            plugins_url('css/gift-inquiry.css', __FILE__)
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'gift-inquiry-scripts',
            plugins_url('js/gift-inquiry.js', __FILE__),
            array('jquery'),
            '1.2',
            true
        );
        
        // Add AJAX URL for script
        wp_localize_script('gift-inquiry-scripts', 'gift_inquiry_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gift_inquiry_nonce'),
        ));
    }

    /**
     * Handle inquiry submission
     */
    public function handle_inquiry_submission() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gift_inquiry_nonce')) {
            wp_send_json_error('Security check failed');
            exit;
        }
        
        // Collect form data
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        // Validate required fields
        if (empty($product_id) || empty($name) || empty($email)) {
            wp_send_json_error('Required fields are missing');
            exit;
        }
        
        // Get product details
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
            exit;
        }
        
        $product_name = $product->get_name();
        $product_url = get_permalink($product_id);
        
        // Create inquiry post
        $inquiry_data = array(
            'post_title' => 'Inquiry from ' . $name . ' about ' . $product_name,
            'post_type' => 'gift_inquiry',
            'post_status' => 'publish',
        );
        
        $inquiry_id = wp_insert_post($inquiry_data);
        
        if ($inquiry_id) {
            // Save inquiry meta
            update_post_meta($inquiry_id, '_product_id', $product_id);
            update_post_meta($inquiry_id, '_product_name', $product_name);
            update_post_meta($inquiry_id, '_customer_name', $name);
            update_post_meta($inquiry_id, '_customer_email', $email);
            update_post_meta($inquiry_id, '_customer_phone', $phone);
            update_post_meta($inquiry_id, '_message', $message);
            update_post_meta($inquiry_id, '_inquiry_date', current_time('mysql'));
            update_post_meta($inquiry_id, '_status', 'new'); // Add status for tracking
            
            // Get notification email from settings or use admin email as fallback
            $notification_email = get_option('gift_inquiry_notification_email', get_option('admin_email'));
            
            // Prepare email content
            $subject = 'New Gift Inquiry: ' . $product_name;
            
            $email_body = "
                <h2>New Gift Inquiry</h2>
                <p><strong>Product:</strong> {$product_name}</p>
                <p><strong>Product URL:</strong> <a href='{$product_url}'>{$product_url}</a></p>
                <p><strong>Customer Name:</strong> {$name}</p>
                <p><strong>Email:</strong> {$email}</p>
                <p><strong>Phone:</strong> {$phone}</p>
                <p><strong>Message:</strong> {$message}</p>
            ";
            
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            // Send email
            $email_sent = wp_mail($notification_email, $subject, $email_body, $headers);
            
            // Get successful message from settings or use default
            $success_message = get_option('gift_inquiry_success_message', 'Your inquiry has been submitted. We will contact you shortly.');
            
            if ($email_sent) {
                wp_send_json_success($success_message);
            } else {
                // Even if email fails, the inquiry is saved in the dashboard
                wp_send_json_success($success_message);
            }
        } else {
            wp_send_json_error('Could not save your inquiry. Please try again.');
        }
        
        exit;
    }
    
    /**
     * Register settings for the plugin
     */
    public function register_settings() {
        // Register settings
        register_setting('gift_inquiry_options', 'gift_inquiry_notification_email');
        register_setting('gift_inquiry_options', 'gift_inquiry_success_message');
        register_setting('gift_inquiry_options', 'gift_inquiry_button_text');
        register_setting('gift_inquiry_options', 'gift_inquiry_button_bg_color');
        register_setting('gift_inquiry_options', 'gift_inquiry_button_text_color');
        
        // Add settings section
        add_settings_section(
            'gift_inquiry_general_section',
            'General Settings',
            array($this, 'general_settings_section_callback'),
            'gift_inquiry_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'gift_inquiry_notification_email',
            'Notification Email',
            array($this, 'notification_email_callback'),
            'gift_inquiry_settings',
            'gift_inquiry_general_section'
        );
        
        add_settings_field(
            'gift_inquiry_success_message',
            'Success Message',
            array($this, 'success_message_callback'),
            'gift_inquiry_settings',
            'gift_inquiry_general_section'
        );
        
        add_settings_field(
            'gift_inquiry_button_text',
            'Button Text',
            array($this, 'button_text_callback'),
            'gift_inquiry_settings',
            'gift_inquiry_general_section'
        );
        
        add_settings_field(
            'gift_inquiry_button_bg_color',
            'Button Background Color',
            array($this, 'button_bg_color_callback'),
            'gift_inquiry_settings',
            'gift_inquiry_general_section'
        );
        
        add_settings_field(
            'gift_inquiry_button_text_color',
            'Button Text Color',
            array($this, 'button_text_color_callback'),
            'gift_inquiry_settings',
            'gift_inquiry_general_section'
        );
    }
    
    /**
     * Section callback function
     */
    public function general_settings_section_callback() {
        echo '<p>Configure your corporate gift inquiry system settings below.</p>';
    }
    
    /**
     * Notification email field callback
     */
    public function notification_email_callback() {
        $email = get_option('gift_inquiry_notification_email', get_option('admin_email'));
        echo '<input type="email" name="gift_inquiry_notification_email" value="' . esc_attr($email) . '" class="regular-text" />';
        echo '<p class="description">Email address where inquiry notifications will be sent.</p>';
    }
    
    /**
     * Success message field callback
     */
    public function success_message_callback() {
        $message = get_option('gift_inquiry_success_message', 'Your inquiry has been submitted. We will contact you shortly.');
        echo '<textarea name="gift_inquiry_success_message" rows="3" class="large-text">' . esc_textarea($message) . '</textarea>';
        echo '<p class="description">Message displayed to customers after successful inquiry submission.</p>';
    }
    
    /**
     * Button text field callback
     */
    public function button_text_callback() {
        $text = get_option('gift_inquiry_button_text', 'Inquire About This Gift');
        echo '<input type="text" name="gift_inquiry_button_text" value="' . esc_attr($text) . '" class="regular-text" />';
    }
    
    /**
     * Button background color field callback
     */
    public function button_bg_color_callback() {
        $color = get_option('gift_inquiry_button_bg_color', '#000000');
        echo '<input type="color" name="gift_inquiry_button_bg_color" value="' . esc_attr($color) . '" />';
    }
    
    /**
     * Button text color field callback
     */
    public function button_text_color_callback() {
        $color = get_option('gift_inquiry_button_text_color', '#ffffff');
        echo '<input type="color" name="gift_inquiry_button_text_color" value="' . esc_attr($color) . '" />';
    }
    
    /**
     * Add admin menu for viewing inquiries
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=gift_inquiry',
            'Gift Inquiry Settings',
            'Settings',
            'manage_options',
            'gift-inquiry-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Add notification bubble with unread inquiry count
     */
    public function add_inquiry_bubble_count() {
        global $menu;
        
        // Count new inquiries
        $count = $this->get_new_inquiry_count();
        
        // Find the gift inquiry menu item and add the count
        foreach ($menu as $key => $value) {
            if (isset($value[2]) && $value[2] == 'edit.php?post_type=gift_inquiry') {
                $menu[$key][0] .= $count ? " <span class='update-plugins count-{$count}'><span class='update-count'>" . number_format_i18n($count) . '</span></span>' : '';
                break;
            }
        }
    }
    
    /**
     * Get count of new inquiries
     */
    public function get_new_inquiry_count() {
        $args = array(
            'post_type' => 'gift_inquiry',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_status',
                    'value' => 'new'
                )
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
        );
        
        $query = new WP_Query($args);
        return $query->post_count;
    }
    
    /**
     * Add meta boxes to display inquiry details
     */
    public function add_inquiry_meta_boxes() {
        add_meta_box(
            'gift_inquiry_details',
            'Inquiry Details',
            array($this, 'render_inquiry_details_meta_box'),
            'gift_inquiry',
            'normal',
            'high'
        );
        
        add_meta_box(
            'gift_inquiry_status',
            'Inquiry Status',
            array($this, 'render_inquiry_status_meta_box'),
            'gift_inquiry',
            'side',
            'high'
        );
    }
    
    /**
     * Render inquiry details meta box
     */
    public function render_inquiry_details_meta_box($post) {
        // Get inquiry meta
        $product_id = get_post_meta($post->ID, '_product_id', true);
        $product_name = get_post_meta($post->ID, '_product_name', true);
        $customer_name = get_post_meta($post->ID, '_customer_name', true);
        $customer_email = get_post_meta($post->ID, '_customer_email', true);
        $customer_phone = get_post_meta($post->ID, '_customer_phone', true);
        $message = get_post_meta($post->ID, '_message', true);
        $inquiry_date = get_post_meta($post->ID, '_inquiry_date', true);
        
        // Get product image
        $product_image = '';
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_image = wp_get_attachment_url($product->get_image_id());
                $product_url = get_permalink($product_id);
            }
        }
        
        ?>
        <style>
            .inquiry-details-grid {
                display: grid;
                grid-template-columns: 1fr 2fr;
                grid-gap: 20px;
            }
            .inquiry-modal-product-image {
                max-width: 100%;
                height: auto;
            }
            .inquiry-meta-item {
                margin-bottom: 10px;
            }
            .inquiry-meta-label {
                font-weight: bold;
            }
            .inquiry-message {
                margin-top: 20px;
            }
        </style>
        
        <div class="inquiry-details-grid">
            <div class="inquiry-product">
                <h3>Product Information</h3>
                <?php if (!empty($product_image)) : ?>
                    <img src="<?php echo esc_url($product_image); ?>" class="inquiry-modal-product-image" />
                <?php endif; ?>
                
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Product Name:</div>
                    <div><?php echo esc_html($product_name); ?></div>
                </div>
                
                <?php if (!empty($product_url)) : ?>
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Product URL:</div>
                    <div><a href="<?php echo esc_url($product_url); ?>" target="_blank">View Product</a></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="inquiry-customer">
                <h3>Customer Information</h3>
                
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Date:</div>
                    <div><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($inquiry_date)); ?></div>
                </div>
                
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Name:</div>
                    <div><?php echo esc_html($customer_name); ?></div>
                </div>
                
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Email:</div>
                    <div><a href="mailto:<?php echo esc_attr($customer_email); ?>"><?php echo esc_html($customer_email); ?></a></div>
                </div>
                
                <?php if (!empty($customer_phone)) : ?>
                <div class="inquiry-meta-item">
                    <div class="inquiry-meta-label">Phone:</div>
                    <div><?php echo esc_html($customer_phone); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($message)) : ?>
                <div class="inquiry-message">
                    <div class="inquiry-meta-label">Message:</div>
                    <div><?php echo wpautop(esc_html($message)); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        
        // Mark as read when viewed
        update_post_meta($post->ID, '_status', 'read');
    }
    
    /**
     * Render inquiry status meta box
     */
    public function render_inquiry_status_meta_box($post) {
        $status = get_post_meta($post->ID, '_status', true);
        if (empty($status)) {
            $status = 'new';
        }
        
        wp_nonce_field('save_inquiry_status', 'inquiry_status_nonce');
        ?>
        <select name="inquiry_status" id="inquiry_status">
            <option value="new" <?php selected($status, 'new'); ?>>New</option>
            <option value="read" <?php selected($status, 'read'); ?>>Read</option>
            <option value="in-progress" <?php selected($status, 'in-progress'); ?>>In Progress</option>
            <option value="completed" <?php selected($status, 'completed'); ?>>Completed</option>
        </select>
        
        <div style="margin-top: 10px;">
            <a href="mailto:<?php echo esc_attr(get_post_meta($post->ID, '_customer_email', true)); ?>" class="button">
                Reply by Email
            </a>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('gift_inquiry_options');
                do_settings_sections('gift_inquiry_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Create directory for CSS and JS files
function create_plugin_files() {
    // Create CSS directory and file
    if (!file_exists(plugin_dir_path(__FILE__) . 'css')) {
        mkdir(plugin_dir_path(__FILE__) . 'css', 0755);
    }
    
    // Get button styling options from settings or use defaults
    $button_bg_color = get_option('gift_inquiry_button_bg_color', '#000000');
    $button_text_color = get_option('gift_inquiry_button_text_color', '#ffffff');
    
    $css_content = "
    #gift-inquiry-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 800px;
        border-radius: 5px;
    }
    
    .close-modal {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close-modal:hover,
    .close-modal:focus {
        color: black;
        text-decoration: none;
    }
    
    .product-details {
        display: flex;
        margin-bottom: 20px;
    }
    
    .modal-product-image {
        flex: 0 0 30%;
        max-width: 200px;
        margin-right: 20px;
    }
    
    .modal-product-image img {
        max-width: 100%;
        height: auto;
    }
    
    .product-info {
        flex: 1;
    }
    
    .form-row {
        margin-bottom: 15px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    
    .form-row input,
    .form-row textarea {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .submit-inquiry {
        background-color: #000000 !important;
        color: #ffffff !important;
        padding: 10px 20px;
        border: 2px solid #000000 !important;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .submit-inquiry:hover {
        background-color: transparent !important;
        color: #000000 !important;
    }
    
    #inquiry-message-response {
        margin-top: 15px;
        padding: 10px;
        display: none;
    }
    
    .inquire-button {
        background-color: #000000 !important;
        color: #ffffff !important;
        display: inline-block;
        text-align: center;
        padding: 12px 20px;
        margin-top: 10px;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 14px;
        border-radius: 4px;
        cursor: pointer;
        border: 2px solid #000000 !important;
    }
    
    .inquire-button:hover {
        background-color: #ffffff !important;
        color: #000000 !important;
    }
    ";
    
    file_put_contents(plugin_dir_path(__FILE__) . 'css/gift-inquiry.css', $css_content);
    
    // Create JS directory and file
    if (!file_exists(plugin_dir_path(__FILE__) . 'js')) {
        mkdir(plugin_dir_path(__FILE__) . 'js', 0755);
    }
    
    $js_content = "
    jQuery(document).ready(function($) {
        // Open modal when inquiry button is clicked
        $(document).on('click', '.inquire-button', function() {
            var productId = $(this).data('product-id');
            var productName = $(this).data('product-name');
            var productImage = $(this).data('modal-product-image');
            var productDescription = $(this).data('product-description');
            
            $('#inquiry-product-id').val(productId);
            $('#inquiry-product-name').text(productName);
            $('#inquiry-modal-product-image').attr('src', productImage);
            $('#inquiry-product-description').text(productDescription);
            
            $('#gift-inquiry-modal').fadeIn();
        });
        
        // Close modal when X is clicked
        $('.close-modal').click(function() {
            $('#gift-inquiry-modal').fadeOut();
        });
        
        // Close modal when clicking outside of it
        $(window).click(function(event) {
            if ($(event.target).is('#gift-inquiry-modal')) {
                $('#gift-inquiry-modal').fadeOut();
            }
        });
        
        // Handle form submission
        $('#gift-inquiry-form').submit(function(event) {
            event.preventDefault();
            
            var formData = $(this).serialize();
            formData += '&action=submit_gift_inquiry&nonce=' + gift_inquiry_ajax.nonce;
            
            $.ajax({
                url: gift_inquiry_ajax.ajax_url,
                type: 'post',
                data: formData,
                beforeSend: function() {
                    $('.submit-inquiry').prop('disabled', true).text('Submitting...');
                },
                success: function(response) {
                    if (response.success) {
                        $('#inquiry-message-response')
                            .html('<div style=\"color:green;\">' + response.data + '</div>')
                            .show();
                        $('#gift-inquiry-form')[0].reset();
                        setTimeout(function() {
                            $('#gift-inquiry-modal').fadeOut();
                            $('#inquiry-message-response').hide();
                        }, 3000);
                    } else {
                        $('#inquiry-message-response')
                            .html('<div style=\"color:red;\">' + response.data + '</div>')
                            .show();
                    }
                },
                error: function() {
                    $('#inquiry-message-response')
                        .html('<div style=\"color:red;\">An error occurred. Please try again.</div>')
                        .show();
                },
                complete: function() {
                    $('.submit-inquiry').prop('disabled', false).text('Submit Inquiry');
                }
            });
        });
    });
    ";
    
    file_put_contents(plugin_dir_path(__FILE__) . 'js/gift-inquiry.js', $js_content);
}

// Add hooks to save inquiry status
function save_inquiry_status($post_id) {
    // Check if this is an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Check if the correct nonce was provided
    if (!isset($_POST['inquiry_status_nonce']) || !wp_verify_nonce($_POST['inquiry_status_nonce'], 'save_inquiry_status')) {
        return;
    }
    
    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Save the status
    if (isset($_POST['inquiry_status'])) {
        update_post_meta($post_id, '_status', sanitize_text_field($_POST['inquiry_status']));
    }
}
add_action('save_post_gift_inquiry', 'save_inquiry_status');

// Initialize the plugin
function run_corporate_gift_inquiry() {
    // Create CSS and JS files
    create_plugin_files();
    
    // Instantiate the main plugin class
    $plugin = new Corporate_Gift_Inquiry();
}

run_corporate_gift_inquiry();
