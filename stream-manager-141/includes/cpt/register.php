<?php
if (!defined('ABSPATH')) exit;
add_action('init', function(){
    register_post_type('stream_class', array(
        'label'=>'Streams',
        'public'=>false,
        'show_ui'=>false,
        'supports'=>array('title','custom-fields')
    ));
});
