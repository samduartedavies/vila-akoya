
<?php
if (!defined('ABSPATH')) { exit; }

function vila_akoya_handle_enquiry_form() {
    if (!isset($_POST['vila_akoya_nonce']) || !wp_verify_nonce($_POST['vila_akoya_nonce'], 'vila_akoya_form')) {
        wp_safe_redirect(wp_get_referer());
        exit;
    }
    $name = sanitize_text_field($_POST['name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $interest = sanitize_text_field($_POST['interest'] ?? '');
    $dates = sanitize_text_field($_POST['dates'] ?? '');
    $people = sanitize_text_field($_POST['people'] ?? '');
    $message = sanitize_textarea_field($_POST['message'] ?? '');
    $subject = 'Vila Akoya enquiry: ' . ($interest ?: 'General');
    $to = get_option('admin_email');
    $body = "Name: {$name}
Email: {$email}
Phone: {$phone}
Interest: {$interest}
Preferred dates: {$dates}
People: {$people}

Message:
{$message}";
    $headers = [];
    if ($email) $headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
    wp_mail($to, $subject, $body, $headers);
    $url = add_query_arg('enquiry', 'sent', wp_get_referer() ?: home_url('/booking/'));
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_nopriv_vila_akoya_enquiry', 'vila_akoya_handle_enquiry_form');
add_action('admin_post_vila_akoya_enquiry', 'vila_akoya_handle_enquiry_form');

function vila_akoya_render_enquiry_form($compact = false) {
    $action = esc_url(admin_url('admin-post.php'));
    $success = isset($_GET['enquiry']) && $_GET['enquiry'] === 'sent';
    ?>
    <div class="form-shell<?php echo $compact ? ' compact' : ''; ?>">
      <?php if ($success): ?><div class="form-success">Thanks. Your enquiry has been sent.</div><?php endif; ?>
      <form method="post" action="<?php echo $action; ?>" class="akoya-form">
        <input type="hidden" name="action" value="vila_akoya_enquiry">
        <?php wp_nonce_field('vila_akoya_form', 'vila_akoya_nonce'); ?>
        <div class="form-grid">
          <label><span>Name</span><input type="text" name="name" required></label>
          <label><span>Email</span><input type="email" name="email" required></label>
          <label><span>Phone / WhatsApp</span><input type="text" name="phone"></label>
          <label><span>Interested in</span>
            <select name="interest">
              <option>Campsite</option>
              <option>Village</option>
              <option>Workshop</option>
              <option>Forest School</option>
              <option>General</option>
            </select>
          </label>
          <label><span>Preferred dates</span><input type="text" name="dates" placeholder="e.g. 12–18 June"></label>
          <label><span>Number of people</span><input type="text" name="people"></label>
          <label class="full"><span>Message</span><textarea name="message" rows="5" placeholder="Tell us what you're interested in."></textarea></label>
        </div>
        <button class="ak-button button-primary" type="submit">Send enquiry</button>
      </form>
    </div>
    <?php
}
