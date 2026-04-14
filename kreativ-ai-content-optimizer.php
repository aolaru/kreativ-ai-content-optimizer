<?php
/**
 * Plugin Name: KREATIV AI Content Optimizer
 * Description: Auditable, reversible, and safe content optimization workflows for font posts.
 * Version: 1.0.1
 * Author: KREATIV
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-kaco-plugin.php';

new KACO_Plugin(__FILE__);
