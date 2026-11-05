<?php
return [
    'website' => ['automatic_after_approval' => true],
    'rss' => ['automatic_after_website' => true],
    'newsletter' => ['requires_approval' => true, 'requires_test_send' => true],
    'facebook_post' => ['requires_approval' => true],
    'facebook_event' => ['mode' => 'manual_eventbrite_publish_to_facebook'],
    'instagram' => ['requires_approval' => true, 'requires_rights' => true],
    'press' => ['requires_approval' => true, 'default_action' => 'draft_only'],
];