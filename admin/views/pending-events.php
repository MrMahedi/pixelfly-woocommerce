<?php

/**
 * PixelFly Pending Events Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$stats = PixelFly_Delayed::get_stats();
$pending_events = PixelFly_Delayed::get_pending_events(50);
?>

<div class="wrap pixelfly-pending-events">
    <h1><?php esc_html_e('Pending Events', 'pixelfly-woocommerce'); ?></h1>

    <!-- Stats -->
    <div class="pixelfly-stats">
        <div class="stat-box pending">
            <span class="stat-number"><?php echo esc_html($stats['pending']); ?></span>
            <span class="stat-label"><?php esc_html_e('Pending', 'pixelfly-woocommerce'); ?></span>
        </div>
        <div class="stat-box fired">
            <span class="stat-number"><?php echo esc_html($stats['fired']); ?></span>
            <span class="stat-label"><?php esc_html_e('Fired', 'pixelfly-woocommerce'); ?></span>
        </div>
        <div class="stat-box failed">
            <span class="stat-number"><?php echo esc_html($stats['failed']); ?></span>
            <span class="stat-label"><?php esc_html_e('Failed', 'pixelfly-woocommerce'); ?></span>
        </div>
        <div class="stat-box total">
            <span class="stat-number"><?php echo esc_html($stats['total']); ?></span>
            <span class="stat-label"><?php esc_html_e('Total', 'pixelfly-woocommerce'); ?></span>
        </div>
    </div>

    <!-- Actions -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <?php if ($stats['pending'] > 0): ?>
                <button type="button" id="pixelfly-fire-all" class="button button-primary">
                    <?php esc_html_e('Fire All Pending Events', 'pixelfly-woocommerce'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Events Table -->
    <?php if (!empty($pending_events)): ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php esc_html_e('ID', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Order', 'pixelfly-woocommerce'); ?></th>
                    <th><?php esc_html_e('Customer', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Amount', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Payment', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Order Status', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 150px;"><?php esc_html_e('Created', 'pixelfly-woocommerce'); ?></th>
                    <th style="width: 150px;"><?php esc_html_e('Actions', 'pixelfly-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_events as $event):
                    $event_data = json_decode($event->event_data, true);
                    $order = wc_get_order($event->order_id);
                    if (!$order) continue;
                ?>
                    <tr data-event-id="<?php echo esc_attr($event->id); ?>">
                        <td><?php echo esc_html($event->id); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $event->order_id . '&action=edit')); ?>">
                                #<?php echo esc_html($event->order_id); ?>
                            </a>
                        </td>
                        <td>
                            <?php
                            echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                            ?>
                            <br>
                            <small><?php echo esc_html($order->get_billing_phone()); ?></small>
                        </td>
                        <td><?php echo wp_kses_post(wc_price($event_data['value'] ?? 0, ['currency' => $event_data['currency'] ?? 'BDT'])); ?></td>
                        <td>
                            <code><?php echo esc_html($order->get_payment_method()); ?></code>
                        </td>
                        <td>
                            <?php
                            $status = $order->get_status();
                            $status_name = wc_get_order_status_name($status);
                            echo '<mark class="order-status status-' . esc_attr($status) . '"><span>' . esc_html($status_name) . '</span></mark>';
                            ?>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($event->created_at))); ?></td>
                        <td>
                            <button type="button" class="button button-small pixelfly-fire-event" data-event-id="<?php echo esc_attr($event->id); ?>">
                                <?php esc_html_e('Fire', 'pixelfly-woocommerce'); ?>
                            </button>
                            <button type="button" class="button button-small button-link-delete pixelfly-delete-event" data-event-id="<?php echo esc_attr($event->id); ?>">
                                <?php esc_html_e('Delete', 'pixelfly-woocommerce'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="pixelfly-no-events">
            <p><?php esc_html_e('No pending events found.', 'pixelfly-woocommerce'); ?></p>
        </div>
    <?php endif; ?>
</div>

<style>
    .pixelfly-stats {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
    }

    .pixelfly-stats .stat-box {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 15px 25px;
        text-align: center;
        min-width: 100px;
    }

    .pixelfly-stats .stat-number {
        display: block;
        font-size: 24px;
        font-weight: 600;
    }

    .pixelfly-stats .stat-label {
        display: block;
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
    }

    .pixelfly-stats .pending {
        border-left: 4px solid #f0b849;
    }

    .pixelfly-stats .fired {
        border-left: 4px solid #46b450;
    }

    .pixelfly-stats .failed {
        border-left: 4px solid #dc3232;
    }

    .pixelfly-stats .total {
        border-left: 4px solid #0073aa;
    }

    .pixelfly-no-events {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 40px;
        text-align: center;
        color: #666;
    }

    .order-status {
        display: inline-flex;
        line-height: 2.5em;
        border-radius: 4px;
        border-bottom: 1px solid rgba(0, 0, 0, .05);
        margin: -.25em 0;
        cursor: inherit !important;
        white-space: nowrap;
        max-width: 100%;
        padding: 0 0.5em;
    }

    .order-status.status-processing {
        background: #c8d7e1;
        color: #2e4453;
    }

    .order-status.status-completed {
        background: #c6e1c6;
        color: #5b841b;
    }

    .order-status.status-on-hold {
        background: #f8dda7;
        color: #94660c;
    }

    .order-status.status-pending {
        background: #e5e5e5;
        color: #777;
    }

    .order-status.status-cancelled {
        background: #eba3a3;
        color: #761919;
    }
</style>