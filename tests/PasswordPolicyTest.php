<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/password_policy.php';

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsPasswordThatMeetsEveryRequirement(): void
    {
        $result = checkPasswordPolicy('Correct-Horse-7');

        self::assertTrue($result['valid']);
        self::assertSame([], $result['failed']);
    }

    public function testRejectsMissingCharacterClasses(): void
    {
        $result = checkPasswordPolicy('lowercaseonly');

        self::assertFalse($result['valid']);
        self::assertContains('uppercase', $result['failed']);
        self::assertContains('number', $result['failed']);
        self::assertContains('symbol', $result['failed']);
    }

    public function testRejectsPasswordContainingAccountIdentity(): void
    {
        $result = checkPasswordPolicy('Maria-Reyes-7!', ['Maria Reyes', 'maria@example.com']);

        self::assertFalse($result['valid']);
        self::assertContains('identity', $result['failed']);
    }

    public function testRejectsCommonPasswordVariants(): void
    {
        $result = checkPasswordPolicy('Password1234!');

        self::assertFalse($result['valid']);
        self::assertContains('common', $result['failed']);
    }

    public function testRejectsIdentityFragments(): void
    {
        $result = checkPasswordPolicy('Reyes-Secure-7!', ['Maria Reyes', 'maria@example.com']);

        self::assertFalse($result['valid']);
        self::assertContains('identity', $result['failed']);
    }

    public function testRejectsPasswordsBeyondBcryptInputLimit(): void
    {
        $result = checkPasswordPolicy(str_repeat('Aa1!', 19));

        self::assertFalse($result['valid']);
        self::assertContains('length', $result['failed']);
        self::assertStringContainsString('72 bytes', $result['message']);
    }
}
