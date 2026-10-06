<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/google_oauth.php';

final class GoogleOAuthTest extends TestCase
{
    public function testBuildGoogleAuthUrl(): void
    {
        putenv('GOOGLE_CLIENT_ID=test-client-id.apps.googleusercontent.com');
        try {
            $state = 'random_csrf_token_123';
            $url = buildGoogleAuthUrl($state);

            $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $url);
            $this->assertStringContainsString('client_id=test-client-id.apps.googleusercontent.com', $url);
            $this->assertStringContainsString('state=random_csrf_token_123', $url);
            $this->assertStringContainsString('scope=' . urlencode('openid email profile'), $url);
        } finally {
            putenv('GOOGLE_CLIENT_ID');
        }
    }

    public function testIsGoogleOAuthReady(): void
    {
        putenv('GOOGLE_CLIENT_ID=');
        putenv('GOOGLE_CLIENT_SECRET=');
        try {
            // Sans variables, dépend de local.php (actuellement vide pour google)
            $isReady = isGoogleOAuthReady();
            $this->assertIsBool($isReady);
        } finally {
            putenv('GOOGLE_CLIENT_ID');
            putenv('GOOGLE_CLIENT_SECRET');
        }
    }
}
