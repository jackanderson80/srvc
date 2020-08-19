<?php
function encryptString( $msg, $cryptKey, $salt ) {

    $data = $msg. $salt;    
    //$msgEncoded = base64_encode( mcrypt_encrypt( MCRYPT_RIJNDAEL_256, md5( $cryptKey ), $data, MCRYPT_MODE_CBC, md5( md5( $cryptKey ) ) ) );
	$msgEncoded = base64_encode( opensslEncrypt($data, $cryptKey) );
	
    return( $msgEncoded );
}

function decryptString( $msg, $cryptKey, $salt ) {

    $data = $msg;
	
    //$msgDecoded  = rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, md5( $cryptKey ), base64_decode( $data ), MCRYPT_MODE_CBC, md5( md5( $cryptKey ) ) ), "\0");
	$msgDecoded  = rtrim( opensslDecrypt(base64_decode($data), $cryptKey ), "\0");
	
    $msgDecoded = substr($msgDecoded, 0, strlen($msgDecoded) - strlen($salt));
    return( $msgDecoded );
}


function opensslEncrypt($plaintext, $password) {
    $method = "AES-256-CBC";
    $key = hash('sha256', $password, true);
    $iv = openssl_random_pseudo_bytes(16);

    $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
    $hash = hash_hmac('sha256', $ciphertext, $key, true);

    return $iv . $hash . $ciphertext;
}

function opensslDecrypt($ivHashCiphertext, $password) {
    $method = "AES-256-CBC";
    $iv = substr($ivHashCiphertext, 0, 16);
    $hash = substr($ivHashCiphertext, 16, 32);
    $ciphertext = substr($ivHashCiphertext, 48);
    $key = hash('sha256', $password, true);

    if (hash_hmac('sha256', $ciphertext, $key, true) !== $hash) return null;

    return openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
}
?>