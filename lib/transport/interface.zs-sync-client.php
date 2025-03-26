<?php

interface ZS_Sync_Client {
	public function list_resources( ZS_Sync_Resource_List_Request $request ): ZS_Sync_Response_Error|array;
	public function get_resources( ZS_Sync_Resource_Fetch_Request $request ): ZS_Sync_Response_Error|CBOR\MapObject;
}
