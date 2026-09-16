<?php

// Package fixtures use authenticated OpenSSL encryption at the Crypt boundary.
// Production resolves Laravel's encrypter from the host application.
function afaqyTestCrypto(): object
{
    return new class {
        public function encryptString(string $value): string
        {
            $iv     = random_bytes(12);
            $cipher = openssl_encrypt($value, 'aes-256-gcm', str_repeat('t', 32), OPENSSL_RAW_DATA, $iv, $tag);

            return base64_encode($iv . $tag . $cipher);
        }

        public function decryptString(string $value): string
        {
            $bytes = base64_decode($value, true);
            $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', str_repeat('t', 32), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
            if ($plain === false) {
                throw new RuntimeException('Invalid encrypted fixture.');
            }

            return $plain;
        }
    };
}
