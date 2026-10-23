<?php

namespace Awove\Core\Events;

final readonly class SignInLinkRequested
{
    public function __construct(public int $userId) {}
}
