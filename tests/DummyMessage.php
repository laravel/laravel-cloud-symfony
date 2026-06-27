<?php

namespace Laravel\Cloud\Symfony\Tests;

final class DummyMessage
{
    public function __construct(
        public readonly string $text,
    ) {
    }
}
