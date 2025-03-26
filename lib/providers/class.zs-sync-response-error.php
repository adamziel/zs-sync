<?php

class ZS_Sync_Response_Error {
	const BAD_RESPONSE = 400;

	/**
	 * Maps error codes to their names.
	 *
	 * @var array
	 */
	private static $error_names = [
		self::BAD_RESPONSE => 'BAD_RESPONSE',
	];

	/**
	 * Creates a new instance of ZS_Sync_Request_Error with the specified code and message.
	 *
	 * @param int    $code    The error code.
	 * @param string $message Optional. The error message. Default empty string.
	 * @return ZS_Sync_Response_Error The error object.
	 */
	public static function create( $code, $message = '' ): ZS_Sync_Response_Error {
		return new self( $code, $message );
	}

	/**
	 * Gets the name of an error code.
	 *
	 * @param int $code The error code.
	 * @return string The name of the error code or 'UNKNOWN_ERROR' if not found.
	 */
	public static function get_error_name( $code ) {
		return self::$error_names[$code] ?? 'UNKNOWN_ERROR';
	}

	/**
	 * @var int
	 */
	public $code;

	/**
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param int    $code    The error code.
	 * @param string $message The error message.
	 */
	public function __construct( $code, $message = '' ) {
		$this->code = $code;
		$this->message = $message;
	}
	/**
	 * Returns a string representation of the error.
	 *
	 * @return string The error code name, code, and message.
	 */
	public function __toString() {
		$error_name = self::get_error_name( $this->code );
		if ( empty( $this->message ) ) {
			return $error_name . ' (' . $this->code . ')';
		}
		return $error_name . ' (' . $this->code . '): ' . $this->message;
	}

}
