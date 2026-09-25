<?php
/**
 * Server-authoritative password policy for newly assigned passwords.
 * Existing password hashes are intentionally not revalidated by this file.
 */
if (!defined('PASSWORD_POLICY_LOADED')) {
    define('PASSWORD_POLICY_LOADED', true);
}

function passwordPolicyMinimumLength(): int
{
    return defined('PASSWORD_MIN_LENGTH')
        ? max(8, (int) PASSWORD_MIN_LENGTH)
        : (defined('APP_ENV') && APP_ENV === 'production' ? 12 : 8);
}

/**
 * @return array<string, string> Stable rule identifiers and UI labels.
 */
function passwordPolicyRequirements(): array
{
    $minLength = passwordPolicyMinimumLength();

    return [
        'length' => "At least {$minLength} characters",
        'uppercase' => 'One uppercase letter',
        'lowercase' => 'One lowercase letter',
        'number' => 'One number',
        'symbol' => 'One special character',
        'common' => 'Not a predictable password',
        'identity' => 'Not your name or email',
    ];
}

/**
 * @param array<int, string> $identityValues Name/email/username fragments to reject.
 * @return array{valid: bool, message: string, failed: array<int, string>}
 */
function checkPasswordPolicy(string $password, array $identityValues = []): array
{
    $requirements = passwordPolicyRequirements();
    $failed = [];

    // password_hash uses bcrypt, which ignores input after 72 bytes. Rejecting
    // longer values prevents two different passwords from producing one hash.
    if (strlen($password) > 72) {
        $failed[] = 'length';
    } elseif (mb_strlen($password) < passwordPolicyMinimumLength()) {
        $failed[] = 'length';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $failed[] = 'uppercase';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $failed[] = 'lowercase';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $failed[] = 'number';
    }
    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        $failed[] = 'symbol';
    }

    $normalized = mb_strtolower(trim($password));
    $commonBases = [
        'password', 'qwerty', 'admin', 'administrator', 'registrar', 'bestlink',
        'welcome', 'letmein', 'iloveyou', 'changeme', 'default', 'secret',
    ];
    foreach ($commonBases as $base) {
        if (preg_match('/^' . preg_quote($base, '/') . '[0-9!@#$%^&*._-]*$/', $normalized)) {
            $failed[] = 'common';
            break;
        }
    }

    $passwordPart = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $password));
    foreach ($identityValues as $identityValue) {
        $identityTokens = preg_split('/[^a-z0-9]+/i', mb_strtolower((string) $identityValue), -1, PREG_SPLIT_NO_EMPTY);
        if (isset($identityTokens[0]) && strpos((string) strstr((string) $identityTokens[0], '@', true), '.') !== false) {
            $identityTokens[0] = strstr((string) $identityTokens[0], '@', true);
        }
        foreach ($identityTokens as $identityToken) {
            $identityToken = preg_replace('/[^a-z0-9]/i', '', (string) $identityToken);
            if (strlen((string) $identityToken) >= 4 && strpos($passwordPart, mb_strtolower((string) $identityToken)) !== false) {
                $failed[] = 'identity';
                break 2;
            }
        }
    }

    $failed = array_values(array_unique($failed));
    if (!$failed) {
        return ['valid' => true, 'message' => 'Password meets all requirements.', 'failed' => []];
    }

    $messages = [];
    if (in_array('length', $failed, true)) {
        $minLength = passwordPolicyMinimumLength();
        $messages[] = strlen($password) > 72
            ? 'Password must be 72 bytes or fewer.'
            : "Password must be at least {$minLength} characters.";
    }
    if (in_array('uppercase', $failed, true)) $messages[] = $requirements['uppercase'] . '.';
    if (in_array('lowercase', $failed, true)) $messages[] = $requirements['lowercase'] . '.';
    if (in_array('number', $failed, true)) $messages[] = $requirements['number'] . '.';
    if (in_array('symbol', $failed, true)) $messages[] = $requirements['symbol'] . '.';
    if (in_array('common', $failed, true)) $messages[] = 'Choose a less predictable password.';
    if (in_array('identity', $failed, true)) $messages[] = 'Do not include the user’s name, email, or username.';

    return [
        'valid' => false,
        'message' => implode(' ', $messages),
        'failed' => $failed,
    ];
}
