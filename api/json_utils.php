<?php
/**
 * Encode a value to JSON and ensure the result is valid.
 * Throws InvalidArgumentException on failure.
 */
function encode_json($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new InvalidArgumentException('JSON encode failed: ' . json_last_error_msg());
    }
    return $json;
}

/**
 * Decode a JSON string to an associative array.
 * Throws InvalidArgumentException if the string is not valid JSON.
 */
function decode_json(string $json): array {
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
    }
    return $data;
}
?>
