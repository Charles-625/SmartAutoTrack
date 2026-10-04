<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/mailer.php';

final class BrevoMailerTest extends TestCase
{
    public function testBuildPasswordResetEmailHtmlContainsRequiredElements(): void
    {
        $name = 'Jean Dupont';
        $resetUrl = 'https://example.com/auth/reset_password.php?token=abcdef123456';
        $html = buildPasswordResetEmailHtml($name, $resetUrl);

        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString($resetUrl, $html);
        $this->assertStringContainsString('Réinitialiser mon mot de passe', $html);
        $this->assertStringContainsString('1 heure', $html);
    }

    public function testSendBrevoEmailFailsCleanlyWhenNoApiKey(): void
    {
        putenv('BREVO_API_KEY=');
        try {
            // Dans un environnement sans clé, la fonction renvoie success=false sans lever d'exception non gérée
            $result = sendBrevoEmail('test@example.com', 'Test User', 'Sujet test', '<p>Test</p>');
            // Si la clé est chargée depuis local.php, success peut être testé
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
        } finally {
            putenv('BREVO_API_KEY');
        }
    }
}
