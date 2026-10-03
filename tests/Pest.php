<?php

uses()->in(__DIR__);

use Ikromjon\NativePHP\SocialAuth\SocialAuth;

/** A SocialAuth that records the bridge call instead of making one. */
function recordingBridge(?string $response = null): SocialAuth
{
    return new class($response) extends SocialAuth
    {
        public ?string $method = null;

        /** @var array<string, mixed> */
        public array $params = [];

        public bool $called = false;

        public function __construct(private ?string $response) {}

        protected function call(string $method, array $params = []): ?string
        {
            $this->called = true;
            $this->method = $method;
            $this->params = $params;

            return $this->response;
        }
    };
}
