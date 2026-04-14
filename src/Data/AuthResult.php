<?php

namespace Ikromjon\NativePHP\SocialAuth\Data;

class AuthResult
{
    public function __construct(
        public string $provider,
        public ?string $userId = null,
        public ?string $identityToken = null,
        public ?string $authorizationCode = null,
        public ?string $accessToken = null,
        public ?string $email = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $displayName = null,
        public ?string $photoUrl = null,
        public ?string $nonce = null,
        public ?string $state = null,
        public ?string $realUserStatus = null,
    ) {}

    public function toArray(): array
    {
        $data = ['provider' => $this->provider];

        if ($this->userId !== null) {
            $data['userId'] = $this->userId;
        }
        if ($this->identityToken !== null) {
            $data['identityToken'] = $this->identityToken;
        }
        if ($this->authorizationCode !== null) {
            $data['authorizationCode'] = $this->authorizationCode;
        }
        if ($this->accessToken !== null) {
            $data['accessToken'] = $this->accessToken;
        }
        if ($this->email !== null) {
            $data['email'] = $this->email;
        }
        if ($this->givenName !== null) {
            $data['givenName'] = $this->givenName;
        }
        if ($this->familyName !== null) {
            $data['familyName'] = $this->familyName;
        }
        if ($this->displayName !== null) {
            $data['displayName'] = $this->displayName;
        }
        if ($this->photoUrl !== null) {
            $data['photoUrl'] = $this->photoUrl;
        }
        if ($this->nonce !== null) {
            $data['nonce'] = $this->nonce;
        }
        if ($this->state !== null) {
            $data['state'] = $this->state;
        }
        if ($this->realUserStatus !== null) {
            $data['realUserStatus'] = $this->realUserStatus;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? 'unknown',
            userId: $data['userId'] ?? null,
            identityToken: $data['identityToken'] ?? null,
            authorizationCode: $data['authorizationCode'] ?? null,
            accessToken: $data['accessToken'] ?? null,
            email: $data['email'] ?? null,
            givenName: $data['givenName'] ?? null,
            familyName: $data['familyName'] ?? null,
            displayName: $data['displayName'] ?? null,
            photoUrl: $data['photoUrl'] ?? null,
            nonce: $data['nonce'] ?? null,
            state: $data['state'] ?? null,
            realUserStatus: $data['realUserStatus'] ?? null,
        );
    }
}
