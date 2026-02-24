<?php
// Define helper functions for JWT generation and verification without external libraries
// Depends on JWT_SECRET_KEY defined in db_config.php

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $padding = strlen($data) % 4;
    $padding = $padding !== 0 ? 4 - $padding : 0;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $padding));
}

function generate_jwt($payload) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    
    // Add issued at and expiration (1 hour) to payload
    $payload['iat'] = time();
    $payload['exp'] = time() + 3600;
    $payload_json = json_encode($payload);

    $base64UrlHeader = base64url_encode($header);
    $base64UrlPayload = base64url_encode($payload_json);
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET_KEY, true);
    $base64UrlSignature = base64url_encode($signature);

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function verify_jwt($jwt) {
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) != 3) {
        return false;
    }
    
    $header = base64url_decode($tokenParts[0]);
    $payload = base64url_decode($tokenParts[1]);
    $signature_provided = $tokenParts[2];

    // Re-verify the signature
    $base64UrlHeader = base64url_encode($header);
    $base64UrlPayload = base64url_encode($payload);
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET_KEY, true);
    $base64UrlSignature = base64url_encode($signature);

    if (hash_equals($base64UrlSignature, $signature_provided)) {
        $payload_data = json_decode($payload, true);
        // Check expiration
        if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
            return false; // Token expired
        }
        return $payload_data;
    }
    
    return false; // Invalid signature
}
?>
