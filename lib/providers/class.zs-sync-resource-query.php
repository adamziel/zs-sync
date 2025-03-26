<?php

/**
 * Class ZS_Sync_Resource_Query
 * 
 * Represents a query for a specific sync resource.
 * Used in resource requests to specify which resources are wanted.
 */
class ZS_Sync_Resource_Query {
    /**
     * The URI of the requested resource
     * 
     * @var ZS_Sync_URI
     */
    public $uri;

    /**
     * @var int|null
     */
    public $range_start;

    /**
     * @var int|null
     */
    public $range_length;

    /**
     * @param string|array $data Resource data
     *     @type string $uri The URI of the requested resource
     *     @type array $range Optional range parameters
     *         @type int $start The start position of the range to retrieve
     *         @type int $length The length of the range to retrieve
	 */
    public function __construct(array $data) {
        $this->uri = ZS_Sync_URI::from_string($data['uri']);
        $this->range_start = $data['range']['start'] ?? null;
        $this->range_length = $data['range']['length'] ?? null;
    }

	public function get_raw_data(): string|array {
		$data = [
			'uri' => $this->uri->__toString(),
		];
		if($this->range_start !== null) {
			$data['range'] = [
				'start' => $this->range_start,
				'length' => $this->range_length
			];
		}
		if(count($data) === 1) {
			return $data['uri'];
		}
		return $data;
	}
} 