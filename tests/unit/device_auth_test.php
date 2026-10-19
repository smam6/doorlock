<?php

declare(strict_types=1);

namespace DoorLock\Tests;

use DoorLock\Config;
use DoorLock\DeviceAuth;

/**
 * Cross-language test vectors. The expected values were produced with an
 * independent Python implementation of the documented protocol.
 */
const VECTOR_UID = '0123456789abcdef0123456789abcdef';
const VECTOR_TS = 1760000000;
const VECTOR_NONCE = 'a1b2c3d4e5f60718';
const VECTOR_REQUEST_ID = 'req-12345678';
const VECTOR_BODY = '{"card_number":"04AABBCC","door_id":1}';
const VECTOR_KEY = '90b55e88d48719b10338ed254fc8a8988d7ec7234f0a9513689679bff61d6e26';
const VECTOR_CANONICAL_SHA = '91dbccb3925ec80be5c729b3b17d35f9567d2ea1b47ca35c4ba7dfbc280660ea';
const VECTOR_SIGNATURE = '8e5eae175623653c888746d38bea984e1944c36eeca518ea876ec382fb1f65d3';
const VECTOR_BODY_SHA = '61b21984380594570a087c8abed7570ff3313b6ad91eec12a155bd0c9d7618b7';

test('DEV-001', 'Device key derivation matches the independent vector', function (): void {
    Assert::same(VECTOR_KEY, DeviceAuth::deriveDeviceKey(VECTOR_UID, 1));
    Assert::notSame(VECTOR_KEY, DeviceAuth::deriveDeviceKey(VECTOR_UID, 2));
});

test('DEV-002', 'Canonical string layout is stable', function (): void {
    $canonical = DeviceAuth::canonicalString(
        VECTOR_UID,
        VECTOR_TS,
        VECTOR_NONCE,
        VECTOR_REQUEST_ID,
        VECTOR_BODY
    );
    Assert::same(VECTOR_CANONICAL_SHA, hash('sha256', $canonical));
    Assert::same(
        "DOORLOCK1\nPOST\ncheck-access\n" . VECTOR_UID . "\n" . VECTOR_TS . "\n" . VECTOR_NONCE . "\n" . VECTOR_REQUEST_ID . "\n" . VECTOR_BODY_SHA,
        $canonical
    );
});

test('DEV-003', 'Signature matches the independent vector', function (): void {
    Assert::same(
        VECTOR_SIGNATURE,
        DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS, VECTOR_NONCE, VECTOR_REQUEST_ID, VECTOR_BODY)
    );
});

test('DEV-004', 'Signature changes when any signed component changes', function (): void {
    $base = DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS, VECTOR_NONCE, VECTOR_REQUEST_ID, VECTOR_BODY);
    $variants = [
        DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS + 1, VECTOR_NONCE, VECTOR_REQUEST_ID, VECTOR_BODY),
        DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS, 'ffffffffffffffff', VECTOR_REQUEST_ID, VECTOR_BODY),
        DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS, VECTOR_NONCE, 'req-87654321', VECTOR_BODY),
        DeviceAuth::signature(VECTOR_KEY, VECTOR_UID, VECTOR_TS, VECTOR_NONCE, VECTOR_REQUEST_ID, VECTOR_BODY . ' '),
        DeviceAuth::signature(str_repeat('0', 64), VECTOR_UID, VECTOR_TS, VECTOR_NONCE, VECTOR_REQUEST_ID, VECTOR_BODY),
    ];
    foreach ($variants as $variant) {
        Assert::notSame($base, $variant);
    }
});

test('DEV-005', 'Device keys are domain-separated from the application key', function (): void {
    Assert::notSame(Config::appKeyRaw(), DeviceAuth::deriveDeviceKey(VECTOR_UID, 1));
    Assert::notSame(
        DeviceAuth::deriveDeviceKey(VECTOR_UID, 1),
        DeviceAuth::deriveDeviceKey('fedcba9876543210fedcba9876543210', 1)
    );
});

test('DEV-006', 'Nonce and signature formats are enforced', function (): void {
    Assert::true(preg_match('/^[a-f0-9]{16,64}$/', VECTOR_NONCE) === 1);
    Assert::true(preg_match('/^[a-f0-9]{64}$/', VECTOR_SIGNATURE) === 1);
});
