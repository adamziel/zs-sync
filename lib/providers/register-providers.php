<?php

function zs_register_providers( ZS_Sync_Request_Registry $registry ) {
	$registry->register_provider( 'core.post.post_contents', ZS_Sync_Provider_Core_Post::provide(...), 10);
}
