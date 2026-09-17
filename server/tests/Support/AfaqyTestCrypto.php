<?php

use Illuminate\Encryption\Encrypter;

// Exercise the same authenticated payload format and exceptions as Laravel.
// This key is synthetic and used only by isolated test fixtures.
function afaqyTestCrypto(): Encrypter
{
    return new Encrypter(str_repeat('t', 32), 'aes-256-cbc');
}
